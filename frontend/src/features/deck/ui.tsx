/** Shared primitives for deck cards — shapes come from the approved mockup. */

export function Card({ children, className = '' }: { children: React.ReactNode; className?: string }) {
  return (
    <section className={`overflow-hidden rounded-[10px] border border-line-soft bg-deck shadow-[var(--card-shadow)] ${className}`}>
      {children}
    </section>
  )
}

export function CardHeader({
  title,
  count,
  action,
}: {
  title: string
  count?: string
  action?: React.ReactNode
}) {
  return (
    <div className="flex items-center gap-2.5 border-b border-line-soft px-[18px] py-[13px]">
      <h2 className="font-display text-[11.5px] font-semibold uppercase tracking-[0.2em] text-mut">
        {title}
      </h2>
      {count && <span className="font-mono2 text-[11px] text-dim">{count}</span>}
      {action && <div className="ml-auto">{action}</div>}
    </div>
  )
}

/** Deterministic project hue so lanes/dots stay consistent without config. */
export function projectColor(name: string): string {
  let hash = 0
  for (let i = 0; i < name.length; i++) hash = (hash * 31 + name.charCodeAt(i)) | 0
  return `hsl(${((hash % 360) + 360) % 360} 55% 66%)`
}
