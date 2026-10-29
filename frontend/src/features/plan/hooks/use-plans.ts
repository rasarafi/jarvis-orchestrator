import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { plansApi } from '@/lib/api-client'
import type { DailyPlan } from '@/types/api'

export function useTodaysPlan() {
  return useQuery({
    queryKey: ['plans', 'today'],
    queryFn: () => plansApi.today().then((r) => r.data.data as DailyPlan | null),
  })
}

export function useStreak() {
  return useQuery({
    queryKey: ['plans', 'streak'],
    queryFn: () =>
      plansApi.streak().then((r) => (r.data.data as { streak: number }).streak),
  })
}

export function useRecentPlans(days = 7) {
  return useQuery({
    queryKey: ['plans', 'recent', days],
    queryFn: () => plansApi.recent(days).then((r) => r.data.data as DailyPlan[]),
  })
}

export function useCreatePlan() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: {
      date: string
      must_do?: string
      should_do?: string
      could_do?: string
    }) => plansApi.store(data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['plans'] }),
  })
}

export function useUpdateOutcome() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, actual_outcome }: { id: string; actual_outcome: string }) =>
      plansApi.updateOutcome(id, actual_outcome),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['plans'] }),
  })
}

export function useMarkCompletion() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...data }: { id: string } & Partial<Record<'must_do_completed' | 'should_do_completed' | 'could_do_completed', boolean>>) =>
      plansApi.updateCompletion(id, data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['plans'] }),
  })
}
