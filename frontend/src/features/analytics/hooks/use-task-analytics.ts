import { useQuery } from '@tanstack/react-query'
import { analyticsApi } from '@/lib/api-client'
import type { TaskAnalytics } from '@/types/api'
import type { ProjectGroupKey } from '@/lib/project-groups'

/** Backlog pressure, flow, age and deadline coverage for the task-analysis page. */
export function useTaskAnalytics(group: ProjectGroupKey | null) {
  return useQuery({
    queryKey: ['analytics', 'tasks', group],
    queryFn: () => analyticsApi.tasks(group ?? undefined).then((r) => r.data.data as TaskAnalytics),
  })
}
