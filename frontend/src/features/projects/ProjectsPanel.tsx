import { useNavigate } from 'react-router-dom'
import { ProjectList } from './ProjectList'

/** The /projects index — clicking a project drills into /projects/:slug. */
export function ProjectsPanel() {
  const navigate = useNavigate()

  return <ProjectList onSelect={(slug) => navigate(`/projects/${slug}`)} />
}
