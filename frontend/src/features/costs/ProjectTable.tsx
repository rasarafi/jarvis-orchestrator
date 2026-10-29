import { useState } from 'react'
import { Card } from '@/features/deck/ui'
import type { CostAccount, CostProject, CostRange } from '@/types/api'
import { formatMoney, formatRelative, formatTokens } from './format'
import { ModelSummary } from './ModelBar'
import { SessionRows } from './SessionRows'
import { SortHeader } from './SortHeader'
import { sortRows, type SortColumn, type SortState } from './sorting'

type ProjectKey = 'slug' | 'sessions' | 'tokens' | 'cost_usd' | 'trend_pct' | 'models'

/**
 * "Share of range" and "Tokens" are the same quantity drawn two ways, so both
 * headers drive one sort key rather than pretending to be independent.
 */
const COLUMNS: SortColumn<CostProject, ProjectKey>[] = [
  { key: 'slug', label: 'Project', type: 'text', get: (r) => r.slug },
  { key: 'sessions', label: 'Sessions', type: 'num', get: (r) => r.sessions, align: 'right' },
  { key: 'tokens', label: 'Share of range', type: 'num', get: (r) => r.tokens },
  { key: 'tokens', label: 'Tokens', type: 'num', get: (r) => r.tokens, align: 'right' },
  { key: 'cost_usd', label: 'API equiv.', type: 'num', get: (r) => r.cost_usd, align: 'right' },
  // Sorted by the share of the heaviest model, which is what the eye compares.
  { key: 'models', label: 'Models', type: 'num', get: (r) => r.models[0]?.pct ?? null },
  { key: 'trend_pct', label: 'Trend', type: 'num', get: (r) => r.trend_pct, align: 'right' },
]

const GRID =
  'grid grid-cols-[22px_minmax(160px,1.5fr)_84px_1fr_104px_92px_118px_74px] items-center gap-3 px-4'

export function ProjectTable({
  projects,
  range,
  account,
}: {
  projects: CostProject[]
  range: CostRange
  account: CostAccount | null
}) {
  const [sort, setSort] = useState<SortState<ProjectKey>>({ key: 'tokens', dir: 'desc' })
  const [open, setOpen] = useState<Set<string>>(new Set())

  if (projects.length === 0) {
    return (
      <Card>
        <div className="flex flex-col items-center gap-1 px-4 py-8 text-center">
          <p className="text-[13px] text-mut">No project activity in this range.</p>
          <p className="text-[11.5px] text-dim">Try a longer range, or clear the group filter.</p>
        </div>
      </Card>
    )
  }

  const rows = sortRows(projects, COLUMNS, sort)
  const maxTokens = Math.max(...projects.map((p) => p.tokens), 1)

  const toggle = (slug: string) =>
    setOpen((prev) => {
      const next = new Set(prev)
      if (next.has(slug)) next.delete(slug)
      else next.add(slug)
      return next
    })

  return (
    <Card>
      <div className="overflow-x-auto">
        <div className="min-w-[880px]">
          <div className={`${GRID} h-[34px] border-b border-line-soft`}>
            <span aria-hidden="true" />
            {COLUMNS.map((column, i) => (
              <SortHeader key={`${column.key}-${i}`} column={column} state={sort} onSort={setSort} />
            ))}
          </div>

          {rows.map((project) => {
            const isOpen = open.has(project.slug)
            const tokens = formatTokens(project.tokens)

            return (
              <div key={project.slug} className="border-b border-line-soft last:border-b-0">
                <button
                  type="button"
                  aria-expanded={isOpen}
                  onClick={() => toggle(project.slug)}
                  className={`${GRID} min-h-[52px] w-full border-l-2 text-left hover:bg-hover ${
                    isOpen ? 'border-l-arc bg-hover' : 'border-l-transparent'
                  }`}
                >
                  <span
                    aria-hidden="true"
                    className={`text-[10px] text-dim transition-transform motion-reduce:transition-none ${
                      isOpen ? 'rotate-90' : ''
                    }`}
                  >
                    ▶
                  </span>

                  <span className="flex min-w-0 flex-col gap-px">
                    <span className="flex items-center gap-1.5 text-[13.5px] font-semibold">
                      {project.slug}
                      {project.group_key && (
                        <span className="rounded border border-line px-1 font-mono2 text-[9.5px] uppercase tracking-wider text-mut">
                          {project.group_key}
                        </span>
                      )}
                      {project.live && (
                        <span className="inline-flex items-center gap-1 rounded-full bg-ok/15 px-1.5 text-[10px] font-semibold uppercase tracking-wide text-ok">
                          <span className="h-1 w-1 rounded-full bg-ok" />
                          live
                        </span>
                      )}
                    </span>
                    <span className="truncate text-[11.5px] text-mut">
                      {formatRelative(project.last_activity_at)}
                    </span>
                  </span>

                  <span className="text-right font-mono2 text-[12.5px] tabular-nums text-mut">
                    {project.sessions}
                  </span>

                  <span className="flex items-center gap-2.5">
                    <span className="h-[7px] min-w-[60px] flex-1 overflow-hidden rounded-full border border-line-soft bg-deck-2">
                      <span
                        className="block h-full rounded-full bg-arc"
                        style={{ width: `${(project.tokens / maxTokens) * 100}%` }}
                      />
                    </span>
                    <span className="w-[46px] text-right font-mono2 text-[12.5px] font-semibold tabular-nums">
                      {project.share_pct}%
                    </span>
                  </span>

                  <span className="text-right font-mono2 text-[13px] font-medium tabular-nums">
                    {tokens.value}
                    <span className="ml-px text-[10.5px] text-mut">{tokens.unit}</span>
                  </span>

                  <span className="text-right font-mono2 text-[12.5px] tabular-nums text-mut">
                    {formatMoney(project.cost_usd)}
                  </span>

                  <ModelSummary models={project.models} />

                  <Trend pct={project.trend_pct} />
                </button>

                {isOpen && <SessionRows slug={project.slug} range={range} account={account} />}
              </div>
            )
          })}
        </div>
      </div>
    </Card>
  )
}

/** A rise in spend is the only direction worth colouring. */
function Trend({ pct }: { pct: number | null }) {
  if (pct === null) {
    return <span className="text-right font-mono2 text-[12px] tabular-nums text-dim">new</span>
  }

  const flat = Math.abs(pct) < 5
  const tone = flat ? 'text-mut' : pct > 0 ? 'text-gold' : 'text-mut'
  const mark = flat ? '—' : pct > 0 ? '▲' : '▼'

  return (
    <span className={`text-right font-mono2 text-[12px] tabular-nums ${tone}`}>
      {mark} {Math.abs(pct)}%
    </span>
  )
}
