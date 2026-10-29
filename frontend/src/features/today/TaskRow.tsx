import { ProjectChip } from '@/components/ProjectChip'
import { formatClock } from './lib/format'
import { cn } from '@/lib/utils'
import type { ScheduledTask } from '@/types/today'

interface TaskRowProps {
  scheduledTask: ScheduledTask
  /** Used to colorize the project chip — looked up by slug. */
  projectColors: Map<string, string>
  /** Optional: highlight this row as the current task. */
  current?: boolean
  /** Optional: render with the "done" style (strikethrough + dimmed). */
  done?: boolean
}

export function TaskRow({ scheduledTask, projectColors, current, done }: TaskRowProps) {
  const task = scheduledTask.task
  const projectSlug = task.project?.slug ?? null
  const projectColor = projectSlug ? projectColors.get(projectSlug) : undefined

  return (
    <div
      className={cn(
        'flex items-center gap-2.5 px-3 py-2 rounded-md',
        current && 'bg-blue-500/[0.07] border border-blue-500/25',
        !current && 'hover:bg-hover-strong',
      )}
    >
      <div
        className={cn(
          'w-4 h-4 border-[1.5px] rounded flex-shrink-0 flex items-center justify-center text-[11px] font-bold',
          done ? 'bg-emerald-500 border-emerald-500 text-black' : 'border-line',
          current && !done && 'border-blue-400',
        )}
      >
        {done && '✓'}
      </div>
      <div className="text-[10px] text-dim font-mono w-[78px] flex-shrink-0">
        {formatClock(scheduledTask.start_time)} – {formatClock(scheduledTask.end_time)}
      </div>
      <div
        className={cn(
          'text-sm flex-1 truncate',
          done && 'text-dim line-through decoration-dim',
        )}
      >
        {task.title}
      </div>
      {task.project && (
        <ProjectChip
          name={task.project.slug.toUpperCase()}
          color={projectColor}
          compact
        />
      )}
    </div>
  )
}
