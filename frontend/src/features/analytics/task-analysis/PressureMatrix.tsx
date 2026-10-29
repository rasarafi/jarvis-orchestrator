import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Card, CardHeader } from '@/features/deck/ui'
import type { TaskPressureRow } from '@/types/api'
import {
  ChartNote,
  ChartTooltip,
  EmptyChart,
  Legend,
  PRIORITY_LABEL,
  PRIORITY_ORDER,
  priorityColor,
  useChartTooltip,
} from './viz'

/** Bar segments below this share of the row render without their count label. */
const LABEL_MIN_SHARE = 0.08

/**
 * One row per project: open tasks split by priority, ordered by pressure
 * score. Bar LENGTH is the open count, so a long bar of mediums reads as
 * volume while a short bar of urgents reads as heat.
 */
export function PressureMatrix({ rows }: { rows: TaskPressureRow[] }) {
  const navigate = useNavigate()
  const { tip, bind } = useChartTooltip()
  const [showQuiet, setShowQuiet] = useState(false)

  const loud = rows.filter((r) => !r.quiet)
  const quiet = rows.filter((r) => r.quiet)
  const visible = showQuiet ? [...loud, ...quiet] : loud
  const maxTotal = Math.max(1, ...rows.map((r) => r.total))

  return (
    <Card>
      <CardHeader
        title="Where the weight is"
        count={`${rows.length} project${rows.length === 1 ? '' : 's'} with open work`}
      />

      {rows.length === 0 ? (
        <EmptyChart message="No open tasks — nothing to weigh." />
      ) : (
        <>
          <Legend
            items={PRIORITY_ORDER.map((p) => ({ color: priorityColor(p), label: PRIORITY_LABEL[p] }))}
            note="bar length = open tasks · score = urgent×4 + high×2 + medium"
          />

          <div className="px-[18px] pb-1 pt-3">
            {visible.map((row) => (
              <button
                key={row.slug}
                type="button"
                onClick={() => navigate(`/tasks?project=${encodeURIComponent(row.display_name)}`)}
                className="grid w-full grid-cols-[minmax(0,132px)_1fr_44px] items-center gap-3 rounded-md py-[3px] text-left transition-colors hover:bg-hover focus-visible:outline focus-visible:outline-1 focus-visible:outline-arc"
              >
                <span className="truncate text-right text-[12.5px] text-mut">
                  {row.display_name}
                </span>

                <span
                  className="flex h-[18px] gap-[2px]"
                  style={{ width: `${(row.total / maxTotal) * 100}%` }}
                >
                  {PRIORITY_ORDER.map((p) => {
                    const n = row.counts[p]
                    if (n === 0) return null
                    return (
                      <span
                        key={p}
                        // The ramp's light end is whichever end sits nearest the
                        // surface, and --deck/--ink flip with the theme in step
                        // with it — so these two classes stay legible in all three
                        // skins without any per-theme branching.
                        className={`flex min-w-[3px] items-center justify-center rounded-[2px] font-mono2 text-[10.5px] font-semibold first:rounded-l-[4px] last:rounded-r-[4px] ${
                          p === 'urgent' || p === 'high' ? 'text-deck' : 'text-ink'
                        }`}
                        style={{ flex: n, background: priorityColor(p) }}
                        {...bind({
                          title: row.display_name,
                          detail: `${n} ${PRIORITY_LABEL[p].toLowerCase()} · ${row.total} open total`,
                        })}
                      >
                        {n / row.total >= LABEL_MIN_SHARE ? n : ''}
                      </span>
                    )
                  })}
                </span>

                <span className="font-mono2 text-[11.5px] text-dim">{row.score}</span>
              </button>
            ))}
          </div>

          {quiet.length > 0 && (
            <button
              type="button"
              onClick={() => setShowQuiet((v) => !v)}
              className="mb-1 ml-[168px] rounded px-1 py-1 text-[11.5px] text-dim underline-offset-2 hover:text-mut hover:underline"
            >
              {showQuiet
                ? 'hide quiet projects'
                : `+ ${quiet.length} quiet projects (nothing above medium)`}
            </button>
          )}

          <ChartNote>{reading(loud)}</ChartNote>
        </>
      )}

      <ChartTooltip tip={tip} />
    </Card>
  )
}

/** Plain-language reading of the top of the chart, computed not hardcoded. */
function reading(loud: TaskPressureRow[]): string {
  if (loud.length === 0) return 'Nothing above medium anywhere — the backlog is all low-heat.'
  const [top, ...rest] = loud
  const restScore = rest.reduce((s, r) => s + r.score, 0)
  const lead =
    restScore === 0
      ? `${top.display_name} is the only project with anything above medium`
      : top.score >= restScore
        ? `${top.display_name} carries more pressure than every other project combined`
        : `${top.display_name} leads on pressure (${top.score})`

  const bulk = [...loud].sort((a, b) => b.counts.medium - a.counts.medium)[0]
  const bulkNote =
    bulk && bulk.slug !== top.slug && bulk.counts.medium >= 5
      ? ` ${bulk.display_name} looks big but is ${bulk.counts.medium} mediums — volume, not heat.`
      : ''

  return `${lead}.${bulkNote}`
}
