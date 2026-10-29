import type { LiveSession } from '@/types/live-sessions'

/** Sub-agent runs land as separate sessions on the same branch — collapse them. */
export function groupChips(sessions: LiveSession[]): LiveSession[][] {
  const byBranch = new Map<string, LiveSession[]>()
  for (const s of sessions) {
    const key = s.git_branch ?? `solo-${s.session_id}`
    byBranch.set(key, [...(byBranch.get(key) ?? []), s])
  }
  return [...byBranch.values()]
}
