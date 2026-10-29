import { useNavigate } from 'react-router-dom'
import { useProjects } from './hooks/use-projects'
import { useTasks } from '@/features/tasks/hooks/use-tasks'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { ScrollArea } from '@/components/ui/scroll-area'
import { formatRelativeTime, cn } from '@/lib/utils'
import { GENERAL_PROJECT } from '@/lib/project-label'
import { GROUP_LABEL, GROUP_ORDER } from '@/lib/project-groups'
import { Loader2, AlertCircle, FolderOpen } from 'lucide-react'
import type { Project } from '@/types/api'

const statusColor: Record<Project['status'], string> = {
  active: 'bg-green-500/20 text-green-400 border-green-500/30',
  paused: 'bg-amber-500/20 text-amber-400 border-amber-500/30',
  maintenance: 'bg-blue-500/20 text-blue-400 border-blue-500/30',
  archived: 'bg-deck-2 text-mut border-line',
}

function groupProjects(projects: Project[]): Array<{ key: string; label: string; items: Project[] }> {
  const byKey = new Map<string, Project[]>()
  for (const p of projects) {
    const key = p.group_key ?? 'other'
    if (!byKey.has(key)) byKey.set(key, [])
    byKey.get(key)!.push(p)
  }

  const ordered: Array<{ key: string; label: string; items: Project[] }> = []
  for (const key of GROUP_ORDER) {
    const items = byKey.get(key)
    if (items?.length) ordered.push({ key, label: GROUP_LABEL[key], items })
  }
  const other = byKey.get('other')
  if (other?.length) ordered.push({ key: 'other', label: 'Other', items: other })
  return ordered
}

interface ProjectListProps {
  onSelect?: (slug: string) => void
}

export function ProjectList({ onSelect }: ProjectListProps) {
  const { data: projects, isLoading, error } = useProjects()
  const { data: tasks } = useTasks()
  const generalCount = (tasks ?? []).filter((t) => !t.project && t.status !== 'done').length

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-48 text-dim">
        <Loader2 className="w-5 h-5 animate-spin mr-2" />
        Loading projects...
      </div>
    )
  }

  if (error) {
    return (
      <div className="flex items-center justify-center h-48 text-red-400">
        <AlertCircle className="w-5 h-5 mr-2" />
        Failed to load projects
      </div>
    )
  }

  if (!projects || projects.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center h-48 text-dim">
        <FolderOpen className="w-8 h-8 mb-2" />
        <span className="text-sm">No projects found</span>
      </div>
    )
  }

  const groups = groupProjects(projects)

  return (
    <div className="flex flex-col h-full">
      <h2 className="text-sm font-semibold text-mut mb-3">
        Projects ({projects.length})
      </h2>
      <ScrollArea className="flex-1">
        <div className="space-y-6 pr-2">
          {groups.map((group) => (
            <section key={group.key}>
              <h3 className="mb-2 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-dim">
                {group.label}
                <span className="font-mono2 text-mut">{group.items.length}</span>
              </h3>
              <div className="space-y-2">
                {group.items.map((project) => (
                  <ProjectCard
                    key={project.id}
                    project={project}
                    onClick={onSelect ? () => onSelect(project.slug) : undefined}
                  />
                ))}
              </div>
            </section>
          ))}
          {generalCount > 0 && (
            <section>
              <h3 className="mb-2 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-dim">
                {GENERAL_PROJECT}
              </h3>
              <GeneralCard count={generalCount} />
            </section>
          )}
        </div>
      </ScrollArea>
    </div>
  )
}

/** Bucket for tasks with no project — clicks through to the task board pre-filtered. */
function GeneralCard({ count }: { count: number }) {
  const navigate = useNavigate()
  const open = () => navigate(`/tasks?project=${GENERAL_PROJECT}`)
  return (
    <Card
      className="bg-deck border-line-soft cursor-pointer hover:border-line transition-colors"
      onClick={open}
      role="button"
      tabIndex={0}
      onKeyDown={(e: React.KeyboardEvent) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault()
          open()
        }
      }}
    >
      <CardHeader className="pb-0">
        <div className="flex items-start justify-between gap-2">
          <CardTitle className="text-sm text-ink">{GENERAL_PROJECT}</CardTitle>
          <Badge className="text-[10px] bg-deck-2 text-mut border-line shrink-0">
            {count} open task{count === 1 ? '' : 's'}
          </Badge>
        </div>
      </CardHeader>
      <CardContent>
        <p className="text-xs text-mut">Tasks not tied to a specific project</p>
      </CardContent>
    </Card>
  )
}

function ProjectCard({ project, onClick }: { project: Project; onClick?: () => void }) {
  return (
    <Card
      className={cn(
        'bg-deck border-line-soft',
        onClick && 'cursor-pointer hover:border-line transition-colors',
      )}
      onClick={onClick}
      role={onClick ? 'button' : undefined}
      tabIndex={onClick ? 0 : undefined}
      onKeyDown={
        onClick
          ? (e: React.KeyboardEvent) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault()
                onClick()
              }
            }
          : undefined
      }
    >
      <CardHeader className="pb-0">
        <div className="flex items-start justify-between gap-2">
          <CardTitle className="text-sm text-ink">{project.name}</CardTitle>
          <div className="flex items-center gap-1.5 shrink-0">
            <Badge className={cn('text-[10px]', statusColor[project.status])}>
              {project.status}
            </Badge>
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-1.5">
        {project.purpose && (
          <p className="text-xs text-mut line-clamp-2">{project.purpose}</p>
        )}
        {project.current_focus && (
          <p className="text-xs text-mut">
            <span className="text-dim">Focus:</span> {project.current_focus}
          </p>
        )}
        {project.last_session_at && (
          <p className="text-[11px] text-dim">
            Last active: {formatRelativeTime(project.last_session_at)}
          </p>
        )}
      </CardContent>
    </Card>
  )
}
