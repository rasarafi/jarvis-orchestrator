import { useEffect, useRef, useState } from 'react'
import { Moon, Sun, Cookie, Check } from 'lucide-react'
import { useAppStore, THEME_ORDER, type Theme } from '@/stores/app-store'

const META: Record<Theme, { label: string; icon: typeof Moon }> = {
  dark: { label: 'Dark', icon: Moon },
  light: { label: 'Light', icon: Sun },
  biscuit: { label: 'Biscuit', icon: Cookie },
}

/** Theme picker: the current theme's icon opens a small popover of all themes. */
export function ThemeMenu() {
  const theme = useAppStore((s) => s.theme)
  const setTheme = useAppStore((s) => s.setTheme)
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    function onDown(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  const CurrentIcon = META[theme].icon

  return (
    <div ref={ref} className="relative shrink-0">
      <button
        onClick={() => setOpen((o) => !o)}
        aria-label="Change theme"
        aria-haspopup="menu"
        aria-expanded={open}
        title={`Theme: ${META[theme].label}`}
        className="flex h-8 w-8 items-center justify-center rounded-lg border border-line text-mut transition-colors hover:border-arc/40 hover:text-ink"
      >
        <CurrentIcon className="h-4 w-4" />
      </button>

      {open && (
        <div
          role="menu"
          className="absolute right-0 z-50 mt-2 w-40 overflow-hidden rounded-xl border border-line bg-deck p-1 shadow-[var(--pill-shadow)]"
        >
          {THEME_ORDER.map((key) => {
            const { label, icon: Icon } = META[key]
            const active = key === theme
            return (
              <button
                key={key}
                role="menuitemradio"
                aria-checked={active}
                onClick={() => {
                  setTheme(key)
                  setOpen(false)
                }}
                className={`flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[13px] transition-colors hover:bg-hover ${
                  active ? 'text-ink' : 'text-mut'
                }`}
              >
                <Icon className="h-4 w-4" />
                {label}
                {active && <Check className="ml-auto h-3.5 w-3.5 text-arc" />}
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}
