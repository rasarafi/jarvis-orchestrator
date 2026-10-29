import { useQuery } from '@tanstack/react-query'
import { analyticsApi } from '@/lib/api-client'
import type { CostSummary, HealthData, WeeklyProjectCost } from '@/types/api'

export function useCostSummary() {
  return useQuery({
    queryKey: ['analytics', 'costs'],
    queryFn: () => analyticsApi.costs().then((r) => r.data.data as CostSummary),
  })
}

export function useHealth() {
  return useQuery({
    queryKey: ['analytics', 'health'],
    // /analytics/health returns the object bare, NOT wrapped in {data: ...};
    // reading .data.data made TanStack Query throw "data cannot be undefined".
    queryFn: () =>
      analyticsApi
        .health()
        .then((r) => ((r.data as unknown as { data?: HealthData }).data ?? r.data) as HealthData),
  })
}

export function useSessionBreakdown() {
  return useQuery({
    queryKey: ['analytics', 'sessions'],
    // Per-project cost breakdown for the current week (was mistyped as
    // {total, today, week}, which rendered "undefined this week").
    queryFn: () => analyticsApi.sessions().then((r) => (r.data.data ?? []) as WeeklyProjectCost[]),
  })
}
