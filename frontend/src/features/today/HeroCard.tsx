import { useQueryClient } from '@tanstack/react-query'
import { ProjectChip } from '@/components/ProjectChip'
import { formatClock, formatDuration, timeToMinutes } from './lib/format'
import { useStartFocus, useEndFocus, useReschedule } from './hooks/use-focus-session'
import { useUpdateTask } from '@/features/tasks/hooks/use-tasks'
import type { ScheduledTask, FocusSession } from '@/types/today'

interface HeroCardProps {
  current: ScheduledTask | null
  projectColors: Map<string, string>
  now: Date
  focusSession: FocusSession | null
  hasEveningBlock: boolean
}

export function HeroCard({ current, projectColors, now, focusSession, hasEveningBlock }: HeroCardProps) {
  const qc = useQueryClient()
  const startFocus = useStartFocus()
  const endFocus = useEndFocus()
  const reschedule = useReschedule()
  const markDone = useUpdateTask()

  if (!current) {
    return (
      <div className="bg-deck border border-line-soft rounded-2xl p-7 flex items-center justify-center text-dim">
        Nothing scheduled right now.
      </div>
    )
  }

  const task = current.task
  const projectSlug = task.project?.slug ?? null
  const projectColor = projectSlug ? projectColors.get(projectSlug) : undefined

  const startMin = timeToMinutes(current.start_time)
  const endMin = timeToMinutes(current.end_time)
  const totalMin = Math.max(1, endMin - startMin)
  const nowMin = now.getHours() * 60 + now.getMinutes()
  const elapsedMin = Math.max(0, Math.min(totalMin, nowMin - startMin))
  const remainingMin = Math.max(0, totalMin - elapsedMin)
  const progressPct = (elapsedMin / totalMin) * 100

  const remH = Math.floor(remainingMin / 60)
  const remM = remainingMin % 60
  const countdown = `${String(remH).padStart(2, '0')}:${String(remM).padStart(2, '0')}`

  const sessionIsForCurrent = focusSession != null && focusSession.task_id === current.task_id

  let focusTimer: string | null = null
  if (focusSession && sessionIsForCurrent) {
    const focusElapsedSec = Math.max(
      0,
      Math.round((now.getTime() - new Date(focusSession.started_at).getTime()) / 1000),
    )
    const focusMin = Math.floor(focusElapsedSec / 60)
    const focusSec = focusElapsedSec % 60
    focusTimer = `${String(focusMin).padStart(2, '0')}:${String(focusSec).padStart(2, '0')}`
  }

  const handleDoneEarly = () => {
    if (focusSession && sessionIsForCurrent) endFocus.mutate(focusSession)
    if (current) {
      markDone.mutate(
        { id: current.task_id, status: 'done' },
        { onSuccess: () => qc.invalidateQueries({ queryKey: ['today'] }) },
      )
    }
  }

  const doneEarlyPending = endFocus.isPending || markDone.isPending

  return (
    <div
      className="relative overflow-hidden rounded-2xl border p-6 pt-5"
      style={{
        background: projectColor
          ? `linear-gradient(135deg, ${projectColor}1f, ${projectColor}07)`
          : 'linear-gradient(135deg, rgba(59,130,246,0.13), rgba(59,130,246,0.04))',
        borderColor: projectColor ? `${projectColor}59` : 'rgba(59,130,246,0.35)',
      }}
    >
      <div
        className="absolute top-0 left-0 bottom-0 w-1"
        style={{ background: projectColor ?? '#3b82f6' }}
      />
      <div className="text-[10px] uppercase tracking-wider text-dim font-semibold mb-1">
        Right Now
      </div>
      <div className="text-[26px] font-semibold tracking-tight leading-[1.2] mb-4 text-ink">
        {task.title}
      </div>
      <div className="flex items-center gap-2.5 mb-2">
        {task.project && (
          <ProjectChip name={task.project.name} color={projectColor} />
        )}
        {task.priority && (
          <span className="text-[10px] px-2 py-0.5 rounded-full bg-deck-2 text-mut">
            {task.priority}
          </span>
        )}
        <span className="text-[10px] px-2 py-0.5 rounded-full bg-deck-2 text-mut">
          est {formatDuration(current.start_time, current.end_time)}
        </span>
      </div>

      <div className="flex items-baseline gap-3.5 mb-1.5 mt-5">
        <span className="text-[38px] font-semibold tracking-tighter font-mono text-ink">
          Ends {formatClock(current.end_time)}
        </span>
        <span className="text-xs text-dim">in this block</span>
      </div>
      <div className="text-xs text-dim mb-4">
        <span className="text-mut font-medium font-mono">{countdown}</span> remaining of{' '}
        {totalMin} min
      </div>

      <div className="h-1.5 bg-deck-2 rounded-full overflow-hidden mb-4">
        <div
          className="h-full rounded-full"
          style={{
            width: `${progressPct}%`,
            background: projectColor
              ? `linear-gradient(90deg, ${projectColor}, ${projectColor})`
              : 'linear-gradient(90deg, #3b82f6, #60a5fa)',
          }}
        />
      </div>

      <div className="flex gap-2 items-center flex-wrap">
        {sessionIsForCurrent ? (
          <span className="bg-ink text-void border-none px-4 py-2.5 rounded-lg text-[13px] font-semibold inline-flex items-center gap-1.5">
            ⏱ focusing · {focusTimer}
          </span>
        ) : (
          <button
            className="bg-ink text-void border-none px-4 py-2.5 rounded-lg text-[13px] font-semibold cursor-pointer inline-flex items-center gap-1.5 disabled:opacity-50 disabled:cursor-not-allowed"
            onClick={() => startFocus.mutate(current.task_id)}
            disabled={startFocus.isPending || focusSession != null}
            title={focusSession != null ? "Another task's focus session is active" : undefined}
          >
            ▶ Start focus session
          </button>
        )}
        {(focusSession || current) && (
          <button
            className="bg-transparent text-mut border border-line px-3 py-2 rounded-lg text-xs cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
            onClick={handleDoneEarly}
            disabled={doneEarlyPending}
          >
            Done early
          </button>
        )}
        {current && (
          <button
            className="bg-transparent text-mut border border-line px-3 py-2 rounded-lg text-xs cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
            onClick={() => reschedule.mutate({ scheduledTaskId: current.id, action: 'push_evening' })}
            disabled={!hasEveningBlock || reschedule.isPending}
            title={hasEveningBlock ? undefined : 'No "Evening" time block defined'}
          >
            Push to evening
          </button>
        )}
      </div>
    </div>
  )
}
