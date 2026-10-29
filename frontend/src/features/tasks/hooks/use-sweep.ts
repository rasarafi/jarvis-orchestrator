import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { sweepApi } from '@/lib/api-client'
import type { RecapModelChoice, SweepRunResponse } from '@/types/api'

export function useLatestSweep() {
  return useQuery({
    queryKey: ['sweep', 'latest'],
    queryFn: () => sweepApi.latest().then((r) => r.data.data),
  })
}

export function useRunSweep() {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: (body: { group?: string; project?: string; window_days?: number; model?: RecapModelChoice }) =>
      sweepApi.run(body).then((r) => r.data.data),
    onSuccess: (result: SweepRunResponse) => {
      qc.setQueryData(['sweep', 'latest'], result)
    },
  })
}

export type SweepMatchAction = 'confirm' | 'dismiss' | 'undo'

export function useSweepMatchAction() {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: ({ id, action }: { id: string; action: SweepMatchAction }) =>
      sweepApi[action](id).then((r) => r.data.data),
    onSuccess: (match) => {
      // Patch the match in place so the card flips without a refetch
      qc.setQueryData<SweepRunResponse | null | undefined>(['sweep', 'latest'], (prev) =>
        prev
          ? { ...prev, matches: prev.matches.map((m) => (m.id === match.id ? { ...m, ...match } : m)) }
          : prev,
      )
      qc.invalidateQueries({ queryKey: ['tasks'] })
    },
  })
}
