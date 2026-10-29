import { useMemo } from 'react'
import { useCostSummary, useHealth, useSessionBreakdown } from './hooks/use-analytics'
import { Loader2, AlertCircle, AlertTriangle } from 'lucide-react'
import { formatTokens } from '@/lib/dates'
import { Card, CardHeader } from '@/features/deck/ui'
import { SystemMonitor } from '@/features/deck/SystemMonitor'
import { QuotaPanel } from '@/features/deck/QuotaPanel'
import { GROUP_LABEL, GROUP_ORDER } from '@/lib/project-groups'
import type { WeeklyProjectCost } from '@/types/api'

interface WeeklyRow extends WeeklyProjectCost {
  /** Group subtotal header row (rollup of the indented member rows below it). */
  isGroupHeader?: boolean
  /** Indent under a group header. */
  grouped?: boolean
}

/** Order rows by group with subtotal headers; single-project groups stay plain. */
function rollupByGroup(rows: WeeklyProjectCost[]): WeeklyRow[] {
  const byCost = (a: WeeklyRow, b: WeeklyRow) => Number(b.cost) - Number(a.cost)
  const out: WeeklyRow[] = []
  for (const key of GROUP_ORDER) {
    const members: WeeklyRow[] = rows
      .filter((r) => r.group_key === key)
      .map((r) => ({ ...r, grouped: true }))
      .sort(byCost)
    if (members.length === 0) continue
    if (members.length > 1) {
      out.push({
        display_name: GROUP_LABEL[key],
        group_key: key,
        isGroupHeader: true,
        cost: members.reduce((s, m) => s + Number(m.cost), 0),
        sessions: members.reduce((s, m) => s + m.sessions, 0),
        total_tokens: members.reduce((s, m) => s + Number(m.total_tokens), 0),
      })
    } else {
      members[0].grouped = false
    }
    out.push(...members)
  }
  out.push(...rows.filter((r) => !r.group_key).map((r) => ({ ...r })).sort(byCost))
  return out
}

export function AnalyticsDashboard() {
  const { data: costs, isLoading, error } = useCostSummary()
  const { data: health } = useHealth()
  const { data: weekly } = useSessionBreakdown()
  const weeklyRows = useMemo(() => rollupByGroup(weekly ?? []), [weekly])

  if (isLoading) {
    return (
      <div className="flex h-48 items-center justify-center text-dim">
        <Loader2 className="mr-2 h-5 w-5 animate-spin" /> Loading analytics…
      </div>
    )
  }

  if (error) {
    return (
      <div className="flex h-48 items-center justify-center text-bad">
        <AlertCircle className="mr-2 h-5 w-5" /> Failed to load analytics.
      </div>
    )
  }

  const noIndex = (costs?.total_sessions ?? 0) === 0
  const insights = Array.isArray(health?.insights) ? health.insights : []

  return (
    <div className="flex flex-col gap-[18px]">
      {/* Relocated from the deck (project-board redesign): full vitals + quota live here now. */}
      <SystemMonitor />
      <QuotaPanel />

      {health && health.warnings.length > 0 && (
        <div className="space-y-2">
          {health.warnings.map((warning, i) => (
            <div
              key={i}
              className="flex items-start gap-2 rounded-lg border border-gold/25 bg-gold-soft px-3 py-2"
            >
              <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-gold" />
              <span className="text-xs text-gold">{warning}</span>
            </div>
          ))}
        </div>
      )}

      <Card>
        <CardHeader title="Session costs" count={noIndex ? undefined : `${costs?.total_sessions} sessions indexed`} />
        {noIndex ? (
          <div className="px-4 py-6 text-[13px] text-dim">
            No session data indexed yet — the 2-hour scan schedule will populate this, or ask
            Jarvis to “scan sessions now”.
          </div>
        ) : (
          <div className="grid grid-cols-3">
            <Stat value={`$${Number(costs?.today_cost ?? 0).toFixed(2)}`} label="today" border />
            <Stat value={`$${Number(costs?.week_cost ?? 0).toFixed(2)}`} label="this week" border />
            <Stat value={`$${Number(costs?.total_cost ?? 0).toFixed(2)}`} label="all time" />
          </div>
        )}
      </Card>

      {weeklyRows.length > 0 && (
        <Card>
          <CardHeader title="This week by project" />
          {weeklyRows.map((row) => (
            <div
              key={row.isGroupHeader ? `group:${row.group_key}` : row.display_name}
              className={`flex items-center gap-3 border-b border-line-soft py-2.5 pr-4 text-[13px] last:border-b-0 ${
                row.grouped ? 'pl-7' : 'pl-4'
              } ${row.isGroupHeader ? 'bg-deck-2/40' : ''}`}
            >
              <span
                className={`min-w-0 flex-1 truncate ${
                  row.isGroupHeader ? 'font-semibold text-mut' : 'text-ink'
                }`}
              >
                {row.display_name}
              </span>
              <span className="font-mono2 text-xs text-dim">
                {row.sessions} sess · {formatTokens(Number(row.total_tokens))} tk
              </span>
              <span
                className={`w-20 text-right font-mono2 text-[13px] ${
                  row.isGroupHeader ? 'font-semibold text-mut' : 'text-ink'
                }`}
              >
                ${Number(row.cost).toFixed(2)}
              </span>
            </div>
          ))}
        </Card>
      )}

      {insights.length > 0 && (
        <Card>
          <CardHeader title="Insights" />
          <ul className="space-y-1.5 px-4 py-3">
            {insights.map((line, i) => (
              <li key={i} className="text-[12.5px] text-mut">
                {String(line)}
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}

function Stat({ value, label, border }: { value: string; label: string; border?: boolean }) {
  return (
    <div className={`px-4 py-3.5 ${border ? 'border-r border-line-soft' : ''}`}>
      <div className="font-mono2 text-[17px] font-medium text-ink">{value}</div>
      <div className="mt-0.5 text-[10.5px] uppercase tracking-[0.08em] text-dim">{label}</div>
    </div>
  )
}
