import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { analyticsApi, sessionsApi } from '@/lib/api-client'
import type { QuotaWindowResponse } from '@/types/api'

export type { QuotaWindow, QuotaWindowResponse } from '@/types/api'

export function useQuotaWindow() {
  return useQuery({
    queryKey: ['quota-window'],
    queryFn: async (): Promise<QuotaWindowResponse> => (await analyticsApi.quotaWindow()).data,
    refetchInterval: 60_000,
    staleTime: 30_000,
  })
}

/** Refresh = incremental transcript scan (fast, safe), then refetch the window. */
export function useRefreshQuota() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => sessionsApi.scan(),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['quota-window'] }),
  })
}
