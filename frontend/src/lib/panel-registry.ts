import type { ComponentType } from 'react'
import { CheckSquare, FolderOpen, Calendar, BarChart3 } from 'lucide-react'

export type PanelKey = 'tasks' | 'project' | 'plan' | 'analytics'

export interface PanelDefinition {
  component: ComponentType
  icon: ComponentType<{ className?: string }>
  label: string
}

const registry = new Map<PanelKey, PanelDefinition>()

export function registerPanel(key: PanelKey, definition: PanelDefinition) {
  registry.set(key, definition)
}

export function getPanel(key: PanelKey): PanelDefinition | undefined {
  return registry.get(key)
}

export function getAllPanels(): Array<[PanelKey, PanelDefinition]> {
  return Array.from(registry.entries())
}

export const panelMeta: Record<PanelKey, { icon: ComponentType<{ className?: string }>; label: string }> = {
  tasks: { icon: CheckSquare, label: 'Tasks' },
  project: { icon: FolderOpen, label: 'Projects' },
  plan: { icon: Calendar, label: 'Plan' },
  analytics: { icon: BarChart3, label: 'Analytics' },
}
