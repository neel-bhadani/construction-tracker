import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import BuildingShow from '@/Pages/Buildings/Show.vue'

vi.mock('@inertiajs/vue3', async original => ({
  ...(await original()),
  usePage: () => ({ props: { errors: {} } }),
  router: { delete: vi.fn() },
}))

// Small hand-made page props; schedule and chart calculations stay on the server.
const activities = [
  { id: 1, description: 'Excavation', status: 'Done', plannedDate: '2026-01-21', actualDate: '2026-01-09', aheadDays: 12 },
  { id: 2, description: 'Ground slab', status: 'Done', plannedDate: '2026-02-10', actualDate: '2026-02-15', aheadDays: -5 },
  { id: 3, description: 'First slab', status: 'NEXT', plannedDate: '2026-03-09', actualDate: null, aheadDays: null, date_adjustment_days: 7 },
  { id: 4, description: 'Festival break', status: 'Pending', plannedDate: '2026-03-29', actualDate: null, aheadDays: null, is_break: true },
  { id: 5, description: 'Handover', status: 'Pending', plannedDate: '2026-04-18', actualDate: null, aheadDays: null },
]
const metrics = {
  currentLead: 12, progress: 0.25, daysCompleted: 120, totalPlannedDays: 480,
  actualStartDate: '2026-01-01', plannedFinish: '2027-07-25',
  forecastHoldLead: '2027-07-13', aheadHoldLead: 12,
  forecastAveragePace: '2027-06-07', forecastRecentPace: '2027-07-13', activities,
}
const wrappers = []
const render = (props = {}) => {
  const wrapper = mount(BuildingShow, {
    props: {
      project: { id: 1, name: 'Test project' }, buildings: [{ id: 2, name: 'Tower' }],
      building: { id: 2, name: 'Tower', type: 'building' },
      metrics, projection: { activities }, activities, chart: {}, today: '2026-10-26', ...props,
    },
    global: { stubs: {
      AppLayout: { template: '<main><slot name="breadcrumb" /><slot /></main>' },
      ProgressChart: true, ActivityFormModal: true, RecordActualModal: true, SubActivityFormModal: true,
      BuildingDatesModal: true, ConfirmDialog: true,
      teleport: true,
    } },
  })
  wrappers.push(wrapper)
  return wrapper
}
afterEach(() => wrappers.splice(0).forEach(w => w.unmount()))

const rows = w => w.findAll('[data-test="activity-row"]')
const cards = w => w.findAll('[data-test="activity-card"]')
const filters = w => w.findAll('[data-test="status-filter"]')
const button = (w, label) => w.findAll('button').find(b => b.text() === label)
const menu = async row => row.find('button[aria-haspopup="menu"]').trigger('click')
const items = w => w.findAll('[role="menuitem"]')

describe('building page readability and activity actions', () => {
  it('shows four main summaries and keeps the other forecasts below them', async () => {
    const w = render()
    const summary = w.find('section[aria-label="Status"]')
    expect(summary.findAll('.card')).toHaveLength(4)
    expect(summary.text()).toContain('120 of 480 days')
    expect(summary.find('[role="progressbar"]').attributes('aria-valuenow')).toBe('25')
    expect(summary.findAll('.card')[3].text()).toContain('13 Jul 2027')
    expect(summary.text()).toContain('Other forecasts: 7 Jun 2027 (average pace) · 13 Jul 2027 (recent pace)')

    await w.setProps({ metrics: { ...metrics, currentLead: 0 } })
    expect(summary.findAll('.card')[0].text()).toContain('On plan')
    await w.setProps({ metrics: { ...metrics, currentLead: -5 } })
    expect(summary.findAll('.card')[0].text()).toContain('Days behind')
    expect(summary.find('.text-rose-700').text()).toBe('−5')
  })

  it('filters both layouts by status, counting NEXT among pending', async () => {
    const w = render()
    expect(filters(w).map(b => b.text().replace(/\s+/g, ' '))).toEqual(['All 5', 'Pending 3', 'Done 2'])
    await filters(w)[1].trigger('click')
    expect(filters(w)[1].attributes('aria-pressed')).toBe('true')
    expect(rows(w)).toHaveLength(3)
    expect(cards(w)).toHaveLength(3)
    expect(rows(w)[0].text()).toContain('First slab')
    await filters(w)[2].trigger('click')
    expect(rows(w)).toHaveLength(2)
    expect(cards(w)).toHaveLength(2)
    await filters(w)[0].trigger('click')
    expect(rows(w)).toHaveLength(5)
  })

  it('replaces row action buttons with one menu and a visible Mark done only on NEXT', async () => {
    const w = render()
    for (const layout of [rows(w), cards(w)]) {
      expect(layout.flatMap(row => row.findAll('button')).filter(b => b.text() === 'Mark done')).toHaveLength(1)
      for (const row of layout) {
        expect(row.findAll('button[aria-haspopup="menu"]')).toHaveLength(1)
        expect(button(row, 'Actual')).toBeUndefined()
        expect(button(row, 'Edit')).toBeUndefined()
      }
    }
    expect(button(rows(w)[2].find('td'), 'Mark done')).toBeDefined()
    expect(rows(w)[2].findAll('td').at(-1).findAll('button').map(b => b.text())).toEqual(['⋯'])
    await menu(rows(w)[0])
    expect(items(w).map(b => b.text())).toEqual(['Change actual date', 'Edit', 'Add sub-activity', 'Insert activity below', 'Delete'])
    await items(w)[0].trigger('click')
    expect(w.findComponent({ name: 'RecordActualModal' }).props()).toMatchObject({ show: true, activity: { id: 1 } })
    expect(items(w)).toHaveLength(0)

    await menu(rows(w)[2])
    expect(items(w)[0].text()).toBe('Mark done')
    await items(w)[0].trigger('click')
    expect(w.findComponent({ name: 'RecordActualModal' }).props('activity').id).toBe(3)
  })

  it('opens Edit from row names or the menu and inserts from the menu in both layouts', async () => {
    const w = render()
    for (const layout of [rows(w), cards(w)]) {
      await button(layout[1], 'Ground slab').trigger('click')
      expect(w.findComponent({ name: 'ActivityFormModal' }).props()).toMatchObject({ show: true, activity: { id: 2 } })
      w.findComponent({ name: 'ActivityFormModal' }).vm.$emit('close')
      await menu(layout[2])
      await items(w)[1].trigger('click')
      expect(w.findComponent({ name: 'ActivityFormModal' }).props('activity').id).toBe(3)
      w.findComponent({ name: 'ActivityFormModal' }).vm.$emit('close')
      await menu(layout[1])
      await items(w).find(b => b.text() === 'Insert activity below').trigger('click')
      expect(w.findComponent({ name: 'ActivityFormModal' }).props()).toMatchObject({ show: true, insertAfter: { id: 2 } })
      w.findComponent({ name: 'ActivityFormModal' }).vm.$emit('close')
    }
  })

  it('keeps Delete wired to its dialog, with no actual date on breaks', async () => {
    const w = render()
    await menu(cards(w)[3])
    expect(items(w).map(b => b.text())).toEqual(['Edit', 'Insert activity below', 'Delete'])
    await items(w).find(b => b.text() === 'Delete').trigger('click')
    expect(w.findAllComponents({ name: 'ConfirmDialog' }).find(c => c.props('title') === 'Delete activity').props('show')).toBe(true)
  })

  it('shows no floors anywhere: no section, chips, tags, menu item or Add floors', async () => {
    const w = render()
    expect(w.text()).not.toMatch(/\bfloors?\b/i)
    expect(w.find('[data-test="floor-tag"]').exists()).toBe(false)
    await menu(rows(w)[0])
    expect(items(w).map(b => b.text()).join(' ')).not.toMatch(/floor/i)
  })

  it('shows each row bar and opens its sub-activities; with any, no Mark done of its own', async () => {
    const withSubs = activities.map(a => (a.id === 3
      ? { ...a, progress: 50, sub_activities: [{ id: 31, position: 1, work_id: 'T-003.1', name: 'Shuttering', progress: 50, planned_date: null, done_date: null, note: null }] }
      : { ...a, progress: a.actualDate ? 100 : 0, sub_activities: [] }))
    const w = render({ activities: withSubs })

    expect(rows(w)[2].find('[data-test="progress-text"]').text()).toBe('50%')
    expect(rows(w)[0].find('[data-test="progress-text"]').text()).toBe('100%')
    // a break has no bar and no sub-activities
    expect(rows(w)[3].find('[data-test="progress-bar"]').exists()).toBe(false)
    expect(rows(w)[3].find('[data-test="toggle-subs"]').exists()).toBe(false)
    // the NEXT row is done through its sub-activities
    expect(button(rows(w)[2].find('td'), 'Mark done')).toBeUndefined()
    await menu(rows(w)[2])
    expect(items(w).map(b => b.text())).toEqual(['Edit', 'Add sub-activity', 'Insert activity below', 'Delete'])

    expect(w.find('[data-test="sub-row"]').exists()).toBe(false)
    await rows(w)[2].find('[data-test="toggle-subs"]').trigger('click')
    expect(w.find('[data-test="sub-row"]').text()).toContain('T-003.1')
    expect(rows(w)[2].find('[data-test="toggle-subs"]').attributes('aria-expanded')).toBe('true')
  })

  it('labels adjustments and ahead/behind in days without changing their figures', () => {
    const w = render()
    expect(rows(w)[0].findAll('td')[3].text()).toBe('+12 days')
    expect(rows(w)[1].findAll('td')[3].text()).toBe('−5 days')
    expect(rows(w)[2].findAll('td')[3].text()).toBe('-')
    expect(rows(w)[2].find('[title^="Planned date includes"]').text()).toBe('+7 days adj.')
    expect(rows(w)[2].find('[title^="Planned date includes"]').attributes('title')).toBe('Planned date includes a +7 days adjustment')
    expect(rows(w)[3].text()).toContain('Break')
    expect(rows(w)[3].text()).not.toContain('Pending')
  })
})
