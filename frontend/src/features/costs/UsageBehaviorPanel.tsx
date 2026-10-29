import { useState } from 'react'
import { AlertCircle, Loader2 } from 'lucide-react'
import { Card } from '@/features/deck/ui'
import type {
  CostAccount,
  CostRange,
  McpServerShare,
  UsageBehaviorRange,
  UsageBehaviors,
  UsageCategoryTokenShare,
} from '@/types/api'
import { formatTokens } from './format'
import { useUsageBehaviors } from './hooks/use-costs'

const BEHAVIOR_RANGES: { key: UsageBehaviorRange; label: string }[] = [
  { key: '24h', label: '24 hours' },
  { key: '7d', label: '7 days' },
]

/** This panel's own range only offers 24h/7d — clamp the page's wider range down to it. */
function clampRange(range: CostRange): UsageBehaviorRange {
  return range === '24h' ? '24h' : '7d'
}

/**
 * The CLI's own `/usage` shows "what's contributing to your limits usage"
 * (cache-miss %, long-context %, subagent-heavy %, parallel-session %,
 * cron-session %, plus category shares) computed client-side from one
 * machine's local sessions. This is the same idea over the full indexed
 * corpus — cross-project, historical, every account.
 */
export function UsageBehaviorPanel({
  range: pageRange,
  account,
}: {
  range: CostRange
  account: CostAccount | null
}) {
  const [rangeOverride, setRangeOverride] = useState<UsageBehaviorRange | null>(null)
  const clamped = clampRange(pageRange)
  const range = rangeOverride ?? clamped
  const wasClamped = rangeOverride === null && pageRange !== range

  const query = useUsageBehaviors(range, account)

  return (
    <Card>
      <div className="flex flex-col gap-4 px-[18px] py-4">
        <div className="flex flex-wrap items-baseline justify-between gap-3">
          <div className="flex flex-wrap items-baseline gap-2.5">
            <h2 className="font-display text-[11.5px] font-semibold uppercase tracking-[0.2em] text-mut">
              Usage behaviors
            </h2>
            {wasClamped && (
              <span className="text-[11px] text-dim">
                showing {range === '24h' ? '24 hours' : '7 days'} — this breakdown doesn&apos;t offer{' '}
                {pageRange}
              </span>
            )}
          </div>
          <div className="inline-flex gap-0.5 rounded-lg border border-line-soft bg-deck-2 p-[3px]">
            {BEHAVIOR_RANGES.map((r) => (
              <button
                key={r.key}
                type="button"
                aria-pressed={range === r.key}
                onClick={() => setRangeOverride(r.key)}
                className={`rounded-md px-2.5 py-1 text-[12px] font-medium transition-colors ${
                  range === r.key ? 'bg-deck text-ink shadow-[var(--card-shadow)]' : 'text-mut hover:text-ink'
                }`}
              >
                {r.label}
              </button>
            ))}
          </div>
        </div>

        {query.isLoading && (
          <div className="flex h-40 items-center justify-center text-dim">
            <Loader2 className="mr-2 h-5 w-5 animate-spin" /> Loading behaviors…
          </div>
        )}

        {query.error && (
          <div className="flex flex-col items-center gap-3 px-4 py-8 text-[13px] text-bad">
            <span className="flex items-center gap-2">
              <AlertCircle className="h-4 w-4" /> Failed to load usage behaviors.
            </span>
            <button
              type="button"
              onClick={() => query.refetch()}
              className="rounded-md border border-line bg-deck-2 px-3 py-1.5 text-[12.5px] text-mut hover:text-ink"
            >
              Retry
            </button>
          </div>
        )}

        {query.data && <Behaviors data={query.data} />}

        <p className="text-[11px] leading-relaxed text-dim">
          Local corpus, cross-project — not the same scope as the CLI&apos;s own{' '}
          <code className="text-mut">/usage</code> tab (one machine, 24h). Skill share = tokens in
          turns after invocation, not the invoking call itself. MCP server share is a share of{' '}
          <strong className="text-mut">calls</strong>, not tokens.
        </p>
      </div>
    </Card>
  )
}

function Behaviors({ data }: { data: UsageBehaviors }) {
  const noActivity = data.cache_miss.total_tokens === 0

  if (noActivity) {
    return (
      <div className="flex flex-col items-center gap-1 px-4 py-7 text-center">
        <p className="text-[13px] text-mut">No activity in this range.</p>
        <p className="text-[11.5px] text-dim">Try a wider range or a different account.</p>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-5">
      <div className="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-3">
        <Meter label="Cache miss" pct={data.cache_miss.pct} tokens={data.cache_miss.tokens} />
        <Meter label="Long context" pct={data.long_context.pct} tokens={data.long_context.tokens} />
        <Meter label="Subagent-heavy" pct={data.subagent_heavy.pct} tokens={data.subagent_heavy.tokens} />
        <Meter label="High parallel" pct={data.high_parallel.pct} tokens={data.high_parallel.tokens} />
        {data.cron.available ? (
          <Meter label="Headless / cron" pct={data.cron.pct} tokens={data.cron.tokens} />
        ) : (
          <Meter label="Headless / cron" unavailable reason={data.cron.reason} />
        )}
      </div>

      <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-4 border-t border-line-soft pt-4">
        <TokenShareList title="Subagent type" items={data.subagent_types.items} />
        <TokenShareList title="Skills" items={data.skills.items} />
        <McpShareList title="MCP servers" items={data.mcp_servers.items} totalCalls={data.mcp_servers.total_calls} />
      </div>
    </div>
  )
}

function Meter({
  label,
  pct,
  tokens,
  unavailable = false,
  reason,
}: {
  label: string
  pct?: number
  tokens?: number
  unavailable?: boolean
  reason?: string
}) {
  if (unavailable) {
    return (
      <div className="flex flex-col gap-1.5 rounded-md border border-line-soft bg-deck-2 px-3 py-2.5">
        <span className="text-[11px] text-mut">{label}</span>
        <span className="text-[13px] text-dim">unavailable</span>
        {reason && <span className="text-[10.5px] leading-snug text-dim">{reason}</span>}
      </div>
    )
  }

  const t = formatTokens(tokens ?? 0)

  return (
    <div className="flex flex-col gap-1.5 rounded-md border border-line-soft bg-deck-2 px-3 py-2.5">
      <span className="text-[11px] text-mut">{label}</span>
      <span className="font-mono2 text-[17px] font-semibold tracking-tight">{pct ?? 0}%</span>
      <div className="h-1 overflow-hidden rounded-full bg-line-soft">
        <div className="h-full rounded-full bg-arc" style={{ width: `${Math.min(100, pct ?? 0)}%` }} />
      </div>
      <span className="text-[10.5px] text-dim">
        {t.value}
        {t.unit} tokens
      </span>
    </div>
  )
}

const MAX_ROWS = 5

function TokenShareList({ title, items }: { title: string; items: UsageCategoryTokenShare[] }) {
  if (items.length === 0) {
    return (
      <div className="flex flex-col gap-1.5">
        <span className="text-[11px] uppercase tracking-[0.14em] text-dim">{title}</span>
        <span className="text-[12px] text-dim">none in this range</span>
      </div>
    )
  }

  const shown = items.slice(0, MAX_ROWS)
  const rest = items.length - shown.length

  return (
    <div className="flex flex-col gap-1.5">
      <span className="text-[11px] uppercase tracking-[0.14em] text-dim">{title}</span>
      <div className="flex flex-col gap-1">
        {shown.map((item) => {
          const t = formatTokens(item.tokens)
          return (
            <div key={item.label} className="flex items-center gap-2">
              <span className="min-w-0 flex-1 truncate text-[12px] text-mut" title={item.label}>
                {item.label}
                {item.is_plugin && (
                  <span className="ml-1.5 rounded-full border border-line-soft px-1.5 py-px text-[9.5px] uppercase tracking-wide text-dim">
                    plugin
                  </span>
                )}
              </span>
              <span className="shrink-0 font-mono2 text-[11.5px] text-dim">
                {t.value}
                {t.unit}
              </span>
              <span className="w-9 shrink-0 text-right font-mono2 text-[11.5px] text-ink">{item.pct}%</span>
            </div>
          )
        })}
      </div>
      {rest > 0 && <span className="text-[10.5px] text-dim">+{rest} more</span>}
    </div>
  )
}

function McpShareList({
  title,
  items,
  totalCalls,
}: {
  title: string
  items: McpServerShare[]
  totalCalls: number
}) {
  if (items.length === 0) {
    return (
      <div className="flex flex-col gap-1.5">
        <span className="text-[11px] uppercase tracking-[0.14em] text-dim">{title}</span>
        <span className="text-[12px] text-dim">none in this range</span>
      </div>
    )
  }

  const shown = items.slice(0, MAX_ROWS)
  const rest = items.length - shown.length

  return (
    <div className="flex flex-col gap-1.5">
      <span className="text-[11px] uppercase tracking-[0.14em] text-dim">
        {title} <span className="text-dim">· {totalCalls} calls</span>
      </span>
      <div className="flex flex-col gap-1">
        {shown.map((item) => (
          <div key={item.label} className="flex items-center gap-2">
            <span className="min-w-0 flex-1 truncate text-[12px] text-mut" title={item.label}>
              {item.label}
            </span>
            <span className="shrink-0 font-mono2 text-[11.5px] text-dim">{item.calls} calls</span>
            <span className="w-9 shrink-0 text-right font-mono2 text-[11.5px] text-ink">{item.pct}%</span>
          </div>
        ))}
      </div>
      {rest > 0 && <span className="text-[10.5px] text-dim">+{rest} more</span>}
    </div>
  )
}
