import { AlertTriangle } from 'lucide-react'
import { useDbHealth } from '../hooks/use-db-health'

/**
 * Full-width outage banner. When Postgres is unreachable every panel is empty,
 * so this is the one thing that renders regardless of route — it names the real
 * cause instead of leaving the user with eight mystery "failed to load" panels.
 * Silent when the DB is up (or when status is merely unknown/loading).
 */
export function DbHealthBanner() {
  const status = useDbHealth()

  if (status !== 'down') return null

  return (
    <div
      role="alert"
      className="flex items-center gap-2.5 bg-destructive/10 px-7 py-2.5 text-sm font-medium text-destructive"
    >
      <AlertTriangle className="size-4 shrink-0" />
      <span className="min-w-0">
        Database unreachable — data can’t load. Postgres (Docker{' '}
        <code className="rounded bg-destructive/10 px-1 py-0.5 font-mono text-xs">DB-postgres</code>)
        may be down.
      </span>
    </div>
  )
}
