import { useMemo, useState } from 'react'
import { useTasks, useUpdateTask } from './hooks/use-tasks'
import { formatDate, formatRelativeTime } from '@/lib/utils'
import { projectLabel } from '@/lib/project-label'
import { projectColor } from '@/features/deck/ui'
import { Loader2, AlertCircle, CheckCircle2, Search, RotateCcw } from 'lucide-react'
import type { Task } from '@/types/api'

type Preset = '1d' | '7d' | '30d' | 'all'

const PRESETS: { value: Preset; label: string }[] = [
  { value: '1d', label: 'Today' },
  { value: '7d', label: '7 days' },
  { value: '30d', label: '30 days' },
  { value: 'all', label: 'All' },
]

/** Bucket key for grouping done tasks under day headings. */
function dayHeading(iso: string | null): string {
  if (!iso) return 'Unknown'
  const then = new Date(iso)
  const today = new Date()
  const startOfDay = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const diffDays = Math.round((startOfDay(today) - startOfDay(then)) / 86_400_000)
  if (diffDays === 0) return 'Today'
  if (diffDays === 1) return 'Yesterday'
  return then.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })
}

export function DoneList({ tabs }: { tabs: React.ReactNode }) {
  const [preset, setPreset] = useState<Preset>('7d')
  const [search, setSearch] = useState('')
  const updateTask = useUpdateTask()

  const { data: tasks, isLoading, error } = useTasks({
    status: 'done',
    sort: 'completed',
    ...(preset === 'all' ? {} : { completed_within: preset }),
  })

  const visible = useMemo(() => {
    const q = search.trim().toLowerCase()
    const list = tasks ?? []
    return q ? list.filter((t) => t.title.toLowerCase().includes(q)) : list
  }, [tasks, search])

  const groups = useMemo(() => {
    const map = new Map<string, Task[]>()
    for (const t of visible) {
      const key = dayHeading(t.completed_at)
      const bucket = map.get(key) ?? []
      bucket.push(t)
      map.set(key, bucket)
    }
    return [...map.entries()]
  }, [visible])

  return (
    <div className="flex flex-col">
      <div className="sticky top-0 z-20 -mx-7 border-b border-line-soft bg-void px-7 pb-2.5 pt-3.5">
        <div className="flex flex-wrap items-center gap-2">
          {tabs}
          <h2 className="whitespace-nowrap font-display text-[13px] font-semibold uppercase tracking-[0.16em] text-mut">
            Done <span className="font-mono2 text-dim">{visible.length}</span>
          </h2>
          <label className="flex min-w-[130px] max-w-[240px] flex-1 items-center gap-1.5 rounded-full border border-line bg-deck px-2.5 py-1 text-dim">
            <Search className="h-3 w-3 shrink-0" />
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search done tasks…"
              aria-label="Search done tasks"
              className="w-full min-w-0 bg-transparent text-xs text-ink outline-none placeholder:text-dim"
            />
          </label>
        </div>
        <div className="mt-2 flex flex-wrap items-center gap-1.5">
          {PRESETS.map((p) => (
            <button
              key={p.value}
              onClick={() => setPreset(p.value)}
              className={`rounded-full border px-2.5 py-1 text-xs transition-colors ${
                preset === p.value
                  ? 'border-arc/50 bg-arc-soft text-arc'
                  : 'border-line text-mut hover:bg-deck-2 hover:text-ink'
              }`}
            >
              {p.label}
            </button>
          ))}
        </div>
      </div>

      {isLoading && (
        <div className="flex h-48 items-center justify-center text-dim">
          <Loader2 className="mr-2 h-5 w-5 animate-spin" /> Loading done tasks…
        </div>
      )}

      {error != null && (
        <div className="flex h-48 items-center justify-center text-bad">
          <AlertCircle className="mr-2 h-5 w-5" /> Failed to load done tasks.
        </div>
      )}

      {!isLoading && !error && (
        <div className="space-y-4 pb-24 pt-3">
          {groups.map(([heading, items]) => (
            <div key={heading}>
              <div className="mb-1.5 font-mono2 text-[11px] uppercase tracking-[0.14em] text-dim">
                {heading}
              </div>
              <div className="space-y-1.5">
                {items.map((task) => (
                  <DoneRow
                    key={task.id}
                    task={task}
                    busy={updateTask.isPending}
                    onReopen={() => updateTask.mutate({ id: task.id, status: 'todo' })}
                  />
                ))}
              </div>
            </div>
          ))}
          {visible.length === 0 && (
            <div className="flex h-48 flex-col items-center justify-center text-dim">
              <CheckCircle2 className="mb-2 h-8 w-8" />
              <span className="text-sm">
                {preset === 'all' ? 'No completed tasks yet' : 'Nothing completed in this range'}
              </span>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

function DoneRow({ task, busy, onReopen }: { task: Task; busy: boolean; onReopen: () => void }) {
  const label = projectLabel(task.project)
  const completedTime = task.completed_at
    ? new Date(task.completed_at).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
    : null

  return (
    <div className="group flex items-center gap-2.5 rounded-[10px] border border-line bg-deck px-3 py-2 shadow-[var(--card-shadow)]">
      <CheckCircle2 className="h-4 w-4 shrink-0 text-ok" />
      <div className="min-w-0 flex-1">
        <div className="truncate text-[13px] text-mut line-through decoration-line">{task.title}</div>
        <div className="mt-0.5 flex items-center gap-2 text-[11px] text-dim">
          <span className="flex items-center gap-1">
            <span
              className="inline-block h-1.5 w-1.5 rounded-full"
              style={{ background: projectColor(label) }}
            />
            {label}
          </span>
          <span title={task.completed_at ?? undefined}>
            {formatRelativeTime(task.completed_at)}
            {completedTime ? ` · ${completedTime}` : ''}
          </span>
          {task.deadline && <span>due {formatDate(task.deadline)}</span>}
        </div>
      </div>
      <button
        onClick={onReopen}
        disabled={busy}
        title="Reopen task (back to To Do)"
        className="flex shrink-0 items-center gap-1 rounded-full border border-line px-2.5 py-1 text-[11.5px] text-mut opacity-0 transition-opacity hover:bg-deck-2 hover:text-ink focus-visible:opacity-100 group-hover:opacity-100 disabled:opacity-30"
      >
        <RotateCcw className="h-3 w-3" /> Reopen
      </button>
    </div>
  )
}
