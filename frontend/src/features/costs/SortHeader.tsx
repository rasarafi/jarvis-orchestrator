import type { SortColumn, SortState } from './sorting'
import { nextSort } from './sorting'

/** Header cell for a sortable column. Presentation only — logic lives in ./sorting. */
export function SortHeader<T, K extends string>({
  column,
  state,
  onSort,
  small = false,
}: {
  column: SortColumn<T, K>
  state: SortState<K>
  onSort: (next: SortState<K>) => void
  small?: boolean
}) {
  const active = state.key === column.key

  return (
    <button
      type="button"
      aria-sort={active ? (state.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
      onClick={() => onSort(nextSort(column, state))}
      className={`group inline-flex min-w-0 items-center gap-1.5 py-1 font-display font-semibold uppercase tracking-[0.08em] transition-colors ${
        small ? 'text-[10px]' : 'text-[10.5px]'
      } ${active ? 'text-ink' : 'text-mut hover:text-ink'} ${
        column.align === 'right' ? 'justify-end' : ''
      } ${column.className ?? ''}`}
    >
      <span className="truncate">{column.label}</span>
      <span
        aria-hidden="true"
        className={`text-[8px] transition-opacity ${
          active ? 'text-arc opacity-100' : 'opacity-0 group-hover:opacity-45'
        }`}
      >
        {active ? (state.dir === 'asc' ? '▲' : '▼') : '↕'}
      </span>
    </button>
  )
}
