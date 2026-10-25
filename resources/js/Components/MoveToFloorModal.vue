<script setup>
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'

/*
 | "Move to floor" from a row's menu: another floor, or the whole building.
 | It moves no date. Not offered for a break, which is on no floor.
 */
const props = defineProps({
  show: Boolean,
  activity: Object,       // { id, description, floor_id }
  floors: Array,          // [{ id, name }], the building's floors
})
const emit = defineEmits(['close'])

const form = useForm({ floor_id: null })

watch(() => props.show, open => {
  if (!open) return
  form.clearErrors()
  form.floor_id = props.activity?.floor_id ?? null
})

const submit = () => form.patch(`/activities/${props.activity.id}/floor`, {
  preserveScroll: true,
  onSuccess: () => emit('close'),
})
</script>

<template>
  <Modal :show="show" :title="activity ? `Move ${activity.description}` : ''" max-width="max-w-md" @close="emit('close')">
    <form id="move-floor-form" @submit.prevent="submit">
      <FormField label="Floor" :error="form.errors.floor_id" hint="Dates don't change.">
        <select v-model="form.floor_id">
          <option :value="null">Whole building</option>
          <option v-for="f in floors" :key="f.id" :value="f.id">{{ f.name }}</option>
        </select>
      </FormField>
    </form>

    <template #footer>
      <button type="button" class="btn-ghost" @click="emit('close')">Cancel</button>
      <button type="submit" form="move-floor-form" class="btn" :disabled="form.processing">
        {{ form.processing ? 'Moving…' : 'Move' }}
      </button>
    </template>
  </Modal>
</template>
