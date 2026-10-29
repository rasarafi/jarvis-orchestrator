import { Link } from 'react-router-dom'
import type { TodayPayload } from '@/types/today'
import { timeToMinutes, formatClock } from '@/features/today/lib/format'
import { Card, CardHeader, projectColor } from './ui'

export function TodayTimeline({ today }: { today: TodayPayload | undefined }) {
  const scheduled = [...(today?.scheduled_tasks ?? [])].sort(
    (a, b) => timeToMinutes(a.start_time) - timeToMinutes(b.start_time),
  )
  const done = scheduled.filter((t) => t.task.status === 'done').length
  const nowMin = new Date().getHours() * 60 + new Date().getMinutes()

  return (
    <Card>
      <CardHeader
        title="Today"
        count={scheduled.length ? `${done} of ${scheduled.length} done` : undefined}
        action={
          <Link to="/planner" className="text-xs text-arc hover:underline">
            Planner →
          </Link>
        }
      />

      {scheduled.length === 0 && (
        <div className="flex flex-col items-center gap-1.5 px-4 py-8 text-center">
          <p className="text-[13px] text-dim">No plan for today yet.</p>
          <p className="font-mono2 text-[11.5px] text-mut">Plan it in a Jarvis session</p>
        </div>
      )}

      <div className="py-1.5">
        {scheduled.map((st) => {
          const start = timeToMinutes(st.start_time)
          const end = timeToMinutes(st.end_time)
          const isNow = start <= nowMin && nowMin < end
          const isDone = st.task.status === 'done'
          const projectName = st.task.project?.name

          return (
            <div key={st.id} className="grid grid-cols-[52px_14px_1fr] gap-x-2.5 px-4">
              <div className="pt-[11px] text-right font-mono2 text-[11px] text-dim">
                {formatClock(st.start_time)}
              </div>
              <div className="relative">
                <span className="absolute bottom-0 left-[6px] top-0 w-px bg-line" />
                <span
                  className={`absolute left-[2.5px] top-3.5 h-2 w-2 rounded-full border-[1.5px] ${
                    isNow
                      ? 'border-arc bg-arc shadow-[0_0_10px_rgba(86,200,232,0.8)]'
                      : isDone
                        ? 'border-ok bg-ok'
                        : 'border-dim bg-deck-2'
                  }`}
                />
              </div>
              <div
                className={`my-1.5 rounded-lg border px-3 py-2 ${
                  isNow ? 'border-arc/30 bg-arc-soft' : 'border-transparent hover:bg-hover-strong'
                }`}
              >
                <div
                  className={`flex items-center gap-2 text-[13px] font-medium ${
                    isDone ? 'text-mut line-through decoration-mut/50' : 'text-ink'
                  }`}
                >
                  {projectName && (
                    <span
                      className="h-[7px] w-[7px] shrink-0 rounded-[2px]"
                      style={{ background: projectColor(projectName) }}
                    />
                  )}
                  <span className="truncate">{st.task.title}</span>
                  {(st.task.priority === 'urgent' || st.task.priority === 'high') && !isDone && (
                    <span
                      className={`rounded px-1.5 font-display text-[9.5px] font-semibold tracking-[0.12em] ${
                        st.task.priority === 'urgent' ? 'bg-gold-soft text-gold' : 'bg-arc-soft text-arc'
                      }`}
                    >
                      {st.task.priority.toUpperCase()}
                    </span>
                  )}
                </div>
                <div className="mt-px font-mono2 text-[11px] text-dim">
                  {projectName ?? '—'}
                  {isNow && ` · now · ${end - nowMin} min left`}
                </div>
              </div>
            </div>
          )
        })}
      </div>
    </Card>
  )
}
