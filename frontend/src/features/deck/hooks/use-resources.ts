import { useQuery } from '@tanstack/react-query'
import { analyticsApi } from '@/lib/api-client'
import type { ResourceTrendPoint, SystemSnapshot } from '@/types/api'

/** Live machine vitals — polled fast so the deck reflects real pressure. */
export function useResources() {
  return useQuery({
    queryKey: ['analytics', 'resources'],
    queryFn: () => analyticsApi.resources().then((r) => r.data.data as SystemSnapshot),
    refetchInterval: 3_000,
    staleTime: 2_000,
  })
}

/** Persisted trend for the sparkline (survives reloads). */
export function useResourceTrend(windowMinutes = 120) {
  return useQuery({
    queryKey: ['analytics', 'resources', 'trend', windowMinutes],
    queryFn: () =>
      analyticsApi.resourcesTrend(windowMinutes).then((r) => (r.data.data ?? []) as ResourceTrendPoint[]),
    refetchInterval: 30_000,
    staleTime: 20_000,
  })
}
