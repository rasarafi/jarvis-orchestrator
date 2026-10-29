import { Card } from '@/features/deck/ui'
import type { CostWindow } from '@/types/api'
import { formatDuration, formatMoney, formatTokens, shortModel } from './format'
import { ModelBar } from './ModelBar'

/**
 * The open 5-hour window — the thing that actually runs out on a subscription.
 *
 * Two separate axes on purpose: consumption (tokens, scaled so the bar can never
 * overflow) and elapsed time. An earlier draft drew a time marker on the token
 * bar and neither could be read.
 *
 * Everything here is a LOCAL estimate. The authoritative quota percentage lives
 * in QuotaStrip above; this card deliberately carries no percentage of its own.
 */
export function WindowStrip({ data }: { data: CostWindow }) {
  if (!data.open) {
    return (
      <Card>
        <div className="flex flex-col items-center gap-1 px-4 py-7 text-center">
          <p className="text-[13px] text-mut">No window is open right now.</p>
          <p className="text-[11.5px] text-dim">
            A new 5-hour window opens with your next message.
          </p>
        </div>
      </Card>
    )
  }

  const tokens = data.tokens ?? 0
  const projected = data.projected_tokens ?? 0
  const median = data.median_tokens ?? 0
  const ratio = data.vs_median ?? null

  // Scale to whichever is largest so neither bar can ever exceed the track.
  const scale = Math.max(tokens, projected, median, 1)
  const pct = (n: number) => `${Math.min(100, (n / scale) * 100)}%`

  const used = formatTokens(tokens)
  const proj = formatTokens(projected)
  const med = formatTokens(median)
  const burn = formatTokens(data.burn_tokens_per_min ?? 0)

  return (
    <Card>
      <div className="flex flex-col gap-3 px-[18px] py-4">
        <div className="flex flex-wrap items-baseline justify-between gap-3">
          <div className="flex flex-wrap items-baseline gap-2.5">
            <h2 className="font-display text-[11.5px] font-semibold uppercase tracking-[0.2em] text-mut">
              Current 5-hour window
            </h2>
            <span className="font-mono2 text-[11.5px] text-dim">
              {timeOf(data.started_at)} → {timeOf(data.ends_at)}
            </span>
            {/* An anchored window rarely lands on a round time, which reads as a
                bug unless the source is stated. */}
            <span className="text-[11px] text-dim">
              {data.boundary_source === 'server_anchor'
                ? 'real boundary'
                : 'estimated boundary'}
            </span>
          </div>
          {ratio !== null && <Verdict ratio={ratio} />}
        </div>

        {/* Consumption. Median is a tick, not a fill, because it is a reference. */}
        <div
          className="relative h-[26px] overflow-hidden rounded-md border border-line-soft bg-deck-2"
          role="img"
          aria-label={`${used.value}${used.unit} tokens used this window${
            ratio !== null ? `, ${ratio}× your median window` : ''
          }; projected ${proj.value}${proj.unit} by close.`}
        >
          <div
            className="absolute inset-y-0 left-0 rounded-l-[5px] bg-arc/25"
            style={{ width: pct(projected) }}
          />
          <div
            className="absolute inset-y-0 left-0 rounded-l-[5px] bg-arc"
            style={{ width: pct(tokens) }}
          />
          {median > 0 && (
            <div
              className="absolute inset-y-0 w-px bg-ink/45"
              style={{ left: pct(median) }}
              title={`median window ${med.value}${med.unit}`}
            />
          )}
        </div>

        <Caption
          left={
            <>
              <strong className="font-mono2 font-semibold text-ink">
                {used.value}
                {used.unit}
              </strong>{' '}
              used · projected{' '}
              <strong className="font-mono2 font-semibold text-ink">
                {proj.value}
                {proj.unit}
              </strong>{' '}
              by close
            </>
          }
          right={
            median > 0 ? (
              <>
                median window{' '}
                <span className="font-mono2">
                  {med.value}
                  {med.unit}
                </span>
              </>
            ) : (
              'no median yet'
            )
          }
        />

        {/* Elapsed time — deliberately quieter than consumption. */}
        <div>
          <div
            className="h-1 overflow-hidden rounded-full border border-line-soft bg-deck-2"
            role="img"
            aria-label={`${data.elapsed_pct ?? 0}% of the window elapsed.`}
          >
            <div
              className="h-full rounded-full bg-mut/50"
              style={{ width: `${data.elapsed_pct ?? 0}%` }}
            />
          </div>
          <Caption
            left={<><span className="font-mono2">{data.elapsed_pct ?? 0}%</span> of the window elapsed</>}
            right={
              <>
                <strong className="font-mono2 font-semibold text-ink">
                  {formatDuration(data.resets_in_minutes ?? 0)}
                </strong>{' '}
                until it resets
              </>
            }
          />
        </div>

        <div className="grid grid-cols-[repeat(auto-fit,minmax(140px,1fr))] gap-y-1 border-t border-line-soft pt-3">
          <Stat
            label="Burn rate"
            value={
              <>
                {burn.value}
                <Unit>{burn.unit}/min</Unit>
              </>
            }
            sub="last 60 minutes"
            mono
          />
          <Stat
            label="API equivalent"
            value={formatMoney(data.cost_usd ?? 0)}
            sub="not billed — subscription"
            mono
          />
          <Stat
            label="Active sessions"
            value={String(data.active_sessions ?? 0)}
            sub="in the last 15 min"
            mono
          />
          {data.heaviest_project && (
            <Stat
              label="Heaviest right now"
              value={<span className="text-[15px]">{data.heaviest_project.slug}</span>}
              sub={
                data.heaviest_project.pct !== null
                  ? `${data.heaviest_project.pct}% of window`
                  : undefined
              }
            />
          )}
          {data.model_mix && data.model_mix.length > 0 && (
            <Stat
              label="Model mix"
              value={<span className="text-[15px]">{shortModel(data.model_mix[0].model)}</span>}
              sub={data.model_mix
                .slice(0, 2)
                .map((m, i) => (i === 0 ? `${m.pct}%` : `${shortModel(m.model)} ${m.pct}%`))
                .join(' · ')}
              extra={<ModelBar models={data.model_mix} className="mt-1.5 max-w-[150px]" />}
            />
          )}
        </div>
      </div>
    </Card>
  )
}

/** Comparative, never absolute — the absolute figure belongs to QuotaStrip. */
function Verdict({ ratio }: { ratio: number }) {
  const heavy = ratio >= 1.5
  const label = ratio >= 1.5 ? 'Heavy' : ratio >= 0.75 ? 'Typical' : 'Light'

  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[12px] font-semibold ${
        heavy ? 'border-gold/40 bg-gold/10 text-gold' : 'border-line text-mut'
      }`}
    >
      {label} · <span className="font-mono2">{ratio}×</span> your typical window
    </span>
  )
}

function Caption({ left, right }: { left: React.ReactNode; right: React.ReactNode }) {
  return (
    <div className="mt-1.5 flex flex-wrap justify-between gap-3 text-[11.5px] text-mut">
      <span>{left}</span>
      <span>{right}</span>
    </div>
  )
}

function Unit({ children }: { children: React.ReactNode }) {
  return <span className="ml-0.5 text-[11px] font-medium text-mut">{children}</span>
}

function Stat({
  label,
  value,
  sub,
  mono = false,
  extra,
}: {
  label: string
  value: React.ReactNode
  sub?: string
  mono?: boolean
  extra?: React.ReactNode
}) {
  return (
    <div className="flex flex-col gap-0.5 pr-3.5">
      <span className="text-[11px] text-mut">{label}</span>
      <span className={`text-[17px] font-semibold tracking-tight ${mono ? 'font-mono2' : ''}`}>
        {value}
      </span>
      {sub && <span className="text-[11px] text-dim">{sub}</span>}
      {extra}
    </div>
  )
}

function timeOf(iso?: string): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
}
