import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { tasksApi } from '@/lib/api-client'
import type { Task, UrgentResponse } from '@/types/api'

export function useTasks(filters?: {
  project?: string
  priority?: string
  status?: string
  sort?: string
  completed_within?: string
}) {
  return useQuery({
    queryKey: ['tasks', filters],
    queryFn: () => tasksApi.list(filters).then((r) => r.data.data as Task[]),
  })
}

export function useUrgentTasks() {
  return useQuery({
    queryKey: ['tasks', 'urgent'],
    queryFn: () => tasksApi.urgent().then((r) => r.data as UrgentResponse),
  })
}

export function useCreateTask() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: { project_id: string; title: string; priority?: string }) =>
      tasksApi.create(data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['tasks'] }),
  })
}

export function useUpdateTask() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({
      id,
      ...data
    }: {
      id: string
      status?: string
      priority?: string
      title?: string
      deadline?: string
    }) => tasksApi.update(id, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['tasks'] })
      // /api/projects embeds each project's open tasks (workstream lanes' "next" row).
      qc.invalidateQueries({ queryKey: ['projects'] })
    },
  })
}

/** Bulk ops fan out as parallel per-task requests so each write keeps its own task_events row. */
export function useBulkUpdateTasks() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ ids, ...data }: { ids: string[]; status?: string; priority?: string; deadline?: string }) =>
      Promise.all(ids.map((id) => tasksApi.update(id, data))),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['tasks'] })
      qc.invalidateQueries({ queryKey: ['projects'] })
    },
  })
}

export function useBulkArchiveTasks() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (ids: string[]) => Promise.all(ids.map((id) => tasksApi.archive(id))),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['tasks'] })
      qc.invalidateQueries({ queryKey: ['projects'] })
    },
  })
}

export function useDeleteTask() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => tasksApi.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['tasks'] })
      qc.invalidateQueries({ queryKey: ['projects'] })
    },
  })
}

export function useBulkDeleteTasks() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (ids: string[]) => Promise.all(ids.map((id) => tasksApi.delete(id))),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['tasks'] })
      qc.invalidateQueries({ queryKey: ['projects'] })
    },
  })
}
