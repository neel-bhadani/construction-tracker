<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import RowMenu from './RowMenu.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import { formatDate } from '@/lib/format'

/*
 | An activity's sub-activities, shown under its row: Work ID, name, planned
 | date, progress (quick buttons 0 to 100%), done date and note, in position
 | order. Drag a row, or Move up / Move down in its ⋯ menu, to reorder: Work IDs
 | stay as they were given. Going to 100% records today as the done date;
 | the Edit dialog (emitted, the page owns it) changes that or anything else.
 */
const props = defineProps({
  activity: Object,        // the row: { id, work_id, description, is_break, sub_activities }
  today: String,
})
const emit = defineEmits(['add', 'edit'])

const STEPS = [0, 25, 50, 75, 100]

const subs = computed(() => [...(props.activity.sub_activities ?? [])].sort((a, b) => a.position - b.position))

const busy = ref(false)
const options = { preserveScroll: true, preserveState: true, onFinish: () => { busy.value = false } }

const setProgress = (sub, progress) => {
  if (sub.progress === progress || busy.value) return
  busy.value = true
  router.patch(`/sub-activities/${sub.id}`, { progress, done_date: progress === 100 ? sub.done_date ?? props.today : null }, options)
}

/* ---------------- order ---------------- */

const saveOrder = ids => {
  busy.value = true
  router.patch(`/activities/${props.activity.id}/sub-activities/order`, { ids }, options)
}

const move = (index, by) => {
  const ids = subs.value.map(s => s.id)
  const [id] = ids.splice(index, 1)
  ids.splice(index + by, 0, id)
  saveOrder(ids)
}

const dragging = ref(null)   // index being dragged
const over = ref(null)       // index it would land on
const drop = index => {
  if (dragging.value !== null && dragging.value !== index) move(dragging.value, index - dragging.value)
  dragging.value = over.value = null
}

/* ---------------- delete ---------------- */

const deleting = ref(null)
const deleteProcessing = ref(false)
const confirmDelete = () => {
  deleteProcessing.value = true
  router.delete(`/sub-activities/${deleting.value.id}`, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => { deleting.value = null },
    onFinish: () => { deleteProcessing.value = false },
  })
}

const menuItems = (sub, index) => [
  { label: 'Edit', action: () => emit('edit', sub) },
  ...(index > 0 ? [{ label: 'Move up', action: () => move(index, -1) }] : []),
  ...(index < subs.value.length - 1 ? [{ label: 'Move down', action: () => move(index, 1) }] : []),
  { label: 'Delete', action: () => { deleting.value = sub }, danger: true },
]
</script>

<template>
  <div class="not-italic" data-test="sub-activities">
    <ol class="divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
      <li v-for="(sub, index) in subs" :key="sub.id" data-test="sub-activity"
          class="px-3 py-2.5 text-sm lg:grid lg:grid-cols-[1rem_6.5rem_minmax(0,1fr)_6.5rem_auto_6.5rem_2.75rem] lg:items-center lg:gap-3"
          :class="[over === index && dragging !== index ? 'bg-teal-50' : '', dragging === index ? 'opacity-50' : '']"
          draggable="true"
          @dragstart="dragging = index" @dragover.prevent="over = index" @dragleave="over = null"
          @drop.prevent="drop(index)" @dragend="dragging = over = null">
        <span class="hidden cursor-grab select-none text-slate-400 lg:order-1 lg:inline" title="Drag to reorder" aria-hidden="true">⋮⋮</span>

        <!-- phone: Work ID and menu on top; desktop: each its own column -->
        <div class="flex items-center justify-between lg:contents">
          <span class="font-mono text-[13px] text-slate-500 lg:order-2" data-test="sub-work-id">{{ sub.work_id }}</span>
          <div class="lg:order-7 lg:justify-self-end">
            <RowMenu :items="menuItems(sub, index)" :label="`More for ${sub.work_id} ${sub.name}`" button-class="btn-ghost !px-2.5 !py-1 !text-lg" />
          </div>
        </div>

        <div class="min-w-0 lg:order-3">
          <span class="break-words font-medium text-slate-900">{{ sub.name }}</span>
          <p v-if="sub.note" class="mt-0.5 break-words text-[13px] text-slate-500">{{ sub.note }}</p>
        </div>

        <div class="mt-1 flex justify-between gap-4 text-slate-600 lg:contents">
          <span class="whitespace-nowrap lg:order-4"><span class="text-slate-400 lg:hidden">Planned </span>{{ sub.planned_date ? formatDate(sub.planned_date) : '—' }}</span>
          <span class="whitespace-nowrap lg:order-6" :class="sub.done_date ? 'text-emerald-800' : 'text-slate-400'"><span class="text-slate-400 lg:hidden">Done </span>{{ sub.done_date ? formatDate(sub.done_date) : '—' }}</span>
        </div>

        <div class="mt-2 flex gap-1 lg:order-5 lg:mt-0" role="group" :aria-label="`Progress of ${sub.work_id}`">
          <button v-for="step in STEPS" :key="step" type="button" data-test="quick-progress"
                  class="min-h-11 flex-1 rounded-md border px-1.5 text-[13px] font-semibold tabular-nums lg:min-h-9 lg:min-w-11 lg:flex-none"
                  :class="sub.progress === step ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
                  :aria-pressed="sub.progress === step" :disabled="busy" @click="setProgress(sub, step)">{{ step }}</button>
        </div>
      </li>
      <li v-if="!subs.length" class="px-3 py-2.5 text-sm text-slate-500">No sub-activities yet.</li>
    </ol>

    <button v-if="!activity.is_break" type="button" data-test="add-sub-activity"
            class="mt-2 min-h-11 text-sm font-semibold text-teal-700 hover:underline" @click="emit('add')">+ Add sub-activity</button>

    <ConfirmDialog :show="!!deleting" title="Delete sub-activity" confirm-text="Delete" :processing="deleteProcessing"
                   :message="deleting ? `Delete [${deleting.work_id} ${deleting.name}]? Its history is kept in the audit trail.${subs.length === 1 ? ' It is the last one: the activity keeps its state and is marked done by hand again.' : ''}` : ''"
                   @close="deleting = null" @confirm="confirmDelete" />
  </div>
</template>
