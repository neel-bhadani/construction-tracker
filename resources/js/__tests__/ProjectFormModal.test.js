import { describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ProjectFormModal from '@/Components/ProjectFormModal.vue'

const ModalStub = { props: ['show', 'title'], template: '<div v-if="show"><h3>{{ title }}</h3><slot /><slot name="footer" /></div>' }
const project = { id: 7, name: 'Test project', code: 'TP', details: null, plannedStartDate: '2026-01-01', timezone: 'Asia/Kolkata' }

async function open(props) {
  const wrapper = mount(ProjectFormModal, {
    props: { show: false, project: null, timezones: ['Asia/Kolkata'], ...props },
    global: { stubs: { Modal: ModalStub } },
  })
  await wrapper.setProps({ show: true })
  await flushPromises()
  return wrapper
}

describe('ProjectFormModal', () => {
  it('explains the Work ID prefix in Edit project: only new activities take a changed one', async () => {
    const w = await open({ project })
    expect(w.text()).toContain('Work ID prefix')
    expect(w.text()).toContain('Every activity in this project gets an ID like TP-001. Changing it only affects new activities; existing IDs stay the same.')
    w.unmount()
  })
})
