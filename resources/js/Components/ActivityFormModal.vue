<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import FormField from './FormField.vue'
import { formatDate, signed } from '@/lib/format'
import { requestJson } from '@/lib/http'
import { offsetFor, plannedDateFor } from '@/lib/schedule'

/*
 | Adding, inserting or editing an activity, in the order the client thinks of
 | it: name, what it comes after, its planned date, and its actual date once
 | done. The planned date and "N days after" are the same thing seen two ways:
 | typing either updates the other, and what is stored is the offset.
 |
 | Before saving it shows what will move. The server works that out with the
 | recalculator's own walk over the proposed graph, writing nothing, and
 | refuses a cycle with the activities named. The same check runs on save.
 |
 | Inserting below a row: "Comes after" starts on that row and the new one goes
 | right under it. "Push" relinks the row below — only if it came after the row
 | above — so it and everything chained to it move later. Actual dates never move.
 */
const props = defineProps({
  show: Boolean,
  building: Object,       // { id, planned_start_date }
  activity: Object,       // the row being edited, or null
  insertAfter: Object,    // the row to insert below, or null
  rows: Array,            // every row in order: { id, description, predecessor_id, planned_day, date_adjustment_days, plannedDate }
  today: String,
  earliest: String,       // the building's actual start: no actual date before it
})
const emit = defineEmits(['close'])

const isEdit = computed(() => !!props.activity)
const isInsert = computed(() => !isEdit.value && !!props.insertAfter)

const form = useForm({
  description: '',
  predecessor_id: null,
  planned_date: '',
  actual_date: '',
  is_break: false,
  notes: '',
  clear_adjustment: false,
  after_id: null,
  push: true,
})
const offset = ref('')         // "N days after", kept in step with planned_date
const showNote = ref(false)

const preview = ref(null)
const previewError = ref(null)
let previewTimer = null
let previewSeq = 0

const byId = computed(() => Object.fromEntries(props.rows.map(r => [r.id, r])))
const predecessor = computed(() => byId.value[form.predecessor_id] ?? null)

// this row's own adjustment is part of its planned date until it is cleared
const adjustment = computed(() => (isEdit.value && !form.clear_adjustment ? props.activity.date_adjustment_days : 0))

const toDate = days => plannedDateFor({
  start: props.building.planned_start_date,
  predecessorDay: predecessor.value?.planned_day ?? 0,
  offset: days,
  adjustment: adjustment.value,
})
const toOffset = date => offsetFor({
  start: props.building.planned_start_date,
  predecessorDay: predecessor.value?.planned_day ?? 0,
  plannedDate: date,
  adjustment: adjustment.value,
})

watch(() => props.show, (open) => {
  if (!open) return
  const a = props.activity
  const last = props.rows.at(-1)

  form.clearErrors()
  form.description = a?.description ?? ''
  // the row above: the one inserted below, else the last row, else the building start
  form.predecessor_id = a ? a.predecessor_id : (props.insertAfter?.id ?? last?.id ?? null)
  form.actual_date = a?.actualDate ?? ''
  form.is_break = !!a?.is_break
  form.notes = a?.notes ?? ''
  form.clear_adjustment = false
  form.after_id = isInsert.value ? props.insertAfter.id : null
  form.push = true
  showNote.value = !!a?.notes

  form.planned_date = a?.plannedDate ?? ''
  offset.value = a ? a.offset_days : ''

  preview.value = null
  previewError.value = null
  schedulePreview()
})

/* ---------------- planned date ⇄ days after ---------------- */

const typedDate = () => {
  offset.value = form.planned_date ? toOffset(form.planned_date) : ''
  schedulePreview()
}

const typedOffset = () => {
  form.planned_date = offset.value === '' || offset.value === null ? '' : toDate(Number(offset.value))
  schedulePreview()
}

// a new "Comes after" keeps the days after it, as the workbook does, and moves the date
watch(() => form.predecessor_id, () => { if (props.show) typedOffset() })

const clearAdjustment = () => {
  form.clear_adjustment = true
  typedOffset()
}

const predecessorHint = computed(() => {
  const p = predecessor.value
  if (!p?.date_adjustment_days) return null
  return `${p.description}'s date includes ${signed(p.date_adjustment_days)} days adjustment, which doesn't carry over.`
})

const comesAfterLabel = computed(() => predecessor.value?.description ?? 'Building start')

// a row cannot come after itself
const predecessorOptions = computed(() => props.rows.filter(r => r.id !== props.activity?.id))

/* ---------------- break and actual ---------------- */

// a break has no actual date
watch(() => form.is_break, isBreak => { if (isBreak) form.actual_date = '' })

/* ---------------- push ---------------- */

// the row directly below the insertion point, and whether pushing would relink it
const rowBelow = computed(() => {
  if (!isInsert.value) return null
  const index = props.rows.findIndex(r => r.id === props.insertAfter.id)
  return props.rows[index + 1] ?? null
})
const canPush = computed(() => rowBelow.value?.predecessor_id === props.insertAfter?.id)

watch(() => form.push, () => { if (props.show) schedulePreview() })

/* ---------------- preview ---------------- */

function schedulePreview() {
  clearTimeout(previewTimer)
  previewTimer = setTimeout(fetchPreview, 300)
}

async function fetchPreview() {
  if (!form.planned_date) {
    preview.value = null
    previewError.value = null
    return
  }

  const seq = ++previewSeq
  const url = isEdit.value ? `/activities/${props.activity.id}/preview` : `/buildings/${props.building.id}/activities/preview`
  const { ok, data } = await requestJson('POST', url, {
    predecessor_id: form.predecessor_id,
    planned_date: form.planned_date,
    ...(isEdit.value ? { clear_adjustment: form.clear_adjustment } : {}),
    ...(isInsert.value ? { after_id: form.after_id, push: canPush.value && form.push } : {}),
  })

  if (seq !== previewSeq) return   // a newer request has been sent since

  if (ok) {
    preview.value = data
    previewError.value = null
  } else {
    preview.value = null
    const errors = data?.errors ?? {}
    previewError.value = errors.planned_date?.[0] ?? errors.predecessor_id?.[0] ?? errors.offset_days?.[0]
      ?? errors.after_id?.[0] ?? data?.message ?? 'The preview could not be worked out.'
  }
}

const summaryLine = computed(() => {
  const p = preview.value
  if (!p) return ''
  if (p.changedCount === 0) return 'No other dates change.'
  const finish = !p.finishBefore || p.finishBefore === p.finishAfter
    ? `finish stays ${formatDate(p.finishAfter)}`
    : `finish moves from ${formatDate(p.finishBefore)} to ${formatDate(p.finishAfter)}`
  return `This changes ${p.changedCount} later ${p.changedCount === 1 ? 'activity' : 'activities'} – ${finish}.`
})

const moreChanged = computed(() => (preview.value ? preview.value.changedCount - preview.value.changed.length : 0))

/* ---------------- save ---------------- */

const submit = () => {
  const options = { preserveScroll: true, onSuccess: () => emit('close') }

  form.transform(data => ({
    description: data.description,
    predecessor_id: data.predecessor_id,
    planned_date: data.planned_date,
    actual_date: data.is_break || !data.actual_date ? null : data.actual_date,
    is_break: data.is_break,
    notes: data.notes,
    ...(isEdit.value ? { clear_adjustment: data.clear_adjustment } : {}),
    ...(isInsert.value ? { after_id: data.after_id, push: canPush.value && data.push } : {}),
  }))

  if (isEdit.value) form.patch(`/activities/${props.activity.id}`, options)
  else form.post(`/buildings/${props.building.id}/activities`, options)
}

// the Work ID is given when the activity is saved: a new one has none yet
const workId = row => (row?.work_id ? `${row.work_id} ` : '')
const title = computed(() => (isEdit.value ? `Edit ${workId(props.activity)}${props.activity.description}`
  : isInsert.value ? `Insert below ${workId(props.insertAfter)}${props.insertAfter.description}` : 'Add activity'))
</script>

<template>
  <Modal :show="show" :title="title" @close="emit('close')">
    <form id="activity-form" class="space-y-4" @submit.prevent="submit">
      <FormField label="Activity name" required :error="form.errors.description">
        <input v-model="form.description" type="text" maxlength="255" />
      </FormField>

      <FormField label="Comes after" :error="form.errors.predecessor_id">
        <select v-model="form.predecessor_id">
          <option :value="null">Building start</option>
          <option v-for="r in predecessorOptions" :key="r.id" :value="r.id">{{ r.description }}</option>
        </select>
      </FormField>

      <div>
        <div class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
          <FormField label="Planned date" required :error="form.errors.planned_date">
            <input v-model="form.planned_date" type="date" @input="typedDate" />
          </FormField>
          <label class="flex items-center gap-2 pb-0.5 text-sm text-slate-600">
            <input v-model.number="offset" type="number" min="0" max="65535" class="!w-24" aria-label="Days after"
                   @input="typedOffset" />
            <span class="min-w-0">days after <b class="font-semibold text-slate-800">{{ comesAfterLabel }}</b></span>
          </label>
        </div>
        <p v-if="predecessorHint" class="mt-1.5 text-xs text-slate-500">{{ predecessorHint }}</p>
        <p v-if="isEdit && activity.date_adjustment_days && !form.clear_adjustment" class="mt-1.5 text-xs text-amber-900">
          Includes {{ signed(activity.date_adjustment_days) }} days adjustment.
          <button type="button" class="font-semibold underline hover:text-amber-700" @click="clearAdjustment">Clear</button>
        </p>
        <p v-else-if="form.clear_adjustment" class="mt-1.5 text-xs text-slate-500">The adjustment will be cleared when you save.</p>
      </div>

      <FormField v-if="!form.is_break" label="Actual date" hint="Leave empty until it is done." :error="form.errors.actual_date">
        <input v-model="form.actual_date" type="date" :min="earliest" :max="today" />
      </FormField>

      <label class="flex items-center gap-2 text-sm text-slate-600">
        <input v-model="form.is_break" type="checkbox" class="!min-h-0 !w-auto rounded" />
        This is a break (Diwali, Holi, festival)
      </label>
      <p v-if="form.is_break" class="-mt-2 text-xs text-slate-500">A break has no actual date.</p>

      <div v-if="!showNote">
        <button type="button" class="text-sm font-semibold text-teal-700 hover:underline" @click="showNote = true">+ Add a note</button>
      </div>
      <FormField v-else label="Note" :error="form.errors.notes">
        <textarea v-model="form.notes" rows="2" />
      </FormField>

      <!-- inserting: push the rows after it, or leave them where they are -->
      <fieldset v-if="isInsert" class="rounded-lg border border-slate-200 p-3">
        <legend class="px-1 text-xs font-semibold text-slate-500">The activities after it</legend>
        <p v-if="!canPush" class="text-sm text-slate-600">
          Nothing to push<template v-if="rowBelow">: {{ rowBelow.description }} doesn't come after {{ insertAfter.description }}</template>.
        </p>
        <div v-else class="space-y-2 text-sm text-slate-700">
          <label class="flex items-start gap-2">
            <input v-model="form.push" type="radio" :value="true" class="!mt-0.5 !min-h-0 !w-auto" />
            <span>Push the activities after it later
              <span class="block text-xs text-slate-500">{{ rowBelow.description }} will come after the new activity, so it and everything after it move by its days.</span>
            </span>
          </label>
          <label class="flex items-start gap-2">
            <input v-model="form.push" type="radio" :value="false" class="!mt-0.5 !min-h-0 !w-auto" />
            <span>Don't move other activities</span>
          </label>
        </div>
      </fieldset>

      <!-- what saving would move: computed on the server, nothing written -->
      <div v-if="previewError" class="warn-box !text-sm" role="alert">{{ previewError }}</div>
      <div v-else-if="preview" class="info-box !text-sm" role="status">
        <b>{{ summaryLine }}</b>
        <ul v-if="preview.changed.length" class="mt-1.5 space-y-0.5">
          <li v-for="c in preview.changed" :key="c.id">
            {{ c.description }}: {{ formatDate(c.before) }} → {{ formatDate(c.after) }}
          </li>
          <li v-if="moreChanged > 0" class="text-teal-800/80">and {{ moreChanged }} more</li>
        </ul>
      </div>
    </form>

    <template #footer>
      <button type="button" class="btn-ghost" @click="emit('close')">Cancel</button>
      <button type="submit" form="activity-form" class="btn" :disabled="form.processing || !!previewError">
        {{ form.processing ? 'Saving…' : isEdit ? 'Save changes' : isInsert ? 'Insert activity' : 'Add activity' }}
      </button>
    </template>
  </Modal>
</template>
