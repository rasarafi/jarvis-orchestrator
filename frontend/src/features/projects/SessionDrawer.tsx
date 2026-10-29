import { useEffect } from 'react'
import { X } from 'lucide-react'
import type { LiveSession } from '@/types/live-sessions'
import { formatDuration, formatTokens } from '@/lib/dates'
import { CopyResumeButton } from '@/features/deck/CopyResumeButton'
import { cleanSessionSummary } from '@/features/deck/clean-summary'
import { sessionNeedsHuman } from '@/features/deck/needs'

/**
 * Slide-over detail for a live session (phase 2 of the project page).
 * Deliberately a drawer, not a route — promote to /sessions/:id only if
 * this ever feels cramped.
 */
export function SessionDrawer({ session, onClose }: { session: LiveSession | null; onClose: () => void }) {
  useEffect(() => {
    if (!session) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [session, onClose])

  if (!session) return null

  const needs = sessionNeedsHuman(session)
  const outputs = session.last_output_lines?.length
    ? session.last_output_lines
    : session.last_output_line
      ? [session.last_output_line]
      : []

  return (
    <>
      <div className="fixed inset-0 z-40 bg-overlay" onClick={onClose} aria-hidden />
      <div
        role="dialog"
        aria-label="Session detail"
        className="fixed inset-y-0 right-0 z-50 flex w-[min(420px,92vw)] flex-col border-l border-line bg-deck shadow-[var(--drawer-shadow)]"
      >
        <div className="border-b border-line-soft px-[18px] py-4">
          <button
            onClick={onClose}
            aria-label="Close"
            className="float-right flex h-7 w-7 items-center justify-center rounded-md border border-line text-mut hover:border-mut hover:text-ink"
          >
            <X className="h-3.5 w-3.5" />
          </button>
          <div className="flex items-center gap-2">
            <span
              className={`h-2 w-2 flex-none rounded-full ${
                needs ? 'bg-gold shadow-[0_0_0_3px_var(--gold-soft)]' : 'bg-arc shadow-[0_0_0_3px_var(--arc-soft)]'
              }`}
              aria-hidden
            />
            <h3 className="font-display text-[15px] font-semibold text-ink">
              {session.git_branch ?? session.project_name}
            </h3>
          </div>
          <div className="mt-1 font-mono2 text-[10px] text-dim">
            {session.session_id.slice(0, 8)}… {session.model && `· ${session.model}`} · {session.status}
          </div>
        </div>

        <div className="grid grid-cols-3 border-b border-line-soft">
          <DrawerStat value={formatTokens(session.tokens.output)} label="tokens out" />
          <DrawerStat value={`$${session.cost_usd.toFixed(2)}`} label="cost" />
          <DrawerStat value={formatDuration(session.elapsed_seconds)} label="duration" />
        </div>

        <div className="flex-1 overflow-y-auto px-[18px] py-3.5">
          {session.attention?.message && (
            <>
              <DrawerLabel>Needs you</DrawerLabel>
              <div className="mb-2 rounded-lg border border-gold/45 bg-gold-soft px-3 py-2.5 text-[12.5px] leading-relaxed text-gold">
                {session.attention.message}
              </div>
            </>
          )}
          <DrawerLabel>Recent output</DrawerLabel>
          {outputs.length === 0 && <div className="text-[12px] text-dim">No output captured yet.</div>}
          {outputs.map((line, i) => {
            const clean = cleanSessionSummary(line)
            if (!clean) return null
            return (
              <div
                key={i}
                className="mb-2 rounded-lg border border-line-soft bg-deck-2 px-3 py-2.5 text-[12px] leading-relaxed text-mut"
              >
                {clean}
              </div>
            )
          })}
          <DrawerLabel>Location</DrawerLabel>
          <div className="break-all font-mono2 text-[11px] text-dim">{session.cwd}</div>
        </div>

        <div className="flex gap-2.5 border-t border-line-soft px-[18px] py-3">
          <CopyResumeButton
            sessionId={session.session_id}
            className="flex-1 justify-center rounded-lg border border-arc/40 bg-arc-soft py-2 text-arc"
          />
        </div>
      </div>
    </>
  )
}

function DrawerStat({ value, label }: { value: string; label: string }) {
  return (
    <div className="border-r border-line-soft px-[18px] py-2.5 last:border-r-0">
      <div className="font-display text-[15px] font-semibold text-ink">{value}</div>
      <div className="font-mono2 text-[9px] uppercase tracking-[0.08em] text-dim">{label}</div>
    </div>
  )
}

function DrawerLabel({ children }: { children: React.ReactNode }) {
  return (
    <h4 className="mb-1.5 mt-3 font-mono2 text-[10px] uppercase tracking-[0.09em] text-dim first:mt-0">
      {children}
    </h4>
  )
}
