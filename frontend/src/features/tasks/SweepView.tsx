import { useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { AlertTriangle, Check, GitCommitHorizontal, Loader2, Play, Undo2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { GROUP_LABEL, GROUP_ORDER, type ProjectGroupKey } from '@/lib/project-groups'
import type { RecapModelChoice, SweepConfidence, SweepMatch, SweepRunResponse, Task } from '@/types/api'
import { useLatestSweep, useRunSweep, useSweepMatchAction } from './hooks/use-sweep'

const MODELS: RecapModelChoice[] = ['haiku', 'sonnet', 'opus']
const WINDOWS = [
  { days: 1, label: 'Last 24 hours' },
  { days: 3, label: 'Last 3 days' },
  { days: 7, label: 'Last 7 days' },
  { days: 14, label: 'Last 14 days' },
  { days: 30, label: 'Last 30 days' },
]

const CONFIDENCE_ORDER: SweepConfidence[] = ['high', 'medium', 'low']
const confidenceBadge: Record<SweepConfidence, string> = {
  high: 'bg-ok/10 text-ok',
  medium: 'bg-gold-soft text-gold',
  low: 'bg-deck-2 text-dim',
}

const priorityDot: Record<Task['priority'], string> = {
  urgent: 'bg-red-400',
  high: 'bg-orange-400',
  medium: 'bg-blue-400',
  low: 'bg-dim',
}

function relativeDay(iso: string): string {
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000)
  if (days <= 0) return 'today'
  if (days === 1) return 'yesterday'
  return `${days}d ago`
}

export function SweepView() {
  const [searchParams] = useSearchParams()
  const group = (searchParams.get('group') as ProjectGroupKey | null) ?? undefined
  const project = searchParams.get('project') ?? undefined

  const [windowDays, setWindowDays] = useState(7)
  const [model, setModel] = useState<RecapModelChoice>(() => {
    const stored = localStorage.getItem('sweep-model')
    return MODELS.includes(stored as RecapModelChoice) ? (stored as RecapModelChoice) : 'haiku'
  })

  const latest = useLatestSweep()
  const run = useRunSweep()
  const result: SweepRunResponse | null | undefined = latest.data

  const scopeLabel = project
    ? project
    : group && GROUP_ORDER.includes(group)
      ? GROUP_LABEL[group]
      : 'All groups'

  const startSweep = () =>
    run.mutate({ group, project, window_days: windowDays, model })

  return (
    <div className="flex flex-col gap-4">
      <div>
        <div className="font-display text-[11.5px] uppercase tracking-[0.18em] text-dim">
          <Link to="/tasks" className="hover:text-mut">Tasks</Link>{' '}
          <span className="font-semibold text-mut">/ Commit sweep</span>
        </div>
        <h2 className="mt-1 flex flex-wrap items-baseline gap-x-3 font-display text-lg font-semibold text-ink">
          Commit sweep
          <span className="font-sans text-[13px] font-normal text-mut">
            match recent commits against open tasks — you confirm, nothing auto-completes
          </span>
        </h2>
      </div>

      {/* config bar */}
      <div className="flex flex-wrap items-center gap-2.5 rounded-xl border border-line-soft bg-deck px-3.5 py-2.5 shadow-[var(--card-shadow)]">
        <ConfigLabel>Scope</ConfigLabel>
        <span className="rounded-full border border-line bg-deck-2 px-2.5 py-1 text-xs text-ink">{scopeLabel}</span>
        <ConfigLabel>Window</ConfigLabel>
        <select
          value={windowDays}
          onChange={(e) => setWindowDays(Number(e.target.value))}
          className="cursor-pointer appearance-none rounded-full border border-line bg-deck px-2.5 py-1 text-xs text-ink"
        >
          {WINDOWS.map((w) => (
            <option key={w.days} value={w.days}>{w.label}</option>
          ))}
        </select>
        <ConfigLabel>Model</ConfigLabel>
        <div className="flex rounded-full border border-line bg-deck-2 p-0.5">
          {MODELS.map((m) => (
            <button
              key={m}
              onClick={() => { setModel(m); localStorage.setItem('sweep-model', m) }}
              className={cn(
                'rounded-full px-3 py-0.5 text-xs',
                m === model ? 'bg-deck font-semibold text-ink shadow-[var(--card-shadow)]' : 'text-mut hover:text-ink',
              )}
            >
              {m}
            </button>
          ))}
        </div>
        <button
          onClick={startSweep}
          disabled={run.isPending}
          className="ml-auto flex items-center gap-1.5 rounded-full border border-arc/50 bg-arc-soft px-3.5 py-1.5 font-display text-[11px] font-semibold uppercase tracking-[0.1em] text-arc hover:bg-arc/20 disabled:opacity-60"
        >
          {run.isPending ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Play className="h-3.5 w-3.5" />}
          {run.isPending ? 'Sweeping…' : 'Run sweep'}
        </button>
        <div className="basis-full text-[11.5px] text-dim">
          Reads <span className="font-medium text-mut">git log</span> only — one model call per repo.
          {result && (
            <>
              {' '}Last sweep: <span className="font-medium text-mut">{relativeDay(result.run.created_at)}</span> · {result.run.model} · {((result.run.stats.duration_ms ?? 0) / 1000).toFixed(0)}s
              {(result.run.stats.cached_projects ?? 0) > 0 && <> · {result.run.stats.cached_projects} repo{result.run.stats.cached_projects! > 1 ? 's' : ''} from cache</>}
            </>
          )}
        </div>
      </div>

      {run.isError && (
        <Callout tone="bad">Sweep failed — {(run.error as Error).message}. The run may have partially completed; check the results below.</Callout>
      )}
      {result?.run.status === 'partial' && (
        <Callout tone="gold">
          Some repos failed to summarize: {result.run.stats.errors?.map((e) => e.project).join(', ')}. Their matches are missing from this run.
        </Callout>
      )}

      {latest.isLoading ? (
        <div className="flex h-40 items-center justify-center text-dim">
          <Loader2 className="mr-2 h-5 w-5 animate-spin" /> Loading last sweep…
        </div>
      ) : !result ? (
        <div className="flex h-48 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-line text-center">
          <GitCommitHorizontal className="h-7 w-7 text-dim" />
          <p className="text-sm text-mut">No sweep yet.</p>
          <p className="text-xs text-dim">Run one to check recent commits against your open tasks.</p>
        </div>
      ) : (
        <SweepResults result={result} />
      )}
    </div>
  )
}

function SweepResults({ result }: { result: SweepRunResponse }) {
  const { run, matches } = result
  const stats = run.stats

  const groups = useMemo(
    () =>
      CONFIDENCE_ORDER
        .map((confidence) => ({ confidence, items: matches.filter((m) => m.confidence === confidence) }))
        .filter((g) => g.items.length > 0),
    [matches],
  )

  return (
    <>
      {/* summary strip */}
      <div className="flex flex-wrap overflow-hidden rounded-xl border border-line-soft bg-deck shadow-[var(--card-shadow)]">
        <Stat n={`${stats.matched ?? 0} / ${stats.tasks_scanned ?? 0}`} label="tasks matched" accent />
        <Stat n={String(stats.repos_scanned ?? 0)} label="repos scanned" />
        <Stat n={String(stats.commits_read ?? 0)} label="commits read" />
        <Stat n={String(matches.filter((m) => m.confidence === 'high' && m.status === 'pending').length)} label="high pending" />
      </div>

      {matches.length === 0 && (
        <div className="flex h-32 flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-line text-center">
          <p className="text-sm text-mut">No matches in this window.</p>
          <p className="text-xs text-dim">Either the commits don't map to open tasks, or everything is already marked done.</p>
        </div>
      )}

      {groups.map(({ confidence, items }) => (
        <ConfidenceGroup key={confidence} confidence={confidence} items={items} />
      ))}

      {(stats.skipped?.length ?? 0) > 0 && (
        <div className="rounded-[10px] border border-dashed border-line px-3.5 py-2.5 text-[12.5px] text-mut">
          <span className="font-semibold text-gold">{stats.skipped!.length} repo{stats.skipped!.length > 1 ? 's' : ''} skipped</span>
          {' — '}
          {stats.skipped!.map((s, i) => (
            <span key={s.project}>
              {i > 0 && ' · '}
              <code className="rounded bg-deck-2 px-1 font-mono2 text-[11.5px]">{s.project}</code> {s.reason}
            </span>
          ))}
        </div>
      )}
    </>
  )
}

function ConfidenceGroup({ confidence, items }: { confidence: SweepConfidence; items: SweepMatch[] }) {
  const action = useSweepMatchAction()
  const pending = items.filter((m) => m.status === 'pending')

  const confirmAll = async () => {
    for (const m of pending) {
      await action.mutateAsync({ id: m.id, action: 'confirm' }).catch(() => {})
    }
  }

  return (
    <section className={cn(confidence === 'low' && 'opacity-80')}>
      <div className="mb-2 flex items-center gap-2.5">
        <h3 className="font-display text-xs font-semibold uppercase tracking-[0.18em] text-mut">
          {confidence} confidence
        </h3>
        <span className="font-mono2 text-[11.5px] text-dim">{pending.length} pending</span>
        {confidence === 'high' && pending.length > 0 && (
          <button
            onClick={confirmAll}
            disabled={action.isPending}
            className="ml-auto rounded-full border border-ok/35 bg-ok/10 px-3 py-1 text-xs font-semibold text-ok hover:bg-ok/20 disabled:opacity-60"
          >
            ✓ Confirm all high ({pending.length})
          </button>
        )}
      </div>
      <div className="flex flex-col gap-2.5">
        {items.map((m) => (
          <MatchCard key={m.id} match={m} />
        ))}
      </div>
    </section>
  )
}

function MatchCard({ match }: { match: SweepMatch }) {
  const action = useSweepMatchAction()
  const task = match.task
  const busy = action.isPending

  if (match.status !== 'pending') {
    const confirmed = match.status === 'confirmed'
    return (
      <div className={cn('flex items-center gap-2.5 rounded-xl border border-line-soft bg-deck px-4 py-2.5 shadow-[var(--card-shadow)]', !confirmed && 'opacity-60')}>
        <span className={cn('font-bold', confirmed ? 'text-ok' : 'text-dim')}>{confirmed ? '✓' : '—'}</span>
        <span className="truncate text-[13px] text-ink">{task?.title ?? 'Task'}</span>
        <span className="hidden text-xs text-dim sm:block">
          {confirmed ? 'marked done · task_events written' : "dismissed · won't resurface for these commits"}
        </span>
        <button
          onClick={() => action.mutate({ id: match.id, action: 'undo' })}
          disabled={busy}
          className="ml-auto flex shrink-0 items-center gap-1 text-xs font-semibold text-arc hover:underline disabled:opacity-60"
        >
          <Undo2 className="h-3 w-3" /> Undo
        </button>
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-line-soft bg-deck px-4 py-3.5 shadow-[var(--card-shadow)]">
      <div className="flex items-start gap-2.5">
        {task && <span className={cn('mt-1.5 h-2 w-2 shrink-0 rounded-full', priorityDot[task.priority])} />}
        <div className="min-w-0">
          <div className="text-[14px] font-semibold leading-snug text-ink">{task?.title ?? 'Task'}</div>
          <div className="mt-0.5 flex flex-wrap items-center gap-2 text-[11.5px] text-dim">
            {task?.project && (
              <span className="rounded-full border border-line-soft bg-deck-2 px-2 py-px text-[11px] font-semibold text-mut">
                {task.project.name}
              </span>
            )}
            {task && <span>open {relativeDay(task.created_at)} · priority {task.priority}</span>}
          </div>
        </div>
        <span className={cn('ml-auto shrink-0 rounded-[5px] px-2 py-0.5 font-display text-[10px] font-semibold uppercase tracking-[0.14em]', confidenceBadge[match.confidence])}>
          {match.confidence}
        </span>
      </div>

      {match.rationale && <p className="ml-[18px] mt-2 text-[12.5px] text-mut">{match.rationale}</p>}

      {/* git rail — evidence as a mini commit graph */}
      <div className="ml-[18px] mt-2.5 border-l-2 border-arc/40 pl-4">
        {match.commits.map((c) => (
          <div key={c.sha} className="relative flex items-baseline gap-2.5 py-0.5 text-[12.5px]">
            <span className="absolute -left-[21px] top-[7px] h-2 w-2 rounded-full border-2 border-arc bg-deck" />
            <span className="shrink-0 font-mono2 text-[11.5px] text-arc">{c.sha.slice(0, 7)}</span>
            <span className="truncate text-ink">{c.subject}</span>
            <span className="ml-auto hidden shrink-0 text-[11px] text-dim sm:block">{relativeDay(c.committed_at)}</span>
          </div>
        ))}
      </div>

      <div className="ml-[18px] mt-3 flex items-center gap-2">
        <button
          onClick={() => action.mutate({ id: match.id, action: 'confirm' })}
          disabled={busy}
          className="flex items-center gap-1.5 rounded-lg border border-ok/40 bg-ok/10 px-3 py-1 text-[12.5px] font-semibold text-ok hover:bg-ok/20 disabled:opacity-60"
        >
          <Check className="h-3.5 w-3.5" /> Confirm done
        </button>
        <button
          onClick={() => action.mutate({ id: match.id, action: 'dismiss' })}
          disabled={busy}
          className="rounded-lg border border-line px-3 py-1 text-[12.5px] font-semibold text-mut hover:bg-hover hover:text-ink disabled:opacity-60"
        >
          Dismiss
        </button>
        <span className="ml-auto text-[11px] text-dim">
          {match.commits.length} commit{match.commits.length > 1 ? 's' : ''}
        </span>
      </div>
    </div>
  )
}

function ConfigLabel({ children }: { children: React.ReactNode }) {
  return (
    <span className="font-display text-[10.5px] uppercase tracking-[0.14em] text-dim">{children}</span>
  )
}

function Stat({ n, label, accent }: { n: string; label: string; accent?: boolean }) {
  return (
    <div className="min-w-[110px] flex-1 border-r border-line-soft px-4 py-2.5 last:border-r-0">
      <div className={cn('font-display text-lg font-semibold', accent ? 'text-arc' : 'text-ink')}>{n}</div>
      <div className="text-[10.5px] uppercase tracking-[0.1em] text-dim">{label}</div>
    </div>
  )
}

function Callout({ tone, children }: { tone: 'bad' | 'gold'; children: React.ReactNode }) {
  return (
    <div className={cn(
      'flex items-start gap-2 rounded-[10px] border px-3.5 py-2.5 text-[12.5px]',
      tone === 'bad' ? 'border-bad/40 bg-bad/10 text-bad' : 'border-gold/40 bg-gold-soft text-gold',
    )}>
      <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
      <span>{children}</span>
    </div>
  )
}
