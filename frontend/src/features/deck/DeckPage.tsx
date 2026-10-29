import { useLiveSessions } from './hooks/use-live-sessions'
import { useToday } from '@/features/today/hooks/use-today'
import { useTasks } from '@/features/tasks/hooks/use-tasks'
import { useInterrupts } from '@/features/interrupts/hooks/use-interrupts'
import { countNeeds } from './needs'
import { NowStrip } from './NowStrip'
import { ProjectBoard } from './ProjectBoard'
import { TodayTimeline } from './TodayTimeline'
import { NeedsYou } from './NeedsYou'
import { SystemStrip } from './SystemStrip'
import { RecapWidget } from '@/features/recap/RecapWidget'

export function DeckPage() {
  const liveQuery = useLiveSessions()
  const { data: today } = useToday()
  const { data: tasks } = useTasks()
  const { data: interrupts } = useInterrupts()

  const sessions = liveQuery.data?.data
  const needsYouCount = countNeeds(sessions, tasks, interrupts)

  return (
    <div className="flex flex-col gap-[18px]">
      <NowStrip today={today} liveCount={sessions?.length ?? 0} needsYouCount={needsYouCount} />

      <ProjectBoard
        sessions={sessions}
        isLoading={liveQuery.isLoading}
        isError={liveQuery.isError}
      />

      <SystemStrip />

      <div className="grid grid-cols-1 items-start gap-[18px] xl:grid-cols-[1.4fr_1fr]">
        <NeedsYou sessions={sessions} tasks={tasks} />
        <div className="flex min-w-0 flex-col gap-[18px]">
          <RecapWidget />
          <TodayTimeline today={today} />
        </div>
      </div>
    </div>
  )
}
