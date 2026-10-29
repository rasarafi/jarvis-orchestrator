import { AlertCircle, RefreshCw } from 'lucide-react'
import { Card } from '@/features/deck/ui'
import type { QuotaLimit, QuotaUtilization } from '@/types/api'
import { formatDuration, formatRelative } from './format'

/**
 * The real quota — fetched from Anthropic, not computed here.
 *
 * Everything else on this page is a local estimate. This card is the one
 * authoritative number, so it says where it came from and how old it is, every
 * time. It never auto-refreshes: the user presses Fetch.
 *
 * Rows come straight from `limits[]` and are rendered generically, because the
 * payload carries feature-flagged buckets that appear and disappear without
 * notice. An unrecognised `kind` shows up as its own row rather than vanishing.
 */
export function QuotaStrip({
  data,
  onRefresh,
  isRefreshing,
}: {
  data: QuotaUtilization | undefined
  onRefresh: () => void
  isRefreshing: boolean
}) {
  const limits = data?.limits ?? []

  return (
    <Card>
      <div className="flex flex-col gap-3 px-[18px] py-4">
        <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1.5">
          <div className="flex flex-wrap items-baseline gap-2.5">
            <h2 className="font-display text-[11.5px] font-semibold uppercase tracking-[0.2em] text-mut">
              Actual quota
            </h2>
            <span className="text-[11.5px] text-dim">reported by Anthropic</span>
          </div>

          <div className="flex items-center gap-2.5">
            {/* A reading is never shown without its age. */}
            <span className="text-[11.5px] text-dim">
              {data?.fetched_at ? `fetched ${formatRelative(data.fetched_at)}` : 'never fetched'}
            </span>
            <button
              type="button"
              onClick={onRefresh}
              disabled={isRefreshing}
              className="inline-flex items-center gap-1.5 rounded-md border border-line bg-deck-2 px-2.5 py-1 text-[12.5px] font-medium text-mut transition-colors hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-arc/50 disabled:cursor-not-allowed disabled:opacity-60"
            >
              <RefreshCw className={`h-3.5 w-3.5 ${isRefreshing ? 'animate-spin' : ''}`} />
              {isRefreshing ? 'Fetching…' : 'Fetch'}
            </button>
          </div>
        </div>

        {!data && <Placeholder>Loading…</Placeholder>}

        {data && !data.available && <Degraded status={data.status} reason={data.reason} />}

        {data?.available && limits.length === 0 && (
          <Placeholder>Anthropic reported no active limits for this account.</Placeholder>
        )}

        {data?.available && limits.length > 0 && (
          <div className="flex flex-col gap-2.5">
            {limits.map((limit) => (
              <LimitRow key={`${limit.kind}-${limit.scope_label ?? ''}`} limit={limit} />
            ))}
          </div>
        )}
      </div>
    </Card>
  )
}

function LimitRow({ limit }: { limit: QuotaLimit }) {
  const pct = limit.percent ?? 0
  const tone = toneFor(limit.severity, pct)

  return (
    <div className="flex flex-col gap-1">
      <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
        <span className="flex min-w-0 items-baseline gap-1.5">
          <span className="truncate text-[12.5px] text-ink">{labelFor(limit)}</span>
          {limit.is_active && (
            <span className="shrink-0 rounded-full border border-line-soft px-1.5 text-[10px] uppercase tracking-[0.1em] text-dim">
              binding
            </span>
          )}
        </span>
        <span className="shrink-0 text-[11.5px] text-mut">
          <strong className={`font-mono2 text-[14px] font-semibold ${tone.text}`}>
            {limit.percent === null ? '—' : `${pct}%`}
          </strong>
          {limit.resets_at && (
            <span className="ml-2 text-dim">resets in {untilReset(limit.resets_at)}</span>
          )}
        </span>
      </div>
      <div
        className="h-[7px] overflow-hidden rounded-full border border-line-soft bg-deck-2"
        role="img"
        aria-label={`${labelFor(limit)}: ${pct}% used${
          limit.resets_at ? `, resets in ${untilReset(limit.resets_at)}` : ''
        }.`}
      >
        <div
          className={`h-full rounded-full ${tone.bar}`}
          style={{ width: `${Math.min(100, Math.max(0, pct))}%` }}
        />
      </div>
    </div>
  )
}

/** An outage is shown as an outage — never as zeroes. */
function Degraded({
  status,
  reason,
}: {
  status: QuotaUtilization['status']
  reason: string | null
}) {
  const neverFetched = status === 'never_fetched'

  return (
    <div
      className={`flex items-start gap-2 rounded-md border px-3 py-2.5 text-[12.5px] ${
        neverFetched ? 'border-line-soft text-mut' : 'border-gold/40 bg-gold/10 text-gold'
      }`}
    >
      {!neverFetched && <AlertCircle className="mt-px h-4 w-4 shrink-0" />}
      <span className="min-w-0 break-words">{reason ?? 'Could not read your quota.'}</span>
    </div>
  )
}

function Placeholder({ children }: { children: React.ReactNode }) {
  return <p className="py-1 text-[12.5px] text-dim">{children}</p>
}

/**
 * Severity is Anthropic's call, not ours — an unknown value falls back to
 * neutral rather than being guessed at from the percentage.
 */
function toneFor(severity: string, pct: number): { bar: string; text: string } {
  if (severity === 'critical' || pct >= 95) return { bar: 'bg-bad', text: 'text-bad' }
  if (severity === 'warning' || severity === 'high') return { bar: 'bg-gold', text: 'text-gold' }
  return { bar: 'bg-arc', text: 'text-ink' }
}

const KIND_LABEL: Record<string, string> = {
  session: '5-hour session',
  weekly_all: 'Weekly · all models',
  weekly_scoped: 'Weekly',
}

function labelFor(limit: QuotaLimit): string {
  const base = KIND_LABEL[limit.kind] ?? humanize(limit.kind)
  return limit.scope_label ? `${base} · ${limit.scope_label}` : base
}

/** So a bucket kind we have never seen still reads as words. */
function humanize(kind: string): string {
  return kind.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase())
}

function untilReset(iso: string): string {
  const minutes = Math.round((new Date(iso).getTime() - Date.now()) / 60_000)
  if (minutes <= 0) return 'moments'
  if (minutes < 60 * 24) return formatDuration(minutes)
  const days = Math.round(minutes / (60 * 24))
  return `${days} day${days === 1 ? '' : 's'}`
}
