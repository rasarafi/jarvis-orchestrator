import { percentOfDay, formatDuration, timeToMinutes } from './lib/format'
import { projectLabel } from '@/lib/project-label'
import type { ScheduledTask, TimeBlock, TodaySessionSpan } from '@/types/today'

interface DayRibbonProps {
  blocks: TimeBlock[]
  /** Current local time as a Date (the NOW pointer). */
  now: Date
  /** Actual CC-session spans for the day (transcripts-derived, not manually logged). */
  actuals?: TodaySessionSpan[]
  /** Scheduled tasks, used to compute the per-project planned-vs-actual readout. */
  scheduledTasks?: ScheduledTask[]
  /** slug -> CSS color, shared with the rest of the Today page. */
  projectColors?: Map<string, string>
}

/** Percentage (0-100) of the 24h day for a Date's local wall-clock time. */
function percentOfDayFromDate(date: Date): number {
  return (date.getHours() * 60 + date.getMinutes()) / (24 * 60) * 100
}

interface ProjectReadoutRow {
  key: string
  name: string
  plannedMinutes: number
  actualMinutes: number
  /** Earliest scheduled start time ('HH:mm:ss') for this project, used for the amber-lag check. */
  earliestStart: string | null
}

function buildProjectReadout(
  scheduledTasks: ScheduledTask[],
  actuals: TodaySessionSpan[],
): ProjectReadoutRow[] {
  const rows = new Map<string, ProjectReadoutRow>()

  for (const t of scheduledTasks) {
    const slug = t.task.project?.slug
    const name = projectLabel(t.task.project)
    const key = slug ?? name
    const minutes = Math.max(0, timeToMinutes(t.end_time) - timeToMinutes(t.start_time))
    const existing = rows.get(key)
    if (existing) {
      existing.plannedMinutes += minutes
      if (!existing.earliestStart || t.start_time < existing.earliestStart) {
        existing.earliestStart = t.start_time
      }
    } else {
      rows.set(key, { key, name, plannedMinutes: minutes, actualMinutes: 0, earliestStart: t.start_time })
    }
  }

  for (const a of actuals) {
    const key = a.project_slug ?? a.project_name
    const minutes = a.seconds / 60
    const existing = rows.get(key)
    if (existing) {
      existing.actualMinutes += minutes
    } else {
      rows.set(key, {
        key,
        name: a.project_name,
        plannedMinutes: 0,
        actualMinutes: minutes,
        earliestStart: null,
      })
    }
  }

  return Array.from(rows.values())
    .sort((a, b) => b.plannedMinutes - a.plannedMinutes)
    .slice(0, 6)
}

function formatMinutes(minutes: number): string {
  const rounded = Math.round(minutes)
  const h = Math.floor(rounded / 60)
  const m = rounded % 60
  if (h === 0) return `${m}m`
  if (m === 0) return `${h}h`
  return `${h}h ${m}m`
}

const BLOCK_COLORS: Record<string, string> = {
  morning: 'linear-gradient(90deg, rgba(245,158,11,0.45), rgba(245,158,11,0.25))',
  evening: 'linear-gradient(90deg, rgba(99,102,241,0.45), rgba(99,102,241,0.22))',
  personal: 'rgba(113,113,122,0.18)',
  default: 'rgba(255,255,255,0.06)',
}

function colorForBlock(block: TimeBlock): string {
  const name = block.name.toLowerCase()
  if (name.includes('morning')) return BLOCK_COLORS.morning
  if (name.includes('evening')) return BLOCK_COLORS.evening
  if (block.kind === 'personal') return BLOCK_COLORS.personal
  return BLOCK_COLORS.default
}

function textColorForBlock(block: TimeBlock): string {
  const name = block.name.toLowerCase()
  if (name.includes('morning')) return '#fde68a'
  if (name.includes('evening')) return '#c7d2fe'
  if (block.kind === 'personal') return '#71717a'
  return '#a1a1aa'
}

export function DayRibbon({ blocks, now, actuals, scheduledTasks, projectColors }: DayRibbonProps) {
  const nowMinutes = now.getHours() * 60 + now.getMinutes()
  const nowPercent = (nowMinutes / (24 * 60)) * 100
  const spans = actuals ?? []
  const readout = buildProjectReadout(scheduledTasks ?? [], spans)

  return (
    <div className="bg-deck border border-line-soft rounded-lg p-2.5 mb-3">
      <div className="relative h-[22px] bg-hover-strong rounded-md overflow-visible mb-[7px]">
        {blocks.map((block) => {
          const left = percentOfDay(block.start_time)
          const right = percentOfDay(block.end_time)
          const width = Math.max(0, right - left)
          if (width <= 0) return null
          return (
            <div
              key={block.id}
              className="absolute top-0 bottom-0 flex items-center px-2 text-[10px] font-medium rounded-sm"
              style={{
                left: `${left}%`,
                width: `${width}%`,
                background: colorForBlock(block),
                color: textColorForBlock(block),
              }}
            >
              <span className="truncate">
                {block.name} · {formatDuration(block.start_time, block.end_time)}
              </span>
            </div>
          )
        })}

        {/* NOW pointer */}
        <div
          className="absolute -top-1 -bottom-1 w-0.5 bg-orange-400 z-10 shadow-[0_0_10px_#fb923c]"
          style={{ left: `${nowPercent}%` }}
        >
          <div className="absolute -top-2 -translate-x-1 w-2 h-2 rounded-full bg-orange-400 animate-pulse shadow-[0_0_10px_#fb923c]" />
          <div className="absolute -top-5 -translate-x-1/2 text-[10px] text-orange-400 font-semibold whitespace-nowrap">
            {now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}
          </div>
        </div>
      </div>

      {/* Actual track — CC-session spans on the same percentOfDay axis. */}
      <div className="relative h-[8px] bg-hover-strong/50 rounded-md overflow-visible">
        {spans.map((a) => {
          const start = new Date(a.started_at)
          const end = new Date(a.ended_at)
          const startPct = percentOfDayFromDate(start)
          // A span ending on a later local day clamps to day-end; the
          // after-midnight remainder belongs to the next day's view.
          const endPct = end.toDateString() === start.toDateString() ? percentOfDayFromDate(end) : 100
          const color = (a.project_slug && projectColors?.get(a.project_slug)) || 'var(--arc)'
          return (
            <div
              key={`${a.project_slug ?? a.project_name}-${a.started_at}`}
              title={`${a.project_name} · ${Math.round(a.seconds / 60)} min`}
              className="absolute top-[1.5px] h-[5px] rounded-[2px] opacity-70"
              style={{
                left: `${startPct}%`,
                width: `${Math.max(0.5, endPct - startPct)}%`,
                background: color,
              }}
            />
          )
        })}
      </div>

      <div className="flex justify-between text-[9px] text-dim font-mono mt-1">
        <span>0</span>
        <span>6</span>
        <span>12</span>
        <span>18</span>
        <span>24</span>
      </div>

      <div className="font-mono2 text-[10.5px] text-dim mt-1">
        planned (top) · actual sessions (bottom)
      </div>

      {readout.length > 0 && (
        <div className="mt-2 pt-2 border-t border-line-soft space-y-1">
          {readout.map((row) => {
            const isLagging =
              row.plannedMinutes > 0 &&
              row.actualMinutes === 0 &&
              row.earliestStart != null &&
              nowMinutes > timeToMinutes(row.earliestStart)
            return (
              <div key={row.key} className="flex items-center justify-between text-[10.5px] font-mono2">
                <span className={isLagging ? 'text-gold' : 'text-ink'}>{row.name}</span>
                <span className="text-dim">
                  planned {formatMinutes(row.plannedMinutes)} · actual {formatMinutes(row.actualMinutes)}
                </span>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
