/**
 * Pure helpers for the Today page. No React, no side effects.
 */

/** Convert an 'HH:mm:ss' time string to minutes since midnight. */
export function timeToMinutes(time: string): number {
  const [h, m] = time.split(':').map(Number)
  return h * 60 + m
}

/** Compute the percentage (0–100) of a time within the 24-hour day. */
export function percentOfDay(time: string): number {
  return (timeToMinutes(time) / (24 * 60)) * 100
}

/** Format 'HH:mm:ss' as 'H:mm AM/PM' or '24:00' style — pick one consistent style. */
export function formatClock(time: string): string {
  // Project uses 24h clock throughout the mockups, so just trim seconds.
  return time.slice(0, 5)
}

/** Format a duration (minutes) as '4 hrs', '1h 30m', etc. */
export function formatDuration(startTime: string, endTime: string): string {
  const minutes = Math.max(0, timeToMinutes(endTime) - timeToMinutes(startTime))
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  if (h === 0) return `${m}m`
  if (m === 0) return `${h}h`
  return `${h}h ${m}m`
}

/** Friendly weekday + part-of-day greeting like 'Saturday morning'. */
export function greetingFor(date: Date): string {
  const weekday = date.toLocaleDateString('en-US', { weekday: 'long' })
  const hour = date.getHours()
  const part = hour < 12 ? 'morning' : hour < 17 ? 'afternoon' : 'evening'
  return `${weekday} ${part}`
}

/** 'April 18 · 8:32 AM' style header subtitle. */
export function dateTimeSubtitle(date: Date): string {
  const d = date.toLocaleDateString('en-US', { month: 'long', day: 'numeric' })
  const t = date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
  return `${d} · ${t}`
}
