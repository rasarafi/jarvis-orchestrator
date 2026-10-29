/** Sorting shared by the project table and the session drilldown. */

export type SortDir = 'asc' | 'desc'

export interface SortState<K extends string> {
  key: K
  dir: SortDir
}

export interface SortColumn<T, K extends string> {
  key: K
  label: string
  /** Quantities open descending (biggest first); text opens ascending. */
  type: 'text' | 'num'
  get: (row: T) => string | number | null
  align?: 'right'
  className?: string
}

/** Clicking the active column flips it; a new column adopts its natural direction. */
export function nextSort<T, K extends string>(
  column: SortColumn<T, K>,
  current: SortState<K>,
): SortState<K> {
  if (current.key === column.key) {
    return { key: column.key, dir: current.dir === 'asc' ? 'desc' : 'asc' }
  }
  return { key: column.key, dir: column.type === 'text' ? 'asc' : 'desc' }
}

export function sortRows<T, K extends string>(
  rows: T[],
  columns: SortColumn<T, K>[],
  state: SortState<K>,
): T[] {
  const column = columns.find((c) => c.key === state.key)
  if (!column) return rows

  return [...rows].sort((a, b) => {
    const av = column.get(a)
    const bv = column.get(b)

    // Nulls always sort last, whichever direction is active — a missing value is
    // not "smallest", it is unknown.
    if (av === null && bv === null) return 0
    if (av === null) return 1
    if (bv === null) return -1

    const out =
      column.type === 'text'
        ? String(av).localeCompare(String(bv))
        : Number(av) === Number(bv)
          ? 0
          : Number(av) < Number(bv)
            ? -1
            : 1

    return state.dir === 'asc' ? out : -out
  })
}
