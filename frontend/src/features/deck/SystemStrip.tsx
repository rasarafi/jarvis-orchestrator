import { Link } from 'react-router-dom'
import { useResources } from './hooks/use-resources'

/**
 * Alert-only resource strip: silent when pressure is nominal — the full
 * SystemMonitor lives on the Analytics page.
 */
export function SystemStrip() {
  const { data: snap } = useResources()
  if (!snap || snap.pressure === 'ok') return null

  const critical = snap.pressure === 'critical'
  return (
    <div
      className={`flex items-center gap-2.5 rounded-lg border px-4 py-2.5 text-[12.5px] ${
        critical ? 'border-bad/40 bg-bad/10' : 'border-gold/40 bg-gold-soft'
      }`}
    >
      <span className={`font-mono2 ${critical ? 'text-bad' : 'text-gold'}`} aria-hidden>
        {critical ? '✕' : '▲'}
      </span>
      <span className="min-w-0 truncate">
        <span className="font-semibold text-ink">{snap.message}</span>
        <span className="text-mut"> — {snap.detail}</span>
      </span>
      <Link
        to="/analytics"
        className="ml-auto flex-none font-mono2 text-[10.5px] uppercase tracking-wide text-mut hover:text-arc"
      >
        Resources →
      </Link>
    </div>
  )
}
