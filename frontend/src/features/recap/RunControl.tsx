import { useEffect, useRef, useState } from 'react'
import { Check, ChevronDown, Loader2, Play } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { RecapModelChoice, RecapRunResult } from '@/types/api'
import { useRunRecap } from './hooks/use-recap'

const MODELS: { value: RecapModelChoice; label: string; note: string }[] = [
  { value: 'haiku', label: 'Haiku', note: 'fast · default' },
  { value: 'sonnet', label: 'Sonnet', note: 'balanced' },
  { value: 'opus', label: 'Opus', note: 'deepest read' },
]

export function RunControl({ pending }: { pending: number }) {
  const [model, setModel] = useState<RecapModelChoice>(() => {
    const stored = localStorage.getItem('recap-model')
    return MODELS.some((m) => m.value === stored) ? (stored as RecapModelChoice) : 'haiku'
  })
  const [menuOpen, setMenuOpen] = useState(false)
  const menuRef = useRef<HTMLDivElement>(null)
  const run = useRunRecap()

  useEffect(() => {
    if (!menuOpen) return
    const onDown = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) setMenuOpen(false)
    }
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setMenuOpen(false)
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [menuOpen])

  const pick = (value: RecapModelChoice) => {
    setModel(value)
    localStorage.setItem('recap-model', value)
    setMenuOpen(false)
  }

  const busy = run.isPending
  const remainingCount: number = (run.data as RecapRunResult | undefined)?.remaining ?? pending

  return (
    <div className="relative flex items-center" ref={menuRef}>
      <button
        onClick={() => run.mutate({ model })}
        disabled={busy}
        className="flex items-center gap-1.5 rounded-l-[7px] border border-arc/50 bg-arc-soft px-3 py-1.5 font-display text-[11px] font-semibold uppercase tracking-[0.1em] text-arc hover:bg-arc/20 disabled:opacity-60"
      >
        {busy ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Play className="h-3.5 w-3.5" />}
        {busy ? `Summarizing · ${remainingCount} left` : pending > 0 ? `Run recap · ${pending}` : 'Run recap'}
      </button>
      <button
        onClick={() => setMenuOpen((v) => !v)}
        aria-haspopup="menu"
        aria-expanded={menuOpen}
        className="flex items-center gap-1 rounded-r-[7px] border border-l-0 border-arc/50 bg-arc-soft px-2 py-1.5 font-mono2 text-[10.5px] uppercase text-arc hover:bg-arc/20"
      >
        {model}
        <ChevronDown className="h-3 w-3" />
      </button>
      {menuOpen && (
        <div role="menu" className="absolute right-0 top-full z-30 mt-1 w-44 rounded-[9px] border border-line bg-deck p-1 shadow-[var(--pill-shadow)]">
          {MODELS.map((m) => (
            <button
              key={m.value}
              role="menuitemradio"
              aria-checked={model === m.value}
              onClick={() => pick(m.value)}
              className={cn(
                'flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-left text-[12.5px]',
                model === m.value ? 'bg-arc-soft text-ink' : 'text-mut hover:bg-hover-strong hover:text-ink',
              )}
            >
              <span className="font-medium">{m.label}</span>
              <span className="text-[10.5px] text-dim">{m.note}</span>
              {model === m.value && <Check className="ml-auto h-3.5 w-3.5 text-arc" />}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
