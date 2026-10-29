import { useEffect, useRef, useState } from 'react'
import { Check, Diamond, X } from 'lucide-react'
import { CopyResumeButton } from '@/features/deck/CopyResumeButton'
import { projectColor } from '@/features/deck/ui'
import type { ProjectOption } from '@/components/ProjectCombobox'
import { matchesProjectOption } from '@/lib/project-groups'
import type { RecapThread, RecapThreadsPayload } from '@/types/api'
import { ageDays, relativeAge } from './recap-format'
import { useDismissStaleThreads, useDismissThread, useResolveThread, useRestoreThreads } from './hooks/use-recap'

const VISIBLE_CAP = 7
const STALE_SOON_DAYS = 10

type Toast = { message: string; ids: string[] } | null

/** "Where you left off" — active threads grouped by project, capped, with
 *  resolve / dismiss / resume per thread, bulk stale cleanup, and undo. */
export function ThreadsBox({ threads, filter }: { threads: RecapThreadsPayload; filter: ProjectOption | null }) {
  const [showAll, setShowAll] = useState(false)
  const [toast, setToast] = useState<Toast>(null)
  const toastTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const resolve = useResolveThread()
  const dismiss = useDismissThread()
  const dismissStale = useDismissStaleThreads()
  const restore = useRestoreThreads()

  useEffect(() => () => clearTimeout(toastTimer.current), [])

  const notify = (message: string, ids: string[]) => {
    clearTimeout(toastTimer.current)
    setToast({ message, ids })
    toastTimer.current = setTimeout(() => setToast(null), 7000)
  }

  const filtered = threads.active.filter((t) => matchesProjectOption(filter, t.project))
  const visible = showAll ? filtered : filtered.slice(0, VISIBLE_CAP)
  const hiddenCount = filtered.length - visible.length

  if (threads.active.length === 0 && threads.stale_count === 0) return null

  // group visible threads by project, groups ordered by their newest thread (list is newest-first)
  const groups: { key: string; name: string | null; threads: RecapThread[] }[] = []
  for (const thread of visible) {
    const key = thread.project?.slug ?? '—'
    const group = groups.find((g) => g.key === key)
    if (group) group.threads.push(thread)
    else groups.push({ key, name: thread.project?.name ?? null, threads: [thread] })
  }

  return (
    <div className="mb-5 rounded-[10px] border border-line border-l-[3px] border-l-gold bg-gold-soft px-4 py-3">
      <div className="flex flex-wrap items-center gap-2">
        <h2 className="flex items-center gap-1.5 font-display text-[11px] font-bold uppercase tracking-[0.16em] text-gold">
          <Diamond className="h-3 w-3 fill-gold" /> Where you left off
        </h2>
        <span className="font-mono2 text-[11px] text-dim">
          {filtered.length} active{threads.stale_count > 0 ? ` · ${threads.stale_count} stale hidden` : ''}
        </span>
        {/* sweep is global — only offer it when the view is unfiltered so the count matches what's on screen */}
        {!filter && (
          <SweepButton threads={threads} onSwept={notify} disabled={dismissStale.isPending} sweep={dismissStale.mutate} />
        )}
      </div>

      {groups.map((group) => (
        <div key={group.key} className="mt-2">
          <div className="mb-0.5 flex items-center gap-1.5 font-display text-[10px] font-semibold uppercase tracking-[0.12em] text-dim">
            <span
              className="h-[7px] w-[7px] rounded-full"
              style={{ background: projectColor(group.name ?? 'unknown') }}
            />
            {group.name ?? 'Unassigned'}
          </div>
          {group.threads.map((thread) => (
            <ThreadLine
              key={thread.id}
              thread={thread}
              onResolve={() => resolve.mutate(thread.id)}
              onDismiss={() => dismiss.mutate(thread.id, { onSuccess: () => notify('Thread dismissed.', [thread.id]) })}
            />
          ))}
        </div>
      ))}

      {(hiddenCount > 0 || showAll) && (
        <button
          onClick={() => setShowAll((v) => !v)}
          className="mt-2 font-mono2 text-[12px] text-gold hover:underline"
        >
          {showAll ? 'Show fewer' : `Show all ${filtered.length} threads →`}
        </button>
      )}

      {toast && (
        <div className="fixed bottom-6 left-1/2 z-40 flex -translate-x-1/2 items-center gap-3.5 rounded-[10px] border border-line bg-deck-2 px-4 py-2.5 text-[13px] text-ink shadow-[var(--pill-shadow)]">
          <span>{toast.message}</span>
          <button
            onClick={() => {
              restore.mutate(toast.ids)
              clearTimeout(toastTimer.current)
              setToast(null)
            }}
            className="font-semibold text-arc hover:underline"
          >
            Undo
          </button>
        </div>
      )}
    </div>
  )
}

const SWEEP_DAYS = 3

/** Bulk cleanup: prefers the stale tail (>14d); when there is none but the box is
 *  overflowing (e.g. after a backfill), offers a coarser sweep of older threads. */
function SweepButton({
  threads,
  sweep,
  onSwept,
  disabled,
}: {
  threads: RecapThreadsPayload
  sweep: (days: number | undefined, opts: { onSuccess: (r: { affected: number; ids: string[] }) => void }) => void
  onSwept: (message: string, ids: string[]) => void
  disabled: boolean
}) {
  const sweepable = threads.active.filter((t) => ageDays(t.created_at) > SWEEP_DAYS).length
  const overflowing = threads.active.length > VISIBLE_CAP * 2

  let label: string
  let days: number | undefined
  if (threads.stale_count > 0) {
    label = `Dismiss all stale (${threads.stale_count})`
    days = undefined
  } else if (overflowing && sweepable > 0) {
    label = `Sweep older than ${SWEEP_DAYS}d (${sweepable})`
    days = SWEEP_DAYS
  } else {
    return null
  }

  return (
    <button
      onClick={() => sweep(days, { onSuccess: (result) => onSwept(`${result.affected} threads dismissed.`, result.ids) })}
      disabled={disabled}
      className="ml-auto rounded-[7px] border border-gold/40 px-2.5 py-1 text-[11px] text-gold hover:bg-gold/15 disabled:opacity-50"
      title={`Bulk-dismiss open threads older than ${days ?? 14} days — undoable`}
    >
      {label}
    </button>
  )
}

function ThreadLine({ thread, onResolve, onDismiss }: { thread: RecapThread; onResolve: () => void; onDismiss: () => void }) {
  const sessionId = thread.source_recap?.cc_session_id
  return (
    <div className="group flex items-baseline gap-2 py-0.5 text-[13px] text-ink">
      <span className="min-w-0 flex-1">{thread.text}</span>
      {ageDays(thread.created_at) > STALE_SOON_DAYS && (
        <span className="rounded border border-line px-1 font-mono2 text-[9.5px] text-dim">stale soon</span>
      )}
      <time className="whitespace-nowrap font-mono2 text-[10.5px] text-dim">{relativeAge(thread.created_at)}</time>
      <span className="flex gap-1 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
        {sessionId && <CopyResumeButton sessionId={sessionId} />}
        <button
          onClick={onResolve}
          className="inline-flex items-center gap-1 rounded-md border border-line px-1.5 py-0.5 font-mono2 text-[10.5px] text-mut hover:border-ok/50 hover:text-ok"
          title="Mark resolved — this got done"
        >
          <Check className="h-3 w-3" /> done
        </button>
        <button
          onClick={onDismiss}
          className="inline-flex items-center gap-1 rounded-md border border-line px-1.5 py-0.5 font-mono2 text-[10.5px] text-mut hover:border-bad/50 hover:text-bad"
          title="Dismiss — hide without resolving (undoable)"
        >
          <X className="h-3 w-3" /> hide
        </button>
      </span>
    </div>
  )
}
