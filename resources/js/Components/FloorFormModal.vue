<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import { floorNameForLevel, quickFloors } from '@/lib/floors'

/*
 | Adding floors, or renaming one. Adding is one floor by name, or the quick
 | "Ground + 1 to N", which lists the floors it will add and those already
 | there before anything is saved. Floors move no date.
 */
const props = defineProps({
  show: Boolean,
  projectId: Number,
  buildingId: Number,
  floor: Object,          // the floor to rename, or null to add
  existing: Array,        // the building's floors: [{ name, level }]
})
const emit = defineEmits(['close'])

const isRename = computed(() => !!props.floor)
const mode = ref('quick')

const one = useForm({ name: '', level: 0 })
const quick = useForm({ up_to: 13, basements: 0 })

watch(() => props.show, open => {
  if (!open) return
  one.clearErrors()
  quick.clearErrors()
  one.level = props.floor?.level ?? nextLevel()
  one.name = props.floor?.name ?? floorNameForLevel(one.level)
  quick.up_to = 13
  quick.basements = 0
  mode.value = props.floor || props.existing.length ? 'one' : 'quick'
})

// the next floor up from the highest the building has
function nextLevel() {
  return props.existing.length ? Math.max(...props.existing.map(f => f.level)) + 1 : 0
}

// the usual name for the level, while the name is still the usual one for the level before
const levelChanged = () => {
  if (!isRename.value && (one.name === '' || /^(ground|basement|\d+(st|nd|rd|th)) /i.test(one.name))) {
    one.name = floorNameForLevel(Number(one.level) || 0)
  }
}

const planned = computed(() => quickFloors({
  upTo: Math.max(0, Math.min(200, Number(quick.up_to) || 0)),
  basements: Math.max(0, Math.min(20, Number(quick.basements) || 0)),
  existing: props.existing.map(f => f.name),
}))
const adding = computed(() => planned.value.filter(f => !f.skip))
const skipping = computed(() => planned.value.filter(f => f.skip))

const base = computed(() => `/projects/${props.projectId}/buildings/${props.buildingId}/floors`)
const options = { preserveScroll: true, preserveState: true, onSuccess: () => emit('close') }

const submit = () => {
  if (isRename.value) one.patch(`${base.value}/${props.floor.id}`, options)
  else if (mode.value === 'one') one.post(base.value, options)
  else quick.post(`${base.value}/range`, options)
}

const processing = computed(() => one.processing || quick.processing)
const canSave = computed(() => isRename.value || mode.value === 'one' || adding.value.length > 0)
</script>

<template>
  <Modal :show="show" :title="isRename ? `Rename ${floor.name}` : 'Add floors'" max-width="max-w-lg" @close="emit('close')">
    <form id="floor-form" class="space-y-4" @submit.prevent="submit">
      <div v-if="!isRename" class="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm" role="radiogroup" aria-label="How to add">
        <label class="flex-1 cursor-pointer rounded-md px-3 py-1.5 text-center font-medium"
               :class="mode === 'quick' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'">
          <input v-model="mode" type="radio" value="quick" class="sr-only" /> Ground + 1 to N
        </label>
        <label class="flex-1 cursor-pointer rounded-md px-3 py-1.5 text-center font-medium"
               :class="mode === 'one' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'">
          <input v-model="mode" type="radio" value="one" class="sr-only" /> One floor
        </label>
      </div>

      <template v-if="isRename || mode === 'one'">
        <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
          <FormField label="Name" required :error="one.errors.name">
            <input v-model="one.name" type="text" maxlength="50" />
          </FormField>
          <FormField label="Level" required hint="0 = ground, −1 = basement." :error="one.errors.level">
            <input v-model.number="one.level" type="number" min="-20" max="300" @input="levelChanged" />
          </FormField>
        </div>
      </template>

      <template v-else>
        <div class="grid grid-cols-2 gap-4">
          <FormField label="Ground + 1 to" required :error="quick.errors.up_to">
            <input v-model.number="quick.up_to" type="number" min="0" max="200" aria-label="Top floor" />
          </FormField>
          <FormField label="Basements" :error="quick.errors.basements">
            <input v-model.number="quick.basements" type="number" min="0" max="20" aria-label="Basements" />
          </FormField>
        </div>

        <div class="rounded-lg border border-slate-200 p-3 text-sm" data-test="quick-preview">
          <p class="font-semibold text-slate-800">
            {{ adding.length ? `Adds ${adding.length} ${adding.length === 1 ? 'floor' : 'floors'}` : 'Every one of these floors is already there' }}
          </p>
          <p v-if="adding.length" class="mt-1 text-slate-600">{{ adding.map(f => f.name).join(', ') }}</p>
          <p v-if="skipping.length" class="mt-2 text-xs text-slate-500">
            Already there, skipped: {{ skipping.map(f => f.name).join(', ') }}
          </p>
        </div>
      </template>
    </form>

    <template #footer>
      <button type="button" class="btn-ghost" @click="emit('close')">Cancel</button>
      <button type="submit" form="floor-form" class="btn" :disabled="processing || !canSave">
        {{ processing ? 'Saving…' : isRename ? 'Save' : mode === 'quick' ? `Add ${adding.length} ${adding.length === 1 ? 'floor' : 'floors'}` : 'Add floor' }}
      </button>
    </template>
  </Modal>
</template>
