import { useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Loader2, AlertCircle, ChevronDown, ChevronRight, Crosshair, OctagonAlert } from 'lucide-react'
import { Card, CardHeader, projectColor } from '@/features/deck/ui'
import { groupChips } from '@/features/deck/session-groups'
import { useLiveSessions } from '@/features/deck/hooks/use-live-sessions'
import { sessionNeedsHuman } from '@/features/deck/needs'
import { cleanSessionSummary } from '@/features/deck/clean-summary'
import { formatDuration, secondsSince } from '@/lib/dates'
import { formatRelativeTime, cn } from '@/lib/utils'
import { GROUP_LABEL } from '@/lib/project-groups'
import type { LiveSession } from '@/types/live-sessions'
import type { Project, Task } from '@/types/api'
import {
  useProjectDetail,
  useProjectOverview,
  useProjectColors,
  useProjectTasks,
} from './hooks/use-projects'
import { ProjectActivity } from './ProjectActivity'
import { ProjectRail } from './ProjectRail'
import { SessionDrawer } from './SessionDrawer'

const statusColor: Record<Project['status'], string> = {
  active: 'text-ok border-ok/40 bg-ok/10',
  paused: 'text-gold border-gold/45 bg-gold-soft',
  maintenance: 'text-arc border-arc/35 bg-arc-soft',
  archived: 'text-dim border-line',
}

const priorityRank: Record<Task['priority'], number> = { urgent: 0, high: 1, medium: 2, low: 3 }
const statusRank: Record<Task['status'], number> = { review: 0, in_progress: 1, blocked: 2, todo: 3, done: 4 }

const priorityBadge: Record<Task['priority'], string> = {
  urgent: 'text-bad border-bad/40 bg-bad/10',
  high: 'text-gold border-gold/45 bg-gold-soft',
  medium: 'text-arc border-arc/35 bg-arc-soft',
  low: 'text-dim border-line',
}

/** Soft translucent wash of the project hue, used for the header tint. */
function soft(hue: string, pct = 10): string {
  return `color-mix(in srgb, ${hue} ${pct}%, transparent)`
}

export function ProjectPage() {
  const { slug = '' } = useParams<{ slug: string }>()
  const { data: project, isLoading, error } = useProjectDetail(slug)
  const { data: overview } = useProjectOverview(slug)
  const { data: colors } = useProjectColors()
  const { data: liveSessions } = useLiveSessions()
  const { data: tasks } = useProjectTasks(project?.id ?? '')
  const [drawerSession, setDrawerSession] = useState<LiveSession | null>(null)

  const projectLive = useMemo(
    () => (liveSessions?.data ?? []).filter((s) => s.project_slug === slug),
    [liveSessions, slug],
  )

  if (isLoading) {
    return (
      <div className="flex h-48 items-center justify-center text-dim">
        <Loader2 className="mr-2 h-5 w-5 animate-spin" /> Loading project…
      </div>
    )
  }

  if (error || !project) {
    return (
      <div className="flex h-48 flex-col items-center justify-center gap-2 text-bad">
        <div className="flex items-center">
          <AlertCircle className="mr-2 h-5 w-5" /> Project not found
        </div>
        <Link to="/projects" className="text-xs text-mut underline hover:text-ink">
          Back to projects
        </Link>
      </div>
    )
  }

  const hue = colors?.[slug] ?? projectColor(project.name)
  const stats = overview?.stats
  const newestLive = [...projectLive].sort((a, b) =>
    (b.last_activity_at ?? '').localeCompare(a.last_activity_at ?? ''),
  )[0]
  const lastActivity = newestLive?.last_activity_at ?? stats?.last_session_at ?? project.last_session_at

  return (
    <div className="flex flex-col gap-[18px]">
      <nav className="flex items-center gap-2 font-mono2 text-[11.5px] text-dim" aria-label="Breadcrumb">
        <Link to="/" className="text-arc hover:underline">
          ← Deck
        </Link>
        <span className="text-line">/</span>
        <Link to="/projects" className="hover:text-mut">
          projects
        </Link>
        <span className="text-line">/</span>
        <span className="text-mut">{slug}</span>
      </nav>

      {/* header band — tinted with the project hue */}
      <header className="overflow-hidden rounded-[10px] border border-line bg-deck shadow-[var(--card-shadow)]">
        <div
          className="flex flex-wrap items-center gap-3 px-5 pb-3.5 pt-[18px]"
          style={{ background: `linear-gradient(180deg, ${soft(hue)}, transparent 75%)` }}
        >
          <h1 className="flex items-center gap-2.5 font-display text-[22px] font-bold text-ink">
            <span
              className="h-2.5 w-2.5 rounded-full"
              style={{ background: hue, boxShadow: `0 0 0 4px ${soft(hue, 18)}` }}
              aria-hidden
            />
            {project.name}
          </h1>
          {project.group_key && (
            <span className="rounded-full border border-line px-2.5 py-px font-mono2 text-[10px] uppercase tracking-[0.08em] text-mut">
              {GROUP_LABEL[project.group_key]}
            </span>
          )}
          <span
            className={cn(
              'rounded-full border px-2.5 py-px font-mono2 text-[10px] uppercase tracking-[0.08em]',
              statusColor[project.status],
            )}
          >
            {project.status}
          </span>
          {projectLive.length > 0 && (
            <span className="rounded-full border border-arc/35 bg-arc-soft px-2.5 py-px font-mono2 text-[10px] uppercase tracking-[0.08em] text-arc">
              {projectLive.length} live session{projectLive.length === 1 ? '' : 's'}
            </span>
          )}
          <Link
            to={`/audits?project=${encodeURIComponent(slug ?? '')}`}
            className="rounded-full border border-line px-2.5 py-px font-mono2 text-[10px] uppercase tracking-[0.08em] text-mut hover:border-arc/35 hover:text-arc"
          >
            audits
          </Link>
          <div className="flex basis-full flex-wrap gap-x-5 gap-y-1 pt-1 font-mono2 text-[11px] text-dim">
            {project.local_path && (
              <span>
                <b className="font-medium text-mut">path</b> {project.local_path}
              </span>
            )}
            {newestLive?.git_branch && (
              <span>
                <b className="font-medium text-mut">branch</b> {newestLive.git_branch}
              </span>
            )}
            {lastActivity && (
              <span>
                <b className="font-medium text-mut">last activity</b> {formatRelativeTime(lastActivity)}
              </span>
            )}
          </div>
        </div>

        {(project.current_focus || project.blockers) && (
          <div className="flex flex-col gap-1.5 border-t border-line-soft px-5 py-2.5">
            {project.current_focus && (
              <div className="flex gap-2 text-[13px] text-ink">
                <Crosshair className="mt-0.5 h-3.5 w-3.5 shrink-0 text-arc" />
                <span>{project.current_focus}</span>
              </div>
            )}
            {project.blockers && (
              <div className="flex gap-2 text-[13px] text-gold">
                <OctagonAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>{project.blockers}</span>
              </div>
            )}
          </div>
        )}

        <div className="grid grid-cols-2 border-t border-line-soft sm:grid-cols-4">
          <HeaderStat
            value={
              <>
                <span className={cn(stats && stats.open_tasks > 0 && 'text-gold')}>
                  {stats?.open_tasks ?? '—'}
                </span>
                {stats && (
                  <em className="font-mono2 text-[12px] not-italic text-dim"> / {stats.total_tasks}</em>
                )}
              </>
            }
            label="open tasks"
          />
          <HeaderStat
            value={<span className={cn(projectLive.length > 0 && 'text-arc')}>{projectLive.length}</span>}
            label="live sessions"
          />
          <HeaderStat value={stats ? `$${stats.cost_7d.toFixed(2)}` : '—'} label="cost · 7d" />
          <HeaderStat
            value={stats?.last_recap_at ? formatRelativeTime(stats.last_recap_at) : '—'}
            label="last recap"
          />
        </div>
      </header>

      <div className="grid items-start gap-[18px] lg:grid-cols-[minmax(0,1fr)_300px]">
        <main className="flex min-w-0 flex-col gap-[18px]">
          <LiveNowCard sessions={projectLive} onOpen={setDrawerSession} />
          <ProjectActivity slug={slug} tasks={tasks} hue={hue} />
          <TasksCard tasks={tasks} projectName={project.name} />
        </main>
        <ProjectRail project={project} dailyCosts={overview?.daily_costs} cost7d={stats?.cost_7d} hue={hue} />
      </div>

      <SessionDrawer session={drawerSession} onClose={() => setDrawerSession(null)} />
    </div>
  )
}

function HeaderStat({ value, label }: { value: React.ReactNode; label: string }) {
  return (
    <div className="border-line-soft px-5 py-3 max-sm:odd:border-r max-sm:[&:nth-child(-n+2)]:border-b sm:[&:not(:last-child)]:border-r">
      <div className="font-display text-[19px] font-semibold text-ink">{value}</div>
      <div className="font-mono2 text-[10px] uppercase tracking-[0.08em] text-dim">{label}</div>
    </div>
  )
}

function LiveNowCard({
  sessions,
  onOpen,
}: {
  sessions: LiveSession[]
  onOpen: (s: LiveSession) => void
}) {
  return (
    <Card>
      <CardHeader
        title="Live now"
        count={sessions.length > 0 ? `${sessions.length} session${sessions.length === 1 ? '' : 's'}` : undefined}
      />
      {sessions.length === 0 && (
        <div className="px-[18px] py-5 text-[12.5px] text-dim">No Claude sessions active on this project.</div>
      )}
      {sessions.length > 0 && (
        <div className="flex flex-col gap-2 p-3.5">
          {groupChips(sessions).map((group) => {
            const newest = group[0]
            const needs = group.some(sessionNeedsHuman)
            const idle = secondsSince(newest.last_activity_at)
            const summary = cleanSessionSummary(newest.last_output_line)
            return (
              <button
                key={newest.session_id}
                onClick={() => onOpen(newest)}
                className="flex items-center gap-2.5 rounded-lg border border-line-soft bg-deck-2 px-3 py-2.5 text-left font-mono2 text-[11.5px] transition-colors hover:border-line"
              >
                <span
                  className={cn(
                    'h-1.5 w-1.5 flex-none rounded-full',
                    needs
                      ? 'bg-gold shadow-[0_0_0_3px_var(--gold-soft)]'
                      : 'bg-arc shadow-[0_0_0_3px_var(--arc-soft)]',
                  )}
                  aria-hidden
                />
                <span className="flex-none truncate text-ink max-sm:min-w-0 max-sm:flex-1">
                  {newest.git_branch ?? 'no branch'}
                </span>
                {group.length > 1 && (
                  <span className="flex-none rounded border border-line px-1 text-[10px] text-dim">
                    ×{group.length}
                  </span>
                )}
                {summary && (
                  <span className="min-w-0 flex-1 truncate font-body text-[12px] text-mut max-sm:hidden">
                    {summary}
                  </span>
                )}
                {needs && (
                  <span className="flex-none rounded-full border border-gold/45 bg-gold-soft px-2 py-px text-[9.5px] uppercase tracking-[0.06em] text-gold">
                    needs you
                  </span>
                )}
                <span className="ml-auto flex-none text-[10px] text-dim">
                  {idle != null ? formatDuration(idle) : '—'}
                </span>
              </button>
            )
          })}
        </div>
      )}
    </Card>
  )
}

function TasksCard({ tasks, projectName }: { tasks: Task[] | undefined; projectName: string }) {
  const [showDone, setShowDone] = useState(false)

  const { open, done } = useMemo(() => {
    const all = tasks ?? []
    const open = all
      .filter((t) => t.status !== 'done')
      .sort(
        (a, b) =>
          statusRank[a.status] - statusRank[b.status] ||
          priorityRank[a.priority] - priorityRank[b.priority],
      )
    const done = all.filter((t) => t.status === 'done')
    return { open, done }
  }, [tasks])

  return (
    <Card>
      <CardHeader
        title="Tasks"
        count={`${open.length} open`}
        action={
          <Link to={`/tasks?project=${encodeURIComponent(projectName)}`} className="text-xs text-arc hover:underline">
            Board →
          </Link>
        }
      />
      {open.length === 0 && <div className="px-[18px] py-5 text-[12.5px] text-dim">No open tasks.</div>}
      {open.map((task) => (
        <div
          key={task.id}
          className="flex items-center gap-2.5 border-b border-line-soft px-[18px] py-2.5 last:border-b-0"
        >
          <span
            className={cn(
              'flex-none rounded border px-1.5 py-px font-mono2 text-[9.5px] uppercase tracking-[0.05em]',
              priorityBadge[task.priority],
            )}
          >
            {task.priority}
          </span>
          <span
            className={cn(
              'min-w-0 flex-1 truncate text-[13px]',
              task.status === 'blocked' || task.status === 'review' ? 'text-gold' : 'text-ink',
            )}
          >
            {task.status === 'in_progress' && <span className="text-arc">▸ </span>}
            {task.title}
          </span>
          <span className="flex-none font-mono2 text-[10.5px] text-dim">
            {task.status === 'in_progress' && 'in progress'}
            {task.status === 'blocked' && 'blocked'}
            {task.status === 'review' && 'in review'}
            {task.status === 'todo' && task.deadline && `due ${formatRelativeTime(task.deadline)}`}
          </span>
        </div>
      ))}
      {done.length > 0 && (
        <button
          onClick={() => setShowDone((v) => !v)}
          className="flex items-center gap-1 px-[18px] py-2 text-[11px] text-dim hover:text-mut"
          aria-expanded={showDone}
        >
          {showDone ? <ChevronDown className="h-3 w-3" /> : <ChevronRight className="h-3 w-3" />}
          {done.length} done
        </button>
      )}
      {showDone &&
        done.map((task) => (
          <p key={task.id} className="px-[18px] pb-1.5 text-xs text-dim line-through">
            {task.title}
          </p>
        ))}
    </Card>
  )
}
