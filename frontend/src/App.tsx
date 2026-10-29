import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { DeckShell } from '@/layouts/DeckShell'
import { DeckPage } from '@/features/deck/DeckPage'
import { TodayPage } from '@/features/today/TodayPage'
import { TaskBoard } from '@/features/tasks/TaskBoard'
import { SweepView } from '@/features/tasks/SweepView'
import { NotesPage } from '@/features/notes/NotesPage'
import { AuditsPage } from '@/features/audits/AuditsPage'
import { ProjectsPanel } from '@/features/projects/ProjectsPanel'
import { ProjectPage } from '@/features/projects/ProjectPage'
import { AnalyticsDashboard } from '@/features/analytics/AnalyticsDashboard'
import { TaskAnalysisPage } from '@/features/analytics/task-analysis/TaskAnalysisPage'
import { RecapPage } from '@/features/recap/RecapPage'
import { CostsPage } from '@/features/costs/CostsPage'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: true,
    },
  },
})

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <Routes>
          <Route element={<DeckShell />}>
            <Route path="/" element={<DeckPage />} />
            <Route path="/recap" element={<RecapPage />} />
            <Route path="/planner" element={<TodayPage />} />
            <Route path="/tasks" element={<TaskBoard />} />
            <Route path="/tasks/sweep" element={<SweepView />} />
            <Route path="/notes" element={<NotesPage />} />
            <Route path="/projects" element={<ProjectsPanel />} />
            <Route path="/projects/:slug" element={<ProjectPage />} />
            <Route path="/audits" element={<AuditsPage />} />
            <Route path="/analytics" element={<AnalyticsDashboard />} />
            <Route path="/analytics/tasks" element={<TaskAnalysisPage />} />
            <Route path="/costs" element={<CostsPage />} />
          </Route>
          <Route path="/today" element={<Navigate to="/planner" replace />} />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  )
}
