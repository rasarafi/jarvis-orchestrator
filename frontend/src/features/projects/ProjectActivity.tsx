import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { ChevronDown } from 'lucide-react'
import { Card, CardHeader } from '@/features/deck/ui'
import { useRecapOverview } from '@/features/recap/hooks/use-recap'
import { dayLabel, formatEndTime } from '@/features/recap/recap-format'
import { cn } from '@/lib/utils'
import type { SessionRecap, Task } from '@/types/api'

type TimelineItem =
  | { kind: 'recap'; at: string; recap: SessionRecap }
  | { kind: 'task'; at: string; task: Task }

function completedWithin7d(task: Task): boolean {
  return (
    task.status === 'done' &&
    !!task.completed_at &&
    Date.now() - new Date(task.completed_at).getTime() <= 7 * 86_400_000
  )
}

/**
 * Merged per-project timeline: session recaps (last week) + tasks completed
 * in the last 7 days, newest first, grouped by day. The hue spine ties the
 * page to its project color.
 */
const INITIAL_ITEMS = 12

export function ProjectActivity({ slug, tasks, hue }: { slug: string; tasks: Task[] | undefined; hue: string }) {
  const { data: overview, isLoading } = useRecapOverview('week')
  const [showAll, setShowAll] = useState(false)

  const { days, total } = useMemo(() => {
    const recaps = (overview?.recaps ?? []).filter((r) => r.project?.slug === slug)
    const doneTasks = (tasks ?? []).filter(completedWithin7d)

    const all: TimelineItem[] = [
      ...recaps
        .filter((r) => r.ended_at)
        .map((r) => ({ kind: 'recap' as const, at: r.ended_at as string, recap: r })),
      ...doneTasks.map((t) => ({ kind: 'task' as const, at: t.completed_at as string, task: t })),
    ].sort((a, b) => b.at.localeCompare(a.at))

    const items = showAll ? all : all.slice(0, INITIAL_ITEMS)

    const grouped: Array<{ label: string; items: TimelineItem[] }> = []
    for (const item of items) {
      const label = dayLabel(item.at)
      const last = grouped[grouped.length - 1]
      if (last?.label === label) last.items.push(item)
      else grouped.push({ label, items: [item] })
    }
    return { days: grouped, total: all.length }
  }, [overview, tasks, slug, showAll])

  return (
    <Card>
      <CardHeader
        title="Activity"
        count="last 7 days"
        action={
          <Link to="/recap" className="text-xs text-arc hover:underline">
            Full recap →
          </Link>
        }
      />
      {isLoading && <div className="px-[18px] py-5 text-[12.5px] text-dim">Loading activity…</div>}
      {!isLoading && days.length === 0 && (
        <div className="px-[18px] py-5 text-[12.5px] text-dim">
          Nothing recorded this week — recaps and completed tasks will land here.
        </div>
      )}
      {days.length > 0 && (
        <div className="px-[18px] pb-4 pt-1.5">
          {days.map((day) => (
            <div key={day.label}>
              <div className="mb-0.5 ml-[26px] mt-3 font-mono2 text-[10px] uppercase tracking-[0.1em] text-dim">
                {day.label}
              </div>
              {day.items.map((item) =>
                item.kind === 'recap' ? (
                  <RecapRow key={item.recap.id} recap={item.recap} hue={hue} />
                ) : (
                  <TaskRow key={item.task.id} task={item.task} hue={hue} />
                ),
              )}
            </div>
          ))}
          {total > INITIAL_ITEMS && (
            <button
              onClick={() => setShowAll((v) => !v)}
              className="ml-[26px] mt-2 text-[11.5px] text-arc hover:underline"
              aria-expanded={showAll}
            >
              {showAll ? 'Show less' : `Show all ${total} items`}
            </button>
          )}
        </div>
      )}
    </Card>
  )
}

function Spine({ hue, ok }: { hue: string; ok?: boolean }) {
  return (
    <>
      <span
        className="absolute bottom-0 left-2 top-0 w-[2px] opacity-30"
        style={{ background: `linear-gradient(180deg, ${hue}, transparent)` }}
        aria-hidden
      />
      <span
        className={cn('absolute left-[5px] top-[15px] h-2 w-2 rounded-full border-2 bg-deck', ok && 'border-ok')}
        style={ok ? undefined : { borderColor: hue }}
        aria-hidden
      />
    </>
  )
}

function RecapRow({ recap, hue }: { recap: SessionRecap; hue: string }) {
  const [open, setOpen] = useState(false)
  const hasBody = recap.bullets.length > 0 || recap.next_action

  return (
    <div className="relative py-2 pl-[26px]">
      <Spine hue={hue} />
      <button
        onClick={hasBody ? () => setOpen((v) => !v) : undefined}
        className={cn('w-full text-left text-[12.5px] leading-relaxed text-mut', hasBody && 'hover:text-ink')}
        aria-expanded={hasBody ? open : undefined}
      >
        <span className="mr-2 font-mono2 text-[10.5px] text-dim">{formatEndTime(recap.ended_at)}</span>
        <span className="mr-1.5 font-mono2 text-[9.5px] uppercase tracking-[0.07em]" style={{ color: hue }}>
          recap
        </span>
        <b className="font-medium text-ink">{recap.headline ?? 'Untitled session'}</b>
        {recap.bullets[0] && !open && <span> — {recap.bullets[0]}</span>}
        {hasBody && (
          <ChevronDown
            className={cn('ml-1 inline h-3 w-3 text-dim transition-transform', open && 'rotate-180')}
          />
        )}
      </button>
      {open && (
        <ul className="mt-1.5 grid gap-1">
          {recap.bullets.map((bullet, i) => (
            <li key={i} className="pl-1 text-[12.5px] leading-relaxed text-mut">
              · {bullet}
            </li>
          ))}
          {recap.next_action && (
            <li className="pl-1 text-[12.5px] text-gold">
              <b className="font-semibold">Next:</b> {recap.next_action}
            </li>
          )}
        </ul>
      )}
    </div>
  )
}

function TaskRow({ task, hue }: { task: Task; hue: string }) {
  return (
    <div className="relative py-2 pl-[26px] text-[12.5px] leading-relaxed text-mut">
      <Spine hue={hue} ok />
      <span className="mr-2 font-mono2 text-[10.5px] text-dim">{formatEndTime(task.completed_at)}</span>
      <span className="mr-1.5 font-mono2 text-[9.5px] uppercase tracking-[0.07em] text-ok">task</span>
      Completed <b className="font-medium text-ink">“{task.title}”</b>
    </div>
  )
}
