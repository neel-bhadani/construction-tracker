<script setup>
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import { formatDate } from '@/lib/format'

/*
 | The quick way to record what happened on site. An actual date cannot be in
 | the future or before the building's actual start, and a break has none
 | (the page offers no "Actual" on a break). The server enforces all three;
 | the date input's min and max only save a round trip.
 */
const props = defineProps({
  show: Boolean,
  activity: Object,      // { id, description, plannedDate, actualDate, notes }
  today: String,
  earliest: String,
})
const emit = defineEmits(['close'])

const form = useForm({ actual_date: '', notes: '' })

watch(() => props.show, (open) => {
  if (!open || !props.activity) return
  form.clearErrors()
  form.actual_date = props.activity.actualDate ?? props.today
  form.notes = props.activity.notes ?? ''
})

const submit = () => form.patch(`/activities/${props.activity.id}/actual`, {
  preserveScroll: true,
  onSuccess: () => emit('close'),
})

const clear = () => {
  form.actual_date = ''
  submit()
}
</script>

<template>
  <Modal :show="show" title="Record actual date" max-width="max-w-md" @close="emit('close')">
    <template v-if="activity">
      <p class="mb-1 font-semibold text-slate-900">{{ activity.description }}</p>
      <p class="mb-5 text-sm text-slate-500">Planned for {{ formatDate(activity.plannedDate) }}</p>

      <form id="record-actual" class="space-y-4" @submit.prevent="submit">
        <FormField label="Actual date" :error="form.errors.actual_date"
                   :hint="`Between ${formatDate(earliest)} and today.`">
          <input v-model="form.actual_date" type="date" :min="earliest" :max="today" />
        </FormField>

        <FormField label="Notes" :error="form.errors.notes">
          <textarea v-model="form.notes" rows="3" />
        </FormField>
      </form>
    </template>

    <template #footer>
      <button v-if="activity?.actualDate" type="button" class="btn-ghost mr-auto" :disabled="form.processing"
              @click="clear">Clear actual date</button>
      <button type="button" class="btn-ghost" @click="emit('close')">Cancel</button>
      <button type="submit" form="record-actual" class="btn" :disabled="form.processing || !form.actual_date">
        {{ form.processing ? 'Saving…' : 'Save' }}
      </button>
    </template>
  </Modal>
</template>
