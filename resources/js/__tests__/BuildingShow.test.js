import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import BuildingShow from '@/Pages/Buildings/Show.vue'
import { floors } from './floorFixture'

vi.mock('@inertiajs/vue3', async original => ({
  ...(await original()),
  usePage: () => ({ props: { errors: {} } }),
  router: { delete: vi.fn() },
}))

// Small hand-made page props; schedule and chart calculations stay on the server.
const activities = [
  { id: 1, description: 'Excavation', floor_id: null, status: 'Done', plannedDate: '2026-01-21', actualDate: '2026-01-09', aheadDays: 12 },
  { id: 2, description: 'Ground slab', floor_id: 11, status: 'Done', plannedDate: '2026-02-10', actualDate: '2026-02-15', aheadDays: -5 },
  { id: 3, description: 'First slab', floor_id: 12, status: 'NEXT', plannedDate: '2026-03-09', actualDate: null, aheadDays: null, date_adjustment_days: 7 },
  { id: 4, description: 'Festival break', floor_id: null, status: 'Pending', plannedDate: '2026-03-29', actualDate: null, aheadDays: null, is_break: true },
  { id: 5, description: 'Handover', floor_id: null, status: 'Pending', plannedDate: '2026-04-18', actualDate: null, aheadDays: null },
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
      metrics, projection: { activities }, activities, floors: floors(), chart: {}, today: '2026-10-26', ...props,
    },
    global: { stubs: {
      AppLayout: { template: '<main><slot name="breadcrumb" /><slot /></main>' },
      ProgressChart: true, ActivityFormModal: true, RecordActualModal: true,
      FloorFormModal: true, MoveToFloorModal: true, BuildingDatesModal: true, ConfirmDialog: true,
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

  it('combines status with floor chips and resets a removed floor filter', async () => {
    const w = render()
    await w.findAll('[data-test="floor-chip"]')[2].trigger('click')
    expect(filters(w).map(b => b.text().replace(/\s+/g, ' '))).toEqual(['All 1', 'Pending 1', 'Done 0'])
    await filters(w)[2].trigger('click')
    expect(rows(w)).toHaveLength(0)
    expect(w.text()).toContain('No activities match these filters.')
    await w.setProps({ floors: [] })
    expect(rows(w)).toHaveLength(2)
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
    expect(items(w).map(b => b.text())).toEqual(['Change actual date', 'Edit', 'Insert activity below', 'Move to floor…', 'Delete'])
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

  it('keeps Move and Delete wired to their existing dialogs, with no actual or move on breaks', async () => {
    const w = render()
    await menu(cards(w)[2])
    await items(w).find(b => b.text() === 'Move to floor…').trigger('click')
    expect(w.findComponent({ name: 'MoveToFloorModal' }).props()).toMatchObject({ show: true, activity: { id: 3 } })
    await menu(cards(w)[3])
    expect(items(w).map(b => b.text())).toEqual(['Edit', 'Insert activity below', 'Delete'])
    await items(w).find(b => b.text() === 'Delete').trigger('click')
    expect(w.findAllComponents({ name: 'ConfirmDialog' }).find(c => c.props('title') === 'Delete activity').props('show')).toBe(true)
  })

  it.each([{ floorRows: [] }, { floorRows: floors() }])('hides Add floors for common work regardless of its floors', ({ floorRows }) => {
    const w = render({ floors: floorRows, building: { id: 2, name: 'Overall Timeline', type: 'common_work' } })
    expect(button(w, '+ Add floors')).toBeUndefined()
    expect(w.find('section[aria-label="Status"]').findAll('.card')).toHaveLength(4)
    expect(filters(w)).toHaveLength(3)
  })

  it('hides Move to floor when no floors exist and can add floors to a tower', async () => {
    const w = render({ floors: [] })
    await menu(rows(w)[0])
    expect(items(w).map(b => b.text())).not.toContain('Move to floor…')

    const tower = render({ floors: [] })
    await button(tower, '+ Add floors').trigger('click')
    expect(tower.findAllComponents({ name: 'FloorFormModal' }).some(c => c.props('show'))).toBe(true)
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
