import { registerPanel, panelMeta } from './panel-registry'
import { TaskBoard } from '@/features/tasks/TaskBoard'
import { ProjectsPanel } from '@/features/projects/ProjectsPanel'
import { PlanView } from '@/features/plan/PlanView'
import { AnalyticsDashboard } from '@/features/analytics/AnalyticsDashboard'

export function registerDefaultPanels() {
  registerPanel('tasks', { ...panelMeta.tasks, component: TaskBoard })
  registerPanel('project', { ...panelMeta.project, component: ProjectsPanel })
  registerPanel('plan', { ...panelMeta.plan, component: PlanView })
  registerPanel('analytics', { ...panelMeta.analytics, component: AnalyticsDashboard })
}
