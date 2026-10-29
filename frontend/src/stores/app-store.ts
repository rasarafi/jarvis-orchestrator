import { create } from 'zustand'

export type Theme = 'dark' | 'light' | 'biscuit'

/** Cycle order for toggleTheme (and the visual order of the theme menu). */
export const THEME_ORDER: Theme[] = ['dark', 'light', 'biscuit']

const THEME_KEY = 'jarvis-theme'

/** Read the current theme from the DOM (set pre-paint by the index.html script). */
function initialTheme(): Theme {
  if (typeof document !== 'undefined') {
    const cl = document.documentElement.classList
    if (cl.contains('dark')) return 'dark'
    if (cl.contains('biscuit')) return 'biscuit'
  }
  return 'light'
}

/** Apply a theme: set the class signal on <html> ('light' = no class) and persist it. */
function applyTheme(theme: Theme) {
  const cl = document.documentElement.classList
  cl.toggle('dark', theme === 'dark')
  cl.toggle('biscuit', theme === 'biscuit')
  try {
    localStorage.setItem(THEME_KEY, theme)
  } catch {
    /* private mode / storage disabled — the class still applies for this session */
  }
}

const ALERTS_KEY = 'jarvis-alerts'

function initialAlertsEnabled(): boolean {
  try {
    return localStorage.getItem(ALERTS_KEY) === 'on'
  } catch {
    return false
  }
}

interface AppState {
  /** Active color theme. 'dark' is the deck's default; 'light' and 'biscuit' are opt-in. */
  theme: Theme
  setTheme: (theme: Theme) => void
  toggleTheme: () => void

  /** Browser notifications for needs-you transitions. Opt-in via the Topbar bell. */
  alertsEnabled: boolean
  setAlertsEnabled: (enabled: boolean) => void
}

export const useAppStore = create<AppState>((set) => ({
  theme: initialTheme(),
  setTheme: (theme) => {
    applyTheme(theme)
    set({ theme })
  },
  toggleTheme: () =>
    set((s) => {
      const next = THEME_ORDER[(THEME_ORDER.indexOf(s.theme) + 1) % THEME_ORDER.length]
      applyTheme(next)
      return { theme: next }
    }),

  alertsEnabled: initialAlertsEnabled(),
  setAlertsEnabled: (enabled) => {
    try {
      localStorage.setItem(ALERTS_KEY, enabled ? 'on' : 'off')
    } catch {
      /* private mode — state still applies for this session */
    }
    set({ alertsEnabled: enabled })
  },
}))
