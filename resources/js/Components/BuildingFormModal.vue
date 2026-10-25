<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import { uploadFile } from '@/lib/http'

/*
 | A new building's plan starts empty, copied from any building in one of
 | your projects, or imported from Excel. Copied and imported plans
 | bring the chain and durations, never actual dates or notes; their dates are
 | computed from this building's own start. Editing changes the name and type
 | only — start dates are the admin's, on the timeline page.
 */
const props = defineProps({
  show: Boolean,
  project: Object,         // { id, plannedStartDate }
  building: Object,        // null to add
  copySources: Array,      // [{ id, label, activities }]
})
const emit = defineEmits(['close'])

const isEdit = computed(() => !!props.building)

const form = useForm({
  name: '', type: 'building', planned_start_date: '', plan: 'empty',
  copy_from: null, import_token: null, sheet: null,
})

const upload = ref({ name: '', sheets: [], error: '', busy: false })

watch(() => props.show, (open) => {
  if (!open) return
  form.clearErrors()
  form.name = props.building?.name ?? ''
  form.type = props.building?.type ?? 'building'
  form.planned_start_date = props.project.plannedStartDate
  form.plan = 'empty'
  form.copy_from = null
  form.import_token = null
  form.sheet = null
  upload.value = { name: '', sheets: [], error: '', busy: false }
})

async function chooseFile(event) {
  const file = event.target.files[0]
  form.import_token = null
  form.sheet = null
  upload.value = { name: file?.name ?? '', sheets: [], error: '', busy: !!file }
  if (!file) return

  const { ok, data } = await uploadFile('/imports', 'file', file)
  upload.value.busy = false

  if (!ok) {
    upload.value.error = data?.errors?.file?.[0] ?? data?.message ?? 'The file could not be uploaded.'
    return
  }

  form.import_token = data.token
  upload.value.sheets = data.sheets
  form.sheet = data.sheets.length === 1 ? data.sheets[0] : null
}

const submit = () => {
  const options = { preserveScroll: true, onSuccess: () => emit('close') }
  if (isEdit.value) {
    form.transform(data => ({ name: data.name, type: data.type }))
      .patch(`/projects/${props.project.id}/buildings/${props.building.id}`, options)
  } else {
    form.transform(data => data).post(`/projects/${props.project.id}/buildings`, options)
  }
}
</script>

<template>
  <Modal :show="show" :title="isEdit ? 'Edit building' : 'Add building'" max-width="max-w-lg" @close="emit('close')">
    <form id="building-form" class="space-y-4" @submit.prevent="submit">
      <FormField label="Name" required :error="form.errors.name">
        <input v-model="form.name" type="text" maxlength="100" placeholder="Tower C" />
      </FormField>

      <FormField label="Type" required :error="form.errors.type">
        <div class="flex gap-4 text-sm text-slate-700">
          <label class="flex items-center gap-2"><input v-model="form.type" type="radio" value="building" class="!min-h-0 !w-auto" /> Building</label>
          <label class="flex items-center gap-2"><input v-model="form.type" type="radio" value="common_work" class="!min-h-0 !w-auto" /> Common work</label>
        </div>
      </FormField>

      <template v-if="!isEdit">
        <FormField label="Planned start" :error="form.errors.planned_start_date" hint="Defaults to the project's planned start.">
          <input v-model="form.planned_start_date" type="date" />
        </FormField>

        <FormField label="Start the plan" :error="form.errors.plan">
          <div class="space-y-2 text-sm text-slate-700">
            <label class="flex items-center gap-2"><input v-model="form.plan" type="radio" value="empty" class="!min-h-0 !w-auto" /> Empty — add activities one by one</label>
            <label class="flex items-center gap-2"><input v-model="form.plan" type="radio" value="copy" class="!min-h-0 !w-auto" /> Copy the plan of another building</label>
            <label class="flex items-center gap-2"><input v-model="form.plan" type="radio" value="import" class="!min-h-0 !w-auto" /> Import from Excel</label>
          </div>
        </FormField>

        <FormField v-if="form.plan === 'copy'" label="Copy from" required :error="form.errors.copy_from"
                   hint="Activities, durations, adjustments and breaks. Not actual dates or notes.">
          <select v-model="form.copy_from">
            <option :value="null" disabled>Choose a building</option>
            <option v-for="s in copySources" :key="s.id" :value="s.id">{{ s.label }} ({{ s.activities }} activities)</option>
          </select>
        </FormField>

        <template v-if="form.plan === 'import'">
          <FormField label="Excel file" required :error="upload.error || form.errors.import_token"
                     hint="The same layout as the client's workbooks: activities from row 4, the start date in E2.">
            <input type="file" accept=".xlsx" class="block w-full text-sm" @change="chooseFile" />
          </FormField>
          <p v-if="upload.busy" class="text-sm text-slate-500">Reading {{ upload.name }}…</p>
          <FormField v-if="upload.sheets.length" label="Sheet" required :error="form.errors.sheet">
            <select v-model="form.sheet">
              <option :value="null" disabled>Choose the sheet with the plan</option>
              <option v-for="name in upload.sheets" :key="name" :value="name">{{ name }}</option>
            </select>
          </FormField>
          <div v-else-if="form.errors.sheet" class="warn-box whitespace-pre-line !text-sm">{{ form.errors.sheet }}</div>
        </template>
      </template>
    </form>

    <template #footer>
      <button type="button" class="btn-ghost" @click="emit('close')">Cancel</button>
      <button type="submit" form="building-form" class="btn" :disabled="form.processing || upload.busy">
        {{ form.processing ? 'Saving…' : isEdit ? 'Save changes' : 'Add building' }}
      </button>
    </template>
  </Modal>
</template>
