import { useMemo, useState } from 'react'
import { marked } from 'marked'
import DOMPurify from 'dompurify'
import { ChevronDown, ChevronRight } from 'lucide-react'
import { Card, CardHeader } from '@/features/deck/ui'
import { useProjectContext } from './hooks/use-projects'
import { useOpenThreads } from '@/features/recap/hooks/use-recap'
import { relativeAge } from '@/features/recap/recap-format'
import { formatRelativeTime, cn } from '@/lib/utils'
import type { Project, ProjectContextSection, ProjectDailyCost } from '@/types/api'

const STALE_AFTER_DAYS = 14

function isStale(updatedAt: string): boolean {
  return Date.now() - new Date(updatedAt).getTime() > STALE_AFTER_DAYS * 86_400_000
}

const SECTION_ORDER: ProjectContextSection['section'][] = [
  'todos',
  'bugs',
  'features',
  'architecture',
  'decisions',
  'spending',
  'changelog',
]

export function ProjectRail({
  project,
  dailyCosts,
  cost7d,
  hue,
}: {
  project: Project
  dailyCosts: ProjectDailyCost[] | undefined
  cost7d: number | undefined
  hue: string
}) {
  return (
    <aside className="flex flex-col gap-4">
      <ContextCard slug={project.slug} />
      <ThreadsCard slug={project.slug} />
      <CostCard dailyCosts={dailyCosts} cost7d={cost7d} hue={hue} />
      <FactsCard project={project} />
    </aside>
  )
}

function ContextCard({ slug }: { slug: string }) {
  const { data: sections, isLoading } = useProjectContext(slug)

  const ordered = useMemo(
    () =>
      (sections ?? [])
        .filter((s) => s.content)
        .sort((a, b) => SECTION_ORDER.indexOf(a.section) - SECTION_ORDER.indexOf(b.section)),
    [sections],
  )

  if (!isLoading && ordered.length === 0) return null

  return (
    <Card>
      <CardHeader title="Context" />
      {isLoading && <div className="px-[18px] py-4 text-[12px] text-dim">Loading context…</div>}
      {ordered.map((section) => (
        <ContextRow key={section.id} section={section} />
      ))}
    </Card>
  )
}

function ContextRow({ section }: { section: ProjectContextSection }) {
  const [open, setOpen] = useState(false)
  const stale = isStale(section.updated_at)

  // Sanitized with DOMPurify before rendering.
  const html = useMemo(() => {
    if (!open || !section.content) return ''
    return DOMPurify.sanitize(marked.parse(section.content) as string)
  }, [open, section.content])

  return (
    <div className="border-b border-line-soft last:border-b-0">
      <button
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="flex w-full items-center gap-2 px-[18px] py-2.5 text-left text-[12.5px] text-ink hover:bg-hover"
      >
        {open ? (
          <ChevronDown className="h-3 w-3 shrink-0 text-dim" />
        ) : (
          <ChevronRight className="h-3 w-3 shrink-0 text-dim" />
        )}
        <span className="flex-1 capitalize">{section.section}</span>
        {stale && (
          <span className="rounded border border-gold/40 bg-gold-soft px-1.5 font-mono2 text-[9.5px] text-gold">
            stale
          </span>
        )}
        <span className="font-mono2 text-[10px] text-dim">{formatRelativeTime(section.updated_at)}</span>
      </button>
      {open && (
        <div
          className="prose prose-sm dark:prose-invert max-w-none px-[18px] pb-3 text-xs text-mut prose-p:my-1 prose-ul:my-1 prose-ol:my-1 prose-li:my-0.5 prose-headings:my-2 prose-headings:text-xs prose-headings:text-ink prose-table:text-xs prose-pre:bg-deck-2 prose-code:text-mut"
          dangerouslySetInnerHTML={{ __html: html }}
        />
      )}
    </div>
  )
}

const INITIAL_THREADS = 6

function ThreadsCard({ slug }: { slug: string }) {
  const { data } = useOpenThreads()
  const [showAll, setShowAll] = useState(false)
  const threads = (data?.active ?? []).filter((t) => t.project?.slug === slug)

  if (threads.length === 0) return null

  const visible = showAll ? threads : threads.slice(0, INITIAL_THREADS)

  return (
    <Card>
      <CardHeader title="Open threads" count={String(threads.length)} />
      {visible.map((thread) => (
        <div key={thread.id} className="border-b border-line-soft px-[18px] py-2.5 last:border-b-0">
          <div className="line-clamp-2 text-[12.5px] leading-snug text-ink">{thread.text}</div>
          <div className="mt-0.5 font-mono2 text-[10px] text-dim">opened {relativeAge(thread.created_at)}</div>
        </div>
      ))}
      {threads.length > INITIAL_THREADS && (
        <button
          onClick={() => setShowAll((v) => !v)}
          className="px-[18px] py-2.5 text-left text-[11.5px] text-arc hover:underline"
          aria-expanded={showAll}
        >
          {showAll ? 'Show less' : `Show all ${threads.length}`}
        </button>
      )}
    </Card>
  )
}

/** Tiny 7-day bar spark in the project hue — today emphasized. */
function CostCard({
  dailyCosts,
  cost7d,
  hue,
}: {
  dailyCosts: ProjectDailyCost[] | undefined
  cost7d: number | undefined
  hue: string
}) {
  if (!dailyCosts?.length) return null
  const max = Math.max(...dailyCosts.map((d) => d.cost), 0.01)

  return (
    <Card>
      <CardHeader title="Cost · 7d" count={cost7d != null ? `$${cost7d.toFixed(2)}` : undefined} />
      <div
        className="flex h-[52px] items-end gap-1 px-[18px] pt-3"
        role="img"
        aria-label={`Daily cost, last 7 days, total $${(cost7d ?? 0).toFixed(2)}`}
      >
        {dailyCosts.map((d, i) => (
          <div
            key={d.date}
            title={`${d.date}: $${d.cost.toFixed(2)}`}
            className={cn('min-h-[3px] flex-1 rounded-t-[2px]', i < dailyCosts.length - 1 && 'opacity-50')}
            style={{ background: hue, height: `${Math.max(6, (d.cost / max) * 100)}%` }}
          />
        ))}
      </div>
      <div className="flex justify-between px-[18px] pb-3 pt-1 font-mono2 text-[9px] text-dim">
        <span>{shortDay(dailyCosts[0].date)}</span>
        <span>{shortDay(dailyCosts[dailyCosts.length - 1].date)}</span>
      </div>
    </Card>
  )
}

function shortDay(date: string): string {
  return new Date(`${date}T00:00:00`).toLocaleDateString('en-US', { weekday: 'short' })
}

function FactsCard({ project }: { project: Project }) {
  const [open, setOpen] = useState(false)

  const facts: Array<[string, string]> = []
  if (project.stack?.length) facts.push(['Stack', project.stack.join(', ')])
  if (project.key_modules?.length) facts.push(['Key modules', project.key_modules.join(', ')])
  if (project.ports && Object.keys(project.ports).length)
    facts.push(['Ports', Object.entries(project.ports).map(([k, v]) => `${k}: ${v}`).join(', ')])
  if (project.run_commands && Object.keys(project.run_commands).length)
    facts.push(['Run', Object.entries(project.run_commands).map(([k, v]) => `${k}: ${v}`).join(' · ')])
  if (project.environments && Object.keys(project.environments).length)
    facts.push(['Environments', Object.entries(project.environments).map(([k, v]) => `${k}: ${v}`).join(', ')])
  if (project.repo_url) facts.push(['Repo', project.repo_url])
  if (project.known_risks) facts.push(['Known risks', project.known_risks])

  if (facts.length === 0) return null

  return (
    <Card>
      <button
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="flex w-full items-center gap-2 px-[18px] py-[13px] text-left hover:bg-hover"
      >
        {open ? (
          <ChevronDown className="h-3 w-3 shrink-0 text-dim" />
        ) : (
          <ChevronRight className="h-3 w-3 shrink-0 text-dim" />
        )}
        <span className="font-display text-[11.5px] font-semibold uppercase tracking-[0.2em] text-mut">
          Quick facts
        </span>
      </button>
      {open && (
        <div className="space-y-1.5 border-t border-line-soft px-[18px] py-3">
          {facts.map(([label, value]) => (
            <p key={label} className="break-all text-xs text-mut">
              <span className="text-dim">{label}:</span> {value}
            </p>
          ))}
        </div>
      )}
    </Card>
  )
}
