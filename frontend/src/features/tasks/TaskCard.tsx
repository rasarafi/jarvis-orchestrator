import { useRef, useState } from 'react'
import { formatDate, formatRelativeTime, cn } from '@/lib/utils'
import { isOverdue } from '@/lib/dates'
import { projectColor } from '@/features/deck/ui'
import { projectLabel } from '@/lib/project-label'
import type { Task } from '@/types/api'
import { Check, CalendarDays, ChevronDown, Trash2, X, AlertCircle } from 'lucide-react'
import { useUpdateTask, useDeleteTask } from './hooks/use-tasks'

const PRIORITY_ORDER: Task['priority'][] = ['urgent', 'high', 'medium', 'low']

const stripeColor: Record<Task['priority'], string> = {
  urgent: 'border-l-red-400',
  high: 'border-l-orange-400',
  medium: 'border-l-blue-400',
  low: 'border-l-line',
}

const priorityPill: Record<Task['priority'], string> = {
  urgent: 'bg-red-500/15 text-red-400',
  high: 'bg-orange-500/15 text-orange-400',
  medium: 'bg-blue-500/15 text-blue-400',
  low: 'bg-deck-2 text-mut',
}

const priorityDot: Record<Task['priority'], string> = {
  urgent: 'bg-red-400',
  high: 'bg-orange-400',
  medium: 'bg-blue-400',
  low: 'bg-dim',
}

const statusLabel: Record<Task['status'], string> = {
  todo: 'To Do',
  in_progress: 'In Progress',
  blocked: 'Blocked',
  review: 'In Review',
  done: 'Done',
}

interface TaskCardProps {
  task: Task
  selected: boolean
  selectMode: boolean
  onToggleSelect: (id: string) => void
}

export function TaskCard({ task, selected, selectMode, onToggleSelect }: TaskCardProps) {
  const updateTask = useUpdateTask()
  const deleteTask = useDeleteTask()
  const dateRef = useRef<HTMLInputElement>(null)
  const overdue = task.status !== 'done' && isOverdue(task.deadline)

  const [editingTitle, setEditingTitle] = useState(false)
  const [titleDraft, setTitleDraft] = useState(task.title)
  const [priorityMenu, setPriorityMenu] = useState(false)
  const [confirmingDelete, setConfirmingDelete] = useState(false)

  const commitTitle = () => {
    const next = titleDraft.trim()
    setEditingTitle(false)
    if (next && next !== task.title) updateTask.mutate({ id: task.id, title: next })
    else setTitleDraft(task.title)
  }

  const setPriority = (p: Task['priority']) => {
    setPriorityMenu(false)
    if (p !== task.priority) updateTask.mutate({ id: task.id, priority: p })
  }

  const openDatePicker = () => {
    const input = dateRef.current
    if (!input) return
    if ('showPicker' in input) input.showPicker()
    else (input as HTMLInputElement).focus()
  }

  return (
    <div
      className={cn(
        'group grid grid-cols-[26px_1fr_auto] items-start gap-x-1 rounded-[10px] border border-l-[3px] bg-deck py-2.5 pl-2 pr-3.5 shadow-[var(--card-shadow)] transition-colors',
        stripeColor[task.priority],
        selected ? 'border-y-arc/40 border-r-arc/40 bg-arc-soft' : 'border-y-line-soft border-r-line-soft hover:border-y-line hover:border-r-line',
      )}
    >
      <label className="mt-px flex h-[22px] w-[22px] items-center justify-center">
        <input
          type="checkbox"
          checked={selected}
          onChange={() => onToggleSelect(task.id)}
          aria-label={`Select task: ${task.title}`}
          className={cn(
            'h-[15px] w-[15px] cursor-pointer rounded-[5px] accent-[var(--arc)] transition-opacity',
            selected || selectMode ? 'opacity-100' : 'opacity-0 focus-visible:opacity-100 group-hover:opacity-100',
          )}
        />
      </label>

      <div className="min-w-0">
        {editingTitle ? (
          <input
            autoFocus
            value={titleDraft}
            onChange={(e) => setTitleDraft(e.target.value)}
            onBlur={commitTitle}
            onKeyDown={(e) => {
              if (e.key === 'Enter') commitTitle()
              else if (e.key === 'Escape') {
                setTitleDraft(task.title)
                setEditingTitle(false)
              }
            }}
            aria-label="Edit task title"
            className="w-full rounded-[6px] border border-arc/40 bg-deck-2 px-1.5 py-0.5 text-[13.5px] font-medium leading-[1.35] text-ink outline-none focus-visible:border-arc"
          />
        ) : (
          <button
            onClick={() => {
              setTitleDraft(task.title)
              setEditingTitle(true)
            }}
            title="Click to rename"
            className="block w-full rounded-[6px] px-1.5 py-0.5 -ml-1.5 text-left text-[13.5px] font-medium leading-[1.35] text-ink hover:bg-deck-2"
          >
            {task.title}
          </button>
        )}
        <div className="mt-1 flex flex-wrap items-center gap-2.5 pl-1.5 text-[11.5px] text-dim">
          <span className="flex items-center gap-1.5 text-mut">
            <span
              className="h-1.5 w-1.5 shrink-0 rounded-full"
              style={{ background: projectColor(projectLabel(task.project)) }}
            />
            {projectLabel(task.project)}
          </span>
          {task.status !== 'todo' && (
            <span
              className={cn(
                'rounded-full border px-1.5 py-px text-[10.5px]',
                task.status === 'review'
                  ? // Filled, not outlined — review is the one state waiting on the user,
                    // so it has to read louder than blocked's outlined amber.
                    'border-transparent bg-gold font-semibold text-void'
                  : task.status === 'blocked'
                    ? 'border-gold/40 bg-gold-soft text-gold'
                    : 'border-arc/40 bg-arc-soft text-arc',
              )}
            >
              {statusLabel[task.status]}
            </span>
          )}
          {task.deadline && (
            <span className={cn('font-mono2 text-[11px]', overdue && 'font-semibold text-gold')}>
              {formatDate(task.deadline)}
              {overdue && ' · overdue'}
            </span>
          )}
          <span>{formatRelativeTime(task.created_at)}</span>
        </div>
      </div>

      <div className="mt-px flex items-center gap-1.5">
        <select
          value={task.status}
          onChange={(e) => updateTask.mutate({ id: task.id, status: e.target.value })}
          disabled={updateTask.isPending}
          aria-label="Set status"
          className="cursor-pointer appearance-none rounded-full border border-line bg-deck px-2 py-0.5 text-[11px] text-mut opacity-0 transition-opacity focus-visible:opacity-100 group-hover:opacity-100 hover:bg-deck-2 hover:text-ink"
        >
          <option value="todo">To Do</option>
          <option value="in_progress">In Progress</option>
          <option value="blocked">Blocked</option>
          <option value="review">In Review</option>
        </select>

        <button
          onClick={openDatePicker}
          disabled={updateTask.isPending}
          aria-label="Set deadline"
          title="Set deadline"
          className="relative flex items-center gap-1 rounded-full border border-line bg-deck px-2 py-0.5 text-[11px] text-mut opacity-0 transition-opacity focus-visible:opacity-100 group-hover:opacity-100 hover:bg-deck-2 hover:text-ink"
        >
          <CalendarDays className="h-3 w-3" />
          {task.deadline ? formatDate(task.deadline) : 'Set date'}
          <input
            ref={dateRef}
            type="date"
            tabIndex={-1}
            aria-hidden="true"
            defaultValue={task.deadline?.slice(0, 10) ?? ''}
            onChange={(e) => e.target.value && updateTask.mutate({ id: task.id, deadline: e.target.value })}
            className="pointer-events-none absolute inset-0 opacity-0"
          />
        </button>

        <div className="relative">
          <button
            onClick={() => setPriorityMenu((v) => !v)}
            disabled={updateTask.isPending}
            aria-haspopup="menu"
            aria-expanded={priorityMenu}
            title="Set priority"
            aria-label={`Priority: ${task.priority}. Click to change.`}
            className={cn(
              'flex items-center gap-1 rounded-full px-2 py-0.5 text-[10.5px] font-semibold uppercase tracking-[0.04em]',
              priorityPill[task.priority],
            )}
          >
            {task.priority}
            <ChevronDown className="h-2.5 w-2.5 opacity-70" />
          </button>
          {priorityMenu && (
            <>
              <button
                aria-hidden="true"
                tabIndex={-1}
                onClick={() => setPriorityMenu(false)}
                className="fixed inset-0 z-40 cursor-default"
              />
              <div
                role="menu"
                className="absolute right-0 top-[calc(100%+6px)] z-50 rounded-[10px] border border-line bg-deck p-1 shadow-[var(--pill-shadow)]"
              >
                {PRIORITY_ORDER.map((p) => (
                  <button
                    key={p}
                    role="menuitemradio"
                    aria-checked={p === task.priority}
                    onClick={() => setPriority(p)}
                    className={cn(
                      'flex w-full items-center gap-2 rounded-[7px] px-2.5 py-1.5 text-left text-xs capitalize hover:bg-deck-2 hover:text-ink',
                      p === task.priority ? 'text-ink' : 'text-mut',
                    )}
                  >
                    <span className={`inline-block h-1.5 w-1.5 rounded-full ${priorityDot[p]}`} />
                    {p}
                    {p === task.priority && <Check className="ml-auto h-3 w-3 text-arc" />}
                  </button>
                ))}
              </div>
            </>
          )}
        </div>

        <button
          onClick={() => updateTask.mutate({ id: task.id, status: 'done' })}
          disabled={updateTask.isPending}
          aria-label="Mark task done"
          title="Mark task done"
          className="grid h-6 w-6 place-content-center rounded-[7px] border border-line text-transparent opacity-0 transition-opacity focus-visible:opacity-100 group-hover:opacity-100 hover:border-ok/60 hover:bg-ok/10 hover:text-ok"
        >
          <Check className="h-3.5 w-3.5" />
        </button>

        {confirmingDelete ? (
          <span className="flex items-center gap-1">
            <button
              onClick={() => deleteTask.mutate(task.id)}
              disabled={deleteTask.isPending}
              aria-label="Confirm delete task"
              className="flex items-center gap-1 rounded-[7px] bg-red-500/15 px-2 py-1 text-[11px] font-semibold text-red-400 hover:bg-red-500/25 disabled:opacity-50"
            >
              {deleteTask.isError ? (
                <>
                  <AlertCircle className="h-3 w-3" /> Retry
                </>
              ) : deleteTask.isPending ? (
                'Deleting…'
              ) : (
                'Delete'
              )}
            </button>
            <button
              onClick={() => {
                deleteTask.reset()
                setConfirmingDelete(false)
              }}
              disabled={deleteTask.isPending}
              aria-label="Cancel delete"
              className="grid h-6 w-6 place-content-center rounded-[7px] border border-line text-dim hover:bg-deck-2 hover:text-ink"
            >
              <X className="h-3.5 w-3.5" />
            </button>
          </span>
        ) : (
          <button
            onClick={() => setConfirmingDelete(true)}
            aria-label="Delete task"
            title="Delete task"
            className="grid h-6 w-6 place-content-center rounded-[7px] border border-line text-transparent opacity-0 transition-opacity focus-visible:opacity-100 group-hover:opacity-100 hover:border-red-400/60 hover:bg-red-500/10 hover:text-red-400"
          >
            <Trash2 className="h-3.5 w-3.5" />
          </button>
        )}
      </div>
    </div>
  )
}
