<script setup>
import { nextTick, onBeforeUnmount, ref } from 'vue'

/*
 | A row's "⋯" menu. Drawn fixed to the viewport, beside its button, so a table
 | that scrolls sideways cannot clip it; it closes on a pick, a click outside,
 | Escape, scrolling or resizing.
 */
const props = defineProps({
  items: Array,                           // [{ label, action, danger? }]
  label: { type: String, default: 'More actions' },
  buttonClass: { type: String, default: 'btn-xs !px-2' },
})

const open = ref(false)
const button = ref(null)
const menu = ref(null)
const position = ref({})

const close = () => {
  open.value = false
  document.removeEventListener('click', onOutside, true)
  document.removeEventListener('keydown', onKey)
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', close)
}

const onOutside = event => {
  if (!menu.value?.contains(event.target) && !button.value?.contains(event.target)) close()
}
const onKey = event => { if (event.key === 'Escape') { close(); button.value?.focus() } }

const toggle = async () => {
  if (open.value) return close()

  const rect = button.value.getBoundingClientRect()
  const below = window.innerHeight - rect.bottom > props.items.length * 44 + 8
  position.value = {
    right: `${Math.max(8, window.innerWidth - rect.right)}px`,
    ...(below ? { top: `${rect.bottom + 4}px` } : { bottom: `${window.innerHeight - rect.top + 4}px` }),
  }
  open.value = true
  await nextTick()
  document.addEventListener('click', onOutside, true)
  document.addEventListener('keydown', onKey)
  window.addEventListener('scroll', close, true)
  window.addEventListener('resize', close)
  menu.value?.querySelector('button')?.focus()
}

const pick = item => { close(); item.action() }

onBeforeUnmount(close)
</script>

<template>
  <button ref="button" type="button" :class="buttonClass" :aria-label="label" :title="label"
          aria-haspopup="menu" :aria-expanded="open" @click="toggle">⋯</button>
  <Teleport to="body">
    <div v-if="open" ref="menu" role="menu" :style="position"
         class="fixed z-50 min-w-48 overflow-hidden rounded-lg bg-white py-1 text-left text-sm text-slate-700 shadow-lg ring-1 ring-slate-900/10">
      <button v-for="item in items" :key="item.label" type="button" role="menuitem"
              class="block min-h-[var(--control-h)] w-full px-4 py-2 text-left hover:bg-slate-50 focus:bg-slate-50 focus:outline-none"
              :class="item.danger ? 'text-rose-700' : ''" @click="pick(item)">{{ item.label }}</button>
    </div>
  </Teleport>
</template>
