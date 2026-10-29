// Mirrors backend/app/Services/LiveSessionService.php payload.

export type LiveSessionStatus = 'working' | 'waiting' | 'stalled'

export interface SessionAttention {
  kind: 'permission' | 'idle'
  message: string | null
  flagged_at: string
}

export interface LiveSession {
  session_id: string
  project_dir: string
  project_name: string
  project_slug: string | null
  cwd: string | null
  git_branch: string | null
  status: LiveSessionStatus
  started_at: string | null
  last_activity_at: string | null
  elapsed_seconds: number | null
  tokens: {
    input: number
    output: number
    cache_read: number
    cache_creation: number
  }
  cost_usd: number
  last_output_line: string | null
  last_output_lines: string[]
  model: string | null
  attention: SessionAttention | null
}

export interface LiveSessionsResponse {
  data: LiveSession[]
  meta: {
    scanned_at: string
    window_minutes: number
  }
}
