import { Check } from 'lucide-react'
import type { LiveSession } from '@/types/live-sessions'
import type { Project, Task } from '@/types/api'
import { useUpdateTask } from '@/features/tasks/hooks/use-tasks'
import { sessionNeedsHuman } from './needs'

export interface Lane {
  key: string
  name: string
  slug: string | null
  sessions: LiveSession[]
  nextTask: Task | null
  lastSessionAt: string | null
}

/** Groups live sessions into per-project lanes and picks parked (active, no session) projects. */
export function buildLanes(
  sessions: LiveSession[] | undefined,
  projects: Project[] | undefined,
): { live: Lane[]; parked: Lane[] } {
  const bySlug = new Map<string, Project>()
  for (const p of projects ?? []) {
    bySlug.set(p.slug, p)
  }

  // Key by slug when the backend matched a project, else by project_dir (the
  // encoded absolute repo path) — never by basename, which collides across
  // unrelated repos named "backend"/"api"/etc.
  const grouped = new Map<string, LiveSession[]>()
  for (const s of sessions ?? []) {
    const key = s.project_slug ?? s.project_dir
    grouped.set(key, [...(grouped.get(key) ?? []), s])
  }

  const live: Lane[] = []
  const liveProjectIds = new Set<string>()
  for (const [key, group] of grouped) {
    // Slug-only join: the backend sets project_slug whenever cwd matched a
    // known project, so a name-based fallback could only ever join wrongly.
    const project = group[0].project_slug ? bySlug.get(group[0].project_slug) : undefined
    if (project) liveProjectIds.add(project.id)
    live.push({
      key,
      name: project?.name ?? group[0].project_name,
      slug: project?.slug ?? null,
      sessions: group,
      nextTask: project?.tasks?.[0] ?? null,
      lastSessionAt: project?.last_session_at ?? null,
    })
  }

  // Amber lanes first, then most recent activity.
  live.sort((a, b) => {
    const an = a.sessions.some(sessionNeedsHuman) ? 0 : 1
    const bn = b.sessions.some(sessionNeedsHuman) ? 0 : 1
    return an - bn || (b.sessions[0]?.last_activity_at ?? '').localeCompare(a.sessions[0]?.last_activity_at ?? '')
  })

  // Projects come from the API ordered by last_session_at desc already.
  const parked: Lane[] = (projects ?? [])
    .filter((p) => p.status === 'active' && !liveProjectIds.has(p.id))
    .slice(0, 4)
    .map((p) => ({
      key: p.slug,
      name: p.name,
      slug: p.slug,
      sessions: [],
      nextTask: p.tasks?.[0] ?? null,
      lastSessionAt: p.last_session_at,
    }))

  return { live, parked }
}

export function NextTaskRow({ task, compact = false }: { task: Task; compact?: boolean }) {
  const updateTask = useUpdateTask()
  return (
    <div
      className={`flex items-center gap-2 border-t border-dashed border-line-soft ${
        compact ? 'pt-2' : 'px-4 py-2'
      }`}
    >
      <button
        onClick={() => updateTask.mutate({ id: task.id, status: 'done' })}
        disabled={updateTask.isPending}
        aria-label="Mark task done"
        title="Mark task done"
        className="flex h-4 w-4 shrink-0 items-center justify-center rounded-[5px] border border-line text-transparent transition-colors hover:border-arc/50 hover:text-arc/40"
      >
        <Check className="h-3 w-3" />
      </button>
      <span className="min-w-0 truncate text-[12.5px] text-mut">
        <span className="font-mono2 text-[10.5px] text-dim">next · </span>
        {task.title}
      </span>
    </div>
  )
}
