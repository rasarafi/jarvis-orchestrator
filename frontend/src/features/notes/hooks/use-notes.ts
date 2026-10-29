import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { notesApi } from '@/lib/api-client'
import type { NotesResponse } from '@/types/api'

export function useNotes(status: string, project?: string) {
  return useQuery({
    queryKey: ['notes', status, project ?? null],
    queryFn: () =>
      notesApi.list({ status, ...(project ? { project } : {}) }).then((r) => r.data as NotesResponse),
  })
}

export function useNoteCounts() {
  return useQuery({
    queryKey: ['notes', 'inbox', null],
    queryFn: () => notesApi.list({ status: 'inbox' }).then((r) => r.data as NotesResponse),
    staleTime: 60_000,
  })
}

function useNotesInvalidation() {
  const qc = useQueryClient()
  return () => qc.invalidateQueries({ queryKey: ['notes'] })
}

export function useCaptureNote() {
  const invalidate = useNotesInvalidation()
  return useMutation({
    mutationFn: (data: { body: string; project_id?: string }) => notesApi.create(data),
    onSuccess: invalidate,
  })
}

export function useNoteTransition() {
  const invalidate = useNotesInvalidation()
  return useMutation({
    mutationFn: ({ id, action }: { id: string; action: 'keep' | 'archive' | 'restore' }) =>
      notesApi[action](id),
    onSuccess: invalidate,
  })
}

export function useUpdateNote() {
  const invalidate = useNotesInvalidation()
  return useMutation({
    mutationFn: ({
      id,
      ...data
    }: {
      id: string
      body?: string
      project_id?: string | null
      pinned?: boolean
    }) => notesApi.update(id, data),
    onSuccess: invalidate,
  })
}

export function useConvertNote() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...data }: { id: string; priority?: string; project_id?: string }) =>
      notesApi.convert(id, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['notes'] })
      qc.invalidateQueries({ queryKey: ['tasks'] })
      qc.invalidateQueries({ queryKey: ['projects'] })
    },
  })
}

export function usePromoteNote() {
  const invalidate = useNotesInvalidation()
  return useMutation({
    mutationFn: ({ id, title, type }: { id: string; title: string; type: string }) =>
      notesApi.promote(id, { title, type }),
    onSuccess: invalidate,
  })
}

export function useDeleteNote() {
  const invalidate = useNotesInvalidation()
  return useMutation({
    mutationFn: (id: string) => notesApi.delete(id),
    onSuccess: invalidate,
  })
}
