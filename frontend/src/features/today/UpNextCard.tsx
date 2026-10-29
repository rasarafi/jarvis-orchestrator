import { ProjectChip } from '@/components/ProjectChip'
import { formatClock, formatDuration } from './lib/format'
import type { ScheduledTask } from '@/types/today'

interface UpNextCardProps {
  next: ScheduledTask | null
  projectColors: Map<string, string>
  /** Counts to display in the footer summary. */
  counts: {
    morning: number
    evening: number
  }
}

export function UpNextCard({ next, projectColors, counts }: UpNextCardProps) {
  return (
    <div className="bg-deck border border-line-soft rounded-2xl p-5 flex flex-col">
      <div className="text-[10px] uppercase tracking-wider text-dim font-semibold mb-2.5">
        Up Next
      </div>
      {next ? (
        <>
          <div className="text-xs text-dim font-mono mb-1">
            {formatClock(next.start_time)} — {formatClock(next.end_time)} ·{' '}
            {formatDuration(next.start_time, next.end_time)}
          </div>
          <div className="text-sm font-medium text-ink leading-snug mb-2.5">
            {next.task.title}
          </div>
          {next.task.project && (
            <ProjectChip
              name={next.task.project.slug.toUpperCase()}
              color={projectColors.get(next.task.project.slug)}
              compact
              className="self-start"
            />
          )}
        </>
      ) : (
        <div className="text-sm text-dim leading-snug">Nothing else scheduled.</div>
      )}
      <div className="flex-1" />
      <div className="flex gap-3.5 pt-3 mt-3 border-t border-line-soft text-[11px] text-dim">
        <div>
          <strong className="text-mut font-medium">{counts.morning} left</strong> morning
        </div>
        <div>
          <strong className="text-mut font-medium">{counts.evening} left</strong> evening
        </div>
      </div>
    </div>
  )
}
