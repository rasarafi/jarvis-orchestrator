import { AlertCircle } from 'lucide-react'
import { Card, CardHeader } from './ui'
import { useResources, useResourceTrend } from './hooks/use-resources'
import type { HostSnapshot, ResourcePressure, ResourceTrendPoint, SystemSnapshot } from '@/types/api'

const gb = (mb: number) => (mb / 1024).toFixed(1)

/** Semantic CSS var per pressure level (matches the deck palette). */
const TONE: Record<ResourcePressure, string> = {
  ok: 'var(--ok)',
  warning: 'var(--gold)',
  critical: 'var(--bad)',
}
const SOFT: Record<ResourcePressure, string> = {
  ok: 'bg-ok/10',
  warning: 'bg-gold-soft',
  critical: 'bg-bad/12',
}
const GLYPH: Record<ResourcePressure, string> = { ok: '✓', warning: '▲', critical: '✕' }

/** Per-metric tone so a bar reddens on its own threshold, not just the overall verdict. */
function toneFor(pct: number, warn: number, crit: number): ResourcePressure {
  if (pct >= crit) return 'critical'
  if (pct >= warn) return 'warning'
  return 'ok'
}

function Bar({ pct, tone }: { pct: number; tone: ResourcePressure }) {
  return (
    <div className="mt-2.5 h-[5px] overflow-hidden rounded-full bg-hover-strong/60">
      <div
        className="h-full rounded-full transition-[width,background-color] duration-500"
        style={{ width: `${Math.min(100, Math.max(2, pct))}%`, backgroundColor: TONE[tone] }}
      />
    </div>
  )
}

function Vital({
  label,
  value,
  unit,
  meta,
  pct,
  tone,
}: {
  label: string
  value: string
  unit?: string
  meta: React.ReactNode
  pct: number
  tone: ResourcePressure
}) {
  return (
    <div className="bg-deck px-4 py-[14px]">
      <div className="font-display text-[10px] uppercase tracking-[0.13em] text-dim">{label}</div>
      <div className="mt-[7px] font-mono2 text-[22px] font-medium tabular-nums text-ink">
        {value}
        {unit && <span className="text-[12px] font-normal text-dim"> {unit}</span>}
      </div>
      <div className="mt-0.5 font-mono2 text-[10.5px] text-dim">{meta}</div>
      <Bar pct={pct} tone={tone} />
    </div>
  )
}

/**
 * Normalized 0–100 lines over the persisted window.
 *
 * WSL and host readings get separate charts rather than one four-line chart —
 * mixing two machines' memory in a single plot is the fastest way to make
 * someone misread which number is which.
 */
type Series = {
  key: string
  color: string
  /** null → sample has no reading (host agent was down); the line breaks there. */
  value: (p: ResourceTrendPoint) => number | null
  fill?: boolean
  dot?: boolean
}

function Sparkline({ points, series, height = 'h-16' }: { points: ResourceTrendPoint[]; series: Series[]; height?: string }) {
  if (points.length < 2) {
    return (
      <div className={`flex ${height} items-center justify-center font-mono2 text-[10.5px] text-dim`}>
        collecting samples…
      </div>
    )
  }
  const W = 600
  const H = 64
  const x = (i: number) => (i / (points.length - 1)) * W
  const y = (v: number) => H - 2 - (Math.min(100, Math.max(0, v)) / 100) * (H - 6)

  /**
   * Split into contiguous runs so gaps (null readings, e.g. host agent down)
   * don't draw a false line across. Each run carries its own x-range so a fill
   * closes under just that run instead of the whole chart width.
   */
  const segments = (fn: (p: ResourceTrendPoint) => number | null) => {
    const out: { d: string; x0: number; x1: number }[] = []
    let cur: string[] = []
    let x0 = 0
    let x1 = 0
    const flush = () => {
      if (cur.length > 1) out.push({ d: cur.join(' '), x0, x1 })
      cur = []
    }
    points.forEach((p, i) => {
      const v = fn(p)
      if (v === null || v === undefined || Number.isNaN(v)) {
        flush()
        return
      }
      if (!cur.length) x0 = x(i)
      x1 = x(i)
      cur.push(`${cur.length ? 'L' : 'M'}${x(i).toFixed(1)} ${y(v).toFixed(1)}`)
    })
    flush()
    return out
  }

  return (
    <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" className={`block ${height} w-full`}>
      {series.map((s) => {
        const segs = segments(s.value)
        const lastVal = [...points].reverse().map(s.value).find((v) => v !== null && v !== undefined)
        return (
          <g key={s.key}>
            {s.fill &&
              segs.map((seg, i) => (
                <path
                  key={`f${i}`}
                  d={`${seg.d} L${seg.x1.toFixed(1)} ${H} L${seg.x0.toFixed(1)} ${H} Z`}
                  fill={s.color}
                  opacity={0.1}
                />
              ))}
            {segs.map((seg, i) => (
              <path key={i} d={seg.d} fill="none" stroke={s.color} strokeWidth={2} strokeLinejoin="round" />
            ))}
            {s.dot && lastVal != null && <circle cx={W} cy={y(lastVal)} r={3} fill={s.color} />}
          </g>
        )
      })}
    </svg>
  )
}

const WSL_SERIES: Series[] = [
  { key: 'swap', color: 'var(--bad)', value: (p) => p.swap_pct },
  { key: 'mem', color: 'var(--gold)', value: (p) => p.mem_pct },
  {
    key: 'cpu',
    color: 'var(--arc)',
    value: (p) => Math.min(100, (p.cpu_load / Math.max(1, p.cores) / 3) * 100),
    fill: true,
    dot: true,
  },
]

const HOST_SERIES: Series[] = [
  { key: 'host-commit', color: 'var(--bad)', value: (p) => p.host_commit_pct },
  { key: 'host-mem', color: 'var(--gold)', value: (p) => p.host_mem_pct, fill: true, dot: true },
]

function LegendDot({ color, label }: { color: string; label: string }) {
  return (
    <span className="inline-flex items-center gap-1.5 font-mono2 text-[10px] text-dim">
      <i className="inline-block h-[2.5px] w-[9px] rounded-full" style={{ backgroundColor: color }} /> {label}
    </span>
  )
}

/**
 * Windows-host band. The /proc numbers above it describe only the inside of the
 * WSL VM, so this is the half of the machine that was previously invisible —
 * including what the VM itself costs the host.
 */
function HostBand({ host, trend }: { host: HostSnapshot; trend: ResourceTrendPoint[] }) {
  const hm = host.metrics
  const label = host.hostname ?? 'Windows host'

  return (
    <div className="border-t border-line-soft">
      <div className="flex items-center gap-2 bg-deck-2/50 px-[18px] py-2.5">
        <h3 className="font-display text-[10px] uppercase tracking-[0.13em] text-dim">Windows Host</h3>
        <span className="min-w-0 truncate font-mono2 text-[10.5px] text-dim">{label}</span>
        <span className="ml-auto flex flex-none items-center gap-1.5 font-mono2 text-[10px] text-dim">
          <span
            className="h-1.5 w-1.5 rounded-full"
            style={{ backgroundColor: host.available ? TONE[host.pressure ?? 'ok'] : 'var(--dim)' }}
          />
          {host.available ? 'agent live' : host.status === 'stale' ? 'agent stale' : 'agent offline'}
        </span>
      </div>

      {!host.available || !hm ? (
        <div className="flex items-start gap-2.5 px-[18px] py-4">
          <AlertCircle className="mt-px h-3.5 w-3.5 flex-none text-dim" />
          <div className="min-w-0">
            <div className="text-[12.5px] text-mut">{host.reason ?? 'Host metrics unavailable.'}</div>
            <div className="mt-1 font-mono2 text-[10.5px] text-dim">
              Start it: <span className="text-mut">pythonw C:\Comm\Jarvis-HostMetrics\agent.py</span>
            </div>
          </div>
        </div>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-px bg-line-soft sm:grid-cols-3">
            <Vital
              label="Host Memory"
              value={gb(hm.mem_used_mb)}
              unit={`/ ${gb(hm.mem_total_mb)} GB`}
              meta={`${gb(hm.mem_available_mb)} GB available`}
              pct={hm.mem_pct}
              tone={toneFor(hm.mem_pct, 75, 90)}
            />
            <Vital
              label="Commit"
              value={gb(hm.commit_used_mb)}
              unit={`/ ${gb(hm.commit_limit_mb)} GB`}
              meta={`${hm.commit_pct}% of limit`}
              pct={hm.commit_pct}
              tone={toneFor(hm.commit_pct, 85, 95)}
            />
            <Vital
              label="Host CPU"
              value={hm.cpu_pct.toFixed(0)}
              unit="%"
              meta={`${hm.cpu_cores} logical cores`}
              pct={hm.cpu_pct}
              tone={toneFor(hm.cpu_pct, 70, 90)}
            />
          </div>

          {/* The bridge between this band and the /proc numbers above it. */}
          <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1 border-t border-line-soft px-[18px] py-2.5">
            <span className="font-mono2 text-[10.5px] text-dim">WSL VM costs the host</span>
            <span className="font-mono2 text-[12px] font-medium tabular-nums text-ink">{gb(hm.wsl_mb)} GB</span>
            {hm.mem_total_mb > 0 && (
              <span className="font-mono2 text-[10.5px] text-dim">
                · {Math.round((hm.wsl_mb / hm.mem_total_mb) * 100)}% of host RAM
              </span>
            )}
          </div>

          {host.top.length > 0 && (
            <div className="border-t border-line-soft px-[18px] py-3">
              <div className="mb-2 font-display text-[10px] uppercase tracking-[0.13em] text-dim">
                Top host processes
              </div>
              <div className="flex flex-wrap gap-1.5">
                {host.top.map((p) => (
                  <span
                    key={p.name}
                    className="inline-flex max-w-full items-baseline gap-1.5 rounded border border-line px-1.5 py-0.5 font-mono2 text-[10.5px] text-dim"
                  >
                    <span className="min-w-0 truncate text-mut">{p.name}</span>
                    <span className="flex-none tabular-nums">{p.mb >= 1024 ? `${gb(p.mb)} GB` : `${p.mb} MB`}</span>
                  </span>
                ))}
              </div>
            </div>
          )}

          <div className="border-t border-line-soft px-[18px] pb-4 pt-3">
            <div className="mb-2 flex items-baseline justify-between">
              <h3 className="font-display text-[10px] uppercase tracking-[0.13em] text-dim">Host · last 2 hours</h3>
              <div className="flex gap-3.5">
                <LegendDot color="var(--gold)" label="Mem" />
                <LegendDot color="var(--bad)" label="Commit" />
              </div>
            </div>
            <Sparkline points={trend} series={HOST_SERIES} height="h-12" />
          </div>
        </>
      )}
    </div>
  )
}

function Body({ snap }: { snap: SystemSnapshot }) {
  const m = snap.metrics
  const p = snap.pressure
  const { data: trend } = useResourceTrend(120)

  return (
    <>
      {/* verdict banner — the guardrail */}
      <div className={`flex items-start gap-3 border-b border-line-soft px-[18px] py-3.5 ${SOFT[p]}`}>
        <div
          className="grid h-[30px] w-[30px] flex-none place-items-center rounded-lg text-[15px] text-white"
          style={{ backgroundColor: TONE[p] }}
        >
          {GLYPH[p]}
        </div>
        <div>
          <div className="text-[13.5px] font-semibold text-ink">{snap.message}</div>
          <div className="mt-0.5 text-[12px] text-mut">{snap.detail}</div>
        </div>
      </div>

      {/* vitals */}
      <div className="grid grid-cols-2 gap-px bg-line-soft sm:grid-cols-4">
        <Vital
          label="CPU Load"
          value={m.cpu_load.toFixed(2)}
          meta={<>/ {m.cores} cores · {m.load_per_core.toFixed(2)}×</>}
          pct={(m.load_per_core / 3) * 100}
          tone={toneFor(m.load_per_core, 1.5, 2.5)}
        />
        <Vital
          label="Memory"
          value={gb(m.mem_used_mb)}
          unit={`/ ${gb(m.mem_total_mb)} GB`}
          meta={`${gb(m.mem_available_mb)} GB available`}
          pct={m.mem_pct}
          tone={toneFor(m.mem_pct, 75, 90)}
        />
        <Vital
          label="Swap"
          value={gb(m.swap_used_mb)}
          unit={m.swap_total_mb > 0 ? `/ ${gb(m.swap_total_mb)} GB` : 'off'}
          meta={m.swap_pct >= 15 ? 'rising · hang signal' : 'not swapping'}
          pct={m.swap_pct}
          tone={toneFor(m.swap_pct, 15, 40)}
        />
        <Vital
          label="Sessions"
          value={String(m.session_count)}
          meta={`claude · ${snap.sessions.length} repos`}
          pct={Math.min(100, m.session_count * 12)}
          tone="ok"
        />
      </div>

      {/* trend */}
      <div className="border-t border-line-soft px-[18px] pb-4 pt-3.5">
        <div className="mb-2 flex items-baseline justify-between">
          <h3 className="font-display text-[10px] uppercase tracking-[0.13em] text-dim">WSL · last 2 hours</h3>
          <div className="flex gap-3.5">
            <LegendDot color="var(--arc)" label="CPU" />
            <LegendDot color="var(--gold)" label="Mem" />
            <LegendDot color="var(--bad)" label="Swap" />
          </div>
        </div>
        <Sparkline points={trend ?? []} series={WSL_SERIES} />
      </div>

      <HostBand host={snap.host} trend={trend ?? []} />

      {/* per-session attribution — which one to drop */}
      {snap.sessions.length > 0 && (
        <div className="border-t border-line-soft">
          {snap.sessions.map((s, i) => (
            <div key={s.slug ?? s.path ?? i} className="flex items-center gap-3 border-b border-line-soft px-[18px] py-[11px] last:border-b-0">
              <div className="w-[3px] self-stretch flex-none rounded-full" style={{ backgroundColor: TONE[toneFor(s.pct_of_used, 20, 40)] }} />
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1.5 text-[13px] font-semibold text-ink">
                  {s.project}
                  <span className="rounded border border-line px-1 font-mono2 text-[9.5px] font-normal text-dim">
                    {s.pids} proc
                  </span>
                </div>
                <div className="mt-0.5 truncate font-mono2 text-[10.5px] text-dim">{s.path}</div>
              </div>
              <div className="text-right font-mono2 text-[13px] tabular-nums text-ink">
                {gb(s.rss_mb)} GB
                <small className="block text-[9.5px] text-dim">~{s.pct_of_used}% of used</small>
              </div>
            </div>
          ))}
        </div>
      )}
    </>
  )
}

export function SystemMonitor() {
  const { data: snap, isLoading, isError } = useResources()
  const p = snap?.pressure ?? 'ok'

  return (
    <Card>
      <CardHeader
        title="System"
        count={snap ? `WSL2 · ${gb(snap.metrics.mem_total_mb)} GB cap` : undefined}
        action={
          <span className="flex items-center gap-1.5 font-mono2 text-[10.5px] tracking-wide text-dim">
            <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: TONE[p] }} /> live · 3s
          </span>
        }
      />

      {isError && (
        <div className="flex items-center gap-2 px-4 py-5 text-[12.5px] text-bad">
          <AlertCircle className="h-3.5 w-3.5" /> System vitals unavailable — couldn&apos;t read /proc.
        </div>
      )}

      {isLoading && !isError && <div className="px-4 py-5 text-[12.5px] text-dim">…</div>}

      {snap && <Body snap={snap} />}
    </Card>
  )
}
