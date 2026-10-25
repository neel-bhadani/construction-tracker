<script setup>
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'

/*
 | Admin only. The planned start moves every planned date in every scope; the
 | server recalculates all of them, and records the change.
 */
const props = defineProps({ show: Boolean, project: Object })
const emit = defineEmits(['close'])

const form = useForm({ planned_start_date: '', actual_start_date: '' })

watch(() => props.show, (open) => {
  if (!open) return
  form.clearErrors()
  form.planned_start_date = props.project.planned_start_date
  form.actual_start_date = props.project.actual_start_date ?? ''
})

const submit = () => form
  .transform(data => ({ ...data, actual_start_date: data.actual_start_date || null }))
  .patch(`/projects/${props.project.id}/dates`, { preserveScroll: true, onSuccess: () => emit('close') })
</script>

<template>
  <Modal :show="show" title="Project start dates" max-width="max-w-md" @close="emit('close')">
    <div class="warn-box mb-5 !text-sm">
      Changing the planned start moves every planned date in every scope of this project.
    </div>

    <form id="project-dates" class="space-y-4" @submit.prevent="submit">
      <FormField label="Planned start date" required :error="form.errors.planned_start_date">
        <input v-model="form.planned_start_date" type="date" />
      </FormField>
      <FormField label="Actual start date" :error="form.errors.actual_start_date"
                 hint="When work really began. Leave empty to use the planned start. Drives average pace.">
        <input v-model="form.actual_start_date" type="date" />
      </FormField>
    </form>

    <template #footer>
      <button type="button" class="btn-ghost" @click="emit('close')">Cancel</button>
      <button type="submit" form="project-dates" class="btn" :disabled="form.processing">
        {{ form.processing ? 'Recalculating…' : 'Save and recalculate' }}
      </button>
    </template>
  </Modal>
</template>
