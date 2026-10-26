import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import FloorsSection from '@/Components/FloorsSection.vue'
import FloorChips from '@/Components/FloorChips.vue'
import { activityRows, floors } from './floorFixture'

vi.mock('@inertiajs/vue3', async original => ({ ...(await original()), router: { delete: vi.fn() } }))

// modals and menus draw into <body>; here they draw in place
const ModalStub = { props: ['show', 'title'], template: '<div v-if="show" data-test="modal"><h3>{{ title }}</h3><slot /><slot name="footer" /></div>' }
const mountSection = props => mount(FloorsSection, {
  props: { floors: floors(), projectId: 1, buildingId: 2, active: 'all', ...props },
  global: { stubs: { Modal: ModalStub, teleport: true } },
})

describe('FloorsSection', () => {
  it('shows one row per floor, lowest first, Whole building last', () => {
    const rows = mountSection().findAll('[data-test="floor-row"]')
    expect(rows.map(r => r.find('button').text())).toEqual(['Ground floor', '1st floor', '2nd floor', 'Whole building'])
  })

  it('shows each floor\'s figures', () => {
    const [ground, first, , whole] = mountSection().findAll('[data-test="floor-row"]').map(r => r.text())
    expect(ground).toContain('100.0%')
    expect(ground).toContain('2 of 2 done')
    expect(ground).toContain('10 Feb 2026')
    expect(ground).toContain('Actual 12 Feb 2026')
    expect(ground).toContain('Done')
    expect(first).toContain('0 of 2 done')
    expect(first).toContain('Forecast 9 Mar 2026')
    expect(first).toContain('Behind')
    expect(whole).toContain('66.7%')
    expect(whole).toContain('1 of 2 done')
    expect(whole).toContain('In progress')
  })

  it('shows "Not started" instead of a bar for a floor with one activity', () => {
    const second = mountSection().findAll('[data-test="floor-row"]')[2]
    expect(second.find('[aria-label$="done"]').exists()).toBe(false)
    expect(second.text()).toContain('Not started')
    expect(second.text()).not.toContain('0.0%')
  })

  it('filters to a floor on click, and clears on a second click', async () => {
    const w = mountSection()
    await w.findAll('[data-test="floor-row"]')[1].trigger('click')
    expect(w.emitted('select').at(-1)).toEqual([12])

    await w.setProps({ active: 12 })
    await w.findAll('[data-test="floor-row"]')[1].trigger('click')
    expect(w.emitted('select').at(-1)).toEqual(['all'])

    await w.findAll('[data-test="floor-row"]')[3].trigger('click')
    expect(w.emitted('select').at(-1)).toEqual(['whole'])
  })

  it('asks before deleting a floor, saying its activities move and dates stay', async () => {
    const w = mountSection()
    const first = w.findAll('[data-test="floor-row"]')[1]
    await first.find('button[aria-label="More for 1st floor"]').trigger('click')
    await w.findAll('button[role="menuitem"]').find(b => b.text() === 'Delete').trigger('click')

    expect(w.text()).toContain("Delete [1st floor]? 2 activities will move to Whole building. Dates don't change.")
  })

  it('offers no menu on Whole building', () => {
    const whole = mountSection().findAll('[data-test="floor-row"]')[3]
    expect(whole.find('button[aria-label^="More for"]').exists()).toBe(false)
  })

  it('leaves adding floors to the page filters, including when there are no floors', () => {
    const w = mountSection({ floors: [] })
    expect(w.find('section').exists()).toBe(false)
    expect(w.findAll('[data-test="floor-row"]')).toHaveLength(0)
    expect(w.text()).toBe('')
    expect(mountSection().text()).not.toContain('+ Add floors')
  })
})

describe('FloorChips', () => {
  const mountChips = modelValue => mount(FloorChips, { props: { floors: floors(), rows: activityRows(), modelValue } })

  it('lists All, each floor by its short name and Whole building, with counts', () => {
    expect(mountChips('all').findAll('[data-test="floor-chip"]').map(c => c.text().replace(/\s+/g, ' ')))
      .toEqual(['All 8', 'Ground 2', '1st 2', '2nd 1', 'Whole building 3'])
  })

  it('marks the chosen chip and sets the filter on click', async () => {
    const w = mountChips(12)
    const chips = w.findAll('[data-test="floor-chip"]')
    expect(chips[2].attributes('aria-pressed')).toBe('true')
    expect(chips[0].attributes('aria-pressed')).toBe('false')

    await chips[4].trigger('click')
    expect(w.emitted('update:modelValue').at(-1)).toEqual(['whole'])
  })
})
