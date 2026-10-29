import { useEffect, useState } from 'react'
import { Loader2, AlertCircle } from 'lucide-react'
import { useToday } from './hooks/use-today'
import { useTodayActuals } from './hooks/use-today-actuals'
import { useFocusHeartbeat } from './hooks/use-focus-session'
import { DayRibbon } from './DayRibbon'
import { ProjectMixBar } from './ProjectMixBar'
import { HeroCard } from './HeroCard'
import { UpNextCard } from './UpNextCard'
import { PlanCard } from './PlanCard'
import { BlockList } from './BlockList'
import { timeToMinutes } from './lib/format'
import type { ScheduledTask } from '@/types/today'

function findCurrentTask(tasks: ScheduledTask[], now: Date): ScheduledTask | null {
  const nowMin = now.getHours() * 60 + now.getMinutes()
  return (
    tasks.find(
      (t) =>
        t.task?.status !== 'done' &&
        timeToMinutes(t.start_time) <= nowMin &&
        nowMin < timeToMinutes(t.end_time),
    ) ?? null
  )
}

function findNextTask(
  tasks: ScheduledTask[],
  now: Date,
  excludeId?: string,
): ScheduledTask | null {
  const nowMin = now.getHours() * 60 + now.getMinutes()
  return (
    tasks
      .filter(
        (t) => t.id !== excludeId && t.task?.status !== 'done' && timeToMinutes(t.start_time) > nowMin,
      )
      .sort((a, b) => a.start_time.localeCompare(b.start_time))[0] ?? null
  )
}

function isInMorning(time: string): boolean {
  return timeToMinutes(time) < 12 * 60
}

export function TodayPage() {
  const [now, setNow] = useState(() => new Date())
  const { data, isLoading, error } = useToday()
  const actualsQuery = useTodayActuals()

  // Tick the clock every minute so the NOW pointer + state derivations stay current.
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 60_000)
    return () => clearInterval(id)
  }, [])

  useFocusHeartbeat(data?.active_focus_session ?? null)

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-screen text-dim">
        <Loader2 className="w-5 h-5 animate-spin mr-2" /> Loading today…
      </div>
    )
  }
  if (error || !data) {
    return (
      <div className="flex items-center justify-center h-screen text-red-400">
        <AlertCircle className="w-5 h-5 mr-2" /> Failed to load today's schedule.
      </div>
    )
  }

  const projectColors = new Map(data.project_mix.map((p) => [p.slug, p.color]))
  const current = findCurrentTask(data.scheduled_tasks, now)
  const next = findNextTask(data.scheduled_tasks, now, current?.id)

  const nowMinutes = now.getHours() * 60 + now.getMinutes()
  const remainingMorning = data.scheduled_tasks.filter(
    (t) => isInMorning(t.start_time) && t.id !== current?.id && timeToMinutes(t.start_time) > nowMinutes,
  ).length
  const remainingEvening = data.scheduled_tasks.filter(
    (t) => !isInMorning(t.start_time) && t.id !== current?.id && timeToMinutes(t.start_time) > nowMinutes,
  ).length

  return (
    <div className="text-ink">
      <div className="pb-15">
        <DayRibbon
          blocks={data.blocks}
          now={now}
          actuals={actualsQuery.data}
          scheduledTasks={data.scheduled_tasks}
          projectColors={projectColors}
        />
        <ProjectMixBar projects={data.project_mix} />
        <div className="grid grid-cols-[2.4fr_1fr] gap-5 mb-6">
          <HeroCard
            current={current}
            projectColors={projectColors}
            now={now}
            focusSession={data.active_focus_session}
            hasEveningBlock={data.blocks.some((b) => b.name === 'Evening')}
          />
          <UpNextCard
            next={next}
            projectColors={projectColors}
            counts={{ morning: remainingMorning, evening: remainingEvening }}
          />
        </div>
        <div className="mb-6">
          <PlanCard />
        </div>
        <BlockList
          blocks={data.blocks}
          scheduledTasks={data.scheduled_tasks}
          projectColors={projectColors}
          currentScheduledTaskId={current?.id ?? null}
          now={now}
        />
      </div>
    </div>
  )
}
