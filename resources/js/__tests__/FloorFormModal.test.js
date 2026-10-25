import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import FloorFormModal from '@/Components/FloorFormModal.vue'

const ModalStub = { props: ['show', 'title'], template: '<div v-if="show"><h3>{{ title }}</h3><slot /><slot name="footer" /></div>' }

async function open(existing) {
  const w = mount(FloorFormModal, {
    props: { show: false, projectId: 1, buildingId: 2, floor: null, existing },
    global: { stubs: { Modal: ModalStub } },
  })
  await w.setProps({ show: true })
  return w
}

describe('FloorFormModal', () => {
  it('starts on the quick option for a building without floors and lists the fourteen it adds', async () => {
    const w = await open([])
    const preview = w.find('[data-test="quick-preview"]').text()

    expect(preview).toContain('Adds 14 floors')
    expect(preview).toContain('Ground floor, 1st floor, 2nd floor')
    expect(preview).toContain('13th floor')
    expect(preview).not.toContain('skipped')
    expect(w.find('button[type="submit"]').text()).toBe('Add 14 floors')
  })

  it('names the floors it will skip, and follows the numbers typed', async () => {
    const w = await open([{ name: 'Ground floor', level: 0 }, { name: '1st floor', level: 1 }])
    await w.findAll('input[type="radio"]').find(r => r.element.value === 'quick').setValue()
    await w.find('input[aria-label="Top floor"]').setValue(3)
    await w.find('input[aria-label="Basements"]').setValue(1)

    const preview = w.find('[data-test="quick-preview"]').text()
    expect(preview).toContain('Adds 3 floors')
    expect(preview).toContain('Basement 1, 2nd floor, 3rd floor')
    expect(preview).toContain('Already there, skipped: Ground floor, 1st floor')
  })

  it('says when every floor is already there, and will not save', async () => {
    const w = await open([{ name: 'Ground floor', level: 0 }, { name: '1st floor', level: 1 }])
    await w.findAll('input[type="radio"]').find(r => r.element.value === 'quick').setValue()
    await w.find('input[aria-label="Top floor"]').setValue(1)

    expect(w.find('[data-test="quick-preview"]').text()).toContain('Every one of these floors is already there')
    expect(w.find('button[type="submit"]').attributes('disabled')).toBeDefined()
  })

  it('suggests the next floor up for one floor', async () => {
    const w = await open([{ name: 'Ground floor', level: 0 }, { name: '1st floor', level: 1 }])
    expect(w.find('input[type="text"]').element.value).toBe('2nd floor')
    expect(w.find('input[type="number"]').element.value).toBe('2')
  })
})
