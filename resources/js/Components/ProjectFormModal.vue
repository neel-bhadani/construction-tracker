<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import { cleanCode, editWorkIdHint, initials } from '@/lib/projectCode'

/*
 | The planned start is only the default for new buildings:
 | changing it moves no existing timeline. The code starts every Work ID; a new
 | project's follows the name's initials until it is typed over, and changing
 | it later leaves the IDs already given as they are.
 */
const props = defineProps({
  show: Boolean,
  project: Object,        // null to create
  timezones: Array,
  defaultTimezone: { type: String, default: 'Asia/Kolkata' },
})
const emit = defineEmits(['close'])

const isEdit = computed(() => !!props.project)

const form = useForm({ name: '', code: '', details: '', planned_start_date: '', timezone: '' })

// a new project's code follows its name until it is typed over
const codeTyped = ref(false)
watch(() => form.name, name => { if (!isEdit.value && !codeTyped.value) form.code = initials(name) })
const typeCode = event => { codeTyped.value = true; form.code = cleanCode(event.target.value); event.target.value = form.code }

watch(() => props.show, (open) => {
  if (!open) return
  form.clearErrors()
  form.name = props.project?.name ?? ''
  form.code = props.project?.code ?? ''
  codeTyped.value = false
  form.details = props.project?.details ?? ''
  form.planned_start_date = props.project?.plannedStartDate ?? ''
  form.timezone = props.project?.timezone ?? props.defaultTimezone
})

const submit = () => {
  const options = { preserveScroll: true, onSuccess: () => emit('close') }
  if (isEdit.value) form.patch(`/projects/${props.project.id}`, options)
  else form.post('/projects', options)
}
</script>

<template>
  <Modal :show="show" :title="isEdit ? 'Edit project' : 'New project'" max-width="max-w-lg" @close="emit('close')">
    <form id="project-form" class="space-y-4" @submit.prevent="submit">
      <FormField label="Name" required :error="form.errors.name">
        <input v-model="form.name" type="text" maxlength="150" autofocus />
      </FormField>

      <FormField label="Work ID prefix" :error="form.errors.code"
                 :hint="isEdit ? editWorkIdHint(project.code) : 'Starts every Work ID, like SV-001. From the name unless you type one.'">
        <input :value="form.code" type="text" maxlength="8" class="font-mono uppercase" autocomplete="off" data-test="project-code" @input="typeCode" />
      </FormField>

      <FormField label="Details" :error="form.errors.details" hint="Optional: location, client contact, anything worth keeping with the project.">
        <textarea v-model="form.details" rows="3" />
      </FormField>

      <div class="grid gap-4 sm:grid-cols-2">
        <FormField label="Planned start" required :error="form.errors.planned_start_date"
                   hint="New buildings start on this date unless you choose another.">
          <input v-model="form.planned_start_date" type="date" />
        </FormField>
        <FormField label="Timezone" required :error="form.errors.timezone" hint="Decides what “today” is.">
          <select v-model="form.timezone">
            <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
          </select>
        </FormField>
      </div>
    </form>

    <template #footer>
      <button type="button" class="btn-ghost" @click="emit('close')">Cancel</button>
      <button type="submit" form="project-form" class="btn" :disabled="form.processing">
        {{ form.processing ? 'Saving…' : isEdit ? 'Save changes' : 'Create project' }}
      </button>
    </template>
  </Modal>
</template>
