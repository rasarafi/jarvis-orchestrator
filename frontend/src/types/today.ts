// Types matching the Laravel /api/today composite endpoint and related Plan 1 endpoints.
// All IDs are UUID strings.

import type { Project, Task } from '@/types/api'

export type TimeBlockKind = 'work' | 'personal'
export type FocusSessionStatus = 'active' | 'paused' | 'ended'

export interface TimeBlock {
  id: string
  name: string
  kind: TimeBlockKind
  start_time: string // 'HH:mm:ss'
  end_time: string // 'HH:mm:ss'
  weekday_mask: number
  color_hint: string | null
  display_order: number
  created_at: string
  updated_at: string
}

export interface ScheduledTask {
  id: string
  task_id: string
  time_block_id: string | null
  scheduled_date: string // 'YYYY-MM-DD'
  start_time: string // 'HH:mm:ss'
  end_time: string // 'HH:mm:ss'
  position: number
  created_at: string
  updated_at: string

  // Eager-loaded by the backend via with(['task.project', 'timeBlock'])
  task: Task & { project?: Project }
  timeBlock?: TimeBlock | null
}

export interface FocusSession {
  id: string
  task_id: string
  status: FocusSessionStatus
  started_at: string
  ended_at: string | null
  elapsed_seconds: number
  interruptions: number
  notes: string | null
  created_at: string
  updated_at: string
}

export interface ProjectMixEntry {
  slug: string
  name: string
  color: string // CSS hsl(...) string
  scheduled_count: number
}

export interface TodayPayload {
  date: string // 'YYYY-MM-DD'
  blocks: TimeBlock[]
  scheduled_tasks: ScheduledTask[]
  active_focus_session: FocusSession | null
  project_mix: ProjectMixEntry[]
}

// Actual CC-session span, from GET /api/analytics/today-sessions.
export interface TodaySessionSpan {
  project_name: string
  project_slug: string | null
  started_at: string
  ended_at: string
  seconds: number
  cost_usd: number
}
