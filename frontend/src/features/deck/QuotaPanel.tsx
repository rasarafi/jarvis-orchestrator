import { AlertCircle, RefreshCw } from 'lucide-react'
import { formatTokens } from '@/lib/dates'
import { Card, CardHeader } from './ui'
import { useQuotaWindow, useRefreshQuota } from './hooks/use-quota-window'

function clock(iso: string): string {
  return new Date(iso).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false })
}

function minutesAgo(iso: string | null): string {
  if (!iso) return 'no index data'
  const mins = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60_000))
  return mins < 1 ? 'just now' : `${mins}m ago`
}

function Stat({ value, label, gold = false }: { value: string; label: string; gold?: boolean }) {
  return (
    <div>
      <div className={`font-display text-[19px] font-semibold ${gold ? 'text-gold' : 'text-ink'}`}>{value}</div>
      <div className="mt-px font-mono2 text-[10.5px] uppercase tracking-wide text-dim">{label}</div>
    </div>
  )
}

export function QuotaPanel() {
  const { data: response, isLoading, isError } = useQuotaWindow()
  const refresh = useRefreshQuota()
  const win = response?.data ?? null
  const noOpenWindow = !isLoading && !isError && !win

  const asOf = win?.as_of ?? response?.meta?.as_of ?? null
  const nearCeiling = win?.pct_of_ceiling != null && win.pct_of_ceiling >= 80

  return (
    <Card>
      <CardHeader
        title="Quota window"
        count={win ? `${clock(win.window_started_at)}–${clock(win.window_ends_at)} · ${win.elapsed_pct}% elapsed` : undefined}
        action={
          <button
            onClick={() => refresh.mutate()}
            disabled={refresh.isPending}
            aria-label="Rescan transcripts and refresh"
            title="Rescan transcripts and refresh"
            className="inline-flex items-center gap-1 rounded-md border border-line px-1.5 py-0.5 font-mono2 text-[10.5px] text-mut transition-colors hover:border-arc/40 hover:text-arc disabled:cursor-not-allowed disabled:opacity-50"
          >
            <RefreshCw className={`h-3 w-3 ${refresh.isPending ? 'animate-spin' : ''}`} /> refresh
          </button>
        }
      />

      {isError && (
        <div className="flex items-center gap-2 px-4 py-5 text-[12.5px] text-bad">
          <AlertCircle className="h-3.5 w-3.5" /> Quota estimates unavailable — couldn&apos;t reach the analytics API.
        </div>
      )}

      {isLoading && !isError && (
        <div className="px-4 py-5 text-[12.5px] text-dim">…</div>
      )}

      {noOpenWindow && (
        <div className="px-4 py-5 text-[12.5px] text-dim">
          No activity in an open 5-hour window. The next prompt opens one.
        </div>
      )}

      {win && (
        <>
          <div className="grid grid-cols-2 gap-4 px-4 py-3 sm:grid-cols-4">
            <Stat value={formatTokens(win.tokens_used)} label="tokens this window" />
            <Stat value={`${formatTokens(win.burn_tokens_per_min)}/min`} label="burn rate (60m)" />
            <Stat value={formatTokens(win.projected_total)} label="projected total" />
            <Stat
              value={win.pct_of_ceiling != null ? `${win.pct_of_ceiling}%` : '—'}
              label="of observed ceiling"
              gold={nearCeiling}
            />
          </div>

          {win.ceiling_tokens != null && (
            <div className="mx-4 mb-1 h-[5px] overflow-hidden rounded-full bg-hover-strong/60">
              <div
                className={`h-full rounded-full ${nearCeiling ? 'bg-gold' : 'bg-arc'}`}
                style={{ width: `${Math.min(100, win.pct_of_ceiling ?? 0)}%` }}
              />
            </div>
          )}
        </>
      )}

      <div className="px-4 pb-3 pt-1 font-mono2 text-[10.5px] text-dim">
        local estimate · as of {minutesAgo(asOf)} · ceiling = your max 5h window, last 30 days
      </div>
    </Card>
  )
}
