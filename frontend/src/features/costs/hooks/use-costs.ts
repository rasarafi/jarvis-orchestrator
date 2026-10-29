import { useQuery } from '@tanstack/react-query'
import { costsApi } from '@/lib/api-client'
import type {
  CostAccount,
  CostProjectsData,
  CostRange,
  CostSessionsData,
  CostWindow,
  UsageBehaviorRange,
  UsageBehaviors,
} from '@/types/api'

/**
 * Every hook takes the account filter. `null` means all accounts — the merged
 * view stays the default everywhere, account is only ever a narrowing.
 */

/** The open 5-hour window. Polls faster than the rest — it is the "right now" panel. */
export function useCostWindow(account: CostAccount | null) {
  return useQuery({
    queryKey: ['costs', 'window', account],
    queryFn: () =>
      costsApi.window({ account: account ?? undefined }).then((r) => r.data.data as CostWindow),
    refetchInterval: 60_000,
  })
}

export function useCostProjects(range: CostRange, group: string | null, account: CostAccount | null) {
  return useQuery({
    queryKey: ['costs', 'projects', range, group, account],
    queryFn: () =>
      costsApi
        .projects({ range, group: group ?? undefined, account: account ?? undefined })
        .then((r) => r.data.data as CostProjectsData),
  })
}

/** Session drilldown, fetched only once a project row is actually expanded. */
export function useCostSessions(slug: string | null, range: CostRange, account: CostAccount | null) {
  return useQuery({
    queryKey: ['costs', 'sessions', slug, range, account],
    queryFn: () =>
      costsApi
        .sessions(slug as string, { range, limit: 5, account: account ?? undefined })
        .then((r) => r.data.data as CostSessionsData),
    enabled: slug !== null,
  })
}

/** The usage-behavior breakdown. Its own range is narrower (24h/7d only) than the page's. */
export function useUsageBehaviors(range: UsageBehaviorRange, account: CostAccount | null) {
  return useQuery({
    queryKey: ['costs', 'behaviors', range, account],
    queryFn: () =>
      costsApi.behaviors({ range, account: account ?? undefined }).then((r) => r.data.data as UsageBehaviors),
  })
}
