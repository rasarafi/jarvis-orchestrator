import type { LiveSession } from '@/types/live-sessions'
import type { Task, Interrupt } from '@/types/api'
import { isOverdue } from '@/lib/dates'

/** Single source of truth for "this session needs the human". */
export function sessionNeedsHuman(s: LiveSession): boolean {
  return s.status === 'waiting' || s.attention != null
}

/** Single source of truth for the needs-you total (tab badge, NowStrip). */
export function countNeeds(
  sessions: LiveSession[] | undefined,
  tasks: Task[] | undefined,
  interrupts: Interrupt[] | undefined,
): number {
  const open = (tasks ?? []).filter((t) => t.status !== 'done')
  return (
    (sessions ?? []).filter(sessionNeedsHuman).length +
    open.filter((t) => isOverdue(t.deadline)).length +
    open.filter((t) => t.status === 'blocked').length +
    (interrupts ?? []).filter((i) => i.status === 'active').length
  )
}
