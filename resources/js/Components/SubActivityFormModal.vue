<script setup>
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import { formatDate } from '@/lib/format'

/*
 | Adding or editing one sub-activity: its name, planned date, progress
 | (0, 25, 50, 75 or 100%), done date (at 100% only) and note. Its Work ID is
 | given when it is saved. The first one of an activity that is already done
 | starts at 100% on the activity's actual date: saving it lower reopens the
 | activity. The done date follows the actual date's rules.
 */
const props = defineProps({
  show: Boolean,
  activity: Object,        // the row: { id, work_id, description, actualDate, sub_activities }
  sub: Object,             // null to add
  today: String,
  earliest: String,        // the building's actual start
})
const emit = defineEmits(['close'])

const STEPS = [0, 25, 50, 75, 100]

const isEdit = computed(() => !!props.sub)
// SubActivityEditor::defaults(): the first of a done activity starts done
const startsDone = computed(() => !isEdit.value && !props.activity?.sub_activities?.length && !!props.activity?.actualDate)

// `percent`, not `progress`: the form helper has a progress of its own (uploads); sent as progress
const form = useForm({ name: '', planned_date: '', percent: 0, done_date: '', note: '' })

watch(() => props.show, open => {
  if (!open) return
  form.clearErrors()
  form.name = props.sub?.name ?? ''
  form.planned_date = props.sub?.planned_date ?? ''
  form.percent = props.sub?.progress ?? (startsDone.value ? 100 : 0)
  form.done_date = props.sub?.done_date ?? (startsDone.value ? props.activity.actualDate : '')
  form.note = props.sub?.note ?? ''
})

// a done date only at 100%; going to 100% offers today
watch(() => form.percent, percent => {
  if (percent !== 100) form.done_date = ''
  else if (!form.done_date) form.done_date = props.today
})

const title = computed(() => (isEdit.value ? `Edit ${props.sub.work_id} ${props.sub.name}` : `Add sub-activity to ${props.activity?.work_id ?? ''} ${props.activity?.description ?? ''}`))

const submit = () => {
  // the page keeps its state: the list it was opened from stays open
  const options = { preserveScroll: true, preserveState: true, onSuccess: () => emit('close') }
  form.transform(data => ({
    name: data.name,
    planned_date: data.planned_date || null,
    progress: data.percent,
    done_date: data.percent === 100 ? data.done_date || null : null,
    note: data.note,
  }))
  if (isEdit.value) form.patch(`/sub-activities/${props.sub.id}`, options)
  else form.post(`/activities/${props.activity.id}/sub-activities`, options)
}
</script>

<template>
  <Modal :show="show" :title="title" max-width="max-w-lg" @close="emit('close')">
    <form id="sub-activity-form" class="space-y-4" @submit.prevent="submit">
      <p v-if="startsDone" data-test="starts-done" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
        This activity is done, so the new sub-activity starts at 100%. Change it to reopen the activity.
      </p>

      <FormField label="Name" required :error="form.errors.name">
        <input v-model="form.name" type="text" maxlength="255" placeholder="Shuttering" />
      </FormField>

      <FormField label="Planned date" :error="form.errors.planned_date" hint="Optional.">
        <input v-model="form.planned_date" type="date" />
      </FormField>

      <FormField label="Progress" :error="form.errors.progress">
        <div class="flex flex-wrap gap-1" role="group" aria-label="Progress">
          <button v-for="step in STEPS" :key="step" type="button" data-test="progress-step"
                  class="min-h-11 min-w-14 rounded-lg border px-3 text-sm font-semibold tabular-nums"
                  :class="form.percent === step ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
                  :aria-pressed="form.percent === step" @click="form.percent = step">{{ step }}%</button>
        </div>
      </FormField>

      <FormField v-if="form.percent === 100" label="Done date" required :error="form.errors.done_date"
                 :hint="`Between ${formatDate(earliest)} and today.`">
        <input v-model="form.done_date" type="date" :min="earliest" :max="today" />
      </FormField>

      <FormField label="Note" :error="form.errors.note">
        <textarea v-model="form.note" rows="2" />
      </FormField>

      <p v-if="form.errors.activity" class="text-sm text-rose-700">{{ form.errors.activity }}</p>
    </form>

    <template #footer>
      <button type="button" class="btn-ghost" @click="emit('close')">Cancel</button>
      <button type="submit" form="sub-activity-form" class="btn" :disabled="form.processing">
        {{ form.processing ? 'Saving…' : isEdit ? 'Save changes' : 'Add sub-activity' }}
      </button>
    </template>
  </Modal>
</template>
