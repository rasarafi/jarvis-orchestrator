import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTasks, useBulkUpdateTasks, useBulkArchiveTasks, useBulkDeleteTasks } from './hooks/use-tasks'
import { TaskCard } from './TaskCard'
import { DoneList } from './DoneList'
import { ProjectCombobox, type ProjectOption } from '@/components/ProjectCombobox'
import { isOverdue } from '@/lib/dates'
import { projectLabel } from '@/lib/project-label'
import { GROUP_LABEL, GROUP_ORDER, buildGroupedOptions, type ProjectGroupKey } from '@/lib/project-groups'
import { projectColor } from '@/features/deck/ui'
import { cn } from '@/lib/utils'
import { Loader2, AlertCircle, Inbox, Search, X, CalendarDays, GitCommitHorizontal, FolderTree, ChevronRight } from 'lucide-react'
import type { Task } from '@/types/api'

type Sort = 'smart' | 'deadline' | 'newest'
type View = 'open' | 'review' | 'done'

const GROUP_KEY = 'jarvis.tasks.groupByProject'

const PRIORITY_ORDER: Task['priority'][] = ['urgent', 'high', 'medium', 'low']

const priorityDot: Record<Task['priority'], string> = {
  urgent: 'bg-red-400',
  high: 'bg-orange-400',
  medium: 'bg-blue-400',
  low: 'bg-dim',
}

export function TaskBoard() {
  const { data: tasks, isLoading, error } = useTasks()
  const [searchParams, setSearchParams] = useSearchParams()
  const [view, setView] = useState<View>(() => {
    const v = searchParams.get('view')
    return v === 'done' || v === 'review' ? v : 'open'
  })
  const [search, setSearch] = useState('')
  const [project, setProject] = useState<ProjectOption | null>(() => {
    const group = searchParams.get('group') as ProjectGroupKey | null
    if (group && GROUP_ORDER.includes(group)) {
      return { name: GROUP_LABEL[group], count: 0, groupKey: group }
    }
    const name = searchParams.get('project')
    return name ? { name, count: 0 } : null
  })
  const [priorities, setPriorities] = useState<Set<Task['priority']>>(new Set())
  const [overdueOnly, setOverdueOnly] = useState(false)
  const [hideBlocked, setHideBlocked] = useState(false)
  const [groupByProject, setGroupByProject] = useState(() => localStorage.getItem(GROUP_KEY) === '1')
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set())
  const [sort, setSort] = useState<Sort>('smart')
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set())

  // Group headers freeze just below the sticky filter bar; its height varies with
  // wrapping, so measure it and offset the sticky headers by it.
  const filterBarRef = useRef<HTMLDivElement>(null)
  const [filterBarH, setFilterBarH] = useState(0)
  useEffect(() => {
    const el = filterBarRef.current
    if (!el) return
    const measure = () => setFilterBarH(el.offsetHeight)
    measure()
    const ro = new ResizeObserver(measure)
    ro.observe(el)
    return () => ro.disconnect()
  }, [isLoading, error])

  useEffect(() => {
    localStorage.setItem(GROUP_KEY, groupByProject ? '1' : '0')
  }, [groupByProject])

  const reviewCount = useMemo(
    () => (tasks ?? []).filter((t) => t.status === 'review').length,
    [tasks],
  )

  // The board shows one status pool at a time; every filter below derives from it,
  // so the Review tab reuses the whole search / project / priority pipeline.
  const open = useMemo(
    () =>
      (tasks ?? []).filter((t) =>
        view === 'review' ? t.status === 'review' : t.status !== 'done' && t.status !== 'review',
      ),
    [tasks, view],
  )

  const projects = useMemo(() => {
    // Task counts per project label, remembering each label's group.
    const counts = new Map<string, { count: number; group: ProjectGroupKey | null }>()
    for (const t of open) {
      const name = projectLabel(t.project)
      const entry = counts.get(name) ?? { count: 0, group: t.project?.group_key ?? null }
      entry.count += 1
      counts.set(name, entry)
    }

    return buildGroupedOptions(counts)
  }, [open])

  // Everything the search + project filters allow. The chips below filter this
  // set further, so their counts are computed from it — not from all open tasks.
  const scoped = useMemo(() => {
    let list = open
    const q = search.trim().toLowerCase()
    if (q) list = list.filter((t) => t.title.toLowerCase().includes(q))
    if (project) {
      list = project.groupKey
        ? list.filter((t) => t.project?.group_key === project.groupKey)
        : list.filter((t) => projectLabel(t.project) === project.name)
    }
    return list
  }, [open, search, project])

  const overdueCount = useMemo(() => scoped.filter((t) => isOverdue(t.deadline)).length, [scoped])

  const priorityCounts = useMemo(() => {
    const counts = new Map<Task['priority'], number>()
    for (const t of scoped) counts.set(t.priority, (counts.get(t.priority) ?? 0) + 1)
    return counts
  }, [scoped])

  const visible = useMemo(() => {
    let list = scoped
    if (priorities.size > 0) list = list.filter((t) => priorities.has(t.priority))
    if (overdueOnly) list = list.filter((t) => isOverdue(t.deadline))
    if (hideBlocked) list = list.filter((t) => t.status !== 'blocked')

    const byDeadline = (a: Task, b: Task) => {
      if (!a.deadline && !b.deadline) return 0
      if (!a.deadline) return 1
      if (!b.deadline) return -1
      return a.deadline.localeCompare(b.deadline)
    }
    return [...list].sort((a, b) => {
      if (sort === 'newest') return b.created_at.localeCompare(a.created_at)
      if (sort === 'deadline') return byDeadline(a, b)
      return (
        PRIORITY_ORDER.indexOf(a.priority) - PRIORITY_ORDER.indexOf(b.priority) || byDeadline(a, b)
      )
    })
  }, [scoped, priorities, overdueOnly, hideBlocked, sort])

  // Cluster the already-sorted list by project. Map insertion order preserves
  // first-appearance, so groups inherit whatever sort is active.
  const groups = useMemo(() => {
    const map = new Map<string, Task[]>()
    for (const t of visible) {
      const label = projectLabel(t.project)
      const arr = map.get(label)
      if (arr) arr.push(t)
      else map.set(label, [t])
    }
    return [...map.entries()].map(([label, tasks]) => ({ label, tasks }))
  }, [visible])

  const togglePriority = (p: Task['priority']) => {
    setPriorities((prev) => {
      const next = new Set(prev)
      if (next.has(p)) next.delete(p)
      else next.add(p)
      return next
    })
  }

  const toggleSelect = (id: string) => {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const toggleCollapse = (label: string) => {
    setCollapsed((prev) => {
      const next = new Set(prev)
      if (next.has(label)) next.delete(label)
      else next.add(label)
      return next
    })
  }

  const changeView = (v: View) => {
    setView(v)
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (v === 'open') next.delete('view')
        else next.set('view', v)
        return next
      },
      { replace: true },
    )
  }

  const renderCard = (task: Task) => (
    <TaskCard
      key={task.id}
      task={task}
      selected={selectedIds.has(task.id)}
      selectMode={selectedIds.size > 0}
      onToggleSelect={toggleSelect}
    />
  )

  const tabs = <ViewTabs view={view} onChange={changeView} reviewCount={reviewCount} />

  if (view === 'done') {
    return <DoneList tabs={tabs} />
  }

  if (isLoading) {
    return (
      <div className="flex h-48 items-center justify-center text-dim">
        <Loader2 className="mr-2 h-5 w-5 animate-spin" /> Loading tasks…
      </div>
    )
  }

  if (error) {
    return (
      <div className="flex h-48 items-center justify-center text-bad">
        <AlertCircle className="mr-2 h-5 w-5" /> Failed to load tasks.
      </div>
    )
  }

  return (
    <div className="flex flex-col">
      <div ref={filterBarRef} className="sticky top-0 z-20 -mx-7 border-b border-line-soft bg-void px-7 pb-2.5 pt-3.5">
        <div className="flex flex-wrap items-center gap-2">
          {tabs}
          <h2 className="whitespace-nowrap font-display text-[13px] font-semibold uppercase tracking-[0.16em] text-mut">
            {view === 'review' ? 'In review' : 'Open tasks'}{' '}
            <span className="font-mono2 text-dim">{visible.length}</span>
          </h2>
          <label className="flex min-w-[130px] max-w-[240px] flex-1 items-center gap-1.5 rounded-full border border-line bg-deck px-2.5 py-1 text-dim">
            <Search className="h-3 w-3 shrink-0" />
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search tasks…"
              aria-label="Search tasks"
              className="w-full min-w-0 bg-transparent text-xs text-ink outline-none placeholder:text-dim"
            />
          </label>
          <ProjectCombobox projects={projects} value={project?.name ?? null} onChange={setProject} />
          <select
            value={sort}
            onChange={(e) => setSort(e.target.value as Sort)}
            aria-label="Sort order"
            className="cursor-pointer appearance-none rounded-full border border-line bg-deck px-2.5 py-1 text-xs text-mut"
          >
            <option value="smart">Priority → deadline</option>
            <option value="deadline">Deadline</option>
            <option value="newest">Newest</option>
          </select>
          <Link
            to={`/tasks/sweep${
              project?.groupKey
                ? `?group=${project.groupKey}`
                : project
                  ? (() => {
                      const slug = open.find((t) => projectLabel(t.project) === project.name)?.project?.slug
                      return slug ? `?project=${slug}` : ''
                    })()
                  : ''
            }`}
            title="Match recent git commits against open tasks"
            className="flex items-center gap-1.5 rounded-full border border-line bg-deck px-2.5 py-1 text-xs text-mut hover:bg-deck-2 hover:text-ink"
          >
            <GitCommitHorizontal className="h-3 w-3" /> Sweep
          </Link>
        </div>

        <div className="mt-2 flex flex-wrap items-center gap-1.5">
          {(overdueCount > 0 || overdueOnly) && (
            <Chip active={overdueOnly} gold onClick={() => setOverdueOnly((v) => !v)}>
              Overdue <span className="font-mono2 text-[10.5px] opacity-75">{overdueCount}</span>
            </Chip>
          )}
          {(overdueCount > 0 || overdueOnly) && <span className="mx-0.5 h-4 w-px bg-line" />}
          {/* Keep an active chip visible at zero so it stays toggleable off. */}
          {PRIORITY_ORDER.filter((p) => priorityCounts.has(p) || priorities.has(p)).map((p) => (
            <Chip key={p} active={priorities.has(p)} onClick={() => togglePriority(p)}>
              <span className={`inline-block h-1.5 w-1.5 rounded-full ${priorityDot[p]}`} />
              <span className="capitalize">{p}</span>
              <span className="font-mono2 text-[10.5px] opacity-75">{priorityCounts.get(p) ?? 0}</span>
            </Chip>
          ))}
          <span className="mx-0.5 h-4 w-px bg-line" />
          <Chip active={hideBlocked} onClick={() => setHideBlocked((v) => !v)}>
            Hide blocked
          </Chip>
          <Chip active={groupByProject} onClick={() => setGroupByProject((v) => !v)}>
            <FolderTree className="h-3 w-3" />
            Group by project
          </Chip>
        </div>
      </div>

      <div className={cn('pb-24 pt-3', groupByProject ? 'space-y-5' : 'space-y-2')}>
        {visible.length === 0 ? (
          open.length === 0 ? (
            <div className="flex h-48 flex-col items-center justify-center text-dim">
              <Inbox className="mb-2 h-8 w-8" />
              <span className="text-sm">
                {view === 'review' ? 'Nothing waiting on review' : 'No open tasks'}
              </span>
            </div>
          ) : (
            <div className="py-8 text-center text-[13px] text-dim">Nothing matches this filter.</div>
          )
        ) : groupByProject ? (
          groups.map((g) => {
            const isCollapsed = collapsed.has(g.label)
            return (
              <section key={g.label}>
                <button
                  type="button"
                  onClick={() => toggleCollapse(g.label)}
                  aria-expanded={!isCollapsed}
                  style={{ top: filterBarH }}
                  className="group/hd sticky z-10 flex w-full items-center gap-2 bg-void py-2 pl-1 text-left"
                >
                  <ChevronRight
                    className={cn(
                      'h-3.5 w-3.5 shrink-0 text-dim transition-transform motion-reduce:transition-none',
                      !isCollapsed && 'rotate-90',
                    )}
                  />
                  <span
                    className="h-2 w-2 shrink-0 rounded-full"
                    style={{ background: projectColor(g.label) }}
                  />
                  <h3 className="min-w-0 truncate font-display text-[12px] font-semibold uppercase tracking-[0.14em] text-mut group-hover/hd:text-ink">
                    {g.label}
                  </h3>
                  <span className="font-mono2 text-[11px] text-dim">{g.tasks.length}</span>
                </button>
                {!isCollapsed && (
                  <div className="mt-2 space-y-2 pl-4">{g.tasks.map(renderCard)}</div>
                )}
              </section>
            )
          })
        ) : (
          visible.map(renderCard)
        )}
      </div>

      <BulkBar view={view} selectedIds={selectedIds} onClear={() => setSelectedIds(new Set())} />
    </div>
  )
}

function ViewTabs({
  view,
  onChange,
  reviewCount,
}: {
  view: View
  onChange: (v: View) => void
  reviewCount: number
}) {
  return (
    <div role="tablist" aria-label="Task view" className="flex items-center gap-0.5 rounded-full border border-line bg-deck p-0.5">
      {(['open', 'review', 'done'] as const).map((v) => (
        <button
          key={v}
          role="tab"
          aria-selected={view === v}
          onClick={() => onChange(v)}
          className={cn(
            'rounded-full px-3 py-0.5 text-xs capitalize transition-colors',
            view === v ? 'bg-arc-soft font-semibold text-arc' : 'text-dim hover:text-ink',
          )}
        >
          {v}
          {v === 'review' && reviewCount > 0 && (
            <span className="ml-1 font-mono2 text-[10.5px] opacity-75">{reviewCount}</span>
          )}
        </button>
      ))}
    </div>
  )
}

function BulkBar({
  view,
  selectedIds,
  onClear,
}: {
  view: View
  selectedIds: Set<string>
  onClear: () => void
}) {
  const bulkUpdate = useBulkUpdateTasks()
  const bulkArchive = useBulkArchiveTasks()
  const bulkDelete = useBulkDeleteTasks()
  const [priorityMenu, setPriorityMenu] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const dateRef = useRef<HTMLInputElement>(null)
  const ids = [...selectedIds]
  const busy = bulkUpdate.isPending || bulkArchive.isPending || bulkDelete.isPending
  const failed = bulkUpdate.isError || bulkArchive.isError || bulkDelete.isError

  // Reset the delete confirmation whenever the selection changes.
  useEffect(() => setConfirmDelete(false), [selectedIds])

  const run = (data: { status?: string; priority?: string; deadline?: string }) => {
    setPriorityMenu(false)
    setConfirmDelete(false)
    bulkUpdate.mutate({ ids, ...data }, { onSuccess: onClear })
  }

  const openDatePicker = () => {
    const input = dateRef.current
    if (!input) return
    if ('showPicker' in input) input.showPicker()
    else (input as HTMLInputElement).focus()
  }

  return (
    <div
      role="toolbar"
      aria-label="Bulk actions"
      aria-hidden={selectedIds.size === 0}
      className={cn(
        'fixed bottom-6 left-1/2 z-30 flex -translate-x-1/2 items-center gap-1 whitespace-nowrap rounded-full border border-line bg-deck py-1.5 pl-4 pr-2.5 shadow-[var(--pill-shadow)] transition-transform motion-reduce:transition-none',
        selectedIds.size > 0 ? 'translate-y-0' : 'pointer-events-none translate-y-24',
      )}
    >
      {/* A failed bulk write used to leave the bar silent — surface it instead. */}
      {failed ? (
        <span className="mr-2 flex items-center gap-1 font-mono2 text-xs text-bad">
          <AlertCircle className="h-3 w-3" /> Action failed
        </span>
      ) : (
        <span className="mr-2 font-mono2 text-xs text-arc">{selectedIds.size} selected</span>
      )}
      <BulkButton primary disabled={busy} onClick={() => run({ status: 'done' })}>
        Mark done
      </BulkButton>
      {/* Re-reviewing something already in review is a no-op, so that slot sends it back instead. */}
      {view === 'review' ? (
        <BulkButton disabled={busy} onClick={() => run({ status: 'todo' })}>
          Send back
        </BulkButton>
      ) : (
        <BulkButton disabled={busy} onClick={() => run({ status: 'review' })}>
          Send to review
        </BulkButton>
      )}
      <BulkButton disabled={busy} onClick={() => bulkArchive.mutate(ids, { onSuccess: onClear })}>
        Archive
      </BulkButton>
      <div className="relative">
        <BulkButton disabled={busy} onClick={() => setPriorityMenu((v) => !v)}>
          Priority ▾
        </BulkButton>
        {priorityMenu && (
          <div className="absolute bottom-[calc(100%+8px)] left-0 z-40 rounded-[10px] border border-line bg-deck p-1 shadow-[var(--pill-shadow)]">
            {PRIORITY_ORDER.map((p) => (
              <button
                key={p}
                onClick={() => run({ priority: p })}
                className="flex w-full items-center gap-2 rounded-[7px] px-2.5 py-1.5 text-left text-xs capitalize text-mut hover:bg-deck-2 hover:text-ink"
              >
                <span className={`inline-block h-1.5 w-1.5 rounded-full ${priorityDot[p]}`} />
                {p}
              </button>
            ))}
          </div>
        )}
      </div>
      <BulkButton disabled={busy} onClick={openDatePicker}>
        <span className="relative flex items-center gap-1">
          <CalendarDays className="h-3 w-3" />
          Deadline
          <input
            ref={dateRef}
            type="date"
            tabIndex={-1}
            aria-hidden="true"
            onChange={(e) => e.target.value && run({ deadline: e.target.value })}
            className="pointer-events-none absolute inset-0 opacity-0"
          />
        </span>
      </BulkButton>
      <button
        onClick={() => {
          setPriorityMenu(false)
          if (confirmDelete) bulkDelete.mutate(ids, { onSuccess: onClear })
          else setConfirmDelete(true)
        }}
        disabled={busy}
        className={cn(
          'rounded-full px-3 py-1 text-[12.5px] disabled:opacity-50',
          confirmDelete
            ? 'bg-red-500/15 font-semibold text-red-400 hover:bg-red-500/25'
            : 'text-mut hover:bg-deck-2 hover:text-red-400',
        )}
      >
        {confirmDelete ? `Delete ${ids.length}?` : 'Delete'}
      </button>
      <span className="mx-1 h-[18px] w-px bg-line" />
      <button
        onClick={onClear}
        aria-label="Clear selection"
        className="rounded-full px-2 py-1 text-dim hover:bg-deck-2 hover:text-ink"
      >
        <X className="h-3.5 w-3.5" />
      </button>
    </div>
  )
}

function BulkButton({
  children,
  primary,
  disabled,
  onClick,
}: {
  children: React.ReactNode
  primary?: boolean
  disabled?: boolean
  onClick: () => void
}) {
  return (
    <button
      onClick={onClick}
      disabled={disabled}
      className={cn(
        'rounded-full px-3 py-1 text-[12.5px] disabled:opacity-50',
        primary
          ? 'bg-arc-soft font-semibold text-arc hover:bg-arc/20'
          : 'text-mut hover:bg-deck-2 hover:text-ink',
      )}
    >
      {children}
    </button>
  )
}

function Chip({
  children,
  active,
  gold,
  onClick,
}: {
  children: React.ReactNode
  active: boolean
  gold?: boolean
  onClick: () => void
}) {
  return (
    <button
      onClick={onClick}
      className={`flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors ${
        active
          ? gold
            ? 'border-gold/60 bg-gold-soft text-gold'
            : 'border-arc/50 bg-arc-soft text-arc'
          : gold
            ? 'border-gold/30 text-gold/80 hover:bg-gold-soft'
            : 'border-line text-mut hover:bg-deck-2 hover:text-ink'
      }`}
    >
      {children}
    </button>
  )
}
