import { useQuery } from '@tanstack/react-query'
import { todayApi } from '@/lib/today-api'
import type { TodayPayload } from '@/types/today'

interface UseTodayOptions {
  /** Optional ISO date (YYYY-MM-DD). Defaults to today (server-side). */
  date?: string
}

export function useToday({ date }: UseTodayOptions = {}) {
  return useQuery<TodayPayload>({
    queryKey: ['today', date ?? 'default'],
    queryFn: async () => {
      const response = await todayApi.fetch(date)
      return response.data
    },
    staleTime: 30_000,
    refetchInterval: 60_000, // re-fetch every minute so the NOW pointer stays accurate
  })
}
