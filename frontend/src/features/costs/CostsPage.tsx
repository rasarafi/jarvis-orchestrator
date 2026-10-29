import { useSearchParams } from 'react-router-dom'
import { AlertCircle, Loader2 } from 'lucide-react'
import { Card } from '@/features/deck/ui'
import { GROUP_LABEL, GROUP_ORDER, type ProjectGroupKey } from '@/lib/project-groups'
import type { CostAccount, CostRange } from '@/types/api'
import { useCostProjects, useCostWindow } from './hooks/use-costs'
import { useQuotaLatest, useQuotaRefresh } from './hooks/use-quota'
import { WindowStrip } from './WindowStrip'
import { QuotaStrip } from './QuotaStrip'
import { ProjectTable } from './ProjectTable'
import { UsageBehaviorPanel } from './UsageBehaviorPanel'

const RANGES: { key: CostRange; label: string }[] = [
  { key: 'live', label: 'Live' },
  { key: '24h', label: '24 hours' },
  { key: '7d', label: '7 days' },
  { key: '30d', label: '30 days' },
]

const isRange = (v: string | null): v is CostRange =>
  v !== null && RANGES.some((r) => r.key === v)

const isGroup = (v: string | null): v is ProjectGroupKey =>
  v !== null && (GROUP_ORDER as string[]).includes(v)

const ACCOUNT_KEYS: CostAccount[] = ['cl', 'cl2', 'unattributed']

const ACCOUNT_LABEL: Record<CostAccount, string> = {
  cl: 'cl',
  cl2: 'cl2',
  unattributed: 'Unattributed',
}

const isAccount = (v: string | null): v is CostAccount =>
  v !== null && (ACCOUNT_KEYS as string[]).includes(v)

export function CostsPage() {
  const [params, setParams] = useSearchParams()
  const range: CostRange = isRange(params.get('range')) ? (params.get('range') as CostRange) : '7d'
  const group = isGroup(params.get('group')) ? (params.get('group') as ProjectGroupKey) : null
  const account = isAccount(params.get('account')) ? (params.get('account') as CostAccount) : null

  const windowQuery = useCostWindow(account)
  const projectsQuery = useCostProjects(range, group, account)
  const quotaQuery = useQuotaLatest()
  const quotaRefresh = useQuotaRefresh()

  // Only offer the filter once there is something to filter — with one bucket
  // it is a control that can only ever restate what is already on screen.
  const accounts = windowQuery.data?.accounts ?? []
  const showAccounts = accounts.length > 1

  const setParam = (key: string, value: string | null) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    setParams(next, { replace: true })
  }

  return (
    <div className="flex flex-col gap-[18px]">
      <header className="flex flex-wrap items-baseline justify-between gap-3">
        <div>
          <h1 className="font-display text-[22px] font-bold tracking-tight">Quota &amp; Cost</h1>
          <p className="mt-0.5 text-[13px] text-mut">
            Where the 5-hour window is going, by project and session.
          </p>
        </div>
        {windowQuery.data?.as_of && (
          <span className="text-[12px] text-dim">
            Local estimate · indexed{' '}
            <span className="font-mono2">
              {new Date(windowQuery.data.as_of).toLocaleTimeString(undefined, {
                hour: '2-digit',
                minute: '2-digit',
              })}
            </span>
          </span>
        )}
      </header>

      {showAccounts && (
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-[11.5px] uppercase tracking-[0.14em] text-dim">Account</span>
          <div className="flex flex-wrap gap-1.5">
            <FilterChip active={account === null} onClick={() => setParam('account', null)}>
              Both
            </FilterChip>
            {accounts.map((a) => (
              <FilterChip
                key={a.key}
                active={account === a.key}
                onClick={() => setParam('account', a.key)}
              >
                {ACCOUNT_LABEL[a.key] ?? a.key}
                <span className="ml-1 font-mono2 text-[10.5px] text-dim">{a.sessions}</span>
              </FilterChip>
            ))}
          </div>
          {account !== null && (
            <span className="text-[11.5px] text-dim">
              The 5-hour limit is per account — this window is {ACCOUNT_LABEL[account]} only.
            </span>
          )}
        </div>
      )}

      {/* Authoritative first, estimate second — they are different kinds of
          number and the order says which one to trust. */}
      <QuotaStrip
        data={quotaQuery.data}
        onRefresh={() => quotaRefresh.mutate()}
        isRefreshing={quotaRefresh.isPending}
      />

      {windowQuery.isLoading && <Skeleton label="Loading window…" />}
      {windowQuery.data && <WindowStrip data={windowQuery.data} />}

      <UsageBehaviorPanel range={range} account={account} />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="inline-flex gap-0.5 rounded-lg border border-line-soft bg-deck-2 p-[3px]">
          {RANGES.map((r) => (
            <button
              key={r.key}
              type="button"
              aria-pressed={range === r.key}
              onClick={() => setParam('range', r.key === '7d' ? null : r.key)}
              className={`rounded-md px-3 py-1 text-[13px] font-medium transition-colors ${
                range === r.key ? 'bg-deck text-ink shadow-[var(--card-shadow)]' : 'text-mut hover:text-ink'
              }`}
            >
              {r.label}
            </button>
          ))}
        </div>

        <div className="flex flex-wrap gap-1.5">
          <FilterChip active={group === null} onClick={() => setParam('group', null)}>
            All
          </FilterChip>
          {GROUP_ORDER.map((key) => (
            <FilterChip key={key} active={group === key} onClick={() => setParam('group', key)}>
              {GROUP_LABEL[key]}
            </FilterChip>
          ))}
        </div>
      </div>

      {projectsQuery.isLoading && <Skeleton label="Loading projects…" />}

      {projectsQuery.error && (
        <Card>
          <div className="flex flex-col items-center gap-3 px-4 py-8 text-[13px] text-bad">
            <span className="flex items-center gap-2">
              <AlertCircle className="h-4 w-4" /> Failed to load cost breakdown.
            </span>
            <button
              type="button"
              onClick={() => projectsQuery.refetch()}
              className="rounded-md border border-line bg-deck-2 px-3 py-1.5 text-[12.5px] text-mut hover:text-ink"
            >
              Retry
            </button>
          </div>
        </Card>
      )}

      {projectsQuery.data && (
        <ProjectTable projects={projectsQuery.data.projects} range={range} account={account} />
      )}

      <p className="max-w-[68ch] text-[11.5px] leading-relaxed text-dim">
        <strong className="text-mut">Quota percentages come from Anthropic, not from this page.</strong>{' '}
        The 5-hour limit is enforced server-side and is not a function of the token counts stored
        locally, so it is fetched on demand rather than estimated — press Fetch for a current
        reading. Everything below that card is a local estimate. What is trustworthy there: token counts, burn rate, the reset countdown, and how
        this window compares to your own median. Share is each project's portion of tokens in the
        selected range; on <strong className="text-mut">Live</strong> that is the open window. Dollars
        are API-equivalent, not billed. Figures refresh on the session-scan schedule. Subagent
        spend is folded into the session that spawned it — expand a session to see the agents.
        Sessions started before account capture existed read as{' '}
        <strong className="text-mut">unattributed</strong>; they cannot be re-attributed after
        the fact.
      </p>
    </div>
  )
}

function Skeleton({ label }: { label: string }) {
  return (
    <div className="flex h-32 items-center justify-center text-dim">
      <Loader2 className="mr-2 h-5 w-5 animate-spin" /> {label}
    </div>
  )
}

function FilterChip({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      aria-pressed={active}
      onClick={onClick}
      className={`rounded-full border px-2.5 py-0.5 text-[12px] transition-colors ${
        active ? 'border-line bg-hover text-ink' : 'border-line-soft text-mut hover:text-ink'
      }`}
    >
      {children}
    </button>
  )
}
