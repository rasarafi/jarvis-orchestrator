/** Shared chart vocabulary for the task-analysis page.
 *
 *  Priority is ORDINAL, so it never gets four unrelated hues — it gets the
 *  single-hue ramp defined in index.css (--pri-*), validated per theme. Colors
 *  are read through CSS vars so all three skins (dark / light / biscuit) work
 *  without any JS theme branching. */

import { useCallback, useState } from 'react'
import type { TaskPriorityKey } from '@/types/api'

/** Severity order — drives legends, stacking and scatter bands alike. */
export const PRIORITY_ORDER: TaskPriorityKey[] = ['urgent', 'high', 'medium', 'low']

export const PRIORITY_LABEL: Record<TaskPriorityKey, string> = {
  urgent: 'Urgent',
  high: 'High',
  medium: 'Medium',
  low: 'Low',
}

export const priorityColor = (p: TaskPriorityKey) => `var(--pri-${p})`

/** Chart chrome pulled from the deck tokens so charts sit in the same room. */
export const CHART = {
  grid: 'var(--line-soft)',
  axis: 'var(--line)',
  tick: 'var(--dim)',
  band: 'var(--mut)',
  surface: 'var(--deck)',
} as const

// ── tooltip ────────────────────────────────────────────────────────────────
// One hook, one floating node. SVG can't host HTML, so the tooltip lives in a
// fixed-position div driven by pointer coordinates.

export interface TipContent {
  title: string
  detail: string
}

export function useChartTooltip() {
  const [tip, setTip] = useState<(TipContent & { x: number; y: number }) | null>(null)

  const show = useCallback((e: { clientX: number; clientY: number }, content: TipContent) => {
    setTip({ ...content, x: e.clientX, y: e.clientY })
  }, [])

  const hide = useCallback(() => setTip(null), [])

  /** Spread onto any mark to make it hoverable AND keyboard-focusable. */
  const bind = useCallback(
    (content: TipContent) => ({
      onMouseEnter: (e: React.MouseEvent) => show(e, content),
      onMouseMove: (e: React.MouseEvent) => show(e, content),
      onMouseLeave: hide,
      onFocus: (e: React.FocusEvent<SVGElement | HTMLElement>) => {
        const r = e.currentTarget.getBoundingClientRect()
        show({ clientX: r.left + r.width / 2, clientY: r.top }, content)
      },
      onBlur: hide,
      tabIndex: 0,
      role: 'img' as const,
      'aria-label': `${content.title}. ${content.detail}`,
    }),
    [show, hide],
  )

  return { tip, bind, hide }
}

export function ChartTooltip({ tip }: { tip: (TipContent & { x: number; y: number }) | null }) {
  if (!tip) return null
  return (
    <div
      className="pointer-events-none fixed z-50 max-w-[280px] rounded-lg border border-line bg-deck-2 px-2.5 py-1.5 shadow-[var(--pill-shadow)]"
      style={{ left: Math.min(tip.x + 14, window.innerWidth - 300), top: tip.y + 14 }}
    >
      <div className="truncate text-[12.5px] font-medium text-ink">{tip.title}</div>
      <div className="mt-0.5 font-mono2 text-[11px] text-dim">{tip.detail}</div>
    </div>
  )
}

// ── legend ─────────────────────────────────────────────────────────────────

export function Legend({
  items,
  note,
}: {
  items: { color: string; label: string }[]
  note?: string
}) {
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-[18px] pt-3 text-[11.5px] text-mut">
      {items.map((it) => (
        <span key={it.label} className="flex items-center gap-1.5">
          <i className="h-[10px] w-[10px] rounded-[3px]" style={{ background: it.color }} />
          {it.label}
        </span>
      ))}
      {note && <span className="ml-auto text-dim">{note}</span>}
    </div>
  )
}

/** The one-line reading under a chart — what the picture is telling you. */
export function ChartNote({ children }: { children: React.ReactNode }) {
  return (
    <p className="mx-[18px] my-3.5 border-l-2 border-gold/50 py-1 pl-3 text-[12px] leading-relaxed text-mut">
      {children}
    </p>
  )
}

export function EmptyChart({ message }: { message: string }) {
  return <div className="px-[18px] py-8 text-center text-[12.5px] text-dim">{message}</div>
}
