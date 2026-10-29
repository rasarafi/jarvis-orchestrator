// TypeScript interfaces matching Laravel API JSON responses.
// All models use UUID string IDs.

export interface Project {
  id: string
  slug: string
  name: string
  purpose: string | null
  status: 'active' | 'paused' | 'maintenance' | 'archived'
  group_key: 'mfb' | 'fa' | 'freelance' | 'personal' | null
  local_path: string | null
  repo_url: string | null
  current_focus: string | null
  blockers: string | null
  stack: string[] | null
  key_modules: string[] | null
  ports: Record<string, string | number> | null
  run_commands: Record<string, string> | null
  known_risks: string | null
  environments: Record<string, string> | null
  last_session_at: string | null
  /** GET /api/projects embeds each project's open tasks, pre-sorted by priority then deadline. */
  tasks?: Task[]
  created_at: string
  updated_at: string
}

export interface ProjectContextSection {
  id: string
  project_id: string
  section: 'architecture' | 'features' | 'changelog' | 'todos' | 'bugs' | 'spending' | 'decisions'
  content: string | null
  created_at: string
  updated_at: string
}

export interface ProjectOverviewStats {
  open_tasks: number
  total_tasks: number
  cost_7d: number
  today_cost: number
  last_recap_at: string | null
  last_session_at: string | null
}

export interface ProjectDailyCost {
  date: string
  cost: number
}

export interface ProjectOverview {
  stats: ProjectOverviewStats
  daily_costs: ProjectDailyCost[]
}

export interface Task {
  id: string
  project_id: string | null
  title: string
  description: string | null
  status: 'todo' | 'in_progress' | 'blocked' | 'review' | 'done'
  priority: 'urgent' | 'high' | 'medium' | 'low'
  deadline: string | null
  blocker_reason: string | null
  completed_at: string | null
  created_at: string
  updated_at: string
  project?: Project
}

export interface DailyPlan {
  id: string
  date: string
  must_do: string | null
  should_do: string | null
  could_do: string | null
  alerts: string[] | null
  actual_outcome: string | null
  must_do_completed: boolean
  should_do_completed: boolean
  could_do_completed: boolean
  created_at: string
  updated_at: string
}

export interface WeeklyReview {
  id: string
  week_start: string
  summary: string
  client_digests: Record<string, string> | null
  behavior_insights: string[] | null
  next_week_plan: string | null
  created_at: string
  updated_at: string
}

export interface Interrupt {
  id: string
  project_id: string
  was_working_on: string
  progress: string | null
  resume_instructions: string | null
  status: 'active' | 'resolved'
  resolved_at: string | null
  created_at: string
  updated_at: string
  project?: Project
}

export interface CostSummary {
  total_cost: number
  today_cost: number
  week_cost: number
  total_sessions: number
}

/** One row of GET /api/analytics/sessions — per-project cost for the current week. */
export interface WeeklyProjectCost {
  display_name: string
  /** Group of the matching Jarvis project (via local_path), null if not onboarded. */
  group_key: Project['group_key']
  cost: string | number
  sessions: number
  total_tokens: string | number
}

export interface HealthData {
  warnings: string[]
  insights: string[] | Record<string, unknown>
}

export interface MemoryResult {
  id: string
  score: number
  content: string
  source: string
  type: string
  project_slug: string
  created_at: string
}

export interface ApiResponse<T> {
  data: T
}

export interface ApiListResponse<T> {
  data: T[]
}

export interface UrgentResponse {
  urgent: Task[]
  stale: Task[]
}

/** GET /api/analytics/quota-window — the open 5-hour rate-limit window estimate. */
export interface QuotaWindow {
  window_started_at: string
  window_ends_at: string
  elapsed_pct: number
  tokens_used: number
  burn_tokens_per_min: number
  projected_total: number
  ceiling_tokens: number | null
  pct_of_ceiling: number | null
  as_of: string | null
  provenance: 'local_estimate'
}

export interface QuotaWindowResponse {
  data: QuotaWindow | null
  meta: { as_of: string | null }
}

export type ResourcePressure = 'ok' | 'warning' | 'critical'

export interface SystemMetrics {
  cpu_load: number
  cores: number
  load_per_core: number
  mem_used_mb: number
  mem_total_mb: number
  mem_available_mb: number
  mem_pct: number
  swap_used_mb: number
  swap_total_mb: number
  swap_pct: number
  session_count: number
}

export interface SystemSession {
  project: string
  slug: string | null
  path: string
  rss_mb: number
  pids: number
  pct_of_used: number
}

/** Windows-host vitals from the tray agent. `metrics` is null unless available. */
export interface HostMetrics {
  cpu_pct: number
  cpu_cores: number
  mem_total_mb: number
  mem_used_mb: number
  mem_available_mb: number
  mem_pct: number
  commit_used_mb: number
  commit_limit_mb: number
  commit_pct: number
  /** What the WSL VM costs the host — bridges these numbers to the /proc ones. */
  wsl_mb: number
}

export interface HostProcess {
  name: string
  mb: number
}

export type HostStatus = 'ok' | 'stale' | 'offline'

export interface HostSnapshot {
  available: boolean
  status: HostStatus
  reason: string | null
  pressure: ResourcePressure | null
  hostname: string | null
  age_s: number | null
  clock_offset_s: number | null
  sampled_at: string | null
  metrics: HostMetrics | null
  top: HostProcess[]
}

export interface SystemSnapshot {
  pressure: ResourcePressure
  message: string
  detail: string
  metrics: SystemMetrics
  sessions: SystemSession[]
  host: HostSnapshot
  sampled_at: string
}

export interface ResourceTrendPoint {
  sampled_at: string
  cpu_load: number
  cores: number
  mem_pct: number
  swap_pct: number
  pressure: ResourcePressure
  /** Null for samples taken while the host agent was down. */
  host_mem_pct: number | null
  host_commit_pct: number | null
}

export interface Note {
  id: string
  project_id: string | null
  project?: Project | null
  body: string
  source: 'remote' | 'deck' | 'chat'
  status: 'inbox' | 'kept' | 'archived'
  pinned: boolean
  converted_task_id: string | null
  promoted_knowledge_id: string | null
  created_at: string
  updated_at: string
}

export interface NoteCounts {
  inbox: number
  kept: number
  archived: number
}

export interface NotesResponse {
  data: Note[]
  meta: { counts: NoteCounts }
}

// ── Recap ──
export type RecapModelChoice = 'haiku' | 'sonnet' | 'opus'
export type RecapRange = '24h' | 'week'

export interface RecapProjectRef {
  id: string
  name: string
  slug: string
  color?: string | null
  group_key?: 'mfb' | 'fa' | 'freelance' | 'personal' | null
}

export interface RecapThread {
  id: string
  text: string
  status: 'open' | 'resolved' | 'dismissed'
  project?: RecapProjectRef | null
  source_recap_id: string
  source_recap?: { id: string; cc_session_id: string } | null
  created_at: string
}

export interface RecapThreadsPayload {
  active: RecapThread[]
  stale_count: number
  total_open: number
}

export interface SessionRecap {
  id: string
  cc_session_id: string
  kind: 'work' | 'chore'
  status: 'ok' | 'failed'
  model: string | null
  headline: string | null
  bullets: string[]
  next_action: string | null
  decisions: string[]
  facts: { messages?: number; duration_minutes?: number; tasks_closed?: number }
  error: string | null
  started_at: string | null
  ended_at: string | null
  duration_minutes: number
  git_branch: string | null
  project?: RecapProjectRef | null
  threads?: RecapThread[]
}

export interface RecapStats {
  captured: number
  meaningful: number
  skipped: number
  focus_minutes: number
  tasks_closed: number
  open_threads: number
}

export interface RecapOverview {
  recaps: SessionRecap[]
  skipped: SessionRecap[]
  failed: SessionRecap[]
  stats: RecapStats
  pending: number
  last_summarized_at: string | null
}

export interface RecapRunResult {
  processed: number
  failed: number
  remaining: number
}

/** Merged one-liner for a project's session group (only projects with ≥2 recaps in range). */
export interface RecapRollup {
  project_id: string
  sessions: number
  summary: string
}

export interface RecapSuggestion {
  recap_id: string
  project_name: string | null
  project_slug: string | null
  next_action: string
  ended_at: string | null
}

// ── Commit Sweep ────────────────────────────────────────────────

export type SweepConfidence = 'high' | 'medium' | 'low'
export type SweepMatchStatus = 'pending' | 'confirmed' | 'dismissed'

export interface SweepCommit {
  sha: string
  subject: string
  committed_at: string
}

export interface SweepMatch {
  id: string
  sweep_run_id: string
  task_id: string
  project_id: string | null
  commits: SweepCommit[]
  confidence: SweepConfidence
  rationale: string | null
  status: SweepMatchStatus
  meta: Record<string, unknown>
  resolved_at: string | null
  created_at: string
  updated_at: string
  task?: Task
}

export interface SweepRunStats {
  tasks_scanned?: number
  repos_scanned?: number
  commits_read?: number
  matched?: number
  invalid_matches?: number
  cached_projects?: number
  duration_ms?: number
  skipped?: { project: string; reason: string }[]
  errors?: { project: string; error: string }[]
}

export interface SweepRun {
  id: string
  status: 'completed' | 'partial' | 'failed'
  scope: { group?: string; project?: string }
  window_days: number
  model: string
  stats: SweepRunStats
  created_at: string
  updated_at: string
}

export interface SweepRunResponse {
  run: SweepRun
  matches: SweepMatch[]
}

export type AuditRunStatus = 'running' | 'passed' | 'flagged' | 'blocked'
export type AuditFindingSeverity = 'critical' | 'warning' | 'info'
export type AuditFindingStatus = 'open' | 'dismissed' | 'converted' | 'suppressed'
export type AuditScope = 'system' | 'feature'

export interface AuditRun {
  id: string
  project_id: string
  scope: AuditScope
  feature: string | null
  status: AuditRunStatus
  gate_reached: 0 | 1 | 2
  app_classification: string[]
  summary: string | null
  meta: Record<string, unknown>
  started_at: string | null
  finished_at: string | null
  created_at: string
  updated_at: string
}

export interface AuditFinding {
  id: string
  audit_run_id: string
  project_id: string
  gate: 0 | 1 | 2
  module: string
  severity: AuditFindingSeverity
  title: string
  detail: string | null
  evidence: Record<string, unknown>
  status: AuditFindingStatus
  suppression_key: string
  dismissed_at: string | null
  task_id: string | null
  created_at: string
  updated_at: string
}

export interface AuditRunResponse {
  run: AuditRun
  findings: AuditFinding[]
}

/** Row from GET /audits/features — one per feature that has been audited. */
export interface FeatureAuditSummary {
  feature: string
  status: AuditRunStatus
  gate_reached: 0 | 1 | 2
  open_findings_count: number
  critical_findings_count: number
  last_run_at: string
}

/** Row from GET /audits/recent — cross-project landing list. */
export interface RecentAuditRun extends AuditRun {
  project: Pick<Project, 'id' | 'slug' | 'name'>
  findings_count: number
  open_findings_count: number
  critical_findings_count: number
}

// ═══ Task analysis (GET /analytics/tasks) ═══

export type TaskPriorityKey = Task['priority']

export interface TaskAnalyticsStats {
  open: number
  urgent: number
  blocked: number
  in_progress: number
  review: number
  projects_with_open: number
  created_this_week: number
  completed_this_week: number
  net_this_week: number
  urgent_top_project: { name: string; count: number } | null
  oldest: { id: string; title: string; project: string | null; age_days: number | null } | null
}

export interface TaskPressureRow {
  slug: string
  display_name: string
  group_key: Project['group_key']
  color: string | null
  counts: Record<TaskPriorityKey, number>
  total: number
  score: number
  /** Nothing above medium and barely any open work — collapsible in the UI. */
  quiet: boolean
}

export interface TaskFlowWeek {
  week: string
  created: number
  completed: number
  open_after: number
}

export interface TaskAgePoint {
  id: string
  title: string
  project: string
  slug: string | null
  group_key: Project['group_key']
  priority: TaskPriorityKey
  status: Task['status']
  age_days: number
}

export interface TaskDeadlineItem {
  id: string
  title: string
  project: string
  priority: TaskPriorityKey
  deadline: string
  days_out: number
}

export interface TaskAnalytics {
  stats: TaskAnalyticsStats
  pressure: TaskPressureRow[]
  flow: TaskFlowWeek[]
  age: TaskAgePoint[]
  deadlines: {
    total_open: number
    with_deadline: number
    without_deadline: number
    overdue: number
    items: TaskDeadlineItem[]
  }
}

// ---------------------------------------------------------------------------
// Cost & quota analytics (/costs)
// ---------------------------------------------------------------------------

export type CostRange = 'live' | '24h' | '7d' | '30d'

export interface CostModelShare {
  model: string
  tokens: number
  pct: number
}

/**
 * The current 5-hour window. Note the absence of any quota percentage: the real
 * limit is server-side and does not follow local token counts, so the honest
 * comparison is `vs_median` — this window against a typical one.
 */
/** Which Claude account a view is scoped to. null / omitted = every account. */
export type CostAccount = 'cl' | 'cl2' | 'unattributed'

export interface CostAccountPresence {
  key: CostAccount
  sessions: number
}

export interface CostWindow {
  open: boolean
  started_at?: string
  ends_at?: string
  /** 'server_anchor' = boundary from Anthropic's real reset; 'hour_floor' = local guess. */
  boundary_source?: 'server_anchor' | 'hour_floor'
  elapsed_pct?: number
  resets_in_minutes?: number
  tokens?: number
  cost_usd?: number
  burn_tokens_per_min?: number
  projected_tokens?: number
  median_tokens: number
  vs_median?: number | null
  projected_vs_median?: number | null
  active_sessions?: number
  model_mix?: CostModelShare[]
  heaviest_project?: { slug: string; tokens: number; pct: number | null } | null
  account: CostAccount | null
  /** Accounts present in the index — the filter's options, never hardcoded. */
  accounts: CostAccountPresence[]
  as_of: string | null
  provenance: string
}

/** One quota bucket as Anthropic reports it. Rendered generically — a `kind` we
 *  have never seen must appear as its own row rather than break the panel. */
export interface QuotaLimit {
  kind: string
  group: string | null
  percent: number | null
  severity: string
  resets_at: string | null
  is_active: boolean
  /** Model display name for a model-scoped limit (e.g. "Fable"), else null. */
  scope_label: string | null
}

export interface QuotaBucket {
  percent: number | null
  resets_at: string | null
}

/** Server-reported quota. `available` false means every number is absent, not zero. */
export interface QuotaUtilization {
  available: boolean
  status: 'ok' | 'auth_expired' | 'unreachable' | 'malformed' | 'never_fetched'
  reason: string | null
  fetched_at: string | null
  age_s: number | null
  account: string | null
  limits: QuotaLimit[]
  five_hour: QuotaBucket | null
  seven_day: QuotaBucket | null
  extra_usage: {
    utilization: number | null
    used_credits: number | null
    monthly_limit: number | null
    currency: string | null
  } | null
  spend: { used: number | null; currency: string; percent: number | null } | null
}

export interface CostProject {
  slug: string
  name: string
  group_key: string | null
  tokens: number
  cost_usd: number
  sessions: number
  previous_tokens: number
  share_pct: number
  /** null means the project had no activity in the preceding period — "new". */
  trend_pct: number | null
  last_activity_at: string | null
  live: boolean
  models: CostModelShare[]
}

export interface CostProjectsData {
  range: CostRange
  since: string
  account: CostAccount | null
  models: CostModelShare[]
  total_tokens: number
  total_cost_usd: number
  projects: CostProject[]
}

/** A subagent transcript — real spend, but never a session the user started. */
export interface CostSubagent {
  session_id: string
  first_prompt: string
  messages: number
  tokens: number
  cost_usd: number
  last_activity_at: string | null
}

export interface CostSession {
  session_id: string
  first_prompt: string
  git_branch: string | null
  account: CostAccount | null
  messages: number
  /** Own + subagent tokens. `own_tokens` and `subagent_tokens` split it. */
  tokens: number
  own_tokens: number
  subagent_tokens: number
  subagent_count: number
  cost_usd: number
  models: CostModelShare[]
  /** The heaviest few subagents; `subagent_count` is the true total. */
  subagents: CostSubagent[]
  last_activity_at: string | null
  live: boolean
}

export interface CostSessionsData {
  slug: string
  range: CostRange
  account: CostAccount | null
  total_sessions: number
  sessions: CostSession[]
}

/** Part A3 usage-behaviors: ranges are narrower than the cost page's — no `live`/`30d`. */
export type UsageBehaviorRange = '24h' | '7d'

/** A single-threshold behavior: % of tokens in the range matching a condition. */
export interface UsageBehaviorShare {
  tokens: number
  total_tokens: number
  pct: number
}

/** `cron` degrades to unavailable rather than guessing when no session in range has a captured entrypoint. */
export interface CronBehaviorShare extends UsageBehaviorShare {
  available: boolean
  reason?: string
}

/** A category breakdown by tokens (subagent_type, skill, plugin). */
export interface UsageCategoryTokenShare {
  label: string
  tokens: number
  pct: number
  is_plugin?: boolean
}

/** mcp_servers is deliberately a CALL-COUNT share, not a token share. */
export interface McpServerShare {
  label: string
  calls: number
  pct: number
}

export interface UsageBehaviors {
  range: UsageBehaviorRange
  since: string
  until: string
  account: CostAccount | null
  cache_miss: UsageBehaviorShare
  long_context: UsageBehaviorShare
  subagent_heavy: UsageBehaviorShare
  high_parallel: UsageBehaviorShare
  cron: CronBehaviorShare
  subagent_types: { items: UsageCategoryTokenShare[]; total_tokens: number }
  skills: { items: UsageCategoryTokenShare[]; total_tokens: number }
  plugin: { items: UsageCategoryTokenShare[]; total_tokens: number }
  mcp_servers: { items: McpServerShare[]; total_calls: number }
}
