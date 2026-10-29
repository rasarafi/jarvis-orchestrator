import { Link } from 'react-router-dom'
import { Loader2, AlertCircle } from 'lucide-react'
import { Tooltip as TooltipPrimitive } from 'radix-ui'
import type { LiveSession } from '@/types/live-sessions'
import { formatDuration, formatTokens, secondsSince } from '@/lib/dates'
import { formatRelativeTime } from '@/lib/utils'
import { useProjects } from '@/features/projects/hooks/use-projects'
import { Card, CardHeader, projectColor } from './ui'
import { CopyResumeButton } from './CopyResumeButton'
import { buildLanes, NextTaskRow, type Lane } from './WorkstreamLanes'
import { groupChips } from './session-groups'
import { sessionNeedsHuman } from './needs'
import { cleanSessionSummary } from './clean-summary'

/**
 * Worst-first aggregation: the lane wears its most urgent session's state.
 * `waiting` always implies needs-you, so only working/stalled remain after it.
 */
function laneState(sessions: LiveSession[]): 'needs' | 'working' | 'stalled' {
  if (sessions.some(sessionNeedsHuman)) return 'needs'
  if (sessions.some((s) => s.status === 'working')) return 'working'
  return 'stalled'
}

const BADGE: Record<'needs' | 'working' | 'stalled', { label: string; cls: string }> = {
  needs: { label: 'needs you', cls: 'text-gold border-gold/45 bg-gold-soft' },
  working: { label: 'working', cls: 'text-arc border-arc/35 bg-arc-soft' },
  stalled: { label: 'stalled', cls: 'text-dim border-line' },
}

const DOT: Record<'needs' | 'working' | 'stalled', string> = {
  needs: 'bg-gold shadow-[0_0_0_3px_var(--gold-soft)]',
  working: 'bg-arc shadow-[0_0_0_3px_var(--arc-soft)]',
  stalled: 'bg-dim',
}

function newestActivity(sessions: LiveSession[]): string | null {
  return sessions.reduce<string | null>(
    (max, s) => (s.last_activity_at && (!max || s.last_activity_at > max) ? s.last_activity_at : max),
    null,
  )
}

function PopoverRow({ session }: { session: LiveSession }) {
  const summary = cleanSessionSummary(session.last_output_line)
  return (
    <div className="border-b border-line-soft py-2 first:pt-0 last:border-b-0 last:pb-0">
      {summary && <div className="line-clamp-3 text-[12px] leading-snug text-mut">{summary}</div>}
      <div className="mt-1.5 flex items-center gap-2 font-mono2 text-[10.5px] text-dim">
        <span>
          {formatTokens(session.tokens.output)} out · ${session.cost_usd.toFixed(2)}
        </span>
        {session.model && <span className="truncate">{session.model}</span>}
        <CopyResumeButton sessionId={session.session_id} className="ml-auto" />
      </div>
    </div>
  )
}

function SessionChip({ group }: { group: LiveSession[] }) {
  const state = laneState(group)
  const idle = secondsSince(newestActivity(group))
  const branch = group[0].git_branch ?? 'no branch'
  return (
    <TooltipPrimitive.Root>
      <TooltipPrimitive.Trigger asChild>
        <div className="flex cursor-default items-center gap-2 rounded-md border border-line-soft bg-deck px-2 py-1.5 font-mono2 text-[11px] transition-colors hover:border-line hover:bg-hover">
          <span className={`h-1.5 w-1.5 flex-none rounded-full ${DOT[state]}`} aria-hidden />
          <span className="min-w-0 flex-1 truncate text-ink">{branch}</span>
          {group.length > 1 && (
            <span className="rounded border border-line px-1 text-[10px] text-dim">×{group.length}</span>
          )}
          <span className="text-[10px] text-dim">{idle != null ? formatDuration(idle) : '—'}</span>
        </div>
      </TooltipPrimitive.Trigger>
      <TooltipPrimitive.Portal>
        <TooltipPrimitive.Content
          side="bottom"
          align="start"
          sideOffset={6}
          className="z-50 w-[280px] rounded-lg border border-line bg-deck p-3 shadow-[var(--pill-shadow)]"
        >
          {group.map((s) => (
            <PopoverRow key={s.session_id} session={s} />
          ))}
        </TooltipPrimitive.Content>
      </TooltipPrimitive.Portal>
    </TooltipPrimitive.Root>
  )
}

function ProjectLane({ lane }: { lane: Lane }) {
  const state = laneState(lane.sessions)
  const badge = BADGE[state]
  const newest = [...lane.sessions].sort((a, b) =>
    (b.last_activity_at ?? '').localeCompare(a.last_activity_at ?? ''),
  )[0]
  const doing = cleanSessionSummary(newest?.last_output_line)

  return (
    <div
      className={`flex max-w-[360px] flex-[1_0_250px] flex-col gap-2 rounded-lg border p-2.5 ${
        state === 'needs' ? 'border-gold/45 bg-gold-soft' : 'border-line-soft bg-void'
      }`}
    >
      <div className="flex items-center gap-2">
        <span
          className="h-2 w-2 flex-none rounded-full"
          style={{ background: projectColor(lane.name) }}
          aria-hidden
        />
        {lane.slug ? (
          <Link
            to={`/projects/${lane.slug}`}
            className="min-w-0 flex-1 truncate text-[13px] font-semibold text-ink hover:text-arc"
          >
            {lane.name}
          </Link>
        ) : (
          <span className="min-w-0 flex-1 truncate text-[13px] font-semibold text-ink">{lane.name}</span>
        )}
        <span className={`rounded-full border px-2 py-px font-mono2 text-[9.5px] uppercase tracking-wide ${badge.cls}`}>
          {badge.label}
        </span>
      </div>

      {doing && <div className="line-clamp-2 text-[12px] leading-snug text-mut">{doing}</div>}

      <div className="flex flex-col gap-1.5">
        {groupChips(lane.sessions).map((group) => (
          <SessionChip key={group[0].session_id} group={group} />
        ))}
      </div>

      {lane.nextTask && <NextTaskRow task={lane.nextTask} compact />}
    </div>
  )
}

function ParkedRail({ parked }: { parked: Lane[] }) {
  return (
    <div className="flex w-[210px] flex-none flex-col gap-1.5 rounded-lg border border-dashed border-line-soft p-2.5">
      <div className="mb-0.5 font-mono2 text-[10px] uppercase tracking-wider text-dim">
        Parked · {parked.length}
      </div>
      {parked.map((lane) => (
        <div key={lane.key} className="flex items-center gap-1.5 text-[11.5px] text-mut">
          <span
            className="h-1.5 w-1.5 flex-none rounded-full opacity-60"
            style={{ background: projectColor(lane.name) }}
            aria-hidden
          />
          <span className="min-w-0 flex-1 truncate">
            {lane.slug ? (
              <Link to={`/projects/${lane.slug}`} className="hover:text-arc">
                {lane.name}
              </Link>
            ) : (
              lane.name
            )}
            {lane.nextTask && <span className="text-dim"> — {lane.nextTask.title}</span>}
          </span>
          {lane.lastSessionAt && (
            <span className="flex-none font-mono2 text-[10px] text-dim">
              {formatRelativeTime(lane.lastSessionAt)}
            </span>
          )}
        </div>
      ))}
    </div>
  )
}

export function ProjectBoard({
  sessions,
  isLoading,
  isError,
}: {
  sessions: LiveSession[] | undefined
  isLoading: boolean
  isError: boolean
}) {
  const { data: projects } = useProjects()
  const { live, parked } = buildLanes(sessions, projects)

  return (
    <Card>
      <CardHeader
        title="Projects"
        count={sessions ? `${live.length} live · ${parked.length} parked` : undefined}
      />

      {isLoading && (
        <div className="flex items-center gap-2 px-4 py-6 text-sm text-dim">
          <Loader2 className="h-4 w-4 animate-spin" /> Scanning transcripts…
        </div>
      )}

      {isError && (
        <div className="flex items-center gap-2 px-4 py-6 text-sm text-bad">
          <AlertCircle className="h-4 w-4" /> Failed to reach the session monitor.
        </div>
      )}

      {sessions && live.length === 0 && parked.length === 0 && (
        <div className="px-4 py-6 text-center text-[12.5px] text-dim">
          No Claude sessions active in the last 30 minutes.
        </div>
      )}

      {(live.length > 0 || parked.length > 0) && (
        <div className="relative after:pointer-events-none after:absolute after:inset-y-0 after:right-0 after:w-9 after:rounded-r-[10px] after:bg-gradient-to-r after:from-transparent after:to-deck">
          <TooltipPrimitive.Provider delayDuration={150}>
            <div className="flex gap-2.5 overflow-x-auto p-3 [scrollbar-width:thin]">
              {live.map((lane) => (
                <ProjectLane key={lane.key} lane={lane} />
              ))}
              {parked.length > 0 && <ParkedRail parked={parked} />}
            </div>
          </TooltipPrimitive.Provider>
        </div>
      )}
    </Card>
  )
}
