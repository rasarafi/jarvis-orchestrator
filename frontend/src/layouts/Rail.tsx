import { NavLink } from 'react-router-dom'
import {
  LayoutDashboard,
  CalendarDays,
  ListChecks,
  StickyNote,
  FolderOpen,
  BarChart3,
  ChartColumnBig,
  Newspaper,
  ShieldCheck,
  Gauge,
} from 'lucide-react'

interface RailItem {
  to: string
  label: string
  icon: React.ComponentType<{ className?: string }>
  badge?: number
  hot?: boolean
  /** Match this path exactly — needed for parents of nested routes. */
  end?: boolean
}

interface RailProps {
  openTaskCount?: number
  noteInboxCount?: number
}

export function Rail({ openTaskCount, noteInboxCount }: RailProps) {
  const items: RailItem[] = [
    { to: '/', label: 'Deck', icon: LayoutDashboard },
    { to: '/recap', label: 'Recap', icon: Newspaper },
    { to: '/planner', label: 'Planner', icon: CalendarDays },
    { to: '/tasks', label: 'Tasks', icon: ListChecks, badge: openTaskCount },
    { to: '/notes', label: 'Notes', icon: StickyNote, badge: noteInboxCount },
    { to: '/projects', label: 'Projects', icon: FolderOpen },
    { to: '/audits', label: 'Audits', icon: ShieldCheck },
    { to: '/analytics', label: 'Analytics', icon: BarChart3, end: true },
    { to: '/analytics/tasks', label: 'Task analysis', icon: ChartColumnBig },
    { to: '/costs', label: 'Quota & cost', icon: Gauge },
  ]

  return (
    <nav className="sticky top-0 flex h-screen w-[200px] flex-col gap-0.5 border-r border-line-soft bg-void/60 px-3 py-5">
      <div className="flex items-center gap-2.5 px-2.5 pb-4 font-display text-[17px] font-bold tracking-[0.16em] text-ink">
        <span
          className="h-3.5 w-3.5 rounded-full shadow-[0_0_12px_rgba(86,200,232,0.55)]"
          style={{ background: 'radial-gradient(circle at 35% 35%, var(--arc), #16455a 70%)' }}
        />
        JARVIS
      </div>

      {items.map(({ to, label, icon: Icon, badge, hot, end }) => (
        <NavLink
          key={to}
          to={to}
          end={end ?? to === '/'}
          className={({ isActive }) =>
            `flex items-center gap-2.5 rounded-lg border px-2.5 py-2 text-[13.5px] font-medium transition-colors ${
              isActive
                ? 'border-line bg-deck-2 text-ink'
                : 'border-transparent text-mut hover:bg-deck hover:text-ink'
            }`
          }
        >
          {({ isActive }) => (
            <>
              <Icon className={`h-[15px] w-[15px] shrink-0 ${isActive ? 'text-arc' : ''}`} />
              {label}
              {badge !== undefined && badge > 0 && (
                <span
                  className={`ml-auto rounded-full border px-1.5 font-mono2 text-[10.5px] leading-4 ${
                    hot
                      ? 'border-gold/40 bg-gold-soft text-gold'
                      : 'border-line bg-deck-2 text-dim'
                  }`}
                >
                  {badge}
                </span>
              )}
            </>
          )}
        </NavLink>
      ))}

    </nav>
  )
}
