<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import { formatDate } from '@/lib/format'
import { requestJson } from '@/lib/http'

/*
 | Every field here can reshape the plan, so before saving it shows
 | what will move. The server works that out with the recalculator's own walk
 | over the proposed graph, writing nothing, and refuses a cycle with the
 | activities named. The same check runs again on save.
 */
const props = defineProps({
  show: Boolean,
  buildingId: Number,
  activity: Object,     // the stored fields, or null to add one
  options: Array,       // [{ id, description }] — this building only
})
const emit = defineEmits(['close'])

const isEdit = computed(() => !!props.activity)

const form = useForm({
  description: '',
  predecessor_id: null,
  offset_days: 0,
  date_adjustment_days: 0,
  adjustment_reason: '',
  is_break: false,
  notes: '',
})

const preview = ref(null)
const previewError = ref(null)
let previewTimer = null
let previewSeq = 0

watch(() => props.show, (open) => {
  if (!open) return
  const a = props.activity
  form.clearErrors()
  form.description = a?.description ?? ''
  form.predecessor_id = a?.predecessor_id ?? null
  form.offset_days = a?.offset_days ?? 0
  form.date_adjustment_days = a?.date_adjustment_days ?? 0
  form.adjustment_reason = a?.adjustment_reason ?? ''
  form.is_break = !!a?.is_break
  form.notes = a?.notes ?? ''
  preview.value = null
  previewError.value = null
  schedulePreview()
})

// an activity cannot follow itself
const predecessorOptions = computed(() => props.options.filter(o => o.id !== props.activity?.id))

watch(() => [form.predecessor_id, form.offset_days, form.date_adjustment_days], () => {
  if (props.show) schedulePreview()
})

function schedulePreview() {
  clearTimeout(previewTimer)
  previewTimer = setTimeout(fetchPreview, 300)
}

async function fetchPreview() {
  const seq = ++previewSeq
  const url = isEdit.value ? `/activities/${props.activity.id}/preview` : `/buildings/${props.buildingId}/activities/preview`
  const { ok, data } = await requestJson('POST', url, {
    predecessor_id: form.predecessor_id,
    offset_days: form.offset_days === '' ? null : form.offset_days,
    date_adjustment_days: form.date_adjustment_days === '' ? null : form.date_adjustment_days,
  })

  if (seq !== previewSeq) return   // a newer request has been sent since

  if (ok) {
    preview.value = data
    previewError.value = null
  } else {
    preview.value = null
    const errors = data?.errors ?? {}
    previewError.value = errors.predecessor_id?.[0] ?? errors.offset_days?.[0]
      ?? errors.date_adjustment_days?.[0] ?? data?.message ?? 'The preview could not be worked out.'
  }
}

const finishLine = computed(() => {
  const p = preview.value
  if (!p) return ''
  if (!p.finishBefore || p.finishBefore === p.finishAfter) return `Finish stays ${formatDate(p.finishAfter)}.`
  return `Finish moves from ${formatDate(p.finishBefore)} to ${formatDate(p.finishAfter)}.`
})

const changesLine = computed(() => {
  const n = preview.value?.changedCount ?? 0
  if (n === 0) return 'No other dates change.'
  return `This changes ${n} later ${n === 1 ? 'date' : 'dates'}.`
})

const submit = () => {
  const options = { preserveScroll: true, onSuccess: () => emit('close') }
  if (isEdit.value) form.patch(`/activities/${props.activity.id}`, options)
  else form.post(`/buildings/${props.buildingId}/activities`, options)
}
</script>

<template>
  <Modal :show="show" :title="isEdit ? 'Edit activity' : 'Add activity'" @close="emit('close')">
    <form id="activity-form" class="space-y-4" @submit.prevent="submit">
      <FormField label="Description" required :error="form.errors.description">
        <input v-model="form.description" type="text" maxlength="255" />
      </FormField>

      <div class="grid gap-4 sm:grid-cols-[1fr_9rem]">
        <FormField label="Follows" :error="form.errors.predecessor_id"
                   hint="Leave empty for an activity that counts from the building's start.">
          <select v-model="form.predecessor_id">
            <option :value="null">— Building start —</option>
            <option v-for="o in predecessorOptions" :key="o.id" :value="o.id">{{ o.description }}</option>
          </select>
        </FormField>
        <FormField label="Offset days" required :error="form.errors.offset_days">
          <input v-model.number="form.offset_days" type="number" min="0" max="65535" />
        </FormField>
      </div>

      <div class="grid gap-4 sm:grid-cols-[9rem_1fr]">
        <FormField label="Date adjustment" :error="form.errors.date_adjustment_days" hint="Days, + or −.">
          <input v-model.number="form.date_adjustment_days" type="number" min="-32768" max="32767" />
        </FormField>
        <FormField label="Reason" :required="Number(form.date_adjustment_days) !== 0" :error="form.errors.adjustment_reason">
          <input v-model="form.adjustment_reason" type="text" maxlength="255"
                 :disabled="Number(form.date_adjustment_days) === 0" />
        </FormField>
      </div>

      <label class="flex items-center gap-2 text-sm text-slate-600">
        <input v-model="form.is_break" type="checkbox" class="!min-h-0 !w-auto rounded" />
        This is a break (Diwali, Holi, festival)
      </label>

      <FormField label="Notes" :error="form.errors.notes">
        <textarea v-model="form.notes" rows="2" />
      </FormField>

      <!-- what saving would move: computed on the server, nothing written -->
      <div v-if="previewError" class="warn-box !text-sm" role="alert">{{ previewError }}</div>
      <div v-else-if="preview" class="info-box !text-sm" role="status">
        <b>{{ changesLine }}</b> {{ finishLine }}
        <span class="block text-teal-800/80">This activity: {{ formatDate(preview.plannedDate) }}.</span>
      </div>
    </form>

    <template #footer>
      <button type="button" class="btn-ghost" @click="emit('close')">Cancel</button>
      <button type="submit" form="activity-form" class="btn" :disabled="form.processing || !!previewError">
        {{ form.processing ? 'Saving…' : isEdit ? 'Save changes' : 'Add activity' }}
      </button>
    </template>
  </Modal>
</template>
