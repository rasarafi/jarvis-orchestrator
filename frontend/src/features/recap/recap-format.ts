import type { SessionRecap } from '@/types/api'

export function formatDuration(minutes: number): string {
  if (minutes < 60) return `${minutes}m`
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m > 0 ? `${h}h ${m.toString().padStart(2, '0')}m` : `${h}h`
}

export function formatTimeRange(start: string | null, end: string | null): string {
  const fmt = (iso: string) =>
    new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false })
  if (!start || !end) return ''
  return `${fmt(start)}–${fmt(end)}`
}

export function formatEndTime(iso: string | null): string {
  if (!iso) return ''
  return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false })
}

/** Compact "how long ago": 5m ago · 3h ago · yesterday · 4d ago. */
export function relativeAge(iso: string): string {
  const minutes = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 60000))
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.floor(hours / 24)
  if (days === 1) return 'yesterday'
  return `${days}d ago`
}

export function ageDays(iso: string): number {
  return (Date.now() - new Date(iso).getTime()) / 86_400_000
}

export function dayLabel(iso: string): string {
  const d = new Date(iso)
  const today = new Date()
  const yesterday = new Date(today)
  yesterday.setDate(today.getDate() - 1)
  const sameDay = (a: Date, b: Date) => a.toDateString() === b.toDateString()
  const base = d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' })
  if (sameDay(d, today)) return `Today · ${base}`
  if (sameDay(d, yesterday)) return `Yesterday · ${base}`
  return base
}

/** A session counts as significant work, not a quick automated pass, once it clears
 *  either threshold. Duration/message-count based rather than headline text — holds up
 *  even if a review skill's wording changes. */
const SIGNIFICANT_DURATION_MIN = 10
const SIGNIFICANT_MESSAGE_COUNT = 60

export function isSignificant(recap: SessionRecap): boolean {
  return recap.duration_minutes >= SIGNIFICANT_DURATION_MIN || (recap.facts.messages ?? 0) >= SIGNIFICANT_MESSAGE_COUNT
}

export interface QuickBranchGroup {
  branch: string
  recaps: SessionRecap[]
  totalMinutes: number
}

/** Quick (non-significant) sessions grouped by git branch/worktree — the branch name
 *  already identifies which feature thread spawned them. Largest group first. */
function groupQuickByBranch(recaps: SessionRecap[]): QuickBranchGroup[] {
  const groups = new Map<string, SessionRecap[]>()
  for (const recap of recaps) {
    const key = recap.git_branch ?? 'unknown'
    groups.set(key, [...(groups.get(key) ?? []), recap])
  }
  return [...groups.entries()]
    .map(([branch, list]) => ({
      branch,
      recaps: list,
      totalMinutes: list.reduce((sum, r) => sum + r.duration_minutes, 0),
    }))
    .sort((a, b) => b.recaps.length - a.recaps.length)
}

export interface ProjectRecapGroup {
  key: string
  project: SessionRecap['project'] | null
  recaps: SessionRecap[]
  /** Sessions clearing the significance threshold — rendered as full cards. */
  significant: SessionRecap[]
  /** Everything else, collapsed by branch — rendered as one row per branch. */
  quick: QuickBranchGroup[]
  totalMinutes: number
  /** "10:20–14:05" across the group's earliest start / latest end, when both known. */
  timeRange: string
}

/** Group a newest-first recap list by project, preserving recency order between groups. */
export function groupByProject(recaps: SessionRecap[]): ProjectRecapGroup[] {
  const groups = new Map<string, SessionRecap[]>()
  for (const recap of recaps) {
    const key = recap.project?.id ?? 'unassigned'
    groups.set(key, [...(groups.get(key) ?? []), recap])
  }
  return [...groups.entries()].map(([key, list]) => {
    const starts = list.map((r) => r.started_at).filter(Boolean) as string[]
    const ends = list.map((r) => r.ended_at).filter(Boolean) as string[]
    return {
      key,
      project: list[0].project ?? null,
      recaps: list,
      significant: list.filter(isSignificant),
      quick: groupQuickByBranch(list.filter((r) => !isSignificant(r))),
      totalMinutes: list.reduce((sum, r) => sum + r.duration_minutes, 0),
      timeRange: formatTimeRange(
        starts.length ? starts.reduce((a, b) => (a < b ? a : b)) : null,
        ends.length ? ends.reduce((a, b) => (a > b ? a : b)) : null,
      ),
    }
  })
}

export function groupByDay(recaps: SessionRecap[]): { label: string; recaps: SessionRecap[] }[] {
  const groups = new Map<string, SessionRecap[]>()
  for (const recap of recaps) {
    const key = recap.ended_at ? new Date(recap.ended_at).toDateString() : 'unknown'
    groups.set(key, [...(groups.get(key) ?? []), recap])
  }
  return [...groups.entries()].map(([, dayRecaps]) => ({
    label: dayRecaps[0].ended_at ? dayLabel(dayRecaps[0].ended_at) : 'Unknown day',
    recaps: dayRecaps,
  }))
}
