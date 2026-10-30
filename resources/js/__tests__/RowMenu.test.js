import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import RowMenu from '@/Components/RowMenu.vue'

let wrapper
afterEach(() => { wrapper?.unmount(); vi.unstubAllGlobals() })

describe('RowMenu', () => {
  it('fits the expanded activity menu above a low row and restores focus on Escape', async () => {
    vi.stubGlobal('innerHeight', 768)
    wrapper = mount(RowMenu, {
      props: { items: ['Mark done', 'Edit', 'Insert activity below', 'Delete'].map(label => ({ label, action: vi.fn() })) },
      attachTo: document.body,
      global: { stubs: { teleport: true } },
    })
    const trigger = wrapper.find('button')
    vi.spyOn(trigger.element, 'getBoundingClientRect').mockReturnValue({ top: 560, bottom: 598, right: 990 })
    await trigger.trigger('click')
    expect(wrapper.find('[role="menu"]').attributes('style')).toContain('bottom: 212px')
    expect(document.activeElement).toBe(wrapper.find('[role="menuitem"]').element)

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
    expect(document.activeElement).toBe(trigger.element)
  })
})
