import { useMemo } from 'react'
import { Card, CardHeader } from '@/features/deck/ui'
import type { TaskAgePoint } from '@/types/api'
import {
  CHART,
  ChartNote,
  ChartTooltip,
  EmptyChart,
  PRIORITY_LABEL,
  PRIORITY_ORDER,
  priorityColor,
  useChartTooltip,
} from './viz'

const W = 1080
const H = 250
const PAD = { l: 72, r: 26, t: 12, b: 30 }
const IW = W - PAD.l - PAD.r
const IH = H - PAD.t - PAD.b
const BAND = IH / PRIORITY_ORDER.length
const DOT = 4.5

/** Deterministic vertical spread so same-day dots don't stack into one blob. */
const jitter = (n: number) => ((n % 7) - 3) * (BAND / 9)

/**
 * Every open task as a dot: age on x, priority band on y. The top-right
 * corner is the shame quadrant — urgent work that has been sitting.
 */
export function AgeScatter({ points }: { points: TaskAgePoint[] }) {
  const { tip, bind } = useChartTooltip()

  const maxAge = Math.max(7, ...points.map((p) => p.age_days))
  const axisMax = Math.ceil(maxAge / 30) * 30 || 30
  const x = (age: number) => PAD.l + (age / axisMax) * IW
  const bandY = (p: TaskAgePoint['priority']) =>
    PAD.t + (PRIORITY_ORDER.indexOf(p) + 0.5) * BAND

  // Index within (priority, age) bucket drives the jitter.
  const placed = useMemo(() => {
    const seen = new Map<string, number>()
    return points.map((p) => {
      const key = `${p.priority}|${p.age_days}`
      const n = seen.get(key) ?? 0
      seen.set(key, n + 1)
      return { point: p, offset: jitter(n) }
    })
  }, [points])

  const xTicks = Array.from({ length: axisMax / 30 + 1 }, (_, i) => i * 30)

  return (
    <Card>
      <CardHeader title="Age vs priority" count={`${points.length} open tasks`} />
      {points.length === 0 ? (
        <EmptyChart message="No open tasks to plot." />
      ) : (
        <>
          {/* Scrolls rather than shrinks: below ~760px the band labels and day
              ticks stop being readable if the SVG scales with the card. */}
          <div className="overflow-x-auto px-2 pt-3">
          <svg viewBox={`0 0 ${W} ${H}`} className="w-full min-w-[760px]" role="img"
               aria-label="Open tasks plotted by age against priority">
            {PRIORITY_ORDER.map((p, i) => (
              <g key={p}>
                <rect
                  x={PAD.l}
                  y={PAD.t + i * BAND}
                  width={IW}
                  height={BAND}
                  fill={priorityColor(p)}
                  opacity={i === 0 ? 0.09 : 0.035}
                />
                {i > 0 && (
                  <line
                    x1={PAD.l}
                    x2={W - PAD.r}
                    y1={PAD.t + i * BAND}
                    y2={PAD.t + i * BAND}
                    stroke={CHART.grid}
                  />
                )}
                <text
                  x={PAD.l - 12}
                  y={PAD.t + (i + 0.5) * BAND + 4}
                  textAnchor="end"
                  fontSize="11.5"
                  fill={CHART.band}
                >
                  {PRIORITY_LABEL[p]}
                </text>
              </g>
            ))}

            {xTicks.map((v) => (
              <g key={v}>
                <line
                  x1={x(v)}
                  x2={x(v)}
                  y1={PAD.t}
                  y2={PAD.t + IH}
                  stroke={CHART.grid}
                  strokeDasharray="2 4"
                />
                <text x={x(v)} y={H - 9} textAnchor="middle" fontSize="10" fill={CHART.tick}>
                  {v}d
                </text>
              </g>
            ))}

            {placed.map(({ point, offset }) => (
              <circle
                key={point.id}
                cx={x(point.age_days)}
                cy={bandY(point.priority) + offset}
                r={DOT}
                fill={priorityColor(point.priority)}
                stroke={CHART.surface}
                strokeWidth={1.5}
                {...bind({
                  title: point.title,
                  detail: `${point.project} · ${point.priority} · ${point.age_days}d old`,
                })}
              />
            ))}

            <line x1={PAD.l} x2={W - PAD.r} y1={PAD.t + IH} y2={PAD.t + IH} stroke={CHART.axis} />
            <text x={PAD.l - 62} y={H - 9} fontSize="10" fill={CHART.tick}>
              age →
            </text>
          </svg>
          </div>
          <ChartNote>{ageReading(points)}</ChartNote>
        </>
      )}
      <ChartTooltip tip={tip} />
    </Card>
  )
}

/** Two weeks of sitting is where "urgent" stops meaning urgent. */
const STALE_URGENT_DAYS = 14

function ageReading(points: TaskAgePoint[]): string {
  const hot = points.filter(
    (p) => (p.priority === 'urgent' || p.priority === 'high') && p.age_days >= STALE_URGENT_DAYS,
  )
  const oldest = points.reduce((a, b) => (b.age_days > a.age_days ? b : a))

  if (hot.length === 0) {
    return `Nothing urgent or high has been sitting past ${STALE_URGENT_DAYS} days. Oldest open task is ${oldest.age_days}d (${oldest.project}).`
  }
  const worst = hot.reduce((a, b) => (b.age_days > a.age_days ? b : a))
  return `${hot.length} urgent/high tasks have been open past ${STALE_URGENT_DAYS} days — the worst is ${worst.age_days}d in ${worst.project}. Oldest task overall: ${oldest.age_days}d.`
}
