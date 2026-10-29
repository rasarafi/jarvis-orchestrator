import { useQuery } from '@tanstack/react-query'
import { pingApi } from '@/lib/api-client'

export type DbStatus = 'up' | 'down' | 'unknown'

/**
 * Polls the DB-independent /api/ping probe. Distinguishes:
 *  - 'up'      → Postgres reachable, hub data will load
 *  - 'down'    → ping answered but reported db: 'down' (Postgres/Docker down)
 *  - 'unknown' → still loading, or ping itself unreachable (backend down);
 *                we don't cry "DB down" when we can't even reach the API.
 */
export function useDbHealth(): DbStatus {
  const { data, isError } = useQuery({
    queryKey: ['ping'],
    queryFn: () => pingApi.check().then((r) => r.data),
    refetchInterval: 15_000,
    refetchIntervalInBackground: true,
    retry: false,
    staleTime: 10_000,
  })

  if (isError) return 'unknown'
  if (!data) return 'unknown'
  return data.db === 'down' ? 'down' : 'up'
}
