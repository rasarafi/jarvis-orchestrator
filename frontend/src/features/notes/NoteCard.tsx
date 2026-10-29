import { useState } from 'react'
import { cn, formatRelativeTime } from '@/lib/utils'
import { projectColor } from '@/features/deck/ui'
import { projectLabel } from '@/lib/project-label'
import type { Note } from '@/types/api'
import { Pin, Trash2 } from 'lucide-react'

const sourceLabel: Record<Note['source'], string> = {
  remote: 'PHONE',
  deck: 'DECK',
  chat: 'CHAT',
}

export interface ProjectRef {
  id: string
  name: string
}

interface NoteCardProps {
  note: Note
  selected: boolean
  projects: ProjectRef[]
  convertOpen: boolean
  promoteOpen: boolean
  onSelect: () => void
  onTransition: (action: 'keep' | 'archive' | 'restore') => void
  onPin: () => void
  onOpenConvert: () => void
  onOpenPromote: () => void
  onConvert: (data: { priority: string; project_id?: string }) => void
  onPromote: (data: { title: string; type: string }) => void
  onCloseInline: () => void
  onDelete: () => void
}

export function NoteCard({
  note,
  selected,
  projects,
  convertOpen,
  promoteOpen,
  onSelect,
  onTransition,
  onPin,
  onOpenConvert,
  onOpenPromote,
  onConvert,
  onPromote,
  onCloseInline,
  onDelete,
}: NoteCardProps) {
  const [priority, setPriority] = useState('medium')
  const [projectId, setProjectId] = useState<string>(note.project_id ?? '')
  const [title, setTitle] = useState(note.body.slice(0, 60))
  const [ktype, setKtype] = useState('pattern')

  const inbox = note.status === 'inbox'
  const kept = note.status === 'kept'
  const archived = note.status === 'archived'

  return (
    <div
      onClick={onSelect}
      className={cn(
        'group relative rounded-[10px] border bg-deck px-3.5 py-3 shadow-[var(--card-shadow)] transition-colors',
        selected
          ? 'border-arc/50'
          : 'border-line-soft hover:border-line',
        archived && 'opacity-60',
      )}
    >
      {selected && <div className="absolute -left-px top-2 bottom-2 w-[3px] rounded-full bg-arc" />}

      <div className="flex items-start gap-3">
        <p className="m-0 min-w-0 flex-1 max-w-[68ch] text-[13.5px] leading-[1.45] text-ink">
          {kept && note.pinned && <Pin className="mr-1.5 -mt-0.5 inline h-3 w-3 text-gold" />}
          {note.body}
        </p>

        <div
          className={cn(
            'flex shrink-0 items-center gap-1.5 transition-opacity',
            selected ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 focus-within:opacity-100',
          )}
        >
          {inbox && (
            <>
              <button onClick={(e) => { e.stopPropagation(); onOpenConvert() }} className="rounded-[7px] border border-arc/50 px-2 py-0.5 text-[11.5px] text-arc hover:bg-arc-soft">→ Task</button>
              <button onClick={(e) => { e.stopPropagation(); onOpenPromote() }} className="rounded-[7px] border border-gold/50 px-2 py-0.5 text-[11.5px] text-gold hover:bg-gold-soft">→ Knowledge</button>
              <button onClick={(e) => { e.stopPropagation(); onTransition('keep') }} className="rounded-[7px] border border-line px-2 py-0.5 text-[11.5px] text-mut hover:bg-hover-strong hover:text-ink">Keep</button>
              <button onClick={(e) => { e.stopPropagation(); onTransition('archive') }} className="rounded-[7px] border border-line px-2 py-0.5 text-[11.5px] text-mut hover:bg-hover-strong hover:text-ink">Archive</button>
            </>
          )}
          {kept && (
            <>
              <button onClick={(e) => { e.stopPropagation(); onPin() }} className="rounded-[7px] border border-line px-2 py-0.5 text-[11.5px] text-mut hover:bg-hover-strong hover:text-ink">{note.pinned ? 'Unpin' : 'Pin'}</button>
              <button onClick={(e) => { e.stopPropagation(); onOpenConvert() }} className="rounded-[7px] border border-arc/50 px-2 py-0.5 text-[11.5px] text-arc hover:bg-arc-soft">→ Task</button>
              <button onClick={(e) => { e.stopPropagation(); onTransition('archive') }} className="rounded-[7px] border border-line px-2 py-0.5 text-[11.5px] text-mut hover:bg-hover-strong hover:text-ink">Archive</button>
            </>
          )}
          {archived && (
            <>
              <button onClick={(e) => { e.stopPropagation(); onTransition('restore') }} className="rounded-[7px] border border-line px-2 py-0.5 text-[11.5px] text-mut hover:bg-hover-strong hover:text-ink">Restore</button>
              <button onClick={(e) => { e.stopPropagation(); onDelete() }} aria-label="Delete note" className="grid h-6 w-6 place-content-center rounded-[7px] border border-line text-mut hover:border-bad/60 hover:bg-bad/10 hover:text-bad"><Trash2 className="h-3 w-3" /></button>
            </>
          )}
        </div>
      </div>

      <div className="mt-1.5 flex flex-wrap items-center gap-2.5 text-[11px] text-dim">
        <span className="rounded border border-line-soft px-1 font-mono2 text-[9.5px] tracking-[0.08em]">{sourceLabel[note.source]}</span>
        <span className="flex items-center gap-1.5 text-mut">
          <span className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ background: projectColor(projectLabel(note.project)) }} />
          {projectLabel(note.project)}
        </span>
        <span>{formatRelativeTime(note.created_at)}</span>
        {note.converted_task_id && <span className="text-arc">→ became a task</span>}
        {note.promoted_knowledge_id && <span className="text-gold">→ became knowledge</span>}
      </div>

      {convertOpen && (
        <div className="mt-2.5 flex flex-wrap items-center gap-2 border-t border-dashed border-line-soft pt-2.5" onClick={(e) => e.stopPropagation()}>
          <span className="text-[10.5px] tracking-[0.08em] text-dim">→ TASK</span>
          <select value={priority} onChange={(e) => setPriority(e.target.value)} aria-label="Task priority" className="rounded-md border border-line bg-deck px-2 py-1 text-xs text-ink">
            <option value="urgent">urgent</option>
            <option value="high">high</option>
            <option value="medium">medium</option>
            <option value="low">low</option>
          </select>
          <select value={projectId} onChange={(e) => setProjectId(e.target.value)} aria-label="Task project" className="max-w-[180px] rounded-md border border-line bg-deck px-2 py-1 text-xs text-ink">
            <option value="">General (no project)</option>
            {projects.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>
          <button
            onClick={() => onConvert({ priority, ...(projectId ? { project_id: projectId } : {}) })}
            className="rounded-[7px] bg-arc px-3 py-1 text-xs font-semibold text-deck hover:opacity-90"
          >
            Create task &amp; archive note
          </button>
          <button onClick={onCloseInline} className="text-xs text-dim hover:text-mut">esc</button>
        </div>
      )}

      {promoteOpen && (
        <div className="mt-2.5 flex flex-wrap items-center gap-2 border-t border-dashed border-line-soft pt-2.5" onClick={(e) => e.stopPropagation()}>
          <span className="text-[10.5px] tracking-[0.08em] text-dim">→ KNOWLEDGE</span>
          <input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder="Title"
            aria-label="Knowledge title"
            className="w-[240px] rounded-md border border-line bg-deck px-2 py-1 text-xs text-ink outline-none focus:border-arc"
          />
          <select value={ktype} onChange={(e) => setKtype(e.target.value)} aria-label="Knowledge type" className="rounded-md border border-line bg-deck px-2 py-1 text-xs text-ink">
            <option value="fix">fix</option>
            <option value="decision">decision</option>
            <option value="pattern">pattern</option>
            <option value="gotcha">gotcha</option>
          </select>
          <button
            onClick={() => title.trim() && onPromote({ title: title.trim(), type: ktype })}
            className="rounded-[7px] bg-gold px-3 py-1 text-xs font-semibold text-deck hover:opacity-90"
          >
            Promote &amp; archive
          </button>
          <button onClick={onCloseInline} className="text-xs text-dim hover:text-mut">esc</button>
        </div>
      )}
    </div>
  )
}
