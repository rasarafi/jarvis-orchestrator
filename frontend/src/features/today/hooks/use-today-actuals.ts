import { useQuery } from '@tanstack/react-query'
import { analyticsApi } from '@/lib/api-client'
import type { TodaySessionSpan } from '@/types/today'

/**
 * Actual CC-session spans for the day, from GET /api/analytics/today-sessions.
 * The transcripts are the timesheet — zero manual logging.
 */
export function useTodayActuals(date?: string) {
  return useQuery<TodaySessionSpan[]>({
    queryKey: ['today-actuals', date ?? 'default'],
    queryFn: async () => (await analyticsApi.todaySessions(date)).data.data,
    refetchInterval: 120_000,
    staleTime: 60_000,
  })
}
