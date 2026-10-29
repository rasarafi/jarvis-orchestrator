import { useQuery } from '@tanstack/react-query'
import { projectsApi, tasksApi } from '@/lib/api-client'
import { projectColorsApi } from '@/lib/today-api'
import type { Project, ProjectContextSection, ProjectOverview, Task } from '@/types/api'

export function useProjects() {
  return useQuery({
    queryKey: ['projects'],
    queryFn: () => projectsApi.list().then((r) => r.data.data as Project[]),
  })
}

export function useProjectDetail(slug: string) {
  return useQuery({
    queryKey: ['projects', slug],
    queryFn: () => projectsApi.detail(slug).then((r) => r.data.data as Project),
    enabled: !!slug,
  })
}

export function useProjectContext(slug: string) {
  return useQuery({
    queryKey: ['projects', slug, 'context'],
    queryFn: () => projectsApi.context(slug).then((r) => r.data.data as ProjectContextSection[]),
    enabled: !!slug,
  })
}

export function useProjectOverview(slug: string) {
  return useQuery({
    queryKey: ['projects', slug, 'overview'],
    queryFn: () => projectsApi.overview(slug).then((r) => r.data.data as ProjectOverview),
    enabled: !!slug,
    refetchInterval: 60_000,
  })
}

/** slug → CSS color map; falls back to projectColor(name) hashing at call sites. */
export function useProjectColors() {
  return useQuery({
    queryKey: ['projects', 'colors'],
    queryFn: () => projectColorsApi.fetch().then((r) => r.data.data),
    staleTime: 5 * 60_000,
  })
}

// The tasks API filters by project UUID, not slug.
export function useProjectTasks(projectId: string) {
  return useQuery({
    queryKey: ['tasks', { project: projectId }],
    queryFn: () => tasksApi.list({ project: projectId }).then((r) => r.data.data as Task[]),
    enabled: !!projectId,
  })
}
