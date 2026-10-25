import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ProgressChart from '@/Components/ProgressChart.vue'
import { chartWithNoActivities, chartWithNoActuals, chartWithOneActual } from './chartFixture'

// ECharts itself is not under test: a stand-in records what it is asked to draw
const fake = vi.hoisted(() => ({ instances: [] }))
vi.mock('@/lib/echarts', () => ({
  init: vi.fn(() => {
    const instance = { setOption: vi.fn(), resize: vi.fn(), dispose: vi.fn() }
    fake.instances.push(instance)
    return instance
  }),
}))

const lastOption = () => fake.instances.at(-1).setOption.mock.calls.at(-1)[0]
const drawnLines = () => lastOption().series.map(s => s.id).filter(id => id !== 'markers')

async function render(chart) {
  const wrapper = mount(ProgressChart, { props: { chart } })
  await flushPromises()
  return wrapper
}

describe('ProgressChart', () => {
  beforeEach(() => { fake.instances.length = 0 })

  it('draws Planned, Actual and Hold lead at first, with tickboxes for all five', async () => {
    const wrapper = await render(chartWithOneActual())

    expect(drawnLines()).toEqual(['planned', 'actual', 'holdLead'])
    const boxes = Object.fromEntries(wrapper.findAll('[data-series]').map(l => [l.attributes('data-series'), l.find('input').element.checked]))
    expect(boxes).toEqual({ planned: true, actual: true, averagePace: false, recentPace: false, holdLead: true })
    expect(wrapper.find('[data-series="averagePace"]').text()).toBe('Average pace – finish 2 Feb 2026')
  })

  it('shows and hides a line with its tickbox', async () => {
    const wrapper = await render(chartWithOneActual())

    await wrapper.find('[data-series="averagePace"] input').setValue(true)
    expect(drawnLines()).toEqual(['planned', 'actual', 'averagePace', 'holdLead'])

    await wrapper.find('[data-series="planned"] input').setValue(false)
    expect(drawnLines()).toEqual(['actual', 'averagePace', 'holdLead'])
  })

  it('switches between the full timeline and the first twelve months', async () => {
    const wrapper = await render(chartWithOneActual())
    expect(lastOption().xAxis.max).toBe(new Date(2026, 2, 10).getTime())

    await wrapper.find('[data-range="firstYear"]').trigger('click')
    expect(lastOption().xAxis.max).toBe(new Date(2027, 0, 1).getTime())
    expect(wrapper.find('[data-range="firstYear"]').attributes('aria-pressed')).toBe('true')

    await wrapper.find('[data-range="full"]').trigger('click')
    expect(lastOption().xAxis.max).toBe(new Date(2026, 2, 10).getTime())
  })

  it('with no activities, says so and loads no chart', async () => {
    const wrapper = await render(chartWithNoActivities())

    expect(wrapper.find('[data-empty]').exists()).toBe(true)
    expect(wrapper.find('[data-range]').exists()).toBe(false)
    expect(fake.instances).toHaveLength(0)
  })

  it('with nothing finished, offers only Planned and Today', async () => {
    const wrapper = await render(chartWithNoActuals())

    expect(wrapper.findAll('[data-series]').map(l => l.attributes('data-series'))).toEqual(['planned'])
    expect(wrapper.text()).toContain('Today')
    expect(wrapper.find('[data-no-actuals]').exists()).toBe(true)
    expect(drawnLines()).toEqual(['planned'])
  })

  it('redraws in place when the page hands it new points, as after recording an actual', async () => {
    const wrapper = await render(chartWithNoActuals())
    expect(drawnLines()).toEqual(['planned'])

    await wrapper.setProps({ chart: chartWithOneActual() })
    await flushPromises()

    expect(fake.instances).toHaveLength(1)
    expect(drawnLines()).toEqual(['planned', 'actual', 'holdLead'])

    // and back again when the actual is cleared
    await wrapper.setProps({ chart: chartWithNoActuals() })
    await flushPromises()
    expect(drawnLines()).toEqual(['planned'])
  })
})
