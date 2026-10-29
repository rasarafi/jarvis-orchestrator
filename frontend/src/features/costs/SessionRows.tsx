import { useState } from 'react'
import { Loader2 } from 'lucide-react'
import type { CostAccount, CostRange, CostSession } from '@/types/api'
import { useCostSessions } from './hooks/use-costs'
import { formatMoney, formatRelative, formatTokens } from './format'
import { ModelSummary } from './ModelBar'
import { SortHeader } from './SortHeader'
import { sortRows, type SortColumn, type SortState } from './sorting'

type SessionKey =
  | 'first_prompt'
  | 'git_branch'
  | 'last_activity_at'
  | 'messages'
  | 'tokens'
  | 'cost_usd'
  | 'models'

const COLUMNS: SortColumn<CostSession, SessionKey>[] = [
  { key: 'first_prompt', label: 'Session', type: 'text', get: (r) => r.first_prompt },
  { key: 'git_branch', label: 'Branch', type: 'text', get: (r) => r.git_branch },
  { key: 'last_activity_at', label: 'Last active', type: 'text', get: (r) => r.last_activity_at },
  { key: 'messages', label: 'Messages', type: 'num', get: (r) => r.messages, align: 'right' },
  { key: 'tokens', label: 'Tokens', type: 'num', get: (r) => r.tokens, align: 'right' },
  { key: 'cost_usd', label: 'API equiv.', type: 'num', get: (r) => r.cost_usd, align: 'right' },
  { key: 'models', label: 'Models', type: 'num', get: (r) => r.models[0]?.pct ?? null },
]

const GRID =
  'grid grid-cols-[22px_minmax(180px,2.2fr)_120px_92px_82px_92px_86px_112px] items-center gap-3 px-2'

/** The drilldown, fetched only when its project row is open. Sorts on its own. */
export function SessionRows({
  slug,
  range,
  account,
}: {
  slug: string
  range: CostRange
  account: CostAccount | null
}) {
  const { data, isLoading, error } = useCostSessions(slug, range, account)
  const [sort, setSort] = useState<SortState<SessionKey>>({ key: 'tokens', dir: 'desc' })
  const [open, setOpen] = useState<Set<string>>(new Set())

  if (isLoading) {
    return (
      <div className="flex items-center gap-2 px-10 py-5 text-[12.5px] text-dim">
        <Loader2 className="h-3.5 w-3.5 animate-spin" /> Loading sessions…
      </div>
    )
  }

  if (error) {
    return <div className="px-10 py-5 text-[12.5px] text-bad">Could not load sessions for {slug}.</div>
  }

  if (!data || data.sessions.length === 0) {
    return <div className="px-10 py-5 text-[12.5px] text-dim">No sessions in this range.</div>
  }

  const rows = sortRows(data.sessions, COLUMNS, sort)
  const toggle = (id: string) =>
    setOpen((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })

  return (
    <div className="border-t border-line-soft bg-deck-2 py-1 pl-10 pr-4">
      <div className={`${GRID} h-[30px]`}>
        <span aria-hidden="true" />
        {COLUMNS.map((column) => (
          <SortHeader key={column.key} column={column} state={sort} onSort={setSort} small />
        ))}
      </div>

      {rows.map((session) => {
        const tokens = formatTokens(session.tokens)
        const hasAgents = session.subagent_count > 0
        const isOpen = open.has(session.session_id)

        // Only sessions that actually spawned agents are expandable; the rest
        // stay plain rows rather than offering a control that opens nothing.
        const Row = hasAgents ? 'button' : 'div'

        return (
          <div key={session.session_id} className="border-t border-line-soft">
            <Row
              {...(hasAgents
                ? {
                    type: 'button' as const,
                    'aria-expanded': isOpen,
                    onClick: () => toggle(session.session_id),
                  }
                : {})}
              className={`${GRID} min-h-[44px] w-full text-left ${hasAgents ? 'hover:bg-hover' : ''}`}
            >
              <span
                aria-hidden="true"
                className={`text-[9px] text-dim transition-transform motion-reduce:transition-none ${
                  isOpen ? 'rotate-90' : ''
                }`}
              >
                {hasAgents ? '▶' : ''}
              </span>

              <span className="line-clamp-2 text-[12.5px] text-ink">
                {session.first_prompt}
                {session.live && (
                  <span className="ml-1.5 rounded-full bg-ok/15 px-1.5 text-[9.5px] font-semibold uppercase tracking-wide text-ok">
                    live
                  </span>
                )}
                {hasAgents && (
                  <span className="ml-1.5 rounded-full border border-line px-1.5 font-mono2 text-[9.5px] text-mut">
                    +{session.subagent_count} agent{session.subagent_count === 1 ? '' : 's'}
                  </span>
                )}
              </span>

              <span className="truncate font-mono2 text-[11px] text-mut">
                {session.git_branch ?? '—'}
              </span>
              <span className="text-[11.5px] text-mut">{formatRelative(session.last_activity_at)}</span>
              <span className="text-right font-mono2 text-[12px] tabular-nums">
                {session.messages.toLocaleString()}
              </span>

              <span className="flex flex-col items-end">
                <span className="font-mono2 text-[12.5px] font-medium tabular-nums">
                  {tokens.value}
                  <span className="ml-px text-[10px] text-mut">{tokens.unit}</span>
                </span>
                {hasAgents && (
                  <span className="font-mono2 text-[10px] text-dim">
                    {Math.round((session.subagent_tokens / Math.max(session.tokens, 1)) * 100)}% agents
                  </span>
                )}
              </span>

              <span className="text-right font-mono2 text-[12.5px] tabular-nums text-mut">
                {formatMoney(session.cost_usd)}
              </span>

              <ModelSummary models={session.models} max={1} />
            </Row>

            {hasAgents && isOpen && <SubagentRows session={session} />}
          </div>
        )
      })}

      <div className="mt-0.5 flex flex-wrap items-center justify-between gap-3 border-t border-line-soft px-2 pt-2.5 text-[12px] text-mut">
        <span>
          Showing the {data.sessions.length} heaviest of{' '}
          <span className="font-mono2 tabular-nums">{data.total_sessions}</span> sessions in this range.
        </span>
        <span className="text-dim">Session totals include their subagents.</span>
      </div>
    </div>
  )
}

/** The agents one session spawned. Capped server-side — the row carries the true count. */
function SubagentRows({ session }: { session: CostSession }) {
  const hidden = session.subagent_count - session.subagents.length

  return (
    <div className="border-t border-line-soft bg-deck py-1 pl-7 pr-2">
      {session.subagents.map((agent) => {
        const tokens = formatTokens(agent.tokens)
        return (
          <div
            key={agent.session_id}
            className="grid min-h-[36px] grid-cols-[minmax(180px,3fr)_82px_92px_86px] items-center gap-3 border-b border-line-soft px-2 last:border-b-0"
          >
            <span className="line-clamp-1 text-[11.5px] text-mut">
              <span className="mr-1.5 font-mono2 text-[9.5px] uppercase tracking-wider text-dim">
                agent
              </span>
              {agent.first_prompt}
            </span>
            <span className="text-right font-mono2 text-[11px] tabular-nums text-mut">
              {agent.messages.toLocaleString()}
            </span>
            <span className="text-right font-mono2 text-[11.5px] tabular-nums">
              {tokens.value}
              <span className="ml-px text-[9.5px] text-mut">{tokens.unit}</span>
            </span>
            <span className="text-right font-mono2 text-[11.5px] tabular-nums text-mut">
              {formatMoney(agent.cost_usd)}
            </span>
          </div>
        )
      })}

      {hidden > 0 && (
        <p className="px-2 py-1.5 text-[11px] text-dim">
          + {hidden} lighter agent{hidden === 1 ? '' : 's'} not shown.
        </p>
      )}
    </div>
  )
}
