import type { Project } from '@/types/api'

/** SSOT for project group display — mirrors backend App\Enums\ProjectGroup. */
export type ProjectGroupKey = NonNullable<Project['group_key']>

export const GROUP_LABEL: Record<ProjectGroupKey, string> = {
  mfb: 'MFB',
  fa: 'FA',
  freelance: 'Freelance',
  personal: 'Self',
}

/** Display order for grouped sections; null group_key falls to the end. */
export const GROUP_ORDER: ProjectGroupKey[] = ['mfb', 'fa', 'freelance', 'personal']

/** Structurally compatible with ProjectCombobox's ProjectOption. */
export interface GroupedOption {
  name: string
  count: number
  groupKey?: ProjectGroupKey
  grouped?: boolean
}

/**
 * Turn per-project counts into a grouped option list: for each group in
 * GROUP_ORDER a selectable header row (combined count) with members indented,
 * except single-member groups which collapse to a plain row; ungrouped
 * projects follow flat. Members sort by count descending.
 */
export function buildGroupedOptions(
  counts: Map<string, { count: number; group: ProjectGroupKey | null }>,
): GroupedOption[] {
  const byCount = (a: GroupedOption, b: GroupedOption) => b.count - a.count
  const rows: GroupedOption[] = []
  for (const key of GROUP_ORDER) {
    const members = [...counts.entries()]
      .filter(([, v]) => v.group === key)
      .map(([name, v]) => ({ name, count: v.count, grouped: true }))
      .sort(byCount)
    if (members.length === 0) continue
    // A group header is only useful when it spans more than one project.
    if (members.length > 1) {
      const total = members.reduce((sum, m) => sum + m.count, 0)
      rows.push({ name: GROUP_LABEL[key], count: total, groupKey: key })
    } else {
      members[0].grouped = false
    }
    rows.push(...members)
  }
  const ungrouped = [...counts.entries()]
    .filter(([, v]) => v.group === null)
    .map(([name, v]) => ({ name, count: v.count }))
    .sort(byCount)
  return [...rows, ...ungrouped]
}

/** Does a project ref (any shape with name/group_key) match a combobox selection?
 *  Group rows match by group_key; project rows by display name ('Unassigned' ⇢ null ref). */
export function matchesProjectOption(
  option: { name: string; groupKey?: ProjectGroupKey } | null,
  ref: { name?: string | null; group_key?: ProjectGroupKey | null } | null | undefined,
): boolean {
  if (!option) return true
  if (option.groupKey) return ref?.group_key === option.groupKey
  return (ref?.name ?? 'Unassigned') === option.name
}
