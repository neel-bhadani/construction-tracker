import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ProjectShow from '@/Pages/Projects/Show.vue'

vi.mock('@inertiajs/vue3', async original => ({
  ...(await original()),
  usePage: () => ({ props: { errors: {} } }),
  router: { delete: vi.fn(), patch: vi.fn(), visit: vi.fn() },
}))

// small hand-made summaries; every figure is the server's
const card = (id, name, extra = {}) => ({
  id, name, type: 'building', activitiesCount: 5, progress: 0.25, daysCompleted: 10, totalPlannedDays: 40,
  currentLead: 2, next: 'Slab', expectedHandover: '2027-07-13', ...extra,
})
const project = {
  id: 7, name: 'Test project', code: 'TP', details: null, plannedStartDate: '2026-01-01', timezone: 'Asia/Kolkata',
  buildingsCount: 2, progress: 0.3, mostBehind: null, expectedHandover: '2027-07-13',
  buildings: [card(1, 'Tower A'), card(2, 'Tower B')],
  common: card(3, 'Common activities', { type: 'common_work', activitiesCount: 4, currentLead: -3 }),
}
const timeline = {
  building: { id: 3, name: 'Common activities', type: 'common_work', planned_start_date: '2026-01-01', actual_start_date: null },
  metrics: { currentLead: null, progress: 0, daysCompleted: 0, totalPlannedDays: 10, actualStartDate: '2026-01-01', activities: [
    { id: 9, description: 'Site fencing', plannedDate: '2026-01-11', actualDate: null, aheadDays: null },
  ] },
  projection: { activities: [{ id: 9, status: 'NEXT', actualOrForecast: null }] },
  chart: {},
  activities: [{ id: 9, work_id: 'TP-001', description: 'Site fencing', is_break: false }],
  today: '2026-01-05',
}

const wrappers = []
const render = props => {
  const wrapper = mount(ProjectShow, {
    props: { project, timezones: [], copySources: [], ...props },
    global: { stubs: {
      AppLayout: { template: '<main><slot name="breadcrumb" /><slot /></main>' },
      ProgressChart: true, ActivityFormModal: true, RecordActualModal: true, BuildingDatesModal: true,
      ConfirmDialog: true, ProjectFormModal: true, BuildingFormModal: true, teleport: true,
    } },
  })
  wrappers.push(wrapper)
  return wrapper
}
afterEach(() => wrappers.splice(0).forEach(w => w.unmount()))

describe('project page', () => {
  it('shows the building cards, without the common activities among them', () => {
    const w = render({ tab: 'buildings' })
    expect(w.findAll('[data-test="building-card"]').map(c => c.find('a').text())).toEqual(['Tower A', 'Tower B'])
    expect(w.find('[data-test="common-summary"]').text()).toContain('4 activities')
    expect(w.find('[data-test="project-code"]').text()).toBe('TP')
    const tabs = w.findAll('[data-test="project-tab"]')
    expect(tabs.map(t => t.text())).toEqual(['Buildings', 'Common activities'])
    expect(tabs[0].attributes('aria-current')).toBe('page')
    expect(tabs[1].attributes('href')).toBe('/projects/7/common')
  })

  it('shows the common activities as a timeline in their own tab, with Work IDs', () => {
    const w = render({ tab: 'common', timeline })
    expect(w.findAll('[data-test="building-card"]')).toHaveLength(0)
    expect(w.findAll('[data-test="project-tab"]')[1].attributes('aria-current')).toBe('page')
    expect(w.find('[data-test="activity-row"]').text()).toContain('TP-001')
    expect(w.find('[data-test="activity-card"] [data-test="work-id"]').text()).toBe('TP-001')
    expect(w.text()).toContain('Start dates')
    expect(w.find('nav[aria-label="Breadcrumb"]').text()).toContain('Common activities')
  })
})
