import { useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { marked } from 'marked'
import DOMPurify from 'dompurify'
import { AlertTriangle, ChevronDown, ChevronRight, Loader2, ShieldCheck, Undo2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { projectColor } from '@/features/deck/ui'
import { ProjectCombobox, type ProjectOption } from '@/components/ProjectCombobox'
import { useProjects } from '@/features/projects/hooks/use-projects'
import type { AuditFinding, AuditFindingSeverity, AuditRun, AuditRunResponse, AuditScope, RecentAuditRun } from '@/types/api'
import { useAuditFeatures, useAuditHistory, useConvertFindingToTask, useFindingAction, useLatestAudit, useRecentAudits } from './hooks/use-audits'

const SEVERITY_ORDER: AuditFindingSeverity[] = ['critical', 'warning', 'info']

const severityBadge: Record<AuditFindingSeverity, string> = {
  critical: 'bg-bad/10 text-bad',
  warning: 'bg-gold-soft text-gold',
  info: 'bg-deck-2 text-dim',
}

const runStatusChip: Record<AuditRun['status'], string> = {
  passed: 'border-ok/35 bg-ok/10 text-ok',
  flagged: 'border-gold/40 bg-gold-soft text-gold',
  blocked: 'border-bad/40 bg-bad/10 text-bad',
  running: 'border-line bg-deck-2 text-mut',
}

const GATE_LABELS: Record<AuditScope, string[]> = {
  system: ['0 · Alive', '1 · Substance', '2 · Experience'],
  feature: ['0 · Setup', '1 · Conformance', '2 · Experience'],
}

/** Utility styling for marked-rendered finding/summary markdown (no typography plugin in this app). */
const mdBody =
  '[&_p]:my-1 [&_ul]:my-1 [&_ul]:list-disc [&_ul]:pl-4 [&_ol]:my-1 [&_ol]:list-decimal [&_ol]:pl-4 [&_li]:my-0.5 [&_li]:marker:text-dim [&_strong]:font-semibold [&_strong]:text-ink [&_code]:rounded [&_code]:bg-deck-2 [&_code]:px-1 [&_code]:font-mono2 [&_code]:text-[11px] [&_a]:underline'

function relativeDay(iso: string): string {
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000)
  if (days <= 0) return 'today'
  if (days === 1) return 'yesterday'
  return `${days}d ago`
}

function durationLabel(run: AuditRun): string | null {
  if (!run.started_at || !run.finished_at) return null
  const secs = Math.round((new Date(run.finished_at).getTime() - new Date(run.started_at).getTime()) / 1000)
  return secs >= 60 ? `${Math.floor(secs / 60)}m ${secs % 60}s` : `${secs}s`
}

export function AuditsPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const project = searchParams.get('project')
  const feature = searchParams.get('feature')

  const projects = useProjects()
  const features = useAuditFeatures(project)
  const latest = useLatestAudit(project, feature)
  const result: AuditRunResponse | null | undefined = latest.data

  const setProject = (slug: string | null) => setSearchParams(slug ? { project: slug } : {})
  const setFeature = (f: string | null) =>
    setSearchParams(project ? (f ? { project, feature: f } : { project }) : {})

  const featureRows = features.data ?? []
  const isFeature = !!feature
  const emptyHint = isFeature
    ? `Run \`feature-audit ${feature}\` from the project repo.`
    : 'Run `handover-audit` from the project repo before handing over to the client.'

  return (
    <div className="flex flex-col gap-4">
      <div>
        <div className="font-display text-[11.5px] uppercase tracking-[0.18em] text-dim">
          <span className="font-semibold text-mut">{isFeature ? 'Feature audits' : 'Handover audits'}</span>
        </div>
        <h2 className="mt-1 flex flex-wrap items-baseline gap-x-3 font-display text-lg font-semibold text-ink">
          {isFeature ? (
            <>
              Feature audit
              <span className="font-mono2 text-[13px] font-normal text-arc">{feature}</span>
            </>
          ) : (
            'Handover audit'
          )}
          <span className="font-sans text-[13px] font-normal text-mut">
            {isFeature
              ? 'does this feature do what we said, and does it feel done — dismissals become suppressions'
              : 'fresh-eyes acceptance review of the deployed product — dismissals become suppressions'}
          </span>
        </h2>
      </div>

      {/* config bar */}
      <div className="flex flex-wrap items-center gap-2.5 rounded-xl border border-line-soft bg-deck px-3.5 py-2.5 shadow-[var(--card-shadow)]">
        <span className="font-display text-[10.5px] uppercase tracking-[0.14em] text-dim">Project</span>
        <ProjectCombobox
          projects={(projects.data ?? []).map((p): ProjectOption => ({ name: p.name }))}
          value={(projects.data ?? []).find((p) => p.slug === project)?.name ?? null}
          onChange={(option) => {
            const slug = option ? (projects.data ?? []).find((p) => p.name === option.name)?.slug : null
            setProject(slug ?? null)
          }}
        />
        <span className="basis-full text-[11.5px] text-dim sm:ml-auto sm:basis-auto">
          Runs come from the{' '}
          <code className="rounded bg-deck-2 px-1 font-mono2 text-[11px]">{isFeature ? 'feature-audit' : 'handover-audit'}</code>{' '}
          skill, executed in the project repo.
        </span>
      </div>

      {/* scope picker — only when this project has feature audits */}
      {project && featureRows.length > 0 && (
        <div className="flex flex-wrap items-center gap-1.5">
          <span className="mr-1 font-display text-[10.5px] uppercase tracking-[0.14em] text-dim">Scope</span>
          <button
            onClick={() => setFeature(null)}
            className={cn(
              'rounded-full border px-2.5 py-1 font-mono2 text-[11px]',
              !isFeature ? 'border-arc/50 bg-arc-soft font-semibold text-arc' : 'border-line text-mut hover:bg-hover',
            )}
          >
            handover
          </button>
          {featureRows.map((f) => (
            <button
              key={f.feature}
              onClick={() => setFeature(f.feature)}
              className={cn(
                'flex items-center gap-1.5 rounded-full border px-2.5 py-1 font-mono2 text-[11px]',
                feature === f.feature ? 'border-arc/50 bg-arc-soft font-semibold text-arc' : 'border-line text-mut hover:bg-hover',
              )}
            >
              {f.feature}
              {f.critical_findings_count > 0 ? (
                <span className="text-bad">{f.critical_findings_count}!</span>
              ) : f.open_findings_count > 0 ? (
                <span className="text-dim">{f.open_findings_count}</span>
              ) : null}
            </button>
          ))}
        </div>
      )}

      {!project ? (
        <RecentRuns onPick={(slug, f) => setSearchParams(f ? { project: slug, feature: f } : { project: slug })} />
      ) : latest.isLoading ? (
        <div className="flex h-40 items-center justify-center text-dim">
          <Loader2 className="mr-2 h-5 w-5 animate-spin" /> Loading latest audit…
        </div>
      ) : latest.isError ? (
        <div className="flex items-start gap-2 rounded-[10px] border border-bad/40 bg-bad/10 px-3.5 py-2.5 text-[12.5px] text-bad">
          <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
          <span>Couldn't load audits — {(latest.error as Error).message}</span>
        </div>
      ) : !result ? (
        <EmptyBlock title={isFeature ? `No audits yet for “${feature}”.` : 'No audits yet for this project.'} hint={emptyHint} />
      ) : (
        <AuditResult key={result.run.id} project={project} feature={feature} result={result} />
      )}

      {project && <HistoryStrip project={project} feature={feature} currentRunId={result?.run.id} />}
    </div>
  )
}

/** No-project landing: latest runs across all projects — click to open. */
function RecentRuns({ onPick }: { onPick: (slug: string, feature: string | null) => void }) {
  const recent = useRecentAudits(true)

  if (recent.isLoading) {
    return (
      <div className="flex flex-col gap-2">
        {[0, 1, 2].map((i) => (
          <div key={i} className="h-[46px] animate-pulse rounded-xl border border-line-soft bg-deck" />
        ))}
      </div>
    )
  }

  if (recent.isError) {
    return (
      <div className="flex items-start gap-2 rounded-[10px] border border-bad/40 bg-bad/10 px-3.5 py-2.5 text-[12.5px] text-bad">
        <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
        <span>Couldn't load recent audits — {(recent.error as Error).message}</span>
      </div>
    )
  }

  const runs = recent.data ?? []
  if (runs.length === 0) {
    return (
      <EmptyBlock
        title="No audits anywhere yet."
        hint="Run `handover-audit` from a project repo before handing over to the client."
      />
    )
  }

  return (
    <section>
      <div className="mb-2 flex items-center gap-2.5">
        <h3 className="font-display text-xs font-semibold uppercase tracking-[0.18em] text-mut">Recent runs</h3>
        <span className="font-mono2 text-[11.5px] text-dim">all projects · click to open</span>
      </div>
      <div className="flex flex-col gap-2">
        {runs.map((r: RecentAuditRun) => (
          <button
            key={r.id}
            onClick={() => onPick(r.project.slug, r.scope === 'feature' ? r.feature : null)}
            className="flex flex-wrap items-center gap-2.5 rounded-xl border border-line-soft bg-deck px-4 py-2.5 text-left shadow-[var(--card-shadow)] hover:border-line hover:bg-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-arc"
          >
            <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: projectColor(r.project.name) }} />
            <span className="min-w-0 truncate text-[13.5px] font-semibold text-ink">{r.project.name}</span>
            {r.scope === 'feature' && r.feature && (
              <span className="shrink-0 rounded-full border border-arc/40 bg-arc-soft px-2 py-px font-mono2 text-[10px] text-arc">
                feat · {r.feature}
              </span>
            )}
            <span className={cn('rounded-full border px-2 py-px font-display text-[10px] font-semibold uppercase tracking-[0.14em]', runStatusChip[r.status])}>
              {r.status}
            </span>
            <span className="font-mono2 text-[11px] text-dim">gate {r.gate_reached}</span>
            <span className="ml-auto flex shrink-0 items-center gap-2 font-mono2 text-[11px]">
              {r.critical_findings_count > 0 && (
                <span className="text-bad">{r.critical_findings_count} critical</span>
              )}
              <span className="text-mut">{r.open_findings_count} open</span>
              <span className="text-dim">{relativeDay(r.created_at)}</span>
            </span>
          </button>
        ))}
      </div>
    </section>
  )
}

function EmptyBlock({ title, hint }: { title: string; hint: string }) {
  return (
    <div className="flex h-48 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-line text-center">
      <ShieldCheck className="h-7 w-7 text-dim" />
      <p className="text-sm text-mut">{title}</p>
      <p className="text-xs text-dim">{hint}</p>
    </div>
  )
}

function AuditResult({ project, feature, result }: { project: string; feature: string | null; result: AuditRunResponse }) {
  const { run, findings } = result
  const gateLabels = GATE_LABELS[run.scope] ?? GATE_LABELS.system
  const [moduleFilter, setModuleFilter] = useState<string | null>(null)

  const open = findings.filter((f) => f.status !== 'suppressed')
  const suppressed = findings.filter((f) => f.status === 'suppressed')

  const modules = useMemo(() => {
    const counts = new Map<string, number>()
    open.forEach((f) => counts.set(f.module, (counts.get(f.module) ?? 0) + 1))
    return [...counts.entries()].sort((a, b) => b[1] - a[1])
  }, [open])

  const visible = moduleFilter ? open.filter((f) => f.module === moduleFilter) : open

  const groups = useMemo(
    () =>
      SEVERITY_ORDER
        .map((severity) => ({ severity, items: visible.filter((f) => f.severity === severity) }))
        .filter((g) => g.items.length > 0),
    [visible],
  )

  const duration = durationLabel(run)

  return (
    <>
      {/* latest run banner */}
      <div className="flex flex-wrap items-center gap-2.5 rounded-xl border border-line-soft bg-deck px-4 py-3 shadow-[var(--card-shadow)]">
        <span className={cn('rounded-full border px-2.5 py-px font-display text-[10.5px] font-semibold uppercase tracking-[0.14em]', runStatusChip[run.status])}>
          {run.status}
        </span>
        <div className="flex items-center gap-1.5">
          {gateLabels.map((label, gate) => {
            const reached = run.gate_reached >= gate
            const passedGate = run.gate_reached > gate || (run.gate_reached === gate && run.status !== 'blocked')
            return (
              <span
                key={label}
                className={cn(
                  'rounded-full border px-2 py-px font-mono2 text-[10.5px]',
                  !reached
                    ? 'border-line-soft text-dim opacity-60'
                    : passedGate
                      ? 'border-ok/35 bg-ok/10 text-ok'
                      : 'border-bad/40 bg-bad/10 text-bad',
                )}
              >
                {label} {!reached ? '·' : passedGate ? '✓' : '✗'}
              </span>
            )
          })}
        </div>
        {run.app_classification.map((c) => (
          <span key={c} className="rounded-full border border-line bg-deck-2 px-2 py-px font-mono2 text-[10px] uppercase tracking-[0.08em] text-mut">
            {c}
          </span>
        ))}
        <span className="ml-auto font-mono2 text-[11px] text-dim">
          {relativeDay(run.created_at)}{duration && ` · ${duration}`}
        </span>
        {run.summary && (
          <div
            className={cn('basis-full text-[12.5px] text-mut', mdBody)}
            dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(marked.parse(run.summary) as string) }}
          />
        )}
      </div>

      {/* module (category) filter chips */}
      {modules.length > 1 && (
        <div className="flex flex-wrap items-center gap-1.5">
          <button
            onClick={() => setModuleFilter(null)}
            className={cn(
              'rounded-full border px-2.5 py-1 font-mono2 text-[11px]',
              moduleFilter === null ? 'border-arc/50 bg-arc-soft font-semibold text-arc' : 'border-line text-mut hover:bg-hover',
            )}
          >
            all · {open.length}
          </button>
          {modules.map(([module, count]) => (
            <button
              key={module}
              onClick={() => setModuleFilter((m) => (m === module ? null : module))}
              className={cn(
                'rounded-full border px-2.5 py-1 font-mono2 text-[11px]',
                moduleFilter === module ? 'border-arc/50 bg-arc-soft font-semibold text-arc' : 'border-line text-mut hover:bg-hover',
              )}
            >
              {module} · {count}
            </button>
          ))}
        </div>
      )}

      {open.length === 0 && (
        <div className="flex h-32 flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-ok/30 text-center">
          <p className="text-sm font-semibold text-ok">Nothing flagged.</p>
          <p className="text-xs text-dim">
            {suppressed.length > 0 ? 'Only previously-dismissed findings re-occurred.' : 'This run raised no findings.'}
          </p>
        </div>
      )}

      {groups.map(({ severity, items }) => (
        <section key={severity}>
          <div className="mb-2 flex items-center gap-2.5">
            <h3 className="font-display text-xs font-semibold uppercase tracking-[0.18em] text-mut">{severity}</h3>
            <span className="font-mono2 text-[11.5px] text-dim">
              {items.filter((f) => f.status === 'open').length} open
            </span>
          </div>
          <div className="flex flex-col gap-2.5">
            {items.map((f) => (
              <FindingCard key={f.id} project={project} feature={feature} finding={f} />
            ))}
          </div>
        </section>
      ))}

      {suppressed.length > 0 && <SuppressedSection project={project} feature={feature} items={suppressed} />}
    </>
  )
}

function FindingCard({ project, feature, finding }: { project: string; feature: string | null; finding: AuditFinding }) {
  const action = useFindingAction(project, feature)
  const convert = useConvertFindingToTask(project, feature)
  const [showEvidence, setShowEvidence] = useState(false)
  const [showFullDetail, setShowFullDetail] = useState(false)
  const busy = action.isPending || convert.isPending
  const hasEvidence = Object.keys(finding.evidence ?? {}).length > 0

  const detailHtml = useMemo(
    () => (finding.detail ? DOMPurify.sanitize(marked.parse(finding.detail) as string) : ''),
    [finding.detail],
  )
  const detailIsLong = (finding.detail?.length ?? 0) > 320

  if (finding.status === 'dismissed' || finding.status === 'converted') {
    const converted = finding.status === 'converted'
    return (
      <div className={cn('flex items-center gap-2.5 rounded-xl border border-line-soft bg-deck px-4 py-2.5 shadow-[var(--card-shadow)]', !converted && 'opacity-60')}>
        <span className={cn('font-bold', converted ? 'text-arc' : 'text-dim')}>{converted ? '→' : '—'}</span>
        <span className="truncate text-[13px] text-ink">{finding.title}</span>
        <span className="hidden text-xs text-dim sm:block">
          {converted ? 'converted to task' : "dismissed as by-design · won't be re-flagged"}
        </span>
        {converted ? (
          <Link to="/tasks" className="ml-auto shrink-0 text-xs font-semibold text-arc hover:underline">
            view tasks
          </Link>
        ) : (
          <button
            onClick={() => action.mutate({ id: finding.id, action: 'reopen' })}
            disabled={busy}
            className="ml-auto flex shrink-0 items-center gap-1 text-xs font-semibold text-arc hover:underline disabled:opacity-60"
          >
            <Undo2 className="h-3 w-3" /> Reopen
          </button>
        )}
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-line-soft bg-deck px-4 py-3.5 shadow-[var(--card-shadow)]">
      <div className="flex items-start gap-2.5">
        <div className="min-w-0">
          <div className="text-[14px] font-semibold leading-snug text-ink">{finding.title}</div>
          <div className="mt-0.5 flex flex-wrap items-center gap-2 text-[11.5px] text-dim">
            <span className="rounded-full border border-line-soft bg-deck-2 px-2 py-px font-mono2 text-[10.5px] text-mut">
              {finding.module}
            </span>
            <span>gate {finding.gate}</span>
            <span className="break-all font-mono2 text-[10.5px]">{finding.suppression_key}</span>
          </div>
        </div>
        <span className={cn('ml-auto shrink-0 rounded-[5px] px-2 py-0.5 font-display text-[10px] font-semibold uppercase tracking-[0.14em]', severityBadge[finding.severity])}>
          {finding.severity}
        </span>
      </div>

      {finding.detail && (
        <div className="mt-2">
          <div
            className={cn(
              'text-[12.5px] text-mut',
              mdBody,
              detailIsLong && !showFullDetail && 'max-h-24 overflow-hidden [mask-image:linear-gradient(to_bottom,black_60%,transparent)]',
            )}
            dangerouslySetInnerHTML={{ __html: detailHtml }}
          />
          {detailIsLong && (
            <button
              onClick={() => setShowFullDetail((v) => !v)}
              className="mt-1 flex items-center gap-1 text-[11.5px] font-semibold text-dim hover:text-mut"
            >
              {showFullDetail ? <ChevronDown className="h-3 w-3" /> : <ChevronRight className="h-3 w-3" />}
              {showFullDetail ? 'less' : 'full detail'}
            </button>
          )}
        </div>
      )}

      {hasEvidence && (
        <div className="mt-2">
          <button
            onClick={() => setShowEvidence((v) => !v)}
            className="flex items-center gap-1 text-[11.5px] font-semibold text-dim hover:text-mut"
          >
            {showEvidence ? <ChevronDown className="h-3 w-3" /> : <ChevronRight className="h-3 w-3" />}
            evidence
          </button>
          {showEvidence && (
            <pre className="mt-1.5 overflow-x-auto rounded-lg border border-line-soft bg-deck-2 px-3 py-2 font-mono2 text-[11px] leading-relaxed text-mut">
              {JSON.stringify(finding.evidence, null, 2)}
            </pre>
          )}
        </div>
      )}

      <div className="mt-3 flex items-center gap-2">
        <button
          onClick={() => action.mutate({ id: finding.id, action: 'dismiss' })}
          disabled={busy}
          className="rounded-lg border border-line px-3 py-1 text-[12.5px] font-semibold text-mut hover:bg-hover hover:text-ink disabled:opacity-60"
        >
          Dismiss (by design)
        </button>
        <button
          onClick={() => convert.mutate(finding.id)}
          disabled={busy}
          className="rounded-lg border border-arc/40 bg-arc-soft px-3 py-1 text-[12.5px] font-semibold text-arc hover:bg-arc/20 disabled:opacity-60"
        >
          Convert to task
        </button>
      </div>
    </div>
  )
}

function SuppressedSection({ project, feature, items }: { project: string; feature: string | null; items: AuditFinding[] }) {
  const [expanded, setExpanded] = useState(false)
  const action = useFindingAction(project, feature)

  return (
    <div className="rounded-[10px] border border-dashed border-line px-3.5 py-2.5 text-[12.5px] text-mut">
      <button onClick={() => setExpanded((v) => !v)} className="flex items-center gap-1.5 font-semibold hover:text-ink">
        {expanded ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
        {items.length} previously-dismissed finding{items.length > 1 ? 's' : ''} matched
      </button>
      {expanded && (
        <div className="mt-2 flex flex-col gap-1.5">
          {items.map((f) => (
            <div key={f.id} className="flex items-center gap-2.5 rounded-lg border border-line-soft bg-deck px-3 py-1.5">
              <span className="truncate text-[12.5px] text-mut">{f.title}</span>
              <span className="hidden break-all font-mono2 text-[10.5px] text-dim sm:block">{f.suppression_key}</span>
              <button
                onClick={() => action.mutate({ id: f.id, action: 'reopen' })}
                disabled={action.isPending}
                className="ml-auto shrink-0 text-xs font-semibold text-arc hover:underline disabled:opacity-60"
              >
                Reopen
              </button>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

function HistoryStrip({ project, feature, currentRunId }: { project: string; feature: string | null; currentRunId?: string }) {
  const history = useAuditHistory(project, feature)
  const past = (history.data ?? []).filter((r) => r.id !== currentRunId)

  if (history.isLoading || past.length === 0) return null

  return (
    <div className="rounded-[10px] border border-dashed border-line px-3.5 py-2.5 text-[12.5px] text-mut">
      <span className="font-semibold">Previous runs</span>
      {' — '}
      {past.slice(0, 8).map((r, i) => (
        <span key={r.id}>
          {i > 0 && ' · '}
          <span className={cn('font-mono2 text-[11.5px]', r.status === 'passed' ? 'text-ok' : r.status === 'blocked' ? 'text-bad' : 'text-gold')}>
            {r.status}
          </span>{' '}
          <span className="text-dim">{relativeDay(r.created_at)} · gate {r.gate_reached}</span>
        </span>
      ))}
    </div>
  )
}
