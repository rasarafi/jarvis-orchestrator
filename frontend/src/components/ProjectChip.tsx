import { cn } from '@/lib/utils'

interface ProjectChipProps {
  /** Project name to display. */
  name: string
  /** CSS color string (hsl/hex/rgb). When omitted, renders in muted gray. */
  color?: string | null
  /** Optional count badge (e.g. number of tasks today). */
  count?: number
  /** Visually de-emphasize the chip (used for "no tasks today"). */
  dim?: boolean
  /** Compact variant — no count, smaller padding. */
  compact?: boolean
  /** Additional class names. */
  className?: string
}

export function ProjectChip({
  name,
  color,
  count,
  dim = false,
  compact = false,
  className,
}: ProjectChipProps) {
  const dotStyle = color && !dim ? { backgroundColor: color } : undefined
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full font-medium',
        compact ? 'px-2 py-0.5 text-[10px]' : 'px-2.5 py-1 text-xs',
        dim
          ? 'bg-hover-strong text-dim'
          : 'bg-deck-2 text-ink',
        className,
      )}
    >
      <span
        className={cn(
          'rounded-full',
          compact ? 'w-1 h-1' : 'w-1.5 h-1.5',
          dim && 'bg-deck-2',
        )}
        style={dotStyle}
      />
      <span className="truncate max-w-[120px]">{name}</span>
      {count !== undefined && !compact && (
        <span className={cn('text-[10px] tabular-nums opacity-70')}>{count}</span>
      )}
    </span>
  )
}
