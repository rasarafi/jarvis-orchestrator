import axios from 'axios'
import type { AxiosResponse } from 'axios'
import type {
  ApiListResponse,
  ApiResponse,
  AuditFinding,
  AuditRun,
  AuditRunResponse,
  FeatureAuditSummary,
  RecentAuditRun,
  CostSummary,
  CostProjectsData,
  CostSessionsData,
  CostWindow,
  QuotaUtilization,
  CostRange,
  UsageBehaviors,
  UsageBehaviorRange,
  DailyPlan,
  HealthData,
  Interrupt,
  MemoryResult,
  Project,
  ProjectContextSection,
  ProjectOverview,
  Note,
  NotesResponse,
  QuotaWindowResponse,
  RecapModelChoice,
  SweepMatch,
  SweepRunResponse,
  RecapRange,
  Task,
  UrgentResponse,
  WeeklyReview,
} from '@/types/api'

const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
})

export const tasksApi = {
  list: (params?: { project?: string; priority?: string; status?: string; sort?: string }) =>
    api.get<ApiListResponse<Task>>('/tasks', { params }),

  create: (data: { project_id?: string | null; title: string; priority?: string; deadline?: string }) =>
    api.post<ApiResponse<Task>>('/tasks', data),

  update: (
    id: string,
    data: Partial<{
      title: string
      status: string
      priority: string
      deadline: string
      blocker_reason: string
    }>,
  ) => api.patch<ApiResponse<Task>>(`/tasks/${id}`, data),

  delete: (id: string) => api.delete<void>(`/tasks/${id}`),

  archive: (id: string) => api.patch<ApiResponse<Task>>(`/tasks/${id}/archive`),

  urgent: () => api.get<UrgentResponse>('/tasks/urgent'),
}

export const projectsApi = {
  list: () => api.get<ApiListResponse<Project>>('/projects'),

  detail: (slug: string) => api.get<ApiResponse<Project>>(`/projects/${slug}`),

  context: (slug: string) =>
    api.get<ApiListResponse<ProjectContextSection>>(`/projects/${slug}/context`),

  overview: (slug: string) => api.get<ApiResponse<ProjectOverview>>(`/projects/${slug}/overview`),

  update: (
    slug: string,
    data: Partial<{ status: string; current_focus: string; blockers: string }>,
  ) => api.patch<ApiResponse<Project>>(`/projects/${slug}`, data),
}

export const plansApi = {
  today: () => api.get<ApiResponse<DailyPlan>>('/plans/today'),

  store: (data: { date: string; must_do?: string; should_do?: string; could_do?: string }) =>
    api.post<ApiResponse<DailyPlan>>('/plans', data),

  updateOutcome: (id: string, actual_outcome: string) =>
    api.patch<ApiResponse<DailyPlan>>(`/plans/${id}/outcome`, { actual_outcome }),

  updateCompletion: (id: string, data: Partial<Record<'must_do_completed' | 'should_do_completed' | 'could_do_completed', boolean>>) =>
    api.patch<ApiResponse<DailyPlan>>(`/plans/${id}/completion`, data),

  streak: () => api.get<ApiResponse<{ streak: number }>>('/plans/streak'),

  recent: (days?: number) =>
    api.get<ApiListResponse<DailyPlan>>('/plans/recent', { params: { days } }),
}

export const reviewsApi = {
  current: () => api.get<ApiResponse<WeeklyReview>>('/reviews/current'),

  store: (data: {
    week_start: string
    summary: string
    client_digests?: Record<string, string>
    behavior_insights?: string[]
  }) => api.post<ApiResponse<WeeklyReview>>('/reviews', data),
}

export interface PingResponse {
  ok: boolean
  db: 'up' | 'down'
}

export const pingApi = {
  // DB-independent liveness probe. Always resolves 200; db reports 'up'|'down'.
  check: () => api.get<PingResponse>('/ping'),
}

export const analyticsApi = {
  costs: () => api.get<ApiResponse<CostSummary>>('/analytics/costs'),

  health: () => api.get<ApiResponse<HealthData>>('/analytics/health'),

  sessions: () =>
    api.get<ApiListResponse<import('@/types/api').WeeklyProjectCost>>('/analytics/sessions'),

  todaySessions: (date?: string) =>
    api.get<ApiListResponse<import('@/types/today').TodaySessionSpan>>('/analytics/today-sessions', {
      params: date ? { date } : undefined,
    }),

  quotaWindow: () => api.get<QuotaWindowResponse>('/analytics/quota-window'),

  tasks: (group?: string) =>
    api.get<ApiResponse<import('@/types/api').TaskAnalytics>>('/analytics/tasks', {
      params: group ? { group } : undefined,
    }),

  resources: () =>
    api.get<ApiResponse<import('@/types/api').SystemSnapshot>>('/analytics/resources'),

  resourcesTrend: (window?: number) =>
    api.get<ApiListResponse<import('@/types/api').ResourceTrendPoint>>('/analytics/resources/trend', {
      params: window ? { window } : undefined,
    }),
}

export const memoryApi = {
  recall: (query: string, topK?: number, projectSlug?: string) =>
    api.post<ApiListResponse<MemoryResult>>('/memory/recall', {
      query,
      top_k: topK,
      project_slug: projectSlug,
    }),

  remember: (content: string, type?: string, projectSlug?: string) =>
    api.post<ApiResponse<{ stored: boolean }>>('/memory/remember', {
      content,
      type,
      project_slug: projectSlug,
    }),
}

// WorkSession type for session endpoints (lightweight, not a full model)
interface WorkSessionSummary {
  id: string
  project_id: string
  started_at: string
  ended_at: string | null
  cost_estimate: number | null
  summary: string | null
  project?: { slug: string; name: string }
}

export const sessionsApi = {
  live: () => api.get<import('@/types/live-sessions').LiveSessionsResponse>('/sessions/live'),

  scan: () => api.post<ApiResponse<{ scanned: number }>>('/sessions/scan'),

  search: (q: string, limit?: number) =>
    api.get<ApiListResponse<WorkSessionSummary>>('/sessions/search', { params: { q, limit } }),
}

export const focusApi = {
  start: (taskId: string) => api.post<ApiResponse<unknown>>('/focus-sessions', { task_id: taskId }),

  heartbeat: (id: string, elapsedSeconds: number) =>
    api.patch<ApiResponse<unknown>>(`/focus-sessions/${id}/heartbeat`, {
      elapsed_seconds: elapsedSeconds,
    }),

  end: (id: string, elapsedSeconds: number) =>
    api.patch<ApiResponse<unknown>>(`/focus-sessions/${id}/end`, {
      elapsed_seconds: elapsedSeconds,
    }),
}

export const scheduleApi = {
  reschedule: (scheduledTaskId: string, action: string) =>
    api.patch<ApiResponse<unknown>>(`/scheduled-tasks/${scheduledTaskId}/reschedule`, { action }),
}

export const interruptsApi = {
  list: () => api.get<ApiListResponse<Interrupt>>('/interrupts'),

  create: (data: {
    project_id: string
    was_working_on: string
    progress?: string
    resume_instructions?: string
  }) => api.post<ApiResponse<Interrupt>>('/interrupts', data),

  resolve: (id: string) => api.patch<ApiResponse<Interrupt>>(`/interrupts/${id}/resolve`),
}

export const notesApi = {
  list: (params?: { status?: string; project?: string }) =>
    api.get<NotesResponse>('/notes', { params }),
  create: (data: { body: string; project_id?: string }) =>
    api.post<ApiResponse<Note>>('/notes', data),
  update: (id: string, data: { body?: string; project_id?: string | null; pinned?: boolean }) =>
    api.patch<ApiResponse<Note>>(`/notes/${id}`, data),
  keep: (id: string) => api.patch<ApiResponse<Note>>(`/notes/${id}/keep`),
  archive: (id: string) => api.patch<ApiResponse<Note>>(`/notes/${id}/archive`),
  restore: (id: string) => api.patch<ApiResponse<Note>>(`/notes/${id}/restore`),
  convert: (id: string, data: { priority?: string; project_id?: string; title?: string }) =>
    api.post<{ data: Task; note_id: string }>(`/notes/${id}/convert-to-task`, data),
  promote: (id: string, data: { title: string; type: string }) =>
    api.post(`/notes/${id}/promote-to-knowledge`, data),
  delete: (id: string) => api.delete(`/notes/${id}`),
}

export const recapApi = {
  list: (range: RecapRange) => api.get(`/recaps`, { params: { range } }),
  rollups: (range: RecapRange) => api.get('/recaps/rollups', { params: { range } }),
  run: (body: { model?: RecapModelChoice; session_id?: string; limit?: number }) =>
    api.post('/recaps/run', body),
  threads: () => api.get('/recaps/threads'),
  dismissThread: (id: string) => api.patch(`/recaps/threads/${id}/dismiss`),
  resolveThread: (id: string) => api.patch(`/recaps/threads/${id}/resolve`),
  dismissStaleThreads: (days?: number) => api.post('/recaps/threads/dismiss-stale', days ? { days } : {}),
  restoreThreads: (ids: string[]) => api.post('/recaps/threads/restore', { ids }),
  suggestions: () => api.get('/recaps/suggestions'),
}

export const sweepApi = {
  run: (body: { group?: string; project?: string; window_days?: number; model?: RecapModelChoice }) =>
    api.post<ApiResponse<SweepRunResponse>>('/tasks/sweep', body),
  latest: () => api.get<ApiResponse<SweepRunResponse | null>>('/tasks/sweep/latest'),
  confirm: (id: string) =>
    api.patch<ApiResponse<SweepMatch>>(`/tasks/sweep/matches/${id}/confirm`, {}, { headers: { 'X-Jarvis-Actor': 'jarvis' } }),
  dismiss: (id: string) =>
    api.patch<ApiResponse<SweepMatch>>(`/tasks/sweep/matches/${id}/dismiss`, {}, { headers: { 'X-Jarvis-Actor': 'jarvis' } }),
  undo: (id: string) =>
    api.patch<ApiResponse<SweepMatch>>(`/tasks/sweep/matches/${id}/undo`, {}, { headers: { 'X-Jarvis-Actor': 'jarvis' } }),
}

export const auditApi = {
  recent: () => api.get<ApiResponse<RecentAuditRun[]>>('/audits/recent'),
  latest: (project: string, feature?: string | null) =>
    api.get<ApiResponse<AuditRunResponse | null>>('/audits/latest', {
      params: { project, ...(feature ? { feature } : {}) },
    }),
  history: (project: string, feature?: string | null) =>
    api.get<ApiResponse<AuditRun[]>>('/audits', {
      params: { project, ...(feature ? { feature } : {}) },
    }),
  features: (project: string) =>
    api.get<ApiResponse<FeatureAuditSummary[]>>('/audits/features', { params: { project } }),
  dismiss: (id: string) =>
    api.patch<ApiResponse<AuditFinding>>(`/audits/findings/${id}/dismiss`, {}),
  reopen: (id: string) =>
    api.patch<ApiResponse<AuditFinding>>(`/audits/findings/${id}/reopen`, {}),
  convertToTask: (id: string) =>
    api.post<ApiResponse<{ task: Task; finding: AuditFinding }>>(
      `/audits/findings/${id}/convert-to-task`,
      {},
      { headers: { 'X-Jarvis-Actor': 'jarvis' } },
    ),
}

// Re-export the raw instance for custom requests
export default api

// Helper to unwrap AxiosResponse data
export function unwrap<T>(response: AxiosResponse<T>): T {
  return response.data
}

export const costsApi = {
  window: (params?: { account?: string }) =>
    api.get<ApiResponse<CostWindow>>('/analytics/costs/window', { params }),

  projects: (params: { range: CostRange; group?: string; account?: string }) =>
    api.get<ApiResponse<CostProjectsData>>('/analytics/costs/projects', { params }),

  sessions: (slug: string, params: { range: CostRange; limit?: number; account?: string }) =>
    api.get<ApiResponse<CostSessionsData>>(`/analytics/costs/projects/${slug}/sessions`, { params }),

  behaviors: (params?: { range?: UsageBehaviorRange; account?: string }) =>
    api.get<ApiResponse<UsageBehaviors>>('/analytics/costs/behaviors', { params }),
}

/**
 * Server-reported quota. `refresh` is the only call that reaches Anthropic, and
 * it only ever runs from a user click — there is no poll here on purpose.
 */
export const quotaApi = {
  latest: () => api.get<ApiResponse<QuotaUtilization>>('/analytics/quota/latest'),
  refresh: () => api.post<ApiResponse<QuotaUtilization>>('/analytics/quota/refresh'),
}
