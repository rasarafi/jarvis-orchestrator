import { useSearchParams } from 'react-router-dom'
import { AlertCircle, Loader2 } from 'lucide-react'
import { Card } from '@/features/deck/ui'
import { GROUP_LABEL, GROUP_ORDER, type ProjectGroupKey } from '@/lib/project-groups'
import { useTaskAnalytics } from '../hooks/use-task-analytics'
import type { TaskAnalyticsStats } from '@/types/api'
import { PressureMatrix } from './PressureMatrix'
import { BacklogChart, FlowChart } from './FlowCharts'
import { AgeScatter } from './AgeScatter'
import { DeadlineRunway } from './DeadlineRunway'

const isGroup = (v: string | null): v is ProjectGroupKey =>
  v !== null && (GROUP_ORDER as string[]).includes(v)

export function TaskAnalysisPage() {
  const [params, setParams] = useSearchParams()
  const group = isGroup(params.get('group')) ? (params.get('group') as ProjectGroupKey) : null
  const { data, isLoading, error, refetch } = useTaskAnalytics(group)

  const setGroup = (next: ProjectGroupKey | null) => {
    const p = new URLSearchParams(params)
    if (next) p.set('group', next)
    else p.delete('group')
    setParams(p, { replace: true })
  }

  return (
    <div className="flex flex-col gap-[18px]">
      <div className="flex flex-wrap items-center gap-1.5">
        <FilterChip active={group === null} onClick={() => setGroup(null)}>
          All groups
        </FilterChip>
        {GROUP_ORDER.map((key) => (
          <FilterChip key={key} active={group === key} onClick={() => setGroup(key)}>
            {GROUP_LABEL[key]}
          </FilterChip>
        ))}
      </div>

      {isLoading && (
        <div className="flex h-48 items-center justify-center text-dim">
          <Loader2 className="mr-2 h-5 w-5 animate-spin" /> Loading task analysis…
        </div>
      )}

      {error && (
        <Card>
          <div className="flex flex-col items-center gap-3 px-4 py-8 text-[13px] text-bad">
            <span className="flex items-center gap-2">
              <AlertCircle className="h-4 w-4" /> Failed to load task analysis.
            </span>
            <button
              type="button"
              onClick={() => refetch()}
              className="rounded-md border border-line bg-deck-2 px-3 py-1.5 text-[12.5px] text-mut hover:text-ink"
            >
              Retry
            </button>
          </div>
        </Card>
      )}

      {data && data.stats.open === 0 && (
        <Card>
          <div className="px-4 py-10 text-center text-[13px] text-dim">
            No open tasks{group ? ` in ${GROUP_LABEL[group]}` : ''} — nothing to analyse.
          </div>
        </Card>
      )}

      {data && data.stats.open > 0 && (
        <>
          <StatTiles stats={data.stats} />
          <PressureMatrix rows={data.pressure} />
          <div className="grid gap-[18px] xl:grid-cols-2">
            <FlowChart weeks={data.flow} />
            <BacklogChart weeks={data.flow} />
          </div>
          <AgeScatter points={data.age} />
          <DeadlineRunway deadlines={data.deadlines} />
        </>
      )}
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
      onClick={onClick}
      aria-pressed={active}
      className={`rounded-full border px-3 py-[5px] text-[12.5px] transition-colors ${
        active
          ? 'border-arc/50 bg-arc-soft text-arc'
          : 'border-line-soft bg-deck text-mut hover:text-ink'
      }`}
    >
      {children}
    </button>
  )
}

function StatTiles({ stats }: { stats: TaskAnalyticsStats }) {
  const net = stats.net_this_week
  const tiles: { label: string; value: string; foot: string; tone?: 'bad' | 'ok' }[] = [
    {
      label: 'Open',
      value: String(stats.open),
      foot: `across ${stats.projects_with_open} project${stats.projects_with_open === 1 ? '' : 's'}`,
    },
    {
      label: 'Urgent',
      value: String(stats.urgent),
      foot: stats.urgent_top_project
        ? `${stats.urgent_top_project.count} in ${stats.urgent_top_project.name}`
        : 'none outstanding',
      tone: stats.urgent > 0 ? 'bad' : undefined,
    },
    {
      label: 'Blocked',
      value: String(stats.blocked),
      foot: `${stats.in_progress} in progress${stats.review > 0 ? ` · ${stats.review} in review` : ''}`,
    },
    {
      label: 'Net this week',
      value: `${net > 0 ? '+' : ''}${net}`,
      foot: `${stats.created_this_week} created · ${stats.completed_this_week} done`,
      tone: net > 0 ? 'bad' : 'ok',
    },
    {
      label: 'Oldest open',
      value: stats.oldest?.age_days != null ? `${stats.oldest.age_days}d` : '—',
      foot: stats.oldest ? `${stats.oldest.project ?? 'Unassigned'} · ${stats.oldest.title}` : '',
    },
  ]

  return (
    <div className="grid grid-cols-2 gap-[10px] md:grid-cols-3 xl:grid-cols-5">
      {tiles.map((t) => (
        <div key={t.label} className="rounded-[10px] border border-line-soft bg-deck px-3.5 py-3 shadow-[var(--card-shadow)]">
          <div className="text-[10.5px] uppercase tracking-[0.08em] text-dim">{t.label}</div>
          <div className="mt-1 font-mono2 text-[26px] font-medium leading-none text-ink">
            {t.value}
          </div>
          <div
            className={`mt-1.5 truncate text-[11.5px] ${
              t.tone === 'bad' ? 'text-bad' : t.tone === 'ok' ? 'text-ok' : 'text-mut'
            }`}
            title={t.foot}
          >
            {t.foot}
          </div>
        </div>
      ))}
    </div>
  )
}
