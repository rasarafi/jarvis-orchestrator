import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Loader2, AlertCircle, Inbox as InboxIcon } from 'lucide-react'
import { cn } from '@/lib/utils'
import { projectColor } from '@/features/deck/ui'
import { projectLabel } from '@/lib/project-label'
import { ProjectCombobox, type ProjectOption } from '@/components/ProjectCombobox'
import { buildGroupedOptions, type ProjectGroupKey } from '@/lib/project-groups'
import { Layers } from 'lucide-react'
import { useProjects } from '@/features/projects/hooks/use-projects'
import { useDeleteTask } from '@/features/tasks/hooks/use-tasks'
import type { Note } from '@/types/api'
import { NoteCard } from './NoteCard'
import {
  useCaptureNote,
  useConvertNote,
  useDeleteNote,
  useNotes,
  useNoteTransition,
  usePromoteNote,
  useUpdateNote,
} from './hooks/use-notes'

type Status = 'inbox' | 'kept' | 'archived'

interface LastAction {
  kind: 'keep' | 'archive' | 'convert'
  noteId: string
  taskId?: string
  label: string
}

const isEditable = (el: EventTarget | null) =>
  el instanceof HTMLElement && (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA' || el.isContentEditable)

export function NotesPage() {
  const [status, setStatus] = useState<Status>('inbox')
  const [projectFilter, setProjectFilter] = useState<ProjectOption | null>(null)
  const { data, isLoading, error } = useNotes(status)
  const { data: allProjects } = useProjects()

  const [body, setBody] = useState('')
  const [captureProject, setCaptureProject] = useState<string | null>(null)
  const captureRef = useRef<HTMLInputElement>(null)

  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [inlineOpen, setInlineOpen] = useState<{ id: string; kind: 'convert' | 'promote' } | null>(null)
  const [lastAction, setLastAction] = useState<LastAction | null>(null)
  const undoTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const capture = useCaptureNote()
  const transition = useNoteTransition()
  const updateNote = useUpdateNote()
  const convert = useConvertNote()
  const promote = usePromoteNote()
  const deleteNote = useDeleteNote()
  const deleteTask = useDeleteTask()

  const counts = data?.meta.counts
  const notes = useMemo(() => {
    const list = data?.data ?? []
    if (!projectFilter) return list
    return projectFilter.groupKey
      ? list.filter((n) => n.project?.group_key === projectFilter.groupKey)
      : list.filter((n) => projectLabel(n.project) === projectFilter.name)
  }, [data, projectFilter])

  const projectRefs = useMemo(
    () => (allProjects ?? []).map((p) => ({ id: p.id, name: p.name })),
    [allProjects],
  )

  const comboboxOptions = useMemo(() => {
    const names = new Map<string, number>()
    for (const p of allProjects ?? []) names.set(p.name, 0)
    return [...names.keys()].sort().map((name) => ({ name, count: 0 }))
  }, [allProjects])

  const railProjects = useMemo(() => {
    // Note counts per project label, remembering each label's group.
    const counts = new Map<string, { count: number; group: ProjectGroupKey | null }>()
    for (const n of data?.data ?? []) {
      const name = projectLabel(n.project)
      const entry = counts.get(name) ?? { count: 0, group: n.project?.group_key ?? null }
      entry.count += 1
      counts.set(name, entry)
    }

    return buildGroupedOptions(counts)
  }, [data])

  const selectedIndex = useMemo(() => notes.findIndex((n) => n.id === selectedId), [notes, selectedId])

  const flashUndo = useCallback((action: LastAction) => {
    if (undoTimer.current) clearTimeout(undoTimer.current)
    setLastAction(action)
    undoTimer.current = setTimeout(() => setLastAction(null), 10_000)
  }, [])

  const submitCapture = () => {
    const text = body.trim()
    if (!text) return
    const projectId = captureProject ? projectRefs.find((p) => p.name === captureProject)?.id : undefined
    capture.mutate(
      { body: text, ...(projectId ? { project_id: projectId } : {}) },
      { onSuccess: () => { setBody(''); captureRef.current?.focus() } },
    )
  }

  const doTransition = useCallback((note: Note, action: 'keep' | 'archive' | 'restore') => {
    transition.mutate({ id: note.id, action })
    if (action === 'keep') flashUndo({ kind: 'keep', noteId: note.id, label: 'Kept' })
    if (action === 'archive') flashUndo({ kind: 'archive', noteId: note.id, label: 'Archived' })
    setInlineOpen(null)
  }, [transition, flashUndo])

  const doConvert = useCallback((note: Note, payload: { priority: string; project_id?: string }) => {
    convert.mutate(
      { id: note.id, ...payload },
      {
        onSuccess: (res) => {
          const task = res.data.data
          flashUndo({ kind: 'convert', noteId: note.id, taskId: task.id, label: `Task created — ${task.title.slice(0, 48)}` })
        },
      },
    )
    setInlineOpen(null)
  }, [convert, flashUndo])

  const doPromote = useCallback((note: Note, payload: { title: string; type: string }) => {
    promote.mutate({ id: note.id, ...payload })
    setInlineOpen(null)
  }, [promote])

  const undo = useCallback(() => {
    if (!lastAction) return
    transition.mutate({ id: lastAction.noteId, action: 'restore' })
    if (lastAction.kind === 'convert' && lastAction.taskId) deleteTask.mutate(lastAction.taskId)
    setLastAction(null)
    if (undoTimer.current) clearTimeout(undoTimer.current)
  }, [lastAction, transition, deleteTask])

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if (e.metaKey || e.ctrlKey || e.altKey) return
      if (isEditable(e.target)) {
        if (e.key === 'Escape') setInlineOpen(null)
        return
      }

      const current = selectedIndex >= 0 ? notes[selectedIndex] : undefined

      switch (e.key) {
        case 'j': setSelectedId(notes[Math.min(selectedIndex + 1, notes.length - 1)]?.id ?? null); break
        case 'k': setSelectedId(notes[Math.max(selectedIndex - 1, 0)]?.id ?? null); break
        case 'n': e.preventDefault(); captureRef.current?.focus(); break
        case 'u': undo(); break
        case 'Escape': setInlineOpen(null); break
        case 't': if (current && current.status !== 'archived') setInlineOpen({ id: current.id, kind: 'convert' }); break
        case 'g': if (current && current.status !== 'archived') setInlineOpen({ id: current.id, kind: 'promote' }); break
        case 'Enter': if (current && current.status === 'inbox') doTransition(current, 'keep'); break
        case 'e': if (current && current.status !== 'archived') doTransition(current, 'archive'); break
        case 'p': if (current && current.status === 'kept') updateNote.mutate({ id: current.id, pinned: !current.pinned }); break
      }
    }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [notes, selectedIndex, doTransition, undo, updateNote])

  const statusTabs: { key: Status; label: string; count?: number }[] = [
    { key: 'inbox', label: 'Inbox', count: counts?.inbox },
    { key: 'kept', label: 'Kept', count: counts?.kept },
    { key: 'archived', label: 'Archived', count: counts?.archived },
  ]

  return (
    <div className="pb-16 pt-5">
      {/* Capture bar */}
      <div className="flex items-center gap-2.5 rounded-[10px] border border-line bg-deck px-3 py-2.5 shadow-[var(--card-shadow)] focus-within:border-arc">
        <input
          ref={captureRef}
          value={body}
          onChange={(e) => setBody(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && submitCapture()}
          placeholder="Capture a note… it lands in the inbox, unassigned unless you pick a project"
          className="min-w-0 flex-1 bg-transparent text-[13.5px] text-ink outline-none placeholder:text-dim"
        />
        <ProjectCombobox
          projects={comboboxOptions}
          value={captureProject}
          onChange={(opt) => setCaptureProject(opt?.name ?? null)}
        />
        <button
          onClick={submitCapture}
          disabled={capture.isPending || !body.trim()}
          className="rounded-[7px] bg-arc px-4 py-1.5 text-[12.5px] font-semibold text-deck disabled:opacity-40"
        >
          Capture
        </button>
        <span className="hidden whitespace-nowrap text-[11px] text-dim sm:block">
          <kbd className="rounded border border-line px-1 font-mono2 text-[10px]">n</kbd> new
        </span>
      </div>

      <div className="mt-5 grid grid-cols-1 gap-5 md:grid-cols-[190px_1fr]">
        {/* Rail */}
        <div className="flex flex-col gap-0.5 self-start md:sticky md:top-5">
          {statusTabs.map(({ key, label, count }) => (
            <button
              key={key}
              onClick={() => { setStatus(key); setProjectFilter(null); setSelectedId(null); setInlineOpen(null) }}
              className={cn(
                'flex items-center justify-between rounded-[7px] px-2.5 py-1.5 text-left text-[13px]',
                status === key ? 'bg-arc-soft font-medium text-ink' : 'text-mut hover:bg-hover-strong hover:text-ink',
              )}
            >
              {label}
              <span className={cn('font-mono2 text-[11px] tabular-nums', status === key ? 'text-arc' : 'text-dim')}>
                {count ?? ''}
              </span>
            </button>
          ))}

          {railProjects.length > 0 && (
            <>
              <div className="mx-2.5 mb-1 mt-3.5 text-[10px] font-semibold tracking-[0.14em] text-dim">PROJECTS</div>
              {railProjects.map((row) => {
                const active = row.groupKey
                  ? projectFilter?.groupKey === row.groupKey
                  : !projectFilter?.groupKey && projectFilter?.name === row.name
                return (
                  <button
                    key={row.groupKey ? `group:${row.groupKey}` : row.name}
                    onClick={() => setProjectFilter(active ? null : row)}
                    className={cn(
                      'flex items-center gap-2 rounded-[7px] px-2.5 py-1.5 text-left text-[12.5px]',
                      row.grouped && 'pl-5',
                      active ? 'bg-arc-soft text-ink' : 'text-mut hover:bg-hover-strong hover:text-ink',
                    )}
                  >
                    {row.groupKey ? (
                      <Layers className="h-3 w-3 shrink-0 text-dim" />
                    ) : (
                      <span className="h-[7px] w-[7px] shrink-0 rounded-full" style={{ background: projectColor(row.name) }} />
                    )}
                    <span className={cn('truncate', row.groupKey && 'font-semibold')}>{row.name}</span>
                    <span className="ml-auto font-mono2 text-[11px] tabular-nums text-dim">{row.count}</span>
                  </button>
                )
              })}
            </>
          )}
        </div>

        {/* Notes column */}
        <div className="flex min-w-0 flex-col gap-2">
          {isLoading && (
            <div className="flex items-center gap-2 py-10 text-mut"><Loader2 className="h-4 w-4 animate-spin" /> Loading notes…</div>
          )}
          {error != null && (
            <div className="flex items-center gap-2 py-10 text-bad"><AlertCircle className="h-4 w-4" /> Failed to load notes.</div>
          )}
          {!isLoading && !error && notes.length === 0 && (
            <div className="flex flex-col items-center gap-2 py-14 text-dim">
              <InboxIcon className="h-5 w-5" />
              {status === 'inbox' ? 'Inbox zero — nothing to triage.' : `No ${status} notes.`}
            </div>
          )}

          {notes.map((note) => (
            <NoteCard
              key={note.id}
              note={note}
              selected={note.id === selectedId}
              projects={projectRefs}
              convertOpen={inlineOpen?.id === note.id && inlineOpen.kind === 'convert'}
              promoteOpen={inlineOpen?.id === note.id && inlineOpen.kind === 'promote'}
              onSelect={() => setSelectedId(note.id)}
              onTransition={(action) => doTransition(note, action)}
              onPin={() => updateNote.mutate({ id: note.id, pinned: !note.pinned })}
              onOpenConvert={() => { setSelectedId(note.id); setInlineOpen({ id: note.id, kind: 'convert' }) }}
              onOpenPromote={() => { setSelectedId(note.id); setInlineOpen({ id: note.id, kind: 'promote' }) }}
              onConvert={(payload) => doConvert(note, payload)}
              onPromote={(payload) => doPromote(note, payload)}
              onCloseInline={() => setInlineOpen(null)}
              onDelete={() => deleteNote.mutate(note.id)}
            />
          ))}

          {/* Keyboard legend */}
          <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-line-soft pt-2.5 text-[11px] text-dim">
            {[['j/k', 'navigate'], ['t', '→ task'], ['g', '→ knowledge'], ['Enter', 'keep'], ['e', 'archive'], ['p', 'pin'], ['u', 'undo'], ['n', 'new']].map(([key, label]) => (
              <span key={key} className="flex items-center gap-1.5">
                <kbd className="rounded border border-line px-1 font-mono2 text-[10px]">{key}</kbd> {label}
              </span>
            ))}
            <span className="ml-auto hidden lg:block">sources: PHONE = quick note · DECK = capture bar · CHAT = jarvis remembered</span>
          </div>
        </div>
      </div>

      {/* Undo toast */}
      {lastAction && (
        <div className="fixed bottom-5 left-1/2 z-50 flex -translate-x-1/2 items-center gap-3 rounded-[9px] border border-ok/50 bg-deck px-3.5 py-2 text-[12.5px] text-ink shadow-[var(--pill-shadow)]">
          <span className="font-medium text-ok">✓</span> {lastAction.label}
          <button onClick={undo} className="font-semibold text-arc underline-offset-2 hover:underline">Undo</button>
        </div>
      )}
    </div>
  )
}
