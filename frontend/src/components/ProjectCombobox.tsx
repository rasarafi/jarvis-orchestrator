import { useMemo, useRef, useState } from 'react'
import { projectColor } from '@/features/deck/ui'
import type { ProjectGroupKey } from '@/lib/project-groups'
import { X, ListFilter, Layers } from 'lucide-react'
import { cn } from '@/lib/utils'

export interface ProjectOption {
  name: string
  /** Shown as a right-aligned count badge; omit to hide (e.g. audits picker). */
  count?: number
  /** Set when this row selects a whole project group rather than one project. */
  groupKey?: ProjectGroupKey
  /** Render indented under its group header row. */
  grouped?: boolean
}

interface ProjectComboboxProps {
  projects: ProjectOption[]
  value: string | null
  onChange: (option: ProjectOption | null) => void
}

const optionKey = (p: ProjectOption) => (p.groupKey ? `group:${p.groupKey}` : p.name)

/** Type-to-search project filter: live results, arrow-key nav, Enter to pick. */
export function ProjectCombobox({ projects, value, onChange }: ProjectComboboxProps) {
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState(false)
  const [highlight, setHighlight] = useState(0)
  const inputRef = useRef<HTMLInputElement>(null)

  const hits = useMemo(() => {
    const q = query.toLowerCase()
    return q ? projects.filter((p) => p.name.toLowerCase().includes(q)) : projects
  }, [projects, query])

  const pick = (option: ProjectOption) => {
    onChange(option)
    setQuery('')
    setOpen(false)
    inputRef.current?.blur()
  }

  const clear = () => {
    onChange(null)
    setQuery('')
    inputRef.current?.focus()
    setOpen(true)
  }

  const onKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'ArrowDown') {
      setHighlight((h) => Math.min(h + 1, hits.length - 1))
      e.preventDefault()
    } else if (e.key === 'ArrowUp') {
      setHighlight((h) => Math.max(h - 1, 0))
      e.preventDefault()
    } else if (e.key === 'Enter') {
      if (hits[highlight]) pick(hits[highlight])
      e.preventDefault()
    } else if (e.key === 'Escape') {
      if (value) onChange(null)
      setQuery('')
      setOpen(false)
      inputRef.current?.blur()
    }
  }

  return (
    <div className="relative">
      <div
        className={cn(
          'flex w-[150px] items-center gap-1.5 rounded-full border bg-deck py-1 pl-2.5 pr-2 text-dim',
          value ? 'border-arc/50 bg-arc-soft' : 'border-line',
        )}
      >
        <ListFilter className="h-3 w-3 shrink-0" />
        <input
          ref={inputRef}
          role="combobox"
          aria-expanded={open}
          aria-label="Filter by project"
          className={cn(
            'w-full min-w-0 bg-transparent text-xs text-ink outline-none placeholder:text-dim',
            value && 'font-semibold text-arc',
          )}
          placeholder="Project…"
          autoComplete="off"
          value={value && !open ? value : query}
          onFocus={() => {
            setOpen(true)
            setHighlight(0)
          }}
          onBlur={() => {
            // let mousedown on an option land first
            setTimeout(() => {
              setOpen(false)
              setQuery('')
            }, 120)
          }}
          onChange={(e) => {
            if (value) onChange(null)
            setQuery(e.target.value)
            setHighlight(0)
            setOpen(true)
          }}
          onKeyDown={onKeyDown}
        />
        {value && (
          <button
            onClick={clear}
            aria-label="Clear project filter"
            className="shrink-0 rounded-full p-0.5 text-arc hover:bg-arc-soft"
          >
            <X className="h-3 w-3" />
          </button>
        )}
      </div>

      {open && (
        <div
          role="listbox"
          className="absolute left-0 top-[calc(100%+5px)] z-40 min-w-[190px] rounded-[10px] border border-line bg-deck p-1 shadow-[var(--pill-shadow)]"
        >
          {hits.length === 0 && (
            <div className="px-2.5 py-1.5 text-xs text-dim">No matching project</div>
          )}
          {hits.map((p, i) => (
            <button
              key={optionKey(p)}
              role="option"
              aria-selected={i === highlight}
              onMouseDown={(e) => {
                e.preventDefault()
                pick(p)
              }}
              onMouseEnter={() => setHighlight(i)}
              className={cn(
                'flex w-full items-center gap-2 rounded-[7px] px-2.5 py-1.5 text-left text-xs',
                i === highlight ? 'bg-deck-2 text-ink' : 'text-mut',
                p.grouped && !query && 'pl-5',
              )}
            >
              {p.groupKey ? (
                <Layers className="h-3 w-3 shrink-0 text-dim" />
              ) : (
                <span
                  className="h-1.5 w-1.5 shrink-0 rounded-full"
                  style={{ background: projectColor(p.name) }}
                />
              )}
              <Highlighted text={p.name} query={query} bold={!!p.groupKey} />
              {p.groupKey && <span className="text-[10px] text-dim">group</span>}
              {p.count !== undefined && (
                <span className="ml-auto font-mono2 text-[10.5px] text-dim">{p.count}</span>
              )}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

function Highlighted({ text, query, bold }: { text: string; query: string; bold?: boolean }) {
  const q = query.toLowerCase()
  const at = q ? text.toLowerCase().indexOf(q) : -1
  if (at < 0) return <span className={cn('truncate', bold && 'font-semibold')}>{text}</span>
  return (
    <span className={cn('truncate', bold && 'font-semibold')}>
      {text.slice(0, at)}
      <span className="font-semibold text-arc">{text.slice(at, at + q.length)}</span>
      {text.slice(at + q.length)}
    </span>
  )
}
