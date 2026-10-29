/**
 * SSOT for displaying a task/note/interrupt's project.
 *
 * Tasks without a project_id are deliberately NULL in the DB (no fake
 * "General" project row — that would leak into sync, Kira cwd-resolution
 * and the remote). The UI presents that bucket as "General" everywhere.
 */
export const GENERAL_PROJECT = 'General'

export function projectLabel(project?: { name: string } | null): string {
  return project?.name ?? GENERAL_PROJECT
}
