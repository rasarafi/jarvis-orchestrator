import { Outlet } from 'react-router-dom'
import { Rail } from './Rail'
import { Topbar } from './Topbar'
import { DbHealthBanner } from '@/features/deck/components/DbHealthBanner'
import { useLiveSessions, liveCounts } from '@/features/deck/hooks/use-live-sessions'
import { useTasks } from '@/features/tasks/hooks/use-tasks'
import { useCostSummary } from '@/features/analytics/hooks/use-analytics'
import { useInterrupts } from '@/features/interrupts/hooks/use-interrupts'
import { useNoteCounts } from '@/features/notes/hooks/use-notes'
import { useAttentionAlerts, useTitleBadge } from '@/features/deck/hooks/use-attention-alerts'
import { countNeeds } from '@/features/deck/needs'
import { isOverdue } from '@/lib/dates'

export function DeckShell() {
  const { data: live } = useLiveSessions()
  const { data: tasks } = useTasks()
  const { data: costs } = useCostSummary()
  const { data: interrupts } = useInterrupts()
  const { data: noteCounts } = useNoteCounts()

  const counts = liveCounts(live?.data)
  const openTasks = (tasks ?? []).filter((t) => t.status !== 'done')
  const overdue = openTasks.filter((t) => isOverdue(t.deadline)).length
  const needsYou = countNeeds(live?.data, tasks, interrupts)

  useAttentionAlerts(live?.data)
  useTitleBadge(needsYou)

  return (
    <div className="relative z-10 flex min-h-screen flex-col">
      <DbHealthBanner />
      <div className="grid flex-1 grid-cols-[200px_1fr]">
        <Rail
          openTaskCount={openTasks.length}
          noteInboxCount={noteCounts?.meta.counts.inbox ?? 0}
        />

        <main className="min-w-0 px-7 pb-10">
          <Topbar
            liveCount={counts.total}
            spendToday={costs ? `$${Number(costs.today_cost ?? 0).toFixed(2)}` : undefined}
            overdueCount={overdue}
          />
          <Outlet />
        </main>
      </div>
    </div>
  )
}
