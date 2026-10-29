import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import App from './App'
import './index.css'

// Motion gate: honor OS reduced-motion, but allow ?motion=force override.
// This machine's Windows accessibility setting reports reduced motion, so
// without the override all deck animations render as static end-states.
const forceMotion = new URLSearchParams(window.location.search).get('motion') === 'force'
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
if (forceMotion || !reduceMotion) {
  document.documentElement.classList.add('motion')
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
