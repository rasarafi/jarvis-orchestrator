import { useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { focusApi, scheduleApi } from '@/lib/api-client'
import type { FocusSession } from '@/types/today'

const HEARTBEAT_MS = 5 * 60 * 1000

function elapsedSeconds(session: FocusSession): number {
  return Math.max(0, Math.round((Date.now() - new Date(session.started_at).getTime()) / 1000))
}

export function useStartFocus() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (taskId: string) => focusApi.start(taskId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['today'] })
      qc.invalidateQueries({ queryKey: ['tasks'] })
    },
  })
}

export function useEndFocus() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (session: FocusSession) => focusApi.end(session.id, elapsedSeconds(session)),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['today'] })
      qc.invalidateQueries({ queryKey: ['tasks'] })
    },
  })
}

export function useReschedule() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ scheduledTaskId, action }: { scheduledTaskId: string; action: string }) =>
      scheduleApi.reschedule(scheduledTaskId, action),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['today'] }),
  })
}

/** Keeps an active focus session's elapsed_seconds fresh server-side. Mount once. */
export function useFocusHeartbeat(session: FocusSession | null) {
  useEffect(() => {
    if (!session || session.status !== 'active') return
    const id = setInterval(() => {
      focusApi.heartbeat(session.id, elapsedSeconds(session)).catch(() => {})
    }, HEARTBEAT_MS)
    return () => clearInterval(id)
  }, [session])
}
