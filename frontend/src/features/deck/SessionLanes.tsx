import type { LiveSession, LiveSessionStatus } from '@/types/live-sessions'
import { formatDuration, formatTokens, secondsSince } from '@/lib/dates'
import { projectColor } from './ui'
import { CopyResumeButton } from './CopyResumeButton'

const statusMeta: Record<LiveSessionStatus, { label: (s: LiveSession) => string; chip: string; gold?: boolean }> = {
  working: {
    label: () => 'working',
    chip: 'text-arc border-arc/35 bg-arc-soft',
  },
  waiting: {
    label: (s) => {
      if (s.attention?.kind === 'permission') return 'permission needed'
      const idle = secondsSince(s.last_activity_at)
      return idle != null ? `needs you · ${formatDuration(idle)}` : 'needs you'
    },
    chip: 'text-gold border-gold/45 bg-gold-soft',
    gold: true,
  },
  stalled: {
    label: () => 'stalled',
    chip: 'text-dim border-line',
  },
}

function Trace({ status }: { status: LiveSessionStatus }) {
  const heights = [0.5, 0.8, 0.4, 0.9, 0.6]
  const color = status === 'waiting' ? 'var(--gold)' : status === 'working' ? 'var(--arc)' : 'var(--dim)'
  const live = status !== 'stalled'
  return (
    <span className="ml-1 inline-flex h-3.5 items-end gap-[2px]" aria-hidden>
      {heights.map((h, i) => (
        <i
          key={i}
          className={`w-[3px] origin-bottom rounded-[1px] ${live ? 'anim-trace' : 'opacity-40'}`}
          style={{
            height: `${3 + h * 11}px`,
            background: color,
            opacity: live ? 0.75 : undefined,
            animationDelay: live ? `${i * -0.18}s` : undefined,
          }}
        />
      ))}
    </span>
  )
}

export function SessionLane({ session }: { session: LiveSession }) {
  // Amber = the human is the next actor: waiting, or flagged while stalled.
  const amber = session.status === 'waiting' || (session.attention != null && session.status === 'stalled')
  const meta = amber && session.status === 'stalled' ? statusMeta.waiting : statusMeta[session.status]
  return (
    <div
      className={`grid grid-cols-[4px_1fr_auto] border-b border-line-soft last:border-b-0 transition-colors hover:bg-hover ${
        amber ? 'bg-gradient-to-r from-gold-soft to-transparent to-55%' : ''
      }`}
    >
      <div className="opacity-80" style={{ background: projectColor(session.project_name) }} />

      <div className="min-w-0 px-4 py-3">
        <div className="flex flex-wrap items-center gap-2.5">
          <span className="text-[13.5px] font-semibold text-ink">{session.project_name}</span>
          {session.git_branch && (
            <span className="font-mono2 text-xs text-mut">{session.git_branch}</span>
          )}
          <span
            className={`inline-flex items-center gap-1.5 rounded-full border px-2 py-px font-mono2 text-[10.5px] tracking-wide ${meta.chip}`}
          >
            <span className={`h-1.5 w-1.5 rounded-full bg-current ${session.status !== 'stalled' ? 'anim-blink' : ''}`} />
            {meta.label(session)}
          </span>
          <Trace status={session.status} />
        </div>
        {session.status !== 'working' && (session.last_output_lines?.length ?? 0) > 1 ? (
          <div className="mt-[7px] space-y-px">
            {session.last_output_lines.slice(-3).map((line, i, arr) => (
              <div
                key={i}
                className={`truncate font-mono2 text-xs ${
                  i === arr.length - 1 ? (amber ? 'text-gold' : 'text-mut') : 'text-dim'
                }`}
              >
                {line}
              </div>
            ))}
          </div>
        ) : (
          <div
            className={`mt-[7px] truncate font-mono2 text-xs ${amber ? 'text-gold' : 'text-mut'}`}
          >
            {session.last_output_line ?? '—'}
          </div>
        )}
      </div>

      <div className="flex flex-col items-end justify-center gap-1 whitespace-nowrap py-3 pr-[18px] font-mono2 text-[11.5px] text-dim">
        <span className="text-mut">{formatDuration(session.elapsed_seconds)}</span>
        <span>
          {formatTokens(session.tokens.output)} out · ${session.cost_usd.toFixed(2)}
        </span>
        <CopyResumeButton sessionId={session.session_id} />
      </div>
    </div>
  )
}
