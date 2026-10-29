import { useQuery } from '@tanstack/react-query'
import { sessionsApi } from '@/lib/api-client'
import type { LiveSession } from '@/types/live-sessions'

/** Polls the live-session monitor. 5s cadence keeps lanes feeling live. */
export function useLiveSessions() {
  return useQuery({
    queryKey: ['sessions', 'live'],
    queryFn: async () => (await sessionsApi.live()).data,
    refetchInterval: 5_000,
    refetchIntervalInBackground: false,
    staleTime: 4_000,
  })
}

export function liveCounts(sessions: LiveSession[] | undefined) {
  const list = sessions ?? []
  return {
    total: list.length,
    working: list.filter((s) => s.status === 'working').length,
    waiting: list.filter((s) => s.status === 'waiting').length,
  }
}
