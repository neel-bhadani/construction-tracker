import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ActivityFormModal from '@/Components/ActivityFormModal.vue'

// the preview request is not under test here: a stand-in answers it
const http = vi.hoisted(() => ({ requestJson: vi.fn() }))
vi.mock('@/lib/http', () => http)

// the hand-made building of ActivityDialogTest
const building = { id: 1, planned_start_date: '2026-01-01' }
const row = (id, description, predecessor_id, planned_day, plannedDate, extra = {}) =>
  ({ id, description, predecessor_id, planned_day, plannedDate, offset_days: 0, date_adjustment_days: 0, is_break: false, notes: null, actualDate: null, ...extra })
const rows = [
  row(1, 'A', null, 10, '2026-01-11', { offset_days: 10, actualDate: '2026-01-09' }),
  row(2, 'B', 1, 30, '2026-01-31', { offset_days: 20 }),
  row(3, 'C', 2, 45, '2026-02-15', { offset_days: 15 }),
  row(4, 'D', 3, 50, '2026-02-20', { offset_days: 5 }),
  row(5, 'Holi', 3, 60, '2026-03-02', { offset_days: 15, is_break: true }),
]

const ModalStub = { props: ['show', 'title'], template: '<div v-if="show"><h3>{{ title }}</h3><slot /><slot name="footer" /></div>' }

async function open(props) {
  const wrapper = mount(ActivityFormModal, {
    props: { show: false, building, activity: null, insertAfter: null, rows, today: '2026-03-01', earliest: '2026-01-01', ...props },
    global: { stubs: { Modal: ModalStub } },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()
  return wrapper
}

const dateInput = w => w.find('input[type="date"]')
const offsetInput = w => w.find('input[aria-label="Days after"]')

describe('ActivityFormModal', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    http.requestJson.mockReset().mockResolvedValue({ ok: true, data: { plannedDate: '', changedCount: 0, changed: [], finishBefore: '2026-03-02', finishAfter: '2026-03-02', pushes: null } })
  })

  it('starts a new activity after the last row', async () => {
    const w = await open({})
    expect(w.find('select').element.value).toBe('5')
    expect(w.text()).toContain('days after Holi')
  })

  it('typing a planned date sets the days after, and the other way round', async () => {
    const w = await open({ insertAfter: rows[1] })   // comes after B, work-day 30

    await dateInput(w).setValue('2026-02-10')
    expect(offsetInput(w).element.value).toBe('10')

    await offsetInput(w).setValue('7')
    expect(dateInput(w).element.value).toBe('2026-02-07')
  })

  it('sends the planned date, and where to insert, to the preview', async () => {
    const w = await open({ insertAfter: rows[1] })
    await offsetInput(w).setValue('7')
    vi.advanceTimersByTime(300)
    await flushPromises()

    expect(http.requestJson).toHaveBeenLastCalledWith('POST', '/buildings/1/activities/preview',
      { predecessor_id: 2, planned_date: '2026-02-07', after_id: 2, push: true })
  })

  it('a new "Comes after" keeps the days after it and moves the date', async () => {
    const w = await open({ insertAfter: rows[1] })
    await offsetInput(w).setValue('7')

    await w.find('select').setValue('3')     // C, work-day 45
    await flushPromises()
    expect(dateInput(w).element.value).toBe('2026-02-22')
  })

  it("says the Comes-after row's adjustment doesn't carry over", async () => {
    const nudged = rows.map(r => (r.id === 2 ? { ...r, date_adjustment_days: 5 } : r))
    const w = await open({ rows: nudged, insertAfter: nudged[1] })
    expect(w.text()).toContain("B's date includes +5 days adjustment, which doesn't carry over.")
  })

  it("shows a row's own adjustment read-only, and Clear takes it out of the date", async () => {
    const c = { ...rows[2], date_adjustment_days: 3, offset_days: 17, plannedDate: '2026-02-20' }
    const w = await open({ activity: c, rows: rows.map(r => (r.id === 3 ? c : r)) })

    expect(w.text()).toContain('Includes +3 days adjustment.')
    expect(dateInput(w).element.value).toBe('2026-02-20')

    await w.findAll('button').find(b => b.text() === 'Clear').trigger('click')
    expect(dateInput(w).element.value).toBe('2026-02-17')
    expect(offsetInput(w).element.value).toBe('17')
  })

  it('offers push when the row below comes after the row above', async () => {
    const w = await open({ insertAfter: rows[1] })   // C comes after B
    expect(w.text()).toContain('Push the activities after it later')
    expect(w.text()).toContain('C will come after the new activity')
  })

  it('says "Nothing to push" when it does not', async () => {
    const w = await open({ insertAfter: rows[3] })   // below D is Holi, which comes after C
    expect(w.text()).toContain("Nothing to push: Holi doesn't come after D.")
    expect(w.text()).not.toContain('Push the activities after it later')
  })

  it('has no actual date for a break', async () => {
    const w = await open({ activity: rows[4] })
    expect(w.text()).not.toContain('Actual date')
    expect(w.text()).toContain('A break has no actual date.')
  })

  it('keeps the note behind "Add a note" until there is one', async () => {
    const w = await open({})
    expect(w.find('textarea').exists()).toBe(false)
    await w.findAll('button').find(b => b.text() === '+ Add a note').trigger('click')
    expect(w.find('textarea').exists()).toBe(true)

    const withNote = await open({ activity: { ...rows[1], notes: 'Crane booked' } })
    expect(withNote.find('textarea').element.value).toBe('Crane booked')
  })

  it('lists the first changed activities in the preview', async () => {
    http.requestJson.mockResolvedValue({ ok: true, data: {
      plannedDate: '2026-02-07', changedCount: 6, finishBefore: '2026-03-02', finishAfter: '2026-03-09', pushes: 'C',
      changed: [{ id: 3, description: 'C', before: '2026-02-15', after: '2026-02-22' }],
    } })
    const w = await open({ insertAfter: rows[1] })
    await offsetInput(w).setValue('7')
    vi.advanceTimersByTime(300)
    await flushPromises()

    expect(w.text()).toContain('This changes 6 later activities – finish moves from 2 Mar 2026 to 9 Mar 2026.')
    expect(w.text()).toContain('C: 15 Feb 2026 → 22 Feb 2026')
    expect(w.text()).toContain('and 5 more')
  })

  it('has no Floor field, and a break has no actual date', async () => {
    const w = await open({})
    expect(w.text()).not.toMatch(/\bfloors?\b/i)
    await w.find('input[type="checkbox"]').setValue(true)
    expect(w.text()).toContain('A break has no actual date.')
    expect(w.text()).not.toMatch(/\bfloors?\b/i)
  })
})


describe('the title', () => {
  it('names the activity by its Work ID', async () => {
    const withIds = rows.map(r => ({ ...r, work_id: `TP-00${r.id}` }))
    expect((await open({ rows: withIds, activity: withIds[1] })).find('h3').text()).toBe('Edit TP-002 B')
    expect((await open({ rows: withIds, insertAfter: withIds[2] })).find('h3').text()).toBe('Insert below TP-003 C')
    expect((await open({})).find('h3').text()).toBe('Add activity')
  })
})
