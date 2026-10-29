import { NavLink } from 'react-router-dom'
import { ArrowRight, Diamond } from 'lucide-react'
import { Card, CardHeader } from '@/features/deck/ui'
import { useRecapOverview, useOpenThreads } from './hooks/use-recap'

export function RecapWidget() {
  const { data } = useRecapOverview('24h')
  const { data: openThreads } = useOpenThreads()
  if (!data) return null

  const threads = openThreads?.active.slice(0, 3) ?? []
  const headlines = data.recaps.slice(0, 3)

  return (
    <Card>
      <CardHeader
        title="Recap"
        count={data.pending > 0 ? `${data.pending} pending` : undefined}
        action={
          <NavLink to="/recap" className="flex items-center gap-1 font-mono2 text-[11px] text-mut hover:text-arc">
            full recap <ArrowRight className="h-3 w-3" />
          </NavLink>
        }
      />
      <div className="px-[18px] py-2.5">
        {threads.length > 0 && (
          <ul className="mb-2 grid gap-1">
            {threads.map((thread) => (
              <li key={thread.id} className="flex items-start gap-1.5 text-[12.5px] text-ink">
                <Diamond className="mt-[3px] h-3 w-3 shrink-0 text-gold" />
                <span className="min-w-0 truncate">{thread.text}</span>
              </li>
            ))}
          </ul>
        )}
        {headlines.map((recap) => (
          <div key={recap.id} className="border-b border-line-soft py-1.5 text-[12.5px] text-mut last:border-b-0">
            <span className="font-display text-[10px] font-semibold uppercase tracking-[0.1em] text-dim">
              {recap.project?.name ?? '—'}{' '}
            </span>
            {recap.headline}
          </div>
        ))}
        {headlines.length === 0 && threads.length === 0 && (
          <p className="py-2 text-[12px] text-dim">
            No summarized sessions yet — open the full recap to run one.
          </p>
        )}
      </div>
    </Card>
  )
}
