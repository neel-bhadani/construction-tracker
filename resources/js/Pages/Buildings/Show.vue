<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusChip from '@/Components/StatusChip.vue'
import ProgressChart from '@/Components/ProgressChart.vue'
import RecordActualModal from '@/Components/RecordActualModal.vue'
import ActivityFormModal from '@/Components/ActivityFormModal.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import RowMenu from '@/Components/RowMenu.vue'
import FloorsSection from '@/Components/FloorsSection.vue'
import FloorChips from '@/Components/FloorChips.vue'
import FloorFormModal from '@/Components/FloorFormModal.vue'
import MoveToFloorModal from '@/Components/MoveToFloorModal.vue'
import BuildingDatesModal from '@/Components/BuildingDatesModal.vue'
import { formatDate, signed, percent, leadWord } from '@/lib/format'
import { activityFloorKey, shortFloorName } from '@/lib/floors'

/*
 | One building's timeline. Every figure is the server's — BuildingMetricsService and
 | LeadProjectionService output, passed through as it is. This page lays the
 | three per-row lists side by side by id and formats them; it calculates
 | nothing. Only the project's owner ever reaches it, and every endpoint
 | checks that again.
 */
const props = defineProps({
  project: Object,
  buildings: Array,
  building: Object,
  metrics: Object,
  projection: Object,
  chart: Object,
  activities: Array,
  floors: Array,          // ProgressService floor rows, "Whole building" last; empty without floors
  today: String,
})

const page = usePage()

const byId = list => Object.fromEntries(list.map(row => [row.id, row]))

const rows = computed(() => {
  const projected = byId(props.projection.activities)
  const stored = byId(props.activities)

  return props.metrics.activities.map(m => ({ ...m, ...projected[m.id], ...stored[m.id] }))
})

/* ---------------- floors ---------------- */

const hasFloors = computed(() => props.floors.length > 0)
const buildingFloors = computed(() => props.floors.filter(f => f.id !== null))
const floorNames = computed(() => Object.fromEntries(buildingFloors.value.map(f => [f.id, f.name])))

// 'all', 'whole' or a floor id; the Floors section and the chips set the same filter
const floorFilter = ref('all')
watch(hasFloors, has => { if (!has) floorFilter.value = 'all' })
// a deleted floor's filter falls back to everything
watch(buildingFloors, floors => {
  if (typeof floorFilter.value === 'number' && !floors.some(f => f.id === floorFilter.value)) floorFilter.value = 'all'
})

const floorRows = computed(() => floorFilter.value === 'all'
  ? rows.value
  : rows.value.filter(row => activityFloorKey(row) === floorFilter.value))

const statusFilter = ref('all')
const statusChips = computed(() => [
  { key: 'all', label: 'All', count: floorRows.value.length },
  { key: 'pending', label: 'Pending', count: floorRows.value.filter(row => row.status !== 'Done').length },
  { key: 'done', label: 'Done', count: floorRows.value.filter(row => row.status === 'Done').length },
])
const shownRows = computed(() => floorRows.value.filter(row => statusFilter.value === 'all'
  || (statusFilter.value === 'done' ? row.status === 'Done' : row.status !== 'Done')))

// a new activity goes on the floor being looked at
const newFloorId = computed(() => (typeof floorFilter.value === 'number' ? floorFilter.value : null))

const floorTag = row => (row.floor_id ? shortFloorName(floorNames.value[row.floor_id] ?? '') : 'Whole building')

const earliestActual = computed(() => props.metrics.actualStartDate)

const lead = computed(() => props.metrics.currentLead)

const leadTone = computed(() => lead.value === null ? 'text-slate-900' : lead.value >= 0 ? 'text-teal-700' : 'text-rose-700')

/* ---------------- modals ---------------- */

const recording = ref(null)
const editing = ref(null)        // a row, or {} for a new activity at the end
const inserting = ref(null)      // the row a new activity goes below
const moving = ref(null)         // the row whose floor is being changed
const deleting = ref(null)
const deleteError = ref('')
const deleteProcessing = ref(false)
const showBuildingDates = ref(false)
const addingFloors = ref(false)

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

const closeForm = () => { editing.value = null; inserting.value = null }

const menuItems = row => [
  ...(!row.is_break ? [{ label: row.actualDate ? 'Change actual date' : 'Mark done', action: () => { recording.value = row } }] : []),
  { label: 'Edit', action: () => { editing.value = row } },
  { label: 'Insert activity below', action: () => { inserting.value = row } },
  // a break is on no floor
  ...(hasFloors.value && !row.is_break ? [{ label: 'Move to floor…', action: () => { moving.value = row } }] : []),
  { label: 'Delete', action: () => openDelete(row), danger: true },
]
</script>

<template>
  <AppLayout :title="building.name" :project-id="project.id">
    <template #breadcrumb>
      <nav class="mb-1 text-sm text-slate-500" aria-label="Breadcrumb">
        <Link href="/projects" class="hover:text-teal-700 hover:underline">Projects</Link>
        <span class="mx-1.5 text-slate-300">›</span>
        <Link :href="`/projects/${project.id}`" class="hover:text-teal-700 hover:underline">{{ project.name }}</Link>
        <span class="mx-1.5 text-slate-300">›</span>
        <span class="text-slate-700" aria-current="page">{{ building.name }}</span>
      </nav>
    </template>

    <!-- building selector: each building has its own URL, so a link opens the same view -->
    <div class="mb-5 flex flex-wrap items-center gap-3">
      <nav class="flex flex-wrap gap-1 rounded-xl border border-slate-200 bg-white p-1" aria-label="Building">
        <Link v-for="b in buildings" :key="b.id" :href="`/projects/${project.id}/buildings/${b.id}`"
              class="rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors"
              :class="b.id === building.id ? 'bg-teal-700 text-white' : 'text-slate-600 hover:bg-slate-50'"
              :aria-current="b.id === building.id ? 'page' : undefined">{{ b.name }}</Link>
      </nav>

      <div class="ml-auto flex gap-2">
        <button class="btn-ghost" @click="showBuildingDates = true">Building dates</button>
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
          <div class="my-2 h-1.5 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label="Building progress"
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

    <FloorsSection :floors="floors" :project-id="project.id" :building-id="building.id"
                   :active="floorFilter" @select="key => (floorFilter = key)" />

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
      <button v-if="building.type !== 'common_work'" type="button" class="ml-auto min-h-11 text-sm font-semibold text-teal-700 hover:underline"
              @click="addingFloors = true">+ Add floors</button>
    </div>
    <FloorChips v-if="hasFloors" v-model="floorFilter" :floors="floors" :rows="rows" />

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
              <td class="px-4 py-2.5">
                <div class="flex items-center justify-between gap-3">
                  <div class="min-w-0">
                    <button type="button" class="break-words text-left font-medium hover:text-teal-700 hover:underline"
                            :class="row.is_break ? '' : 'text-slate-900'" @click="editing = row">{{ row.description }}</button>
                    <span v-if="hasFloors && !row.is_break" data-test="floor-tag" class="ml-2 whitespace-nowrap rounded px-1.5 py-0.5 text-sm font-medium"
                          :class="row.floor_id ? 'bg-sky-50 text-sky-800' : 'text-slate-400'">{{ floorTag(row) }}</span>
                  </div>
                  <button v-if="row.status === 'NEXT' && !row.is_break" class="btn shrink-0 !px-3" @click="recording = row">Mark done</button>
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
                <RowMenu :items="menuItems(row)" :label="`More for ${row.description}`" button-class="btn-ghost !px-2.5 !py-1 !text-lg not-italic" />
              </td>
            </tr>
            <tr v-if="index < shownRows.length - 1">
              <td colspan="7" class="relative p-0">
                <div class="absolute inset-x-0 -top-2 z-10 flex h-4 items-center justify-center opacity-0 transition-opacity hover:opacity-100 focus-within:opacity-100">
                  <button type="button" title="Insert activity below" :aria-label="`Insert activity below ${row.description}`"
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
            <button type="button" class="break-words text-left font-semibold hover:text-teal-700 hover:underline"
                    :class="row.is_break ? '' : 'text-slate-900'" @click="editing = row">{{ row.description }}</button>
            <span v-if="hasFloors && !row.is_break" class="mt-1 block w-fit rounded px-1.5 py-0.5 text-sm font-medium not-italic"
                  :class="row.floor_id ? 'bg-sky-50 text-sky-800' : 'text-slate-400'">{{ floorTag(row) }}</span>
          </div>
          <RowMenu :items="menuItems(row)" :label="`More for ${row.description}`" button-class="btn-ghost !px-3 !text-lg not-italic" />
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
        </dl>
        <button v-if="row.status === 'NEXT' && !row.is_break" class="btn mt-3 w-full" @click="recording = row">Mark done</button>
      </div>
    </div>

    <p v-if="!rows.length" class="card p-6 text-sm text-slate-500">This building has no activities yet.</p>
    <p v-else-if="!shownRows.length" class="card p-6 text-sm text-slate-500">No activities match these filters.</p>

    <RecordActualModal :show="!!recording" :activity="recording" :today="today" :earliest="earliestActual"
                       @close="recording = null" />
    <ActivityFormModal :show="!!editing || !!inserting" :building="building" :activity="editing?.id ? editing : null"
                       :insert-after="inserting" :rows="rows" :today="today" :earliest="earliestActual"
                       :floors="buildingFloors" :floor-id="newFloorId" @close="closeForm" />
    <MoveToFloorModal :show="!!moving" :activity="moving" :floors="buildingFloors" @close="moving = null" />
    <FloorFormModal :show="addingFloors" :project-id="project.id" :building-id="building.id"
                    :existing="buildingFloors" @close="addingFloors = false" />
    <ConfirmDialog :show="!!deleting" title="Delete activity" confirm-text="Delete"
                   :processing="deleteProcessing"
                   :message="deleteError || (deleting ? `Delete [${deleting.description}]? Its history is kept in the audit trail.` : '')"
                   @close="deleting = null" @confirm="confirmDelete" />
    <BuildingDatesModal :show="showBuildingDates" :project-id="project.id" :building="building"
                        @close="showBuildingDates = false" />
  </AppLayout>
</template>
