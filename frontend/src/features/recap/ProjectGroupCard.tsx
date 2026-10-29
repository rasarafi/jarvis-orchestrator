import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronDown } from 'lucide-react'
import { projectColor } from '@/features/deck/ui'
import { cn } from '@/lib/utils'
import type { SessionRecap } from '@/types/api'
import { RecapItem } from './RecapCard'
import { formatDuration, type ProjectRecapGroup, type QuickBranchGroup } from './recap-format'

/** One project's sessions in the stream. A single session renders as a plain RecapItem
 *  (a group wrapper around one row is just an extra click); 2+ sessions collapse into
 *  a card whose header carries the scannable facts — count, gist, time range, total —
 *  and expands to the session rows. Significant sessions get full cards; quick,
 *  probably-automated sessions (short, low message count — e.g. per-commit security
 *  review passes in a worktree) collapse into one row per branch so a handful of real
 *  work sessions aren't buried under dozens of 1-minute passes. */
export function ProjectGroupCard({
  group,
  rollup,
  defaultOpen = false,
}: {
  group: ProjectRecapGroup
  /** LLM-merged one-liner for the group; falls back to the latest session headline. */
  rollup?: string
  defaultOpen?: boolean
}) {
  const [open, setOpen] = useState(defaultOpen)
  const navigate = useNavigate()

  if (group.recaps.length === 1) {
    return <RecapItem recap={group.recaps[0]} defaultOpen={defaultOpen} />
  }

  const hue = group.project?.color ?? projectColor(group.project?.name ?? 'unknown')
  const gist = rollup ?? group.recaps[0].headline ?? ''
  const tasksClosed = group.recaps.reduce((sum, r) => sum + (r.facts.tasks_closed ?? 0), 0)
  const quickCount = group.quick.reduce((sum, g) => sum + g.recaps.length, 0)

  return (
    <div className="mb-1.5">
      <button
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className={cn(
          'flex w-full items-center gap-2.5 overflow-hidden rounded-[10px] border border-line-soft bg-deck py-2 pr-3 text-left shadow-[var(--card-shadow)] hover:border-arc/30',
          open && 'rounded-b-none border-arc/40',
        )}
      >
        <span className="w-[3px] self-stretch shrink-0" style={{ background: hue }} />
        <span
          className={cn(
            'shrink-0 font-display text-[11px] font-bold uppercase tracking-[0.1em]',
            group.project?.slug && 'cursor-pointer hover:underline',
          )}
          style={{ color: hue }}
          role={group.project?.slug ? 'link' : undefined}
          tabIndex={group.project?.slug ? 0 : undefined}
          onClick={
            group.project?.slug
              ? (e) => {
                  e.stopPropagation()
                  navigate(`/projects/${group.project!.slug}`)
                }
              : undefined
          }
          onKeyDown={
            group.project?.slug
              ? (e) => {
                  if (e.key === 'Enter') {
                    e.stopPropagation()
                    navigate(`/projects/${group.project!.slug}`)
                  }
                }
              : undefined
          }
        >
          {group.project?.name ?? 'Unassigned'}
        </span>
        {quickCount === 0 ? (
          <span className="shrink-0 rounded-full border border-line-soft bg-deck-2 px-2 py-px font-mono2 text-[10.5px] tabular-nums text-mut">
            {group.recaps.length} sessions
          </span>
        ) : group.significant.length === 0 ? (
          <span className="shrink-0 rounded-full border border-line-soft bg-deck-2 px-2 py-px font-mono2 text-[10.5px] tabular-nums text-mut">
            {quickCount} quick sessions
          </span>
        ) : (
          <>
            <span className="shrink-0 rounded-full border border-line-soft bg-deck-2 px-2 py-px font-mono2 text-[10.5px] tabular-nums text-mut">
              {group.significant.length} sessions
            </span>
            <span className="shrink-0 rounded-full border border-gold/35 bg-gold-soft px-2 py-px font-mono2 text-[10.5px] tabular-nums text-gold">
              +{quickCount} quick
            </span>
          </>
        )}
        <span className="min-w-0 flex-1 truncate text-[12.5px] text-mut">{gist}</span>
        <span className="shrink-0 whitespace-nowrap font-mono2 text-[11px] tabular-nums text-dim">
          {tasksClosed > 0 && <span className="text-ok">✓{tasksClosed} · </span>}
          {group.timeRange && `${group.timeRange} · `}
          {formatDuration(group.totalMinutes)}
        </span>
        <ChevronDown className={cn('h-3.5 w-3.5 shrink-0 text-dim transition-transform', open && 'rotate-180')} />
      </button>

      {open && (
        <div className="divide-y divide-line-soft rounded-b-[10px] border border-t-0 border-arc/40 bg-deck px-3 py-1">
          {group.significant.map((recap, i) => (
            <RecapItem key={recap.id} recap={recap} defaultOpen={defaultOpen && i === 0} nested />
          ))}
          {group.quick.length > 0 && (
            <div className={group.significant.length > 0 ? 'pt-1' : undefined}>
              {group.significant.length > 0 && (
                <div className="flex items-center gap-2 py-1.5">
                  <span className="font-display text-[9.5px] font-semibold uppercase tracking-[0.14em] text-dim">Quick passes</span>
                  <span className="h-px flex-1 bg-line-soft" />
                  <span className="font-mono2 text-[10.5px] tabular-nums text-dim">
                    {quickCount} · {formatDuration(group.quick.reduce((sum, g) => sum + g.totalMinutes, 0))}
                  </span>
                </div>
              )}
              {group.quick.map((branchGroup) => (
                <QuickBranchRow key={branchGroup.branch} group={branchGroup} />
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  )
}

/** One branch's worth of quick sessions, collapsed to a single row — expands to the
 *  individual headlines for anyone who wants to audit what actually ran. */
function QuickBranchRow({ group }: { group: QuickBranchGroup }) {
  const [open, setOpen] = useState(false)

  return (
    <div className="border-t border-line-soft first:border-t-0">
      <button
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="flex w-full items-center gap-2.5 rounded-md px-1.5 py-1.5 text-left hover:bg-hover"
      >
        <span className="font-mono2 text-[12px] text-mut">
          <span className="text-dim">⑂ </span>
          {group.branch}
        </span>
        <span className="font-mono2 text-[11px] text-dim">
          {group.recaps.length} pass{group.recaps.length === 1 ? '' : 'es'}
        </span>
        <span className="flex-1" />
        <span className="font-mono2 text-[11px] tabular-nums text-dim">{formatDuration(group.totalMinutes)}</span>
        <ChevronDown className={cn('h-3 w-3 shrink-0 text-dim transition-transform', open && 'rotate-180')} />
      </button>
      {open && (
        <div className="mb-1.5 ml-5 grid gap-0.5">
          {group.recaps.map((recap) => (
            <QuickPassLine key={recap.id} recap={recap} />
          ))}
        </div>
      )}
    </div>
  )
}

function QuickPassLine({ recap }: { recap: SessionRecap }) {
  return (
    <div className="flex items-center gap-2.5 py-0.5 text-[11.5px] text-dim">
      <span className="min-w-0 flex-1 truncate">{recap.headline ?? 'Untitled session'}</span>
      <span className="shrink-0 font-mono2 text-[10.5px] tabular-nums">{formatDuration(recap.duration_minutes)}</span>
    </div>
  )
}
