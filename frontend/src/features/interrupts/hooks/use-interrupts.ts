import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { interruptsApi } from '@/lib/api-client'
import type { Interrupt } from '@/types/api'

export function useInterrupts() {
  return useQuery({
    queryKey: ['interrupts'],
    queryFn: async (): Promise<Interrupt[]> => (await interruptsApi.list()).data.data,
    refetchInterval: 15_000,
  })
}

export function useResolveInterrupt() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => interruptsApi.resolve(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['interrupts'] })
    },
  })
}
