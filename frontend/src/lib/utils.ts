import { clsx, type ClassValue } from "clsx"
import { twMerge } from "tailwind-merge"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/**
 * Format an ISO date string as "Mar 19" style short date.
 * Returns an em-dash for null/undefined input.
 */
export function formatDate(date: string | null): string {
  if (!date) return '\u2014'
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

/**
 * Format an ISO date string as relative time ("5m ago", "2h ago", "3d ago").
 * Falls back to formatDate() for dates older than 7 days.
 */
export function formatRelativeTime(date: string | null): string {
  if (!date) return '\u2014'
  const now = new Date()
  const then = new Date(date)
  const diffMs = now.getTime() - then.getTime()
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMins / 60)
  const diffDays = Math.floor(diffHours / 24)

  if (diffMins < 1) return 'just now'
  if (diffMins < 60) return `${diffMins}m ago`
  if (diffHours < 24) return `${diffHours}h ago`
  if (diffDays < 7) return `${diffDays}d ago`
  return formatDate(date)
}
