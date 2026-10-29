import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { auditApi } from '@/lib/api-client'
import type { AuditFinding, AuditRunResponse } from '@/types/api'

export function useRecentAudits(enabled: boolean) {
  return useQuery({
    queryKey: ['audits', 'recent'],
    queryFn: () => auditApi.recent().then((r) => r.data.data),
    enabled,
  })
}

export function useLatestAudit(project: string | null, feature: string | null = null) {
  return useQuery({
    queryKey: ['audits', 'latest', project, feature],
    queryFn: () => auditApi.latest(project!, feature).then((r) => r.data.data),
    enabled: !!project,
  })
}

export function useAuditHistory(project: string | null, feature: string | null = null) {
  return useQuery({
    queryKey: ['audits', 'history', project, feature],
    queryFn: () => auditApi.history(project!, feature).then((r) => r.data.data),
    enabled: !!project,
  })
}

export function useAuditFeatures(project: string | null) {
  return useQuery({
    queryKey: ['audits', 'features', project],
    queryFn: () => auditApi.features(project!).then((r) => r.data.data),
    enabled: !!project,
  })
}

export type AuditFindingAction = 'dismiss' | 'reopen'

export function useFindingAction(project: string | null, feature: string | null = null) {
  const qc = useQueryClient()

  const patchFinding = (finding: AuditFinding) => {
    // Patch the finding in place so the card flips without a refetch
    qc.setQueryData<AuditRunResponse | null | undefined>(['audits', 'latest', project, feature], (prev) =>
      prev
        ? { ...prev, findings: prev.findings.map((f) => (f.id === finding.id ? { ...f, ...finding } : f)) }
        : prev,
    )
  }

  return useMutation({
    mutationFn: ({ id, action }: { id: string; action: AuditFindingAction }) =>
      auditApi[action](id).then((r) => r.data.data),
    onSuccess: patchFinding,
  })
}

export function useConvertFindingToTask(project: string | null, feature: string | null = null) {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => auditApi.convertToTask(id).then((r) => r.data.data),
    onSuccess: ({ finding }) => {
      qc.setQueryData<AuditRunResponse | null | undefined>(['audits', 'latest', project, feature], (prev) =>
        prev
          ? { ...prev, findings: prev.findings.map((f) => (f.id === finding.id ? { ...f, ...finding } : f)) }
          : prev,
      )
      qc.invalidateQueries({ queryKey: ['tasks'] })
    },
  })
}
