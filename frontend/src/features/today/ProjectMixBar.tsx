import { ProjectChip } from '@/components/ProjectChip'
import type { ProjectMixEntry } from '@/types/today'

interface ProjectMixBarProps {
  projects: ProjectMixEntry[]
}

export function ProjectMixBar({ projects }: ProjectMixBarProps) {
  if (projects.length === 0) return null

  const scheduled = projects.filter((p) => p.scheduled_count > 0)
  const unscheduled = projects.filter((p) => p.scheduled_count === 0)

  return (
    <div className="flex items-center gap-2.5 mb-5 p-2.5 bg-deck border border-line-soft rounded-lg flex-wrap">
      <span className="text-[10px] uppercase tracking-wider text-dim font-semibold">
        Today's project mix
      </span>
      <div className="flex items-center gap-1.5 flex-wrap">
        {scheduled.map((p) => (
          <ProjectChip key={p.slug} name={p.name} color={p.color} count={p.scheduled_count} />
        ))}
        {unscheduled.map((p) => (
          <ProjectChip key={p.slug} name={p.name} dim count={0} />
        ))}
      </div>
    </div>
  )
}
