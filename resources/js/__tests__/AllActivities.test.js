import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AllActivities from '@/Components/AllActivities.vue'

vi.mock('@inertiajs/vue3', async original => ({ ...(await original()), router: { patch: vi.fn(), delete: vi.fn() } }))

// hand-made groups, as AllActivitiesPage gives them
const row = (id, workId, description, status, extra = {}) => ({
  id, work_id: workId, description, status, is_break: false, predecessor_id: null, planned_day: id * 10, offset_days: 10,
  date_adjustment_days: 0, notes: null, plannedDate: '2026-05-01', actualDate: status === 'Done' ? '2026-05-02' : null,
  aheadDays: null, actualOrForecast: '2026-05-03', progress: status === 'Done' ? 100 : 0, sub_activities: [], ...extra,
})
const group = (id, name, rows, extra = {}) => ({
  id, name, type: 'building', planned_start_date: '2026-04-01', actual_start_date: null, earliest: '2026-04-01',
  progress: 0.25, daysCompleted: 10, totalPlannedDays: 40, currentLead: 2, plannedFinish: '2027-01-01', forecastFinish: '2026-12-30', rows, ...extra,
})
const groups = [
  group(3, 'Common activities', [row(1, 'TP-001', 'Site fencing', 'Done'), row(2, 'TP-002', 'Boundary wall', 'NEXT')], { type: 'common_work', currentLead: -3 }),
  group(1, 'Tower A', [
    row(3, 'TP-003', 'Excavation', 'Done'),
    row(4, 'TP-004', 'Footings', 'NEXT', { progress: 50, sub_activities: [{ id: 41, position: 1, work_id: 'TP-004.1', name: 'Rebar cage', progress: 50, planned_date: null, done_date: null, note: null }] }),
    row(5, 'TP-005', 'Slab 1', 'Pending'),
  ]),
  group(2, 'Tower B', [row(6, 'TP-006', 'Excavation', 'Pending')]),
]
const project = { id: 7, progress: 0.3, mostBehind: { id: 3, name: 'Common activities', lead: -3 }, expectedHandover: '2027-01-02' }

const wrappers = []
const render = () => {
  const w = mount(AllActivities, {
    props: { project, groups, today: '2026-10-15' },
    global: { stubs: { teleport: true, ActivityFormModal: true, SubActivityFormModal: true, ConfirmDialog: true } },
  })
  wrappers.push(w)
  return w
}
afterEach(() => wrappers.splice(0).forEach(w => w.unmount()))

const groupNames = w => w.findAll('[data-test="activity-group"]').map(g => g.attributes('aria-label'))
const ids = w => w.findAll('[data-test="all-row"]').map(r => r.findAll('td')[1].text())
const chips = w => w.findAll('[data-test="status-filter"]')

describe('all activities', () => {
  it('shows the project figures, then the common activities and each building as groups', () => {
    const w = render()
    expect(w.find('[data-test="overall-lead"]').text()).toContain('3')
    expect(w.find('[data-test="overall-forecast"]').text()).toBe('2 Jan 2027')
    expect(groupNames(w)).toEqual(['Common activities', 'Tower A', 'Tower B'])
    expect(ids(w)).toEqual(['TP-001', 'TP-002', 'TP-003', 'TP-004', 'TP-005', 'TP-006'])
    expect(w.findAll('[data-test="all-card"]')).toHaveLength(6)
    expect(w.findAll('[data-test="activity-group"]')[1].find('[data-test="progress-text"]').text()).toBe('25%')
  })

  it('filters by status, counting what the search and group leave', async () => {
    const w = render()
    expect(chips(w).map(c => c.text().replace(/\s+/g, ' '))).toEqual(['All 6', 'Pending 4', 'Done 2', 'Next 2'])

    await chips(w)[3].trigger('click')
    expect(ids(w)).toEqual(['TP-002', 'TP-004'])
    // a group with nothing left is not shown
    expect(groupNames(w)).toEqual(['Common activities', 'Tower A'])

    await chips(w)[2].trigger('click')
    expect(ids(w)).toEqual(['TP-001', 'TP-003'])
  })

  it('filters by group', async () => {
    const w = render()
    await w.find('[data-test="group-filter"]').setValue(1)
    expect(groupNames(w)).toEqual(['Tower A'])
    expect(chips(w)[0].text()).toContain('3')
  })

  it('searches names and Work IDs, and opens a row found through its sub-activity', async () => {
    const w = render()
    await w.find('[data-test="activity-search"]').setValue('excavation')
    expect(ids(w)).toEqual(['TP-003', 'TP-006'])

    await w.find('[data-test="activity-search"]').setValue('tp-005')
    expect(ids(w)).toEqual(['TP-005'])

    await w.find('[data-test="activity-search"]').setValue('TP-004.1')
    expect(ids(w)).toEqual(['TP-004'])
    expect(w.find('[data-test="sub-activities"]').text()).toContain('Rebar cage')

    await w.find('[data-test="activity-search"]').setValue('nothing like this')
    expect(w.find('[data-test="no-match"]').exists()).toBe(true)
  })

  it('folds a group and opens a row to show its sub-activities', async () => {
    const w = render()
    await w.findAll('[data-test="group-toggle"]')[0].trigger('click')
    expect(ids(w)).toEqual(['TP-003', 'TP-004', 'TP-005', 'TP-006'])

    const footings = w.findAll('[data-test="all-row"]')[1]
    await footings.find('[data-test="toggle-subs"]').trigger('click')
    expect(w.find('[data-test="sub-activities"]').text()).toContain('TP-004.1')
  })

  it('opens the same Edit dialog for a row, with its group as the building', async () => {
    const w = render()
    await w.findAll('[data-test="all-row"]')[3].find('button.font-medium').trigger('click')
    const dialog = w.findComponent({ name: 'ActivityFormModal' })
    expect(dialog.props()).toMatchObject({ show: true, activity: { id: 4 }, building: { id: 1, planned_start_date: '2026-04-01' }, earliest: '2026-04-01' })
    expect(dialog.props('rows').map(r => r.id)).toEqual([3, 4, 5])
  })
})
