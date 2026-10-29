import { cn } from '@/lib/utils'
import { formatClock } from './lib/format'
import { TaskRow } from './TaskRow'
import type { ScheduledTask, TimeBlock } from '@/types/today'

interface BlockListProps {
  blocks: TimeBlock[]
  scheduledTasks: ScheduledTask[]
  projectColors: Map<string, string>
  /** ID of the current scheduled task (rendered in HeroCard above). Skipped from the list to avoid duplication. */
  currentScheduledTaskId?: string | null
  now: Date
}

interface BlockMeta {
  block: TimeBlock | null
  tasks: ScheduledTask[]
  isActive: boolean
}

function isWithinBlock(block: TimeBlock, now: Date): boolean {
  const nowMin = now.getHours() * 60 + now.getMinutes()
  const [sh, sm] = block.start_time.split(':').map(Number)
  const [eh, em] = block.end_time.split(':').map(Number)
  const startMin = sh * 60 + sm
  const endMin = eh * 60 + em
  return nowMin >= startMin && nowMin < endMin
}

function groupTasksByBlock(blocks: TimeBlock[], tasks: ScheduledTask[]): BlockMeta[] {
  const byBlockId = new Map<string | null, ScheduledTask[]>()
  for (const t of tasks) {
    const key = t.time_block_id
    const arr = byBlockId.get(key) ?? []
    arr.push(t)
    byBlockId.set(key, arr)
  }
  const sorted = blocks
    .slice()
    .sort((a, b) => a.display_order - b.display_order || a.start_time.localeCompare(b.start_time))
  return sorted.map((block) => ({
    block,
    tasks: (byBlockId.get(block.id) ?? []).sort((a, b) =>
      a.start_time.localeCompare(b.start_time),
    ),
    isActive: false, // populated below
  }))
}

function blockIcon(block: TimeBlock): string {
  const name = block.name.toLowerCase()
  if (name.includes('morning')) return 'M'
  if (name.includes('evening')) return 'E'
  if (block.kind === 'personal') return 'P'
  return block.name.charAt(0).toUpperCase()
}

function blockTone(block: TimeBlock): { bg: string; text: string } {
  const name = block.name.toLowerCase()
  if (name.includes('morning')) return { bg: 'bg-amber-500/20', text: 'text-amber-200' }
  if (name.includes('evening')) return { bg: 'bg-indigo-500/20', text: 'text-indigo-200' }
  if (block.kind === 'personal') return { bg: 'bg-deck-2', text: 'text-dim' }
  return { bg: 'bg-deck-2', text: 'text-mut' }
}

export function BlockList({
  blocks,
  scheduledTasks,
  projectColors,
  currentScheduledTaskId,
  now,
}: BlockListProps) {
  const grouped = groupTasksByBlock(blocks, scheduledTasks).map((g) => ({
    ...g,
    isActive: g.block ? isWithinBlock(g.block, now) : false,
  }))

  return (
    <>
      <h2 className="text-[11px] uppercase tracking-wider text-dim font-semibold mb-3 flex justify-between items-center">
        <span>The rest of today</span>
      </h2>
      <div className="flex flex-col gap-3">
        {grouped.map(({ block, tasks, isActive }) => {
          if (!block) return null
          const tone = blockTone(block)
          if (block.kind === 'personal') {
            return (
              <div key={block.id} className="bg-deck border border-line-soft rounded-xl">
                <div className="flex items-center px-4 py-3 gap-2.5 text-dim text-xs">
                  <div
                    className={cn(
                      'w-6 h-6 rounded-md flex items-center justify-center text-[11px] font-bold',
                      tone.bg,
                      tone.text,
                    )}
                  >
                    {blockIcon(block)}
                  </div>
                  Personal time · {block.name.toLowerCase()}
                  <div className="ml-auto text-[11px] font-mono text-dim">
                    {formatClock(block.start_time)} – {formatClock(block.end_time)}
                  </div>
                </div>
              </div>
            )
          }
          return (
            <div key={block.id} className="bg-deck border border-line-soft rounded-xl">
              <div className="flex items-center px-4 py-3 gap-2.5">
                <div
                  className={cn(
                    'w-6 h-6 rounded-md flex items-center justify-center text-[11px] font-bold',
                    tone.bg,
                    tone.text,
                  )}
                >
                  {blockIcon(block)}
                </div>
                <div className="text-[13px] font-semibold text-ink">{block.name} block</div>
                {isActive && (
                  <span className="px-1.5 py-0.5 bg-orange-500/20 text-orange-400 rounded-full text-[9px] font-bold uppercase tracking-wider">
                    ● Active
                  </span>
                )}
                <div className="ml-auto text-[11px] font-mono text-dim">
                  {formatClock(block.start_time)} – {formatClock(block.end_time)}
                </div>
              </div>
              {tasks.length > 0 && (
                <div className="px-3 pb-3 pl-[50px] flex flex-col gap-1">
                  {tasks.map((t) => {
                    if (t.id === currentScheduledTaskId) {
                      return (
                        <div
                          key={t.id}
                          className="flex items-center gap-2.5 px-3 py-2 rounded-md text-xs text-dim"
                        >
                          ↑ shown above
                        </div>
                      )
                    }
                    return <TaskRow key={t.id} scheduledTask={t} projectColors={projectColors} />
                  })}
                </div>
              )}
            </div>
          )
        })}
      </div>
    </>
  )
}
