import { useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { AlertCircle, ChevronDown, ChevronUp } from 'lucide-react'
import { Card, CardHeader } from '@/features/deck/ui'
import { ProjectCombobox, type ProjectOption } from '@/components/ProjectCombobox'
import { GROUP_LABEL, GROUP_ORDER, matchesProjectOption, type ProjectGroupKey } from '@/lib/project-groups'
import { cn } from '@/lib/utils'
import type { RecapRange, SessionRecap } from '@/types/api'
import { ProjectGroupCard } from './ProjectGroupCard'
import { ThreadsBox } from './ThreadsBox'
import { formatDuration, groupByDay, groupByProject, relativeAge } from './recap-format'
import { useOpenThreads, useRecapOverview, useRecapRollups, useRunRecap } from './hooks/use-recap'
import { RunControl } from './RunControl'

export function RecapPage() {
  const [range, setRange] = useState<RecapRange>('24h')
  const [searchParams] = useSearchParams()
  const [project, setProject] = useState<ProjectOption | null>(() => {
    // deep-link parity with /tasks?group=fa
    const group = searchParams.get('group') as ProjectGroupKey | null
    if (group && GROUP_ORDER.includes(group)) {
      return { name: GROUP_LABEL[group], count: 0, groupKey: group }
    }
    return null
  })
  const { data, isLoading, isError } = useRecapOverview(range)
  const run = useRunRecap()
  const [skippedOpen, setSkippedOpen] = useState(false)
  const { data: threads } = useOpenThreads()
  // merged gist lines for multi-session project groups (24h stream only — week
  // groups are per-day slices a range-wide merge wouldn't describe)
  const { data: rollups } = useRecapRollups(range, range === '24h')
  const rollupFor = (projectId: string) => rollups?.find((r) => r.project_id === projectId)?.summary

  const stats = data?.stats
  const byProject = (recaps: SessionRecap[]) => recaps.filter((r) => matchesProjectOption(project, r.project))
  const recaps = byProject(data?.recaps ?? [])
  const skipped = byProject(data?.skipped ?? [])
  const failed = byProject(data?.failed ?? [])
  const decisions = recaps.flatMap((r) =>
    r.decisions.map((d) => ({ project: r.project?.name ?? 'Unassigned', text: d })),
  )

  // combobox options from everything in range (same shape as the tasks page:
  // group header rows spanning >1 project, members indented, ungrouped plain)
  const projectOptions = useMemo(
    () => buildProjectOptions(data ? [...data.recaps, ...data.skipped, ...data.failed] : []),
    [data],
  )

  return (
    <div className="pb-16 pt-5">
      {/* header row */}
      <div className="mb-5 flex flex-wrap items-center gap-4">
        <h1 className="font-display text-[15px] font-bold uppercase tracking-[0.18em] text-ink">
          <span className="text-arc">▸</span> Recap
        </h1>
        <div className="inline-flex gap-0.5 rounded-lg border border-line bg-deck-2 p-0.5">
          {(['24h', 'week'] as const).map((r) => (
            <button
              key={r}
              onClick={() => setRange(r)}
              className={cn(
                'rounded-md px-3.5 py-1 font-display text-[11.5px] font-semibold uppercase tracking-[0.08em]',
                range === r ? 'border border-line bg-deck text-arc shadow-[var(--card-shadow)]' : 'text-mut hover:text-ink',
              )}
            >
              {r === '24h' ? 'Last 24h' : 'Last week'}
            </button>
          ))}
        </div>
        <ProjectCombobox projects={projectOptions} value={project?.name ?? null} onChange={setProject} />
        <div className="ml-auto flex items-center gap-2.5">
          {data?.last_summarized_at && (
            <span
              className="inline-flex items-center gap-1.5 rounded-full border border-ok/35 bg-ok/10 px-2.5 py-1 text-[11.5px] text-ok"
              title="Recaps run automatically every 15 minutes (jarvis:run-recaps)"
            >
              <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-ok" />
              Auto · {relativeAge(data.last_summarized_at)}
            </span>
          )}
          <RunControl pending={data?.pending ?? 0} />
        </div>
      </div>

      {isLoading && <p className="text-[13px] text-dim">Loading recap…</p>}
      {isError && (
        <p className="flex items-center gap-2 text-[13px] text-bad">
          <AlertCircle className="h-4 w-4" /> Couldn't load recaps.
        </p>
      )}

      {data && (
        <>
          {/* stats */}
          <div className="mb-3 grid grid-cols-2 gap-2.5 md:grid-cols-4">
            <Stat value={`${stats!.captured}`} label="Captured" sub={`${stats!.meaningful} meaningful · ${stats!.skipped} skipped`} />
            <Stat value={formatDuration(stats!.focus_minutes)} label="Focus time" sub={range === '24h' ? 'last 24 hours' : 'last 7 days'} />
            <Stat value={`${stats!.tasks_closed}`} label="Tasks closed" sub="from task events" />
            <Stat
              value={`${threads?.active.length ?? stats!.open_threads}`}
              label="Open threads"
              sub={threads && threads.stale_count > 0 ? `${threads.stale_count} stale hidden` : 'across projects'}
            />
          </div>

          {/* where you left off */}
          {threads && <ThreadsBox threads={threads} filter={project} />}

          {/* pending note — auto-run drains this on its own */}
          {data.pending > 0 && (
            <div className="mb-4 flex items-center gap-2 rounded-[10px] border border-dashed border-line px-4 py-2.5 text-[12.5px] text-mut">
              <span>
                {data.pending} session{data.pending === 1 ? '' : 's'} queued.
              </span>
              <span className="text-dim">Auto-run picks them up within 15 minutes — or Run now.</span>
            </div>
          )}

          {/* stream */}
          {range === '24h' ? (
            <>
              {groupByProject(recaps).map((group, i) => (
                <ProjectGroupCard key={group.key} group={group} rollup={rollupFor(group.key)} defaultOpen={i === 0} />
              ))}
              {recaps.length === 0 && (
                <p className="py-8 text-center text-[13px] text-dim">
                  No summarized work sessions in the last 24 hours{project ? ` for ${project.name}` : ''}.
                </p>
              )}
            </>
          ) : (
            <>
              {groupByDay(recaps).map((group) => (
                <section key={group.label}>
                  <div className="mb-2 mt-6 flex items-baseline gap-2.5">
                    <span className="h-0.5 w-2 self-center bg-arc" />
                    <h2 className="font-display text-[12px] font-bold uppercase tracking-[0.16em] text-mut">{group.label}</h2>
                    <span className="ml-auto font-mono2 text-[11px] text-dim">
                      {group.recaps.length} session{group.recaps.length === 1 ? '' : 's'} ·{' '}
                      {formatDuration(group.recaps.reduce((sum, r) => sum + r.duration_minutes, 0))}
                    </span>
                  </div>
                  {groupByProject(group.recaps).map((projectGroup) => (
                    <ProjectGroupCard key={projectGroup.key} group={projectGroup} />
                  ))}
                </section>
              ))}
              {decisions.length > 0 && (
                <Card className="mt-6">
                  <CardHeader title="Decisions this week" count={`${decisions.length}`} />
                  <ul className="px-[18px] py-3">
                    {decisions.map((d, i) => (
                      <li key={i} className="border-b border-line-soft py-1.5 text-[13px] text-mut last:border-b-0">
                        <span className="font-display text-[10px] font-semibold uppercase tracking-[0.1em] text-dim">{d.project} </span>
                        {d.text}
                      </li>
                    ))}
                  </ul>
                </Card>
              )}
            </>
          )}

          {/* failed */}
          {failed.map((recap) => (
            <div key={recap.id} className="mb-1.5 flex items-center gap-2.5 rounded-lg border border-bad/40 bg-deck px-3.5 py-2 text-[12.5px]">
              <AlertCircle className="h-3.5 w-3.5 shrink-0 text-bad" />
              <span className="text-mut">Summary failed: {recap.headline ?? recap.cc_session_id}</span>
              <span className="truncate font-mono2 text-[11px] text-dim">{recap.error}</span>
              <button
                onClick={() => run.mutate({ model: 'haiku', session_id: recap.cc_session_id })}
                disabled={run.isPending}
                className="ml-auto rounded-md border border-line px-2 py-0.5 font-mono2 text-[10.5px] text-mut hover:border-arc/40 hover:text-arc disabled:opacity-50"
              >
                retry
              </button>
            </div>
          ))}

          {/* skipped chores */}
          {skipped.length > 0 && (
            <div className="mt-3">
              <button
                onClick={() => setSkippedOpen((v) => !v)}
                className="flex w-full items-center gap-2 rounded-[10px] border border-dashed border-line px-4 py-2 text-left text-[12.5px] text-dim hover:bg-hover hover:text-mut"
                aria-expanded={skippedOpen}
              >
                <span>
                  {skipped.length} small session{skipped.length === 1 ? '' : 's'} auto-skipped
                </span>
                <span className="opacity-70">— chores &amp; quick checks, no summary generated</span>
                {skippedOpen ? <ChevronUp className="ml-auto h-3.5 w-3.5" /> : <ChevronDown className="ml-auto h-3.5 w-3.5" />}
              </button>
              {skippedOpen && (
                <div className="mx-2 rounded-b-[10px] border border-t-0 border-dashed border-line px-3 py-2">
                  {skipped.map((chore) => (
                    <ChoreRow key={chore.id} chore={chore} onSummarize={() => run.mutate({ model: 'haiku', session_id: chore.cc_session_id })} busy={run.isPending} />
                  ))}
                </div>
              )}
            </div>
          )}
        </>
      )}
    </div>
  )
}

/** Session counts per project, arranged like the tasks-page filter: group header
 *  rows (with combined counts) above indented members; single-project groups
 *  collapse to a plain row; ungrouped (incl. Unassigned) at the end. */
function buildProjectOptions(recaps: SessionRecap[]): ProjectOption[] {
  const counts = new Map<string, { count: number; group: ProjectGroupKey | null }>()
  for (const recap of recaps) {
    const name = recap.project?.name ?? 'Unassigned'
    const entry = counts.get(name) ?? { count: 0, group: recap.project?.group_key ?? null }
    entry.count += 1
    counts.set(name, entry)
  }

  const options: ProjectOption[] = []
  for (const key of GROUP_ORDER) {
    const members = [...counts.entries()]
      .filter(([, v]) => v.group === key)
      .map(([name, v]) => ({ name, count: v.count, grouped: true }))
      .sort((a, b) => b.count - a.count)
    if (members.length === 0) continue
    if (members.length > 1) {
      options.push({ name: GROUP_LABEL[key], count: members.reduce((s, m) => s + m.count, 0), groupKey: key })
    } else {
      members[0].grouped = false
    }
    options.push(...members)
  }

  const ungrouped = [...counts.entries()]
    .filter(([, v]) => v.group === null)
    .map(([name, v]) => ({ name, count: v.count }))
    .sort((a, b) => b.count - a.count)

  return [...options, ...ungrouped]
}

function Stat({ value, label, sub }: { value: string; label: string; sub: string }) {
  return (
    <div className="rounded-[10px] border border-line-soft bg-deck px-3.5 py-2.5 shadow-[var(--card-shadow)]">
      <b className="block font-mono2 text-[21px] font-medium tabular-nums leading-tight text-ink">{value}</b>
      <i className="mt-0.5 block font-display text-[10px] font-semibold not-italic uppercase tracking-[0.14em] text-dim">{label}</i>
      <span className="text-[11px] text-dim">{sub}</span>
    </div>
  )
}

function ChoreRow({ chore, onSummarize, busy }: { chore: SessionRecap; onSummarize: () => void; busy: boolean }) {
  return (
    <div className="flex items-center gap-2.5 py-1 text-[12.5px] text-dim">
      <span className="truncate">{chore.headline}</span>
      <span className="font-mono2 text-[11px]">· {chore.facts.messages ?? 0} msgs</span>
      <button onClick={onSummarize} disabled={busy} className="text-[11px] underline decoration-dotted hover:text-arc disabled:opacity-50">
        summarize anyway
      </button>
      <time className="ml-auto whitespace-nowrap font-mono2 text-[11px]">{formatDuration(chore.duration_minutes)}</time>
    </div>
  )
}
