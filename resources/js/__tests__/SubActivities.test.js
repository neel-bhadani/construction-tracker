import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import SubActivityList from '@/Components/SubActivityList.vue'
import SubActivityFormModal from '@/Components/SubActivityFormModal.vue'

const inertia = vi.hoisted(() => ({ router: { patch: vi.fn(), delete: vi.fn() }, post: vi.fn(), patch: vi.fn() }))
vi.mock('@inertiajs/vue3', async original => {
  const real = await original()
  return {
    ...real,
    router: inertia.router,
    // the form helper's own fields work; its requests are recorded as sent, after transform()
    useForm: data => {
      const form = real.useForm(data)
      let transform = fields => fields
      form.transform = callback => { transform = callback; return form }
      form.post = (url, options) => inertia.post(url, transform(form.data()), options)
      form.patch = (url, options) => inertia.patch(url, transform(form.data()), options)
      return form
    },
  }
})

const sub = (id, position, name, progress = 0, extra = {}) => ({
  id, position, name, progress, work_id: `TP-002.${id}`, planned_date: null, done_date: progress === 100 ? '2026-05-01' : null, note: null, ...extra,
})
const activity = (subs, extra = {}) => ({ id: 2, work_id: 'TP-002', description: 'Footings', is_break: false, actualDate: null, sub_activities: subs, ...extra })

const wrappers = []
const keep = w => { wrappers.push(w); return w }
afterEach(() => wrappers.splice(0).forEach(w => w.unmount()))
beforeEach(() => vi.clearAllMocks())

const list = props => keep(mount(SubActivityList, {
  props: { today: '2026-10-15', ...props },
  global: { stubs: { teleport: true, ConfirmDialog: true } },
}))

describe('sub-activity list', () => {
  it('shows them in position order, with their Work IDs and progress', () => {
    const w = list({ activity: activity([sub(3, 2, 'Pour', 50), sub(1, 1, 'Shuttering', 100)]) })
    const items = w.findAll('[data-test="sub-activity"]')
    expect(items.map(i => i.find('[data-test="sub-work-id"]').text())).toEqual(['TP-002.1', 'TP-002.3'])
    expect(items[0].text()).toContain('Shuttering')
    expect(items[1].find('[aria-pressed="true"]').text()).toBe('50')
  })

  it('sets progress with one click; 100% records today unless it has a date', async () => {
    const w = list({ activity: activity([sub(1, 1, 'Shuttering', 25)]) })
    const steps = w.findAll('[data-test="quick-progress"]')

    await steps[4].trigger('click')
    expect(inertia.router.patch).toHaveBeenCalledWith('/sub-activities/1', { progress: 100, done_date: '2026-10-15' }, expect.anything())

    inertia.router.patch.mock.calls[0][2].onFinish()
    await w.vm.$nextTick()
    await steps[2].trigger('click')
    expect(inertia.router.patch).toHaveBeenLastCalledWith('/sub-activities/1', { progress: 50, done_date: null }, expect.anything())
  })

  it('reorders with Move up and Move down, never renumbering', async () => {
    const w = list({ activity: activity([sub(1, 1, 'A'), sub(2, 2, 'B'), sub(3, 3, 'C')]) })
    const menus = w.findAll('button[aria-haspopup="menu"]')

    await menus[2].trigger('click')
    const labels = w.findAll('[role="menuitem"]').map(b => b.text())
    expect(labels).toEqual(['Edit', 'Move up', 'Delete'])
    await w.findAll('[role="menuitem"]').find(b => b.text() === 'Move up').trigger('click')

    expect(inertia.router.patch).toHaveBeenCalledWith('/activities/2/sub-activities/order', { ids: [1, 3, 2] }, expect.anything())
  })

  it('reorders by dragging', async () => {
    const w = list({ activity: activity([sub(1, 1, 'A'), sub(2, 2, 'B'), sub(3, 3, 'C')]) })
    const items = w.findAll('[data-test="sub-activity"]')

    await items[0].trigger('dragstart')
    await items[2].trigger('dragover')
    await items[2].trigger('drop')

    expect(inertia.router.patch).toHaveBeenCalledWith('/activities/2/sub-activities/order', { ids: [2, 3, 1] }, expect.anything())
  })

  it('offers to add one, except on a break', () => {
    expect(list({ activity: activity([]) }).find('[data-test="add-sub-activity"]').exists()).toBe(true)
    expect(list({ activity: activity([], { is_break: true }) }).find('[data-test="add-sub-activity"]').exists()).toBe(false)
  })
})

const ModalStub = { props: ['show', 'title'], template: '<div v-if="show"><h3>{{ title }}</h3><slot /><slot name="footer" /></div>' }
const dialog = async props => {
  const w = keep(mount(SubActivityFormModal, {
    props: { show: false, sub: null, today: '2026-10-15', earliest: '2026-04-01', ...props },
    global: { stubs: { Modal: ModalStub } },
  }))
  await w.setProps({ show: true })
  await flushPromises()
  return w
}

describe('sub-activity dialog', () => {
  it('starts the first one of a done activity at 100% on its date, and says so', async () => {
    const w = await dialog({ activity: activity([], { actualDate: '2026-05-02' }) })

    expect(w.find('[data-test="starts-done"]').text()).toBe('This activity is done, so the new sub-activity starts at 100%. Change it to reopen the activity.')
    expect(w.find('[data-test="progress-step"][aria-pressed="true"]').text()).toBe('100%')
    expect(w.find('input[type="date"][max="2026-10-15"]').element.value).toBe('2026-05-02')

    await w.find('input[type="text"]').setValue('Pour')
    await w.find('form').trigger('submit')
    expect(inertia.post).toHaveBeenCalledWith('/activities/2/sub-activities', expect.objectContaining({ name: 'Pour', progress: 100, done_date: '2026-05-02' }), expect.anything())
  })

  it('starts any other at 0%, with no done date until 100%', async () => {
    const w = await dialog({ activity: activity([sub(1, 1, 'A')], { actualDate: null }) })

    expect(w.find('[data-test="starts-done"]').exists()).toBe(false)
    expect(w.find('[data-test="progress-step"][aria-pressed="true"]').text()).toBe('0%')
    expect(w.find('input[max="2026-10-15"]').exists()).toBe(false)

    await w.findAll('[data-test="progress-step"]')[4].trigger('click')
    expect(w.find('input[max="2026-10-15"]').element.value).toBe('2026-10-15')
    expect(w.find('input[max="2026-10-15"]').attributes('min')).toBe('2026-04-01')
  })

  it('edits one by its Work ID', async () => {
    const w = await dialog({ activity: activity([sub(1, 1, 'Shuttering', 100)]), sub: sub(1, 1, 'Shuttering', 100) })

    expect(w.find('h3').text()).toBe('Edit TP-002.1 Shuttering')
    await w.find('form').trigger('submit')
    expect(inertia.patch).toHaveBeenCalledWith('/sub-activities/1', expect.objectContaining({ progress: 100, done_date: '2026-05-01' }), expect.anything())
  })
})
