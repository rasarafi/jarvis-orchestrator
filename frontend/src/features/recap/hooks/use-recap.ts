import { useRef } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { recapApi } from '@/lib/api-client'
import type {
  RecapModelChoice,
  RecapOverview,
  RecapRange,
  RecapRollup,
  RecapRunResult,
  RecapSuggestion,
  RecapThreadsPayload,
} from '@/types/api'

export function useRecapOverview(range: RecapRange) {
  return useQuery({
    queryKey: ['recaps', range],
    queryFn: () => recapApi.list(range).then((r) => r.data.data as RecapOverview),
  })
}

/** Merged gist lines for project groups. First fetch after new sessions can take a few
 *  seconds (haiku merge per changed project); headers fall back to the latest headline
 *  until this lands. */
export function useRecapRollups(range: RecapRange, enabled = true) {
  return useQuery({
    queryKey: ['recaps', 'rollups', range],
    queryFn: () => recapApi.rollups(range).then((r) => r.data.data as RecapRollup[]),
    enabled,
    staleTime: 60_000,
  })
}

export function useOpenThreads() {
  return useQuery({
    queryKey: ['recaps', 'threads'],
    queryFn: () => recapApi.threads().then((r) => r.data.data as RecapThreadsPayload),
  })
}

export function useRecapSuggestions() {
  return useQuery({
    queryKey: ['recaps', 'suggestions'],
    queryFn: () => recapApi.suggestions().then((r) => r.data.data as RecapSuggestion[]),
  })
}

/**
 * Runs recap batches in a loop until the backend reports remaining === 0.
 * `variables` stays set while looping; read progress from `data.remaining`.
 */
export function useRunRecap() {
  const qc = useQueryClient()
  const loops = useRef(0)
  const mutation = useMutation({
    mutationFn: (opts: { model: RecapModelChoice; session_id?: string }) =>
      recapApi.run(opts).then((r) => r.data.data as RecapRunResult),
    onSuccess: (result, variables) => {
      qc.invalidateQueries({ queryKey: ['recaps'] })
      if (!variables.session_id && result.remaining > 0 && loops.current < 200) {
        loops.current += 1
        mutation.mutate({ model: variables.model })
      } else {
        loops.current = 0
      }
    },
    onError: () => {
      loops.current = 0
    },
  })
  return mutation
}

export function useDismissThread() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => recapApi.dismissThread(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['recaps'] }),
  })
}

export function useResolveThread() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => recapApi.resolveThread(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['recaps'] }),
  })
}

export function useDismissStaleThreads() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (days?: number) => recapApi.dismissStaleThreads(days).then((r) => r.data.data as { affected: number; ids: string[] }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['recaps'] }),
  })
}

export function useRestoreThreads() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (ids: string[]) => recapApi.restoreThreads(ids),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['recaps'] }),
  })
}
