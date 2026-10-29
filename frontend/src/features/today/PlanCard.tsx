import { useState } from 'react'
import { Check } from 'lucide-react'
import { useTodaysPlan, useUpdateOutcome, useMarkCompletion } from '@/features/plan/hooks/use-plans'
import { useRecapSuggestions } from '@/features/recap/hooks/use-recap'

const TIERS = [
  { key: 'must_do', done: 'must_do_completed', label: 'Must' },
  { key: 'should_do', done: 'should_do_completed', label: 'Should' },
  { key: 'could_do', done: 'could_do_completed', label: 'Could' },
] as const

export function PlanCard() {
  const { data: plan } = useTodaysPlan()
  const markCompletion = useMarkCompletion()
  const updateOutcome = useUpdateOutcome()
  const [outcomeDraft, setOutcomeDraft] = useState('')
  const { data: suggestions = [] } = useRecapSuggestions()

  if (plan === undefined) return null // still loading

  if (plan === null) {
    return (
      <section className="rounded-[10px] border border-line-soft bg-deck p-4 shadow-[var(--card-shadow)]">
        <div className="mb-2 font-display text-[12px] font-semibold uppercase tracking-wider text-mut">
          Today's plan
        </div>
        {suggestions.length > 0 && (
          <div className="mb-2.5 rounded-md bg-gold-soft px-2.5 py-2">
            <span className="font-display text-[9.5px] font-semibold uppercase tracking-[0.14em] text-gold">
              Carried over from recent sessions
            </span>
            <ul className="mt-1 grid gap-0.5">
              {suggestions.map((s) => (
                <li key={s.recap_id} className="text-[12px] text-mut">
                  <span className="text-dim">{s.project_name ?? '—'}:</span> {s.next_action}
                </li>
              ))}
            </ul>
          </div>
        )}
        <p className="rounded-lg border border-dashed border-line py-2 text-center text-[12.5px] text-dim">
          No plan yet. Plan it in a Jarvis session
        </p>
      </section>
    )
  }

  return (
    <section className="rounded-[10px] border border-line-soft bg-deck p-4 shadow-[var(--card-shadow)]">
      <div className="mb-2 font-display text-[12px] font-semibold uppercase tracking-wider text-mut">
        Today's plan
      </div>

      <div className="space-y-1.5">
        {TIERS.map(({ key, done, label }) => {
          const text = plan[key]
          if (!text) return null
          const isDone = plan[done]
          return (
            <div key={key} className="flex items-start gap-2">
              <button
                onClick={() => markCompletion.mutate({ id: plan.id, [done]: !isDone })}
                disabled={markCompletion.isPending}
                aria-label={isDone ? `Reopen ${label.toLowerCase()}-do` : `Mark ${label.toLowerCase()}-do complete`}
                className={`mt-px flex h-4 w-4 shrink-0 items-center justify-center rounded-[5px] border transition-colors ${
                  isDone
                    ? 'border-arc/50 bg-arc-soft text-arc'
                    : 'border-line text-transparent hover:border-arc/50 hover:text-arc/40'
                }`}
              >
                <Check className="h-3 w-3" />
              </button>
              <span className="min-w-0 text-[13px]">
                <span className="mr-1.5 font-mono2 text-[10.5px] uppercase text-dim">{label}</span>
                <span className={isDone ? 'text-mut line-through' : 'text-ink'}>{text}</span>
              </span>
            </div>
          )
        })}
      </div>

      <div className="mt-3 border-t border-line-soft pt-2.5">
        {plan.actual_outcome ? (
          <p className="text-[12.5px] text-mut">
            <span className="font-mono2 text-[10.5px] uppercase text-dim">Outcome · </span>
            {plan.actual_outcome}
          </p>
        ) : (
          <div className="flex gap-2">
            <input
              value={outcomeDraft}
              onChange={(e) => setOutcomeDraft(e.target.value)}
              placeholder="How did the day actually go?"
              className="min-w-0 flex-1 rounded-md border border-line bg-transparent px-2 py-1 text-[12.5px] text-ink placeholder:text-dim focus:border-arc/50 focus:outline-none"
            />
            <button
              onClick={() => outcomeDraft.trim() && updateOutcome.mutate({ id: plan.id, actual_outcome: outcomeDraft.trim() })}
              disabled={!outcomeDraft.trim() || updateOutcome.isPending}
              className="shrink-0 rounded-md border border-arc/40 px-2.5 py-1 text-[12px] text-arc transition-colors hover:bg-arc-soft disabled:cursor-not-allowed disabled:opacity-50"
            >
              Record
            </button>
          </div>
        )}
      </div>
    </section>
  )
}
