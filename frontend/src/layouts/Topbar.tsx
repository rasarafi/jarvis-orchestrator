import { useEffect, useState } from 'react'
import { Bell, BellOff } from 'lucide-react'
import { useAppStore } from '@/stores/app-store'
import { useStreak } from '@/features/plan/hooks/use-plans'
import { ThemeMenu } from './ThemeMenu'

function greeting(now: Date): string {
  const day = now.toLocaleDateString('en-US', { weekday: 'long' })
  const h = now.getHours()
  const part = h < 5 ? 'night' : h < 12 ? 'morning' : h < 17 ? 'afternoon' : 'evening'
  return `${day} ${part}`
}

interface TopbarProps {
  liveCount?: number
  spendToday?: string
  overdueCount?: number
}

export function Topbar({ liveCount, spendToday, overdueCount }: TopbarProps) {
  const [now, setNow] = useState(() => new Date())
  const { data: streak } = useStreak()
  const alertsEnabled = useAppStore((s) => s.alertsEnabled)
  const setAlertsEnabled = useAppStore((s) => s.setAlertsEnabled)

  const toggleAlerts = async () => {
    if (alertsEnabled) {
      setAlertsEnabled(false)
      return
    }
    if (typeof Notification === 'undefined') return
    const permission =
      Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission()
    if (permission === 'granted') setAlertsEnabled(true)
  }

  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 30_000)
    return () => clearInterval(id)
  }, [])

  return (
    <header className="mb-5 flex items-center gap-6 border-b border-line-soft pb-4 pt-[18px]">
      <div>
        <h1 className="font-display text-[19px] font-semibold tracking-wide text-ink">
          {greeting(now)}
        </h1>
        <div className="mt-px font-mono2 text-[12.5px] text-mut">
          {now.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} ·{' '}
          {now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false })}
        </div>
      </div>

      <div className="ml-auto flex gap-4 whitespace-nowrap font-mono2 text-xs text-mut">
        {typeof streak === 'number' && streak > 0 && (
          <span title="Consecutive days the must-do got done">
            <b className="font-medium text-ok">{streak}</b> day streak
          </span>
        )}
        {liveCount !== undefined && (
          <span>
            <b className="font-medium text-arc">{liveCount}</b> live
          </span>
        )}
        {spendToday !== undefined && (
          <span>
            <b className="font-medium text-ink">{spendToday}</b> today
          </span>
        )}
        {overdueCount !== undefined && overdueCount > 0 && (
          <span>
            <b className="font-medium text-gold">{overdueCount}</b> overdue
          </span>
        )}
      </div>

      <button
        onClick={toggleAlerts}
        aria-label={alertsEnabled ? 'Disable needs-you alerts' : 'Enable needs-you alerts'}
        title={alertsEnabled ? 'Needs-you alerts on' : 'Enable needs-you alerts'}
        className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border transition-colors ${
          alertsEnabled
            ? 'border-arc/40 text-arc hover:text-ink'
            : 'border-line text-mut hover:border-arc/40 hover:text-ink'
        }`}
      >
        {alertsEnabled ? <Bell className="h-4 w-4" /> : <BellOff className="h-4 w-4" />}
      </button>

      <ThemeMenu />
    </header>
  )
}
