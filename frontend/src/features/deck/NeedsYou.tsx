import { useNavigate } from 'react-router-dom'
import type { LiveSession } from '@/types/live-sessions'
import type { Task, Interrupt } from '@/types/api'
import { isOverdue, formatDuration, secondsSince } from '@/lib/dates'
import { projectLabel } from '@/lib/project-label'
import { useUpdateTask } from '@/features/tasks/hooks/use-tasks'
import { useInterrupts, useResolveInterrupt } from '@/features/interrupts/hooks/use-interrupts'
import { sessionNeedsHuman } from './needs'
import { Card, CardHeader } from './ui'

interface NeedRow {
  key: string
  icon: string
  title: string
  meta: string
  /** Where clicking the row goes. Rows without one are informational. */
  onClick?: () => void
  /** Rule: every amber item that CAN carry its one obvious action, does. */
  action?: { label: string; run: () => void }
}

export function buildNeeds(
  sessions: LiveSession[] | undefined,
  tasks: Task[] | undefined,
  interrupts: Interrupt[] | undefined,
  handlers: {
    navigate: (to: string) => void
    markDone: (id: string) => void
    resolveInterrupt: (id: string) => void
  },
): NeedRow[] {
  const { navigate, markDone, resolveInterrupt } = handlers
  const rows: NeedRow[] = []

  for (const s of (sessions?.filter(sessionNeedsHuman) ?? []).slice(0, 3)) {
    const idle = secondsSince(s.last_activity_at)
    rows.push({
      key: `sess-${s.session_id}`,
      icon: '?',
      title:
        s.attention?.kind === 'permission'
          ? `${s.project_name} needs permission`
          : `${s.project_name} session is waiting on you`,
      meta:
        s.attention?.message ??
        `${s.git_branch ?? s.project_dir} · waiting ${idle != null ? formatDuration(idle) : ''}`,
      onClick: () => navigate('/'),
    })
  }

  for (const i of (interrupts ?? []).filter((i) => i.status === 'active').slice(0, 2)) {
    rows.push({
      key: `int-${i.id}`,
      icon: '⚡',
      title: `Interrupted: ${i.was_working_on}`,
      meta: `${projectLabel(i.project)}${i.resume_instructions ? ` · ${i.resume_instructions}` : ''}`,
      action: { label: 'Resolve', run: () => resolveInterrupt(i.id) },
    })
  }

  const open = (tasks ?? []).filter((t) => t.status !== 'done')
  for (const t of open.filter((t) => isOverdue(t.deadline)).slice(0, 3)) {
    rows.push({
      key: `over-${t.id}`,
      icon: '!',
      title: t.title,
      meta: `${projectLabel(t.project)} · overdue since ${new Date(t.deadline!).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}`,
      onClick: () => navigate('/tasks'),
      action: { label: 'Done', run: () => markDone(t.id) },
    })
  }

  for (const t of open.filter((t) => t.status === 'blocked').slice(0, 2)) {
    rows.push({
      key: `blk-${t.id}`,
      icon: '✕',
      title: t.title,
      meta: `${projectLabel(t.project)} · blocked${t.blocker_reason ? ` — ${t.blocker_reason}` : ''}`,
      onClick: () => navigate('/tasks'),
    })
  }

  return rows.slice(0, 6)
}

export function NeedsYou({
  sessions,
  tasks,
}: {
  sessions: LiveSession[] | undefined
  tasks: Task[] | undefined
}) {
  const navigate = useNavigate()
  const { data: interrupts } = useInterrupts()
  const updateTask = useUpdateTask()
  const resolveInterrupt = useResolveInterrupt()

  const rows = buildNeeds(sessions, tasks, interrupts, {
    navigate,
    markDone: (id) => updateTask.mutate({ id, status: 'done' }),
    resolveInterrupt: (id) => resolveInterrupt.mutate(id),
  })

  return (
    <Card>
      <CardHeader title="Needs you" count={rows.length ? String(rows.length) : undefined} />

      {rows.length === 0 && (
        <div className="px-4 py-5 text-[12.5px] text-dim">
          Nothing is waiting on you. Amber items land here when they do.
        </div>
      )}

      <div className="max-h-[264px] overflow-y-auto [scrollbar-width:thin]">
      {rows.map((row) => (
        <div
          key={row.key}
          onClick={row.onClick}
          role={row.onClick ? 'button' : undefined}
          tabIndex={row.onClick ? 0 : undefined}
          onKeyDown={(e) => {
            if (row.onClick && (e.key === 'Enter' || e.key === ' ')) {
              if (e.key === ' ') e.preventDefault()
              row.onClick()
            }
          }}
          className={`flex w-full items-center gap-3 border-b border-l-[3px] border-line-soft border-l-gold px-4 py-[11px] text-left transition-colors last:border-b-0 ${row.onClick ? 'cursor-pointer hover:bg-gold-soft' : ''}`}
        >
          <span className="pt-px font-mono2 text-xs text-gold">{row.icon}</span>
          <span className="min-w-0 flex-1">
            <span className="block truncate text-[13px] text-ink">{row.title}</span>
            <span className="block truncate font-mono2 text-[11.5px] text-dim">{row.meta}</span>
          </span>
          {row.action && (
            <button
              onClick={(e) => {
                e.stopPropagation()
                row.action!.run()
              }}
              onKeyDown={(e) => e.stopPropagation()}
              className="shrink-0 rounded-md border border-gold/45 px-2 py-1 font-mono2 text-[10.5px] text-gold transition-colors hover:bg-gold/15"
            >
              {row.action.label}
            </button>
          )}
        </div>
      ))}
      </div>

    </Card>
  )
}
