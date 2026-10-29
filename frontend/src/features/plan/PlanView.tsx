import { useTodaysPlan, useStreak, useRecentPlans } from './hooks/use-plans'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { ScrollArea } from '@/components/ui/scroll-area'
import { formatDate } from '@/lib/utils'
import { Loader2, AlertCircle, Calendar, Flame } from 'lucide-react'

export function PlanView() {
  const { data: plan, isLoading: planLoading, error: planError } = useTodaysPlan()
  const { data: streak } = useStreak()
  const { data: recentPlans } = useRecentPlans(7)

  if (planLoading) {
    return (
      <div className="flex items-center justify-center h-48 text-dim">
        <Loader2 className="w-5 h-5 animate-spin mr-2" />
        Loading plan...
      </div>
    )
  }

  if (planError) {
    return (
      <div className="flex items-center justify-center h-48 text-red-400">
        <AlertCircle className="w-5 h-5 mr-2" />
        Failed to load plan
      </div>
    )
  }

  return (
    <div className="flex flex-col h-full space-y-4">
      {/* Streak */}
      {streak != null && (
        <div className="flex items-center gap-2 text-sm text-mut">
          <Flame className="w-4 h-4 text-orange-400" />
          <span className="font-medium">{streak}-day streak</span>
        </div>
      )}

      {/* Today's plan */}
      {plan ? (
        <Card className="bg-deck border-line-soft">
          <CardHeader>
            <CardTitle className="text-sm text-ink flex items-center gap-2">
              <Calendar className="w-4 h-4" />
              Today&apos;s Plan
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <PlanSection label="Must Do" text={plan.must_do} completed={plan.must_do_completed} color="text-red-400" />
            <PlanSection label="Should Do" text={plan.should_do} completed={plan.should_do_completed} color="text-amber-400" />
            <PlanSection label="Could Do" text={plan.could_do} completed={plan.could_do_completed} color="text-blue-400" />

            {plan.actual_outcome && (
              <>
                <Separator className="bg-line-soft" />
                <div>
                  <span className="text-[11px] font-medium text-dim uppercase tracking-wider">
                    Outcome
                  </span>
                  <p className="text-xs text-mut mt-1">{plan.actual_outcome}</p>
                </div>
              </>
            )}
          </CardContent>
        </Card>
      ) : (
        <Card className="bg-deck border-line-soft">
          <CardContent className="py-6">
            <p className="text-sm text-dim text-center">No plan for today yet</p>
          </CardContent>
        </Card>
      )}

      {/* Recent plans */}
      {recentPlans && recentPlans.length > 0 && (
        <div>
          <h3 className="text-xs font-medium text-dim uppercase tracking-wider mb-2">
            Recent Plans
          </h3>
          <ScrollArea className="max-h-48">
            <div className="space-y-1.5 pr-2">
              {recentPlans.map((p) => (
                <div
                  key={p.id}
                  className="flex items-center justify-between text-xs px-2 py-1.5 rounded bg-deck-2"
                >
                  <span className="text-mut">{formatDate(p.date)}</span>
                  <div className="flex items-center gap-1.5">
                    {p.must_do_completed && (
                      <Badge className="bg-red-500/20 text-red-400 border-red-500/30 text-[9px]">M</Badge>
                    )}
                    {p.should_do_completed && (
                      <Badge className="bg-amber-500/20 text-amber-400 border-amber-500/30 text-[9px]">S</Badge>
                    )}
                    {p.could_do_completed && (
                      <Badge className="bg-blue-500/20 text-blue-400 border-blue-500/30 text-[9px]">C</Badge>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </ScrollArea>
        </div>
      )}
    </div>
  )
}

function PlanSection({
  label,
  text,
  completed,
  color,
}: {
  label: string
  text: string | null
  completed: boolean
  color: string
}) {
  if (!text) return null

  return (
    <div>
      <div className="flex items-center gap-2 mb-0.5">
        <span className={`text-[11px] font-medium uppercase tracking-wider ${color}`}>
          {label}
        </span>
        {completed && (
          <Badge className="bg-green-500/20 text-green-400 border-green-500/30 text-[9px]">
            Done
          </Badge>
        )}
      </div>
      <p className={`text-xs ${completed ? 'text-dim line-through' : 'text-mut'}`}>
        {text}
      </p>
    </div>
  )
}
