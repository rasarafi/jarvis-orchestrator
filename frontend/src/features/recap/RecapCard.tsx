import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronDown, Diamond } from 'lucide-react'
import { CopyResumeButton } from '@/features/deck/CopyResumeButton'
import { projectColor } from '@/features/deck/ui'
import { cn } from '@/lib/utils'
import type { SessionRecap } from '@/types/api'
import { formatDuration, formatEndTime } from './recap-format'

function hue(recap: SessionRecap): string {
  return recap.project?.color ?? projectColor(recap.project?.name ?? 'unknown')
}

/** One session in the stream: a scannable collapsed row that expands to the full
 *  recap body. Standalone rows are cards with a project chip + first-bullet preview;
 *  nested rows (inside a ProjectGroupCard) are flat list rows — the group card owns
 *  the chrome, the color, and the gist, so the row is just headline + time.
 *  Open threads are NOT repeated here — the ThreadsBox owns them. */
export function RecapItem({
  recap,
  defaultOpen = false,
  nested = false,
}: {
  recap: SessionRecap
  defaultOpen?: boolean
  nested?: boolean
}) {
  const [open, setOpen] = useState(defaultOpen)
  const navigate = useNavigate()
  const tasksClosed = recap.facts.tasks_closed ?? 0
  const projectSlug = recap.project?.slug

  const meta = (
    <span className="shrink-0 whitespace-nowrap font-mono2 text-[11px] tabular-nums text-dim">
      {tasksClosed > 0 && <span className="text-ok">✓{tasksClosed} · </span>}
      {recap.ended_at ? `${formatEndTime(recap.ended_at)} · ` : ''}
      {formatDuration(recap.duration_minutes)}
    </span>
  )
  const chevron = (
    <ChevronDown className={cn('h-3.5 w-3.5 shrink-0 text-dim transition-transform', open && 'rotate-180')} />
  )

  if (nested) {
    return (
      <div>
        <button
          onClick={() => setOpen((v) => !v)}
          aria-expanded={open}
          className="flex w-full items-center gap-2.5 rounded-md px-1.5 py-2 text-left hover:bg-hover"
        >
          <b className="min-w-0 flex-1 truncate text-[13px] font-semibold text-ink">
            {recap.headline ?? 'Untitled session'}
          </b>
          {meta}
          {chevron}
        </button>
        {open && (
          <div className="mb-2 ml-2 border-l-2 border-line-soft pb-0.5 pl-3.5">
            <RecapBody recap={recap} />
          </div>
        )}
      </div>
    )
  }

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
        <span className="w-[3px] self-stretch shrink-0" style={{ background: hue(recap) }} />
        <span
          className={cn(
            'shrink-0 font-display text-[10px] font-semibold uppercase tracking-[0.1em]',
            projectSlug && 'cursor-pointer hover:underline',
          )}
          style={{ color: hue(recap) }}
          role={projectSlug ? 'link' : undefined}
          tabIndex={projectSlug ? 0 : undefined}
          onClick={
            projectSlug
              ? (e) => {
                  e.stopPropagation()
                  navigate(`/projects/${projectSlug}`)
                }
              : undefined
          }
          onKeyDown={
            projectSlug
              ? (e) => {
                  if (e.key === 'Enter') {
                    e.stopPropagation()
                    navigate(`/projects/${projectSlug}`)
                  }
                }
              : undefined
          }
        >
          {recap.project?.name ?? 'Unassigned'}
        </span>
        <b className="shrink-0 whitespace-nowrap text-[13px] font-semibold text-ink">
          {recap.headline ?? 'Untitled session'}
        </b>
        <span className="min-w-0 flex-1 truncate text-[12.5px] text-mut">{recap.bullets[0] ?? ''}</span>
        {meta}
        {chevron}
      </button>

      {open && (
        <div className="rounded-b-[10px] border border-t-0 border-arc/40 bg-deck px-4 pb-3 pt-1.5">
          <RecapBody recap={recap} />
        </div>
      )}
    </div>
  )
}

function RecapBody({ recap }: { recap: SessionRecap }) {
  return (
    <>
      {(recap.bullets.length > 0 || recap.next_action) && (
        <ul className="mt-1 grid gap-1">
          {recap.bullets.map((bullet, i) => (
            <li
              key={i}
              className="relative pl-3.5 text-[13px] text-mut before:absolute before:left-0 before:top-[5px] before:h-1.5 before:w-1.5 before:bg-dim before:content-['']"
            >
              {bullet}
            </li>
          ))}
          {recap.next_action && (
            <li className="flex items-start gap-1.5 text-[13px] text-ink">
              <Diamond className="mt-[3px] h-3 w-3 shrink-0 fill-gold text-gold" />
              <span>
                <b className="font-semibold text-gold">Next:</b> {recap.next_action}
              </span>
            </li>
          )}
        </ul>
      )}

      {recap.decisions.length > 0 && (
        <div className="mt-2 rounded-md bg-deck-2 px-2.5 py-1.5 text-[12px] text-mut">
          <span className="font-display text-[9.5px] font-semibold uppercase tracking-[0.14em] text-dim">
            Decisions{' '}
          </span>
          {recap.decisions.join(' · ')}
        </div>
      )}

      <footer className="mt-2.5 flex flex-wrap items-center gap-2">
        <span className="rounded-full border border-line-soft bg-deck-2 px-2 py-px font-mono2 text-[11px] tabular-nums text-mut">
          {recap.facts.messages ?? 0} msgs
        </span>
        {recap.model && (
          <span className="rounded-full bg-arc-soft px-2 py-px font-display text-[9.5px] font-semibold uppercase tracking-[0.12em] text-arc">
            {recap.model}
          </span>
        )}
        <div className="ml-auto">
          <CopyResumeButton sessionId={recap.cc_session_id} />
        </div>
      </footer>
    </>
  )
}
