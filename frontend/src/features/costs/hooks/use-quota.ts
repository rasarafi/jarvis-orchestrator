import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { quotaApi } from '@/lib/api-client'
import type { QuotaUtilization } from '@/types/api'

const KEY = ['quota', 'latest']

/**
 * The last stored reading. Deliberately no `refetchInterval` — this reads our
 * own DB, and the only thing that can change it is the user pressing Fetch.
 */
export function useQuotaLatest() {
  return useQuery({
    queryKey: KEY,
    queryFn: () => quotaApi.latest().then((r) => r.data.data as QuotaUtilization),
  })
}

/**
 * The one call that reaches Anthropic. Manual only — never wire this to a timer
 * or an effect.
 */
export function useQuotaRefresh() {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: () => quotaApi.refresh().then((r) => r.data.data as QuotaUtilization),
    // A failed fetch is still a reading the server stored, so it seeds the cache
    // exactly like a success does — the panel shows the outage, not stale numbers.
    onSuccess: (data) => qc.setQueryData(KEY, data),
  })
}
