/** Shared formatting for the cost views — one definition, so columns agree. */

export function formatTokens(n: number): { value: string; unit: string } {
  if (n >= 1e9) return { value: (n / 1e9).toFixed(2), unit: 'B' }
  if (n >= 1e6) return { value: (n / 1e6).toFixed(1), unit: 'M' }
  if (n >= 1e3) return { value: (n / 1e3).toFixed(1), unit: 'K' }
  return { value: String(n), unit: '' }
}

export function formatMoney(n: number): string {
  // Cents are shown or not, but never half-shown — $46.1 reads as broken.
  const decimals = n < 100 ? 2 : 0
  return `$${n.toLocaleString('en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  })}`
}

export function formatDuration(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return h > 0 ? `${h}h ${m}m` : `${m}m`
}

export function formatRelative(iso: string | null): string {
  if (!iso) return '—'
  const diffMin = Math.round((Date.now() - new Date(iso).getTime()) / 60_000)
  if (diffMin < 1) return 'just now'
  if (diffMin < 60) return `${diffMin} min ago`
  const hours = Math.round(diffMin / 60)
  if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`
  const days = Math.round(hours / 24)
  return days === 1 ? 'yesterday' : `${days} days ago`
}

/** Strip the date-suffix noise so a model column stays readable. */
export function shortModel(model: string): string {
  return model
    .replace(/^claude-/, '')
    .replace(/-\d{8}$/, '')
    .replace(/-/g, ' ')
    .replace(/\b(\d)\s(\d)\b/, '$1.$2')
    .replace(/\b\w/g, (c) => c.toUpperCase())
}
