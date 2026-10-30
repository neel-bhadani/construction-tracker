<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import StatusChip from './StatusChip.vue'
import ProgressChart from './ProgressChart.vue'
import RecordActualModal from './RecordActualModal.vue'
import ActivityFormModal from './ActivityFormModal.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import RowMenu from './RowMenu.vue'
import BuildingDatesModal from './BuildingDatesModal.vue'
import SubActivityList from './SubActivityList.vue'
import ProgressBar from './ProgressBar.vue'
import SubActivityFormModal from './SubActivityFormModal.vue'
import { formatDate, signed, percent, leadWord } from '@/lib/format'

/*
 | One timeline: a building's, or the project's Common activities. Every figure
 | is the server's (TimelinePage: BuildingMetricsService and LeadProjectionService
 | output, passed through as it is). This lays the three per-row lists side by
 | side by id and formats them; it calculates nothing. The nav slot holds what
 | sits left of the buttons: the building tabs on a building's page.
 |
 | Each activity row has a progress bar (its sub-activities' average, from the
 | server) and opens to show its sub-activities. With any, it is done through
 | them: no Mark done of its own.
 */
const props = defineProps({
  projectId: Number,
  building: Object,
  metrics: Object,
  projection: Object,
  chart: Object,
  activities: Array,
  today: String,
})

const page = usePage()

const byId = list => Object.fromEntries(list.map(row => [row.id, row]))

const rows = computed(() => {
  const projected = byId(props.projection.activities)
  const stored = byId(props.activities)

  return props.metrics.activities.map(m => ({ ...m, ...projected[m.id], ...stored[m.id] }))
})

const statusFilter = ref('all')
const statusChips = computed(() => [
  { key: 'all', label: 'All', count: rows.value.length },
  { key: 'pending', label: 'Pending', count: rows.value.filter(row => row.status !== 'Done').length },
  { key: 'done', label: 'Done', count: rows.value.filter(row => row.status === 'Done').length },
])
const shownRows = computed(() => rows.value.filter(row => statusFilter.value === 'all'
  || (statusFilter.value === 'done' ? row.status === 'Done' : row.status !== 'Done')))

const earliestActual = computed(() => props.metrics.actualStartDate)

const lead = computed(() => props.metrics.currentLead)

const isCommon = computed(() => props.building.type === 'common_work')

const label = row => (row.work_id ? `${row.work_id} ${row.description}` : row.description)

const leadTone = computed(() => lead.value === null ? 'text-slate-900' : lead.value >= 0 ? 'text-teal-700' : 'text-rose-700')

/* ---------------- modals ---------------- */

const recording = ref(null)
const editing = ref(null)        // a row, or {} for a new activity at the end
const inserting = ref(null)      // the row a new activity goes below
const deleting = ref(null)
const deleteError = ref('')
const deleteProcessing = ref(false)
const showBuildingDates = ref(false)

const confirmDelete = () => {
  deleteProcessing.value = true
  router.delete(`/activities/${deleting.value.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      // refused deletions come back as an error, not a success redirect
      if (page.props.errors.activity) deleteError.value = page.props.errors.activity
      else deleting.value = null
    },
    onError: errors => { deleteError.value = errors.activity ?? 'The activity could not be deleted.' },
    onFinish: () => { deleteProcessing.value = false },
  })
}

const openDelete = row => { deleteError.value = ''; deleting.value = row }

/* ---------------- sub-activities ---------------- */

const hasSubs = row => !!row.sub_activities?.length
const expanded = ref(new Set())
const isOpen = row => expanded.value.has(row.id)
const toggle = row => {
  const next = new Set(expanded.value)
  next.has(row.id) ? next.delete(row.id) : next.add(row.id)
  expanded.value = next
}
const subForm = ref(null)        // { activity, sub }: sub null to add
const addSub = row => {
  if (!isOpen(row)) toggle(row)
  subForm.value = { activity: row, sub: null }
}
// the row as the page has it now, so the dialog sees the latest sub-activities
const subFormActivity = computed(() => subForm.value && rows.value.find(r => r.id === subForm.value.activity.id))

const closeForm = () => { editing.value = null; inserting.value = null }

const menuItems = row => [
  ...(!row.is_break && !hasSubs(row) ? [{ label: row.actualDate ? 'Change actual date' : 'Mark done', action: () => { recording.value = row } }] : []),
  { label: 'Edit', action: () => { editing.value = row } },
  ...(!row.is_break ? [{ label: 'Add sub-activity', action: () => addSub(row) }] : []),
  { label: 'Insert activity below', action: () => { inserting.value = row } },
  { label: 'Delete', action: () => openDelete(row), danger: true },
]
</script>

<template>
  <div>
    <div class="mb-5 flex flex-wrap items-center gap-3">
      <slot name="nav" />

      <div class="ml-auto flex gap-2">
        <button class="btn-ghost" @click="showBuildingDates = true">{{ isCommon ? 'Start dates' : 'Building dates' }}</button>
        <button class="btn" @click="editing = {}">Add activity</button>
      </div>
    </div>

    <section class="mb-6" aria-label="Status">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4 text-sm sm:p-5">
          <div class="font-semibold text-slate-500">Days {{ lead ? leadWord(lead) : 'ahead' }}</div>
          <div class="mt-2 text-3xl font-bold tracking-tight" :class="leadTone">{{ lead === null ? '—' : lead === 0 ? 'On plan' : signed(lead) }}</div>
          <div class="mt-1 text-slate-500">at the latest milestone</div>
        </div>
        <div class="card p-4 text-sm sm:p-5">
          <div class="font-semibold text-slate-500">Progress</div>
          <div class="mt-2 text-3xl font-bold tracking-tight text-slate-900">{{ percent(metrics.progress) }}</div>
          <div class="my-2 h-1.5 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label="Timeline progress"
               :aria-valuenow="metrics.progress * 100" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full rounded-full bg-teal-700" :style="{ width: `${Math.min(100, metrics.progress * 100)}%` }" />
          </div>
          <div class="text-slate-500">{{ metrics.daysCompleted }} of {{ metrics.totalPlannedDays }} days</div>
        </div>
        <div class="card p-4 text-sm sm:p-5">
          <div class="font-semibold text-slate-500">Planned finish</div>
          <div class="mt-2 text-xl font-semibold text-slate-900 sm:text-2xl">{{ formatDate(metrics.plannedFinish) }}</div>
        </div>
        <div class="card p-4 text-sm sm:p-5">
          <div class="font-semibold text-slate-500">Forecast finish</div>
          <div class="mt-2 text-xl font-semibold text-slate-900 sm:text-2xl">{{ formatDate(metrics.forecastHoldLead) }}</div>
          <div class="mt-1 text-slate-500">Hold current lead</div>
          <div v-if="metrics.aheadHoldLead !== null" class="mt-1 font-medium"
               :class="metrics.aheadHoldLead >= 0 ? 'text-teal-700' : 'text-rose-700'">{{ signed(metrics.aheadHoldLead) }} days vs plan</div>
        </div>
      </div>
      <p class="mt-3 text-sm text-slate-500 lg:text-right">
        Other forecasts: {{ formatDate(metrics.forecastAveragePace) }} (average pace) · {{ formatDate(metrics.forecastRecentPace) }} (recent pace)
      </p>
    </section>

    <ProgressChart :chart="chart" />

    <h2 class="mb-3 text-xl font-semibold text-slate-900">Activities</h2>
    <div class="mb-3 flex flex-wrap items-center gap-3">
      <div class="flex gap-1 rounded-lg border border-slate-200 bg-white p-1" role="group" aria-label="Activity status">
        <button v-for="chip in statusChips" :key="chip.key" type="button" data-test="status-filter"
                class="min-h-9 rounded-md px-3 py-1.5 text-sm font-medium max-md:min-h-11"
                :class="statusFilter === chip.key ? 'bg-teal-700 text-white' : 'text-slate-600 hover:bg-slate-50'"
                :aria-pressed="statusFilter === chip.key" @click="statusFilter = chip.key">
          {{ chip.label }} <span class="ml-1 tabular-nums" :class="statusFilter === chip.key ? 'text-teal-100' : 'text-slate-400'">{{ chip.count }}</span>
        </button>
      </div>
    </div>

    <!-- desktop: the table -->
    <div class="card hidden lg:block">
      <table class="w-full table-fixed text-sm" aria-label="Activities">
        <colgroup>
          <col />
          <col span="2" class="w-28" />
          <col class="w-32" />
          <col span="2" class="w-28" />
          <col class="w-14" />
        </colgroup>
        <thead class="sticky top-14 z-20 border-b border-slate-200 bg-slate-50 text-left text-sm font-semibold text-slate-600">
          <tr>
            <th class="min-w-[280px] px-4 py-3">Activity</th>
            <th class="px-3 py-3">Planned</th>
            <th class="px-3 py-3">Actual</th>
            <th class="px-3 py-3 text-right">Ahead / behind</th>
            <th class="px-3 py-3">Status</th>
            <th class="px-3 py-3">Forecast</th>
            <th class="px-2 py-3"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="(row, index) in shownRows" :key="row.id">
            <tr data-test="activity-row" class="border-b border-slate-200 last:border-0"
                :class="row.is_break ? 'bg-slate-50 italic text-slate-500' : row.status === 'NEXT' ? 'bg-amber-50' : row.status === 'Done' ? 'bg-emerald-50/60' : ''">
              <td class="py-2.5 pl-2 pr-4">
                <div class="flex items-start gap-1">
                  <button v-if="!row.is_break" type="button" data-test="toggle-subs"
                          class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900"
                          :aria-expanded="isOpen(row)" :aria-label="`${isOpen(row) ? 'Hide' : 'Show'} sub-activities of ${label(row)}`" @click="toggle(row)">
                    <span class="inline-block transition-transform" :class="isOpen(row) ? 'rotate-90' : ''" aria-hidden="true">›</span>
                  </button>
                  <span v-else class="w-8 shrink-0" />
                  <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-3">
                      <div class="flex min-w-0 items-baseline gap-2 pt-1">
                        <span data-test="work-id" class="shrink-0 font-mono text-[13px] not-italic text-slate-500">{{ row.work_id }}</span>
                        <button type="button" class="break-words text-left font-medium hover:text-teal-700 hover:underline"
                                :class="row.is_break ? '' : 'text-slate-900'" @click="editing = row">{{ row.description }}</button>
                      </div>
                      <button v-if="row.status === 'NEXT' && !row.is_break && !hasSubs(row)" class="btn shrink-0 !px-3" @click="recording = row">Mark done</button>
                    </div>
                    <div v-if="!row.is_break" class="mt-1.5 flex max-w-sm items-center gap-3 not-italic">
                      <ProgressBar class="flex-1" :value="row.progress ?? 0" :label="`Progress of ${label(row)}`" />
                      <span v-if="hasSubs(row)" class="whitespace-nowrap text-[13px] text-slate-500">{{ row.sub_activities.length }} sub</span>
                    </div>
                  </div>
                </div>
              </td>
              <td class="whitespace-nowrap px-3 py-2.5">
                {{ formatDate(row.plannedDate) }}
                <span v-if="row.date_adjustment_days" class="mt-1 block w-fit cursor-help rounded bg-amber-100 px-1.5 py-0.5 text-[13px] font-semibold text-amber-900"
                      :title="`Planned date includes a ${signed(row.date_adjustment_days)} days adjustment`">{{ signed(row.date_adjustment_days) }} days adj.</span>
              </td>
              <td class="whitespace-nowrap px-3 py-2.5">{{ row.actualDate ? formatDate(row.actualDate) : '-' }}</td>
              <td class="whitespace-nowrap px-3 py-2.5 text-right font-medium"
                  :class="row.aheadDays > 0 ? 'text-teal-700' : row.aheadDays < 0 ? 'text-rose-700' : ''">
                {{ row.actualDate && row.aheadDays !== null ? `${signed(row.aheadDays)} days` : '-' }}
              </td>
              <td class="px-3 py-2.5"><StatusChip :status="row.is_break ? 'Break' : row.status" /></td>
              <td class="whitespace-nowrap px-3 py-2.5">{{ row.actualDate ? '' : formatDate(row.actualOrForecast) }}</td>
              <td class="whitespace-nowrap px-2 py-1 text-right">
                <RowMenu :items="menuItems(row)" :label="`More for ${label(row)}`" button-class="btn-ghost !px-2.5 !py-1 !text-lg not-italic" />
              </td>
            </tr>
            <tr v-if="isOpen(row)" data-test="sub-row" class="border-b border-slate-200 bg-slate-50">
              <td colspan="7" class="py-3 pl-12 pr-4">
                <SubActivityList :activity="row" :today="today" @add="addSub(row)" @edit="sub => (subForm = { activity: row, sub })" />
              </td>
            </tr>
            <tr v-if="index < shownRows.length - 1">
              <td colspan="7" class="relative p-0">
                <div class="absolute inset-x-0 -top-2 z-10 flex h-4 items-center justify-center opacity-0 transition-opacity hover:opacity-100 focus-within:opacity-100">
                  <button type="button" title="Insert activity below" :aria-label="`Insert activity below ${label(row)}`"
                          class="flex h-6 w-6 items-center justify-center rounded-full bg-teal-700 text-sm font-bold text-white shadow"
                          @click="inserting = row">+</button>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <!-- phone: the same rows as cards -->
    <div class="space-y-3 lg:hidden">
      <div v-for="row in shownRows" :key="row.id" data-test="activity-card" class="card p-4 text-sm"
           :class="row.is_break ? '!bg-slate-50 italic text-slate-500' : row.status === 'NEXT' ? '!border-amber-300 !bg-amber-50' : row.status === 'Done' ? '!bg-emerald-50/60' : ''">
        <div class="mb-3 flex items-start gap-2">
          <div class="min-w-0 flex-1">
            <span data-test="work-id" class="block font-mono text-[13px] not-italic text-slate-500">{{ row.work_id }}</span>
            <button type="button" class="break-words text-left font-semibold hover:text-teal-700 hover:underline"
                    :class="row.is_break ? '' : 'text-slate-900'" @click="editing = row">{{ row.description }}</button>
          </div>
          <RowMenu :items="menuItems(row)" :label="`More for ${label(row)}`" button-class="btn-ghost !px-3 !text-lg not-italic" />
        </div>
        <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2">
          <dt class="text-slate-500">Planned</dt>
          <dd>
            {{ formatDate(row.plannedDate) }}
            <span v-if="row.date_adjustment_days" class="mt-1 block w-fit rounded bg-amber-100 px-1.5 py-0.5 text-[13px] font-semibold text-amber-900"
                  :title="`Planned date includes a ${signed(row.date_adjustment_days)} days adjustment`">{{ signed(row.date_adjustment_days) }} days adj.</span>
          </dd>
          <dt class="text-slate-500">Actual</dt>
          <dd>{{ row.actualDate ? formatDate(row.actualDate) : '-' }}</dd>
          <template v-if="row.actualDate">
            <dt class="text-slate-500">Ahead / behind</dt>
            <dd class="font-medium" :class="row.aheadDays >= 0 ? 'text-teal-700' : 'text-rose-700'">{{ row.aheadDays === null ? '-' : `${signed(row.aheadDays)} days` }}</dd>
          </template>
          <template v-else>
            <dt class="text-slate-500">Forecast</dt>
            <dd>{{ formatDate(row.actualOrForecast) }}</dd>
          </template>
          <dt class="text-slate-500">Status</dt>
          <dd><StatusChip :status="row.is_break ? 'Break' : row.status" /></dd>
          <template v-if="!row.is_break">
            <dt class="text-slate-500">Progress</dt>
            <dd class="not-italic"><ProgressBar :value="row.progress ?? 0" :label="`Progress of ${label(row)}`" /></dd>
          </template>
        </dl>
        <button v-if="row.status === 'NEXT' && !row.is_break && !hasSubs(row)" class="btn mt-3 w-full" @click="recording = row">Mark done</button>
        <template v-if="!row.is_break">
          <button type="button" data-test="toggle-subs" class="mt-3 flex min-h-11 w-full items-center justify-between rounded-lg border border-slate-200 bg-white px-3 font-semibold not-italic text-slate-700"
                  :aria-expanded="isOpen(row)" @click="toggle(row)">
            <span>Sub-activities ({{ row.sub_activities?.length ?? 0 }})</span>
            <span class="inline-block transition-transform" :class="isOpen(row) ? 'rotate-90' : ''" aria-hidden="true">›</span>
          </button>
          <SubActivityList v-if="isOpen(row)" class="mt-3" :activity="row" :today="today" @add="addSub(row)" @edit="sub => (subForm = { activity: row, sub })" />
        </template>
      </div>
    </div>

    <p v-if="!rows.length" class="card p-6 text-sm text-slate-500">{{ isCommon ? 'There are no common activities yet.' : 'This building has no activities yet.' }}</p>
    <p v-else-if="!shownRows.length" class="card p-6 text-sm text-slate-500">No activities match these filters.</p>

    <SubActivityFormModal :show="!!subForm" :activity="subFormActivity" :sub="subForm?.sub ?? null" :today="today" :earliest="earliestActual"
                          @close="subForm = null" />
    <RecordActualModal :show="!!recording" :activity="recording" :today="today" :earliest="earliestActual"
                       @close="recording = null" />
    <ActivityFormModal :show="!!editing || !!inserting" :building="building" :activity="editing?.id ? editing : null"
                       :insert-after="inserting" :rows="rows" :today="today" :earliest="earliestActual"
                       @close="closeForm" />
    <ConfirmDialog :show="!!deleting" title="Delete activity" confirm-text="Delete"
                   :processing="deleteProcessing"
                   :message="deleteError || (deleting ? `Delete [${label(deleting)}]? Its history is kept in the audit trail.` : '')"
                   @close="deleting = null" @confirm="confirmDelete" />
    <BuildingDatesModal :show="showBuildingDates" :project-id="projectId" :building="building"
                        @close="showBuildingDates = false" />
  </div>
</template>
