import type { TodayPayload, ScheduledTask } from '@/types/today'
import { timeToMinutes } from '@/features/today/lib/format'
import { projectLabel } from '@/lib/project-label'
import { formatTokens } from '@/lib/dates'
import { useQuotaWindow } from './hooks/use-quota-window'

function fmt(t: string): string {
  return t.slice(0, 5)
}

export function currentScheduled(payload: TodayPayload | undefined, now: Date): ScheduledTask | null {
  if (!payload) return null
  const nowMin = now.getHours() * 60 + now.getMinutes()
  return (
    payload.scheduled_tasks.find(
      (t) => timeToMinutes(t.start_time) <= nowMin && nowMin < timeToMinutes(t.end_time),
    ) ?? null
  )
}

interface NowStripProps {
  today: TodayPayload | undefined
  liveCount: number
  needsYouCount: number
}

export function NowStrip({ today, liveCount, needsYouCount }: NowStripProps) {
  // Quota folded in from the retired deck QuotaPanel (full panel: Analytics).
  const { data: quotaResponse } = useQuotaWindow()
  const win = quotaResponse?.data ?? null
  const quotaValue = win
    ? win.pct_of_ceiling != null
      ? `${win.pct_of_ceiling}%`
      : formatTokens(win.tokens_used)
    : 'idle'
  const quotaGold = win?.pct_of_ceiling != null && win.pct_of_ceiling >= 80

  const now = new Date()
  const current = currentScheduled(today, now)
  const scheduled = today?.scheduled_tasks ?? []
  const doneCount = scheduled.filter((t) => t.task.status === 'done').length

  const nowMin = now.getHours() * 60 + now.getMinutes()
  let progress = 0
  let remainingMin = 0
  if (current) {
    const start = timeToMinutes(current.start_time)
    const end = timeToMinutes(current.end_time)
    progress = Math.min(1, Math.max(0, (nowMin - start) / Math.max(1, end - start)))
    remainingMin = Math.max(0, end - nowMin)
  }

  return (
    <section className="relative flex items-center gap-6 overflow-hidden rounded-[10px] border border-line bg-gradient-to-r from-deck-2 to-deck px-5 py-3.5">
      <span className="absolute inset-y-0 left-0 w-[3px] bg-arc shadow-[0_0_14px_rgba(86,200,232,0.7)]" />
      <span className="font-display text-[11px] font-semibold tracking-[0.22em] text-arc">NOW</span>

      {current ? (
        <>
          <div className="min-w-0">
            <div className="truncate text-[15.5px] font-semibold text-ink">{current.task.title}</div>
            <div className="font-mono2 text-xs text-mut">
              {fmt(current.start_time)} → {fmt(current.end_time)}
              {` · ${projectLabel(current.task.project)}`}
            </div>
          </div>
          <div className="hidden max-w-[260px] flex-1 md:block">
            <div className="h-1 overflow-hidden rounded bg-line">
              <span className="block h-full rounded bg-arc" style={{ width: `${progress * 100}%` }} />
            </div>
            <div className="mt-[5px] font-mono2 text-[11px] text-dim">{remainingMin} min left</div>
          </div>
        </>
      ) : (
        <div className="text-[14px] text-mut">
          Nothing scheduled right now
          {scheduled.length === 0 && ' — the day is unplanned'}.
        </div>
      )}

      <div className="ml-auto flex gap-6">
        <Stat value={String(liveCount)} label="sessions live" tone="arc" />
        <Stat value={String(needsYouCount)} label="needs you" tone={needsYouCount > 0 ? 'gold' : undefined} />
        <Stat value={quotaValue} label="quota" tone={quotaGold ? 'gold' : undefined} />
        {scheduled.length > 0 && <Stat value={`${doneCount}/${scheduled.length}`} label="plan done" />}
      </div>
    </section>
  )
}

function Stat({ value, label, tone }: { value: string; label: string; tone?: 'arc' | 'gold' }) {
  return (
    <div className="text-right">
      <div
        className={`font-mono2 text-[15px] font-medium ${
          tone === 'arc' ? 'text-arc' : tone === 'gold' ? 'text-gold' : 'text-ink'
        }`}
      >
        {value}
      </div>
      <div className="text-[10.5px] uppercase tracking-[0.08em] text-dim">{label}</div>
    </div>
  )
}
