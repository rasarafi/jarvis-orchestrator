import { Card, CardHeader } from '@/features/deck/ui'
import type { TaskFlowWeek } from '@/types/api'
import { CHART, ChartNote, ChartTooltip, EmptyChart, Legend, useChartTooltip } from './viz'

const W = 520
const H = 220
const PAD = { l: 34, r: 16, t: 12, b: 30 }
const IW = W - PAD.l - PAD.r
const IH = H - PAD.t - PAD.b

/** "2026-07-27" → "Jul 27" */
const weekLabel = (iso: string) =>
  new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })

/** Round a max up to a clean 4-step axis. */
function niceMax(value: number): number {
  if (value <= 0) return 4
  const step = Math.pow(10, Math.floor(Math.log10(value / 4)))
  for (const mult of [1, 2, 2.5, 5, 10]) {
    const candidate = step * mult * 4
    if (candidate >= value) return candidate
  }
  return Math.ceil(value / 4) * 4
}

const ticks = (max: number) => [0, 0.25, 0.5, 0.75, 1].map((f) => Math.round(max * f))

/** Created vs completed per week — the "am I winning" chart. */
export function FlowChart({ weeks }: { weeks: TaskFlowWeek[] }) {
  const { tip, bind } = useChartTooltip()
  const data = weeks.slice(-8)
  const max = niceMax(Math.max(...data.map((w) => Math.max(w.created, w.completed)), 1))
  const y = (v: number) => PAD.t + IH - (v / max) * IH
  const slot = IW / Math.max(1, data.length)
  const barW = Math.min(24, (slot - 14) / 2)

  return (
    <Card>
      <CardHeader title="Created vs completed" count="by week" />
      {data.length === 0 ? (
        <EmptyChart message="No task history in this window." />
      ) : (
        <>
          <Legend
            items={[
              { color: 'var(--viz-1)', label: 'Created' },
              { color: 'var(--viz-2)', label: 'Completed' },
            ]}
          />
          <svg viewBox={`0 0 ${W} ${H}`} className="w-full px-2 pt-2" role="img"
               aria-label="Tasks created versus completed, by week">
            {ticks(max).map((v) => (
              <g key={v}>
                <line x1={PAD.l} x2={W - PAD.r} y1={y(v)} y2={y(v)} stroke={CHART.grid} />
                <text x={PAD.l - 7} y={y(v) + 4} textAnchor="end" fontSize="10" fill={CHART.tick}>
                  {v}
                </text>
              </g>
            ))}

            {data.map((w, i) => {
              const cx = PAD.l + slot * i + slot / 2
              return (
                <g key={w.week}>
                  {(
                    [
                      { v: w.created, color: 'var(--viz-1)', label: 'Created', dx: -barW - 1 },
                      { v: w.completed, color: 'var(--viz-2)', label: 'Completed', dx: 1 },
                    ] as const
                  ).map((bar) => (
                    <g key={bar.label}>
                      <rect
                        x={cx + bar.dx}
                        y={y(bar.v)}
                        width={barW}
                        height={Math.max(0, PAD.t + IH - y(bar.v))}
                        rx={3}
                        fill={bar.color}
                        {...bind({
                          title: `${bar.label} · week of ${weekLabel(w.week)}`,
                          detail: `${bar.v} tasks`,
                        })}
                      />
                      {bar.v > 0 && (
                        <text
                          x={cx + bar.dx + barW / 2}
                          y={y(bar.v) - 4}
                          textAnchor="middle"
                          fontSize="10"
                          fill={CHART.tick}
                        >
                          {bar.v}
                        </text>
                      )}
                    </g>
                  ))}
                  <text x={cx} y={H - 10} textAnchor="middle" fontSize="10.5" fill={CHART.band}>
                    {weekLabel(w.week)}
                  </text>
                </g>
              )
            })}

            <line x1={PAD.l} x2={W - PAD.r} y1={y(0)} y2={y(0)} stroke={CHART.axis} />
          </svg>
          <ChartNote>{flowReading(data)}</ChartNote>
        </>
      )}
      <ChartTooltip tip={tip} />
    </Card>
  )
}

/** Cumulative open count — the line only falls when you out-ship intake. */
export function BacklogChart({ weeks }: { weeks: TaskFlowWeek[] }) {
  const { tip, bind } = useChartTooltip()
  const data = weeks.slice(-8)
  const max = niceMax(Math.max(...data.map((w) => w.open_after), 1))
  const rightPad = 46
  const iw = W - PAD.l - rightPad
  const x = (i: number) => PAD.l + (data.length === 1 ? iw / 2 : (i / (data.length - 1)) * iw)
  const y = (v: number) => PAD.t + IH - (v / max) * IH
  const last = data[data.length - 1]

  return (
    <Card>
      <CardHeader title="Cumulative backlog" count="open tasks" />
      {data.length === 0 ? (
        <EmptyChart message="No task history in this window." />
      ) : (
        <>
          <svg viewBox={`0 0 ${W} ${H}`} className="w-full px-2 pt-4" role="img"
               aria-label="Cumulative open task count by week">
            {ticks(max).map((v) => (
              <g key={v}>
                <line x1={PAD.l} x2={W - rightPad} y1={y(v)} y2={y(v)} stroke={CHART.grid} />
                <text x={PAD.l - 7} y={y(v) + 4} textAnchor="end" fontSize="10" fill={CHART.tick}>
                  {v}
                </text>
              </g>
            ))}

            <path
              d={data.map((w, i) => `${i === 0 ? 'M' : 'L'}${x(i)} ${y(w.open_after)}`).join(' ')}
              fill="none"
              stroke="var(--viz-1)"
              strokeWidth={2}
              strokeLinejoin="round"
            />

            {data.map((w, i) => {
              const net = w.created - w.completed
              return (
                <g key={w.week}>
                  <circle
                    cx={x(i)}
                    cy={y(w.open_after)}
                    r={4.5}
                    fill="var(--viz-1)"
                    stroke={CHART.surface}
                    strokeWidth={2}
                    {...bind({
                      title: `Week of ${weekLabel(w.week)}`,
                      detail: `${w.open_after} open · ${net >= 0 ? '+' : ''}${net} net`,
                    })}
                  />
                  {i % 2 === 0 && (
                    <text x={x(i)} y={H - 10} textAnchor="middle" fontSize="10.5" fill={CHART.band}>
                      {weekLabel(w.week)}
                    </text>
                  )}
                </g>
              )
            })}

            <text
              x={Math.min(x(data.length - 1) + 8, W - 4)}
              y={y(last.open_after) + 4}
              fontSize="11.5"
              fontWeight="600"
              fill="var(--ink)"
            >
              {last.open_after}
            </text>
            <line x1={PAD.l} x2={W - rightPad} y1={y(0)} y2={y(0)} stroke={CHART.axis} />
          </svg>
          <ChartNote>{backlogReading(data)}</ChartNote>
        </>
      )}
      <ChartTooltip tip={tip} />
    </Card>
  )
}

function flowReading(data: TaskFlowWeek[]): string {
  const recent = data.slice(-4)
  const created = recent.reduce((s, w) => s + w.created, 0)
  const done = recent.reduce((s, w) => s + w.completed, 0)
  if (created === 0 && done === 0) return 'No tasks created or completed in the last four weeks.'
  const net = created - done
  return net > 0
    ? `Last 4 weeks: ${created} in, ${done} out — the backlog grew by ${net}.`
    : `Last 4 weeks: ${created} in, ${done} out — you shed ${Math.abs(net)} tasks.`
}

function backlogReading(data: TaskFlowWeek[]): string {
  if (data.length < 2) return 'Not enough history yet to show a trend.'
  const first = data[0].open_after
  const last = data[data.length - 1].open_after
  const delta = last - first
  if (delta === 0) return `Flat across the window — still ${last} open.`
  return delta > 0
    ? `Up ${delta} across the window (${first} → ${last}). Intake is outrunning throughput.`
    : `Down ${Math.abs(delta)} across the window (${first} → ${last}). You're clearing it.`
}
