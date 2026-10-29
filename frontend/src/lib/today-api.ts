import api from '@/lib/api-client'
import type { TodayPayload } from '@/types/today'

export const todayApi = {
  /**
   * Fetch the composite Today payload. If `date` is omitted, the backend uses
   * today's date.
   */
  fetch: (date?: string) =>
    api.get<TodayPayload>('/today', { params: date ? { date } : undefined }),
}

/**
 * GET /api/projects/colors — slug → CSS color map.
 * Useful for any page that renders project chips outside Today.
 */
export const projectColorsApi = {
  fetch: () => api.get<{ data: Record<string, string> }>('/projects/colors'),
}
