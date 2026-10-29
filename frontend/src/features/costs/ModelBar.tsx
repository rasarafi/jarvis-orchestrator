import type { CostModelShare } from '@/types/api'
import { shortModel } from './format'
import { modelColor } from './models'

/** One stacked bar. Slivers below 2% are kept — dropping them would misread as absence. */
export function ModelBar({ models, className = '' }: { models: CostModelShare[]; className?: string }) {
  if (models.length === 0) {
    return <span className={`block h-[7px] rounded-full border border-line-soft bg-deck-2 ${className}`} />
  }

  const label = models.map((m) => `${shortModel(m.model)} ${m.pct}%`).join(' · ')

  return (
    <span
      className={`flex h-[7px] overflow-hidden rounded-full border border-line-soft bg-deck-2 ${className}`}
      role="img"
      aria-label={`Model mix: ${label}`}
      title={label}
    >
      {models.map((m) => (
        <span
          key={m.model}
          className="block h-full first:rounded-l-full last:rounded-r-full"
          style={{ width: `${Math.max(m.pct, 1)}%`, background: modelColor(m.model) }}
        />
      ))}
    </span>
  )
}

/** The bar plus a text lead — for rows with space for a name. */
export function ModelSummary({ models, max = 2 }: { models: CostModelShare[]; max?: number }) {
  if (models.length === 0) {
    return <span className="text-[11px] text-dim">—</span>
  }

  const shown = models.slice(0, max)
  const rest = models.length - shown.length

  return (
    <span className="flex min-w-0 flex-col gap-1">
      <ModelBar models={models} />
      <span className="truncate text-[10.5px] text-mut">
        {shown.map((m) => `${shortModel(m.model)} ${m.pct}%`).join(' · ')}
        {rest > 0 && ` +${rest}`}
      </span>
    </span>
  )
}
