import { Card, CardHeader } from '@/features/deck/ui'
import type { TaskAnalytics } from '@/types/api'
import { CHART, ChartNote, ChartTooltip, priorityColor, useChartTooltip } from './viz'

const W = 1080
const H = 108
const PAD = { l: 26, r: 26 }
const AXIS_Y = 62
const DAYS = 30

/**
 * Open tasks placed on a 30-day runway. Overdue work is pinned to the left
 * edge. When almost nothing carries a deadline the *gap* is the finding, so
 * the empty state says so out loud instead of rendering a blank axis.
 */
export function DeadlineRunway({ deadlines }: { deadlines: TaskAnalytics['deadlines'] }) {
  const { tip, bind } = useChartTooltip()
  const iw = W - PAD.l - PAD.r
  const x = (days: number) => PAD.l + (Math.max(0, Math.min(DAYS, days)) / DAYS) * iw
  const inWindow = deadlines.items.filter((i) => i.days_out <= DAYS)
  const coverage =
    deadlines.total_open === 0
      ? 0
      : Math.round((deadlines.with_deadline / deadlines.total_open) * 100)

  return (
    <Card>
      <CardHeader
        title="Deadline runway"
        count={`${deadlines.with_deadline} of ${deadlines.total_open} open tasks dated`}
      />

      {/* Same rule as the scatter: scroll below ~700px instead of scaling the
          day ticks into illegibility. */}
      <div className="overflow-x-auto px-2 pt-2">
      <svg viewBox={`0 0 ${W} ${H}`} className="w-full min-w-[700px]" role="img"
           aria-label="Open tasks with deadlines over the next 30 days">
        <line x1={PAD.l} x2={W - PAD.r} y1={AXIS_Y} y2={AXIS_Y} stroke={CHART.axis} />
        {Array.from({ length: 7 }, (_, i) => i * 5).map((d) => (
          <g key={d}>
            <line x1={x(d)} x2={x(d)} y1={AXIS_Y - 7} y2={AXIS_Y + 7} stroke={CHART.grid} />
            <text x={x(d)} y={AXIS_Y + 24} textAnchor="middle" fontSize="10" fill={CHART.tick}>
              {d === 0 ? 'today' : `+${d}d`}
            </text>
          </g>
        ))}

        {inWindow.map((item, i) => (
          <circle
            key={item.id}
            cx={x(item.days_out)}
            // Stagger overlapping dates so a busy day doesn't render as one dot.
            cy={AXIS_Y + ((i % 3) - 1) * 11}
            r={5.5}
            fill={item.days_out < 0 ? 'var(--bad)' : priorityColor(item.priority)}
            stroke={CHART.surface}
            strokeWidth={2}
            {...bind({
              title: item.title,
              detail: `${item.project} · ${item.priority} · due ${item.deadline}${
                item.days_out < 0 ? ` (${Math.abs(item.days_out)}d overdue)` : ''
              }`,
            })}
          />
        ))}

        {inWindow.length === 0 && (
          <text x={W / 2} y={AXIS_Y - 22} textAnchor="middle" fontSize="12" fill={CHART.tick}>
            nothing due in the next 30 days
          </text>
        )}
      </svg>
      </div>

      <ChartNote>
        {deadlines.without_deadline > 0 ? (
          <>
            <strong className="font-semibold text-ink">
              {deadlines.without_deadline} open tasks have no deadline
            </strong>{' '}
            — only {coverage}% are dated, so this runway can only ever show a sliver of the
            backlog. Setting deadlines is what makes this panel useful.
            {deadlines.overdue > 0 && ` ${deadlines.overdue} of the dated ones are already overdue.`}
          </>
        ) : (
          `Every open task has a deadline. ${deadlines.overdue} overdue.`
        )}
      </ChartNote>

      <ChartTooltip tip={tip} />
    </Card>
  )
}
