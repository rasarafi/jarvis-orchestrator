import { useEffect, useRef } from 'react'
import type { LiveSession } from '@/types/live-sessions'
import { useAppStore } from '@/stores/app-store'
import { sessionNeedsHuman } from '../needs'

/**
 * Fires a browser notification when a session newly flips to needs-you.
 * Mount exactly once (DeckShell). Diffs against the previous poll so only
 * transitions notify — never the initial page load.
 */
export function useAttentionAlerts(sessions: LiveSession[] | undefined) {
  const alertsEnabled = useAppStore((s) => s.alertsEnabled)
  const prev = useRef<Set<string> | null>(null)

  useEffect(() => {
    if (!sessions) return
    const current = new Set(sessions.filter(sessionNeedsHuman).map((s) => s.session_id))

    if (
      prev.current !== null &&
      alertsEnabled &&
      typeof Notification !== 'undefined' &&
      Notification.permission === 'granted'
    ) {
      for (const s of sessions) {
        if (!sessionNeedsHuman(s) || prev.current.has(s.session_id)) continue
        const n = new Notification(`${s.project_name} needs you`, {
          body: s.attention?.message ?? s.last_output_line ?? 'Session is waiting on you.',
          tag: s.session_id,
        })
        n.onclick = () => window.focus()
      }
    }

    prev.current = current
  }, [sessions, alertsEnabled])
}

/** Prefixes the tab title with the needs-you count, e.g. "(2) Jarvis v2". */
export function useTitleBadge(count: number) {
  useEffect(() => {
    const base = 'Jarvis v2' // matches frontend/index.html <title>
    document.title = count > 0 ? `(${count}) ${base}` : base
    return () => {
      document.title = base
    }
  }, [count])
}
