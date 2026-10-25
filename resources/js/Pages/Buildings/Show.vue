<script setup>
import { computed, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import StatusChip from '@/Components/StatusChip.vue'
import ProgressChart from '@/Components/ProgressChart.vue'
import RecordActualModal from '@/Components/RecordActualModal.vue'
import ActivityFormModal from '@/Components/ActivityFormModal.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import BuildingDatesModal from '@/Components/BuildingDatesModal.vue'
import { formatDate, signed, percent, leadWord } from '@/lib/format'

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
  today: String,
})

const page = usePage()

const byId = list => Object.fromEntries(list.map(row => [row.id, row]))

const rows = computed(() => {
  const projected = byId(props.projection.activities)
  const stored = byId(props.activities)

  return props.metrics.activities.map(m => ({ ...m, ...projected[m.id], ...stored[m.id] }))
})

const options = computed(() => props.activities.map(a => ({ id: a.id, description: a.description })))

const earliestActual = computed(() => props.metrics.actualStartDate)

const lead = computed(() => props.metrics.currentLead)

const leadTone = computed(() => lead.value === null ? 'text-slate-900' : lead.value >= 0 ? 'text-teal-700' : 'text-rose-700')

/* ---------------- modals ---------------- */

const recording = ref(null)
const editing = ref(null)        // a stored row, or {} for a new activity
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

    <!-- status row: the lead comes first, it is what the client looks at -->
    <section class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" aria-label="Status">
      <div class="card p-4">
        <div class="text-xs font-semibold text-slate-500">Days {{ lead === null ? 'ahead / behind' : leadWord(lead) }}</div>
        <div class="mt-1 text-3xl font-bold tracking-tight" :class="leadTone">{{ lead === null ? '—' : signed(lead) }}</div>
        <div class="mt-0.5 text-xs text-slate-400">at the latest milestone</div>
      </div>
      <div class="card p-4">
        <div class="text-xs font-semibold text-slate-500">Progress</div>
        <div class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ percent(metrics.progress) }}</div>
        <div class="mt-0.5 text-xs text-slate-400">{{ metrics.daysCompleted }} of {{ metrics.totalPlannedDays }} planned days</div>
      </div>
      <div class="card p-4">
        <div class="text-xs font-semibold text-slate-500">Planned finish</div>
        <div class="mt-1 text-lg font-semibold text-slate-900">{{ formatDate(metrics.plannedFinish) }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs font-semibold text-slate-500">Forecast · average pace</div>
        <div class="mt-1 text-lg font-semibold text-slate-900">{{ formatDate(metrics.forecastAveragePace) }}</div>
        <div v-if="metrics.aheadAveragePace !== null" class="mt-0.5 text-xs text-slate-400">{{ signed(metrics.aheadAveragePace) }} days vs plan</div>
      </div>
      <div class="card p-4">
        <div class="text-xs font-semibold text-slate-500">Forecast · recent pace</div>
        <div class="mt-1 text-lg font-semibold text-slate-900">{{ formatDate(metrics.forecastRecentPace) }}</div>
        <div v-if="metrics.aheadRecentPace !== null" class="mt-0.5 text-xs text-slate-400">{{ signed(metrics.aheadRecentPace) }} days vs plan</div>
      </div>
      <div class="card p-4">
        <div class="text-xs font-semibold text-slate-500">Forecast · hold current lead</div>
        <div class="mt-1 text-lg font-semibold text-slate-900">{{ formatDate(metrics.forecastHoldLead) }}</div>
        <div v-if="metrics.aheadHoldLead !== null" class="mt-0.5 text-xs text-slate-400">{{ signed(metrics.aheadHoldLead) }} days vs plan</div>
      </div>
    </section>

    <ProgressChart :chart="chart" />

    <!-- desktop: the table -->
    <div class="card hidden overflow-x-auto md:block">
      <table class="w-full text-sm">
        <thead class="border-b border-slate-200 text-left text-xs font-semibold text-slate-500">
          <tr>
            <th class="px-4 py-3">Activity</th>
            <th class="px-3 py-3">Planned</th>
            <th class="px-3 py-3">Actual</th>
            <th class="px-3 py-3 text-right">Ahead / behind</th>
            <th class="px-3 py-3">Status</th>
            <th class="px-3 py-3">Forecast</th>
            <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id" class="border-b border-slate-100 last:border-0"
              :class="[
                row.status === 'NEXT' ? 'bg-teal-50 ring-1 ring-inset ring-teal-300' : '',
                row.is_break ? 'bg-[repeating-linear-gradient(135deg,#f8fafc,#f8fafc_8px,#f1f5f9_8px,#f1f5f9_16px)] text-slate-500' : '',
              ]">
            <td class="px-4 py-2.5">
              <span :class="row.is_break ? 'italic' : 'font-medium text-slate-900'">{{ row.description }}</span>
              <span v-if="row.is_break" class="ml-2 rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-600">Break</span>
            </td>
            <td class="whitespace-nowrap px-3 py-2.5">
              {{ formatDate(row.plannedDate) }}
              <span v-if="row.date_adjustment_days" class="ml-1 cursor-help rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold text-amber-900"
                    :title="`Date adjusted ${signed(row.date_adjustment_days)} days: ${row.adjustment_reason}`">{{ signed(row.date_adjustment_days) }} d</span>
            </td>
            <td class="whitespace-nowrap px-3 py-2.5">{{ row.actualDate ? formatDate(row.actualDate) : '' }}</td>
            <td class="whitespace-nowrap px-3 py-2.5 text-right font-medium"
                :class="row.aheadDays > 0 ? 'text-teal-700' : row.aheadDays < 0 ? 'text-rose-700' : ''">
              {{ row.aheadDays === null ? '' : signed(row.aheadDays) }}
            </td>
            <td class="px-3 py-2.5"><StatusChip :status="row.status" /></td>
            <td class="whitespace-nowrap px-3 py-2.5">{{ row.actualDate ? '' : formatDate(row.actualOrForecast) }}</td>
            <td class="whitespace-nowrap px-4 py-2 text-right">
              <div class="flex justify-end gap-1.5">
                <button class="btn-xs" @click="recording = row">Actual</button>
                <button class="btn-xs" @click="editing = row">Edit</button>
                <button class="btn-xs hover:!border-rose-600 hover:!text-rose-700" @click="openDelete(row)">Delete</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- phone: the same rows as cards -->
    <div class="space-y-2.5 md:hidden">
      <div v-for="row in rows" :key="row.id" class="card p-4"
           :class="[row.status === 'NEXT' ? '!border-teal-400 bg-teal-50' : '', row.is_break ? 'bg-slate-50 text-slate-500' : '']">
        <div class="mb-2 flex items-start gap-2">
          <div class="min-w-0 flex-1" :class="row.is_break ? 'italic' : 'font-semibold text-slate-900'">{{ row.description }}</div>
          <StatusChip :status="row.status" />
        </div>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
          <dt class="text-slate-500">Planned</dt>
          <dd>
            {{ formatDate(row.plannedDate) }}
            <span v-if="row.date_adjustment_days" class="ml-1 rounded bg-amber-100 px-1 text-[11px] font-semibold text-amber-900"
                  :title="row.adjustment_reason">{{ signed(row.date_adjustment_days) }} d</span>
          </dd>
          <template v-if="row.actualDate">
            <dt class="text-slate-500">Actual</dt>
            <dd>{{ formatDate(row.actualDate) }} <span class="font-medium" :class="row.aheadDays >= 0 ? 'text-teal-700' : 'text-rose-700'">({{ signed(row.aheadDays) }})</span></dd>
          </template>
          <template v-else>
            <dt class="text-slate-500">Forecast</dt>
            <dd>{{ formatDate(row.actualOrForecast) }}</dd>
          </template>
        </dl>
        <p v-if="row.date_adjustment_days" class="mt-2 text-xs text-amber-900">Adjusted: {{ row.adjustment_reason }}</p>
        <div class="mt-3 flex gap-2">
          <button class="btn-ghost flex-1" @click="recording = row">Record actual</button>
          <button class="btn-ghost" @click="editing = row">Edit</button>
          <button class="btn-ghost" @click="openDelete(row)">Delete</button>
        </div>
      </div>
    </div>

    <p v-if="!rows.length" class="card p-6 text-sm text-slate-500">This building has no activities yet.</p>

    <RecordActualModal :show="!!recording" :activity="recording" :today="today" :earliest="earliestActual"
                       @close="recording = null" />
    <ActivityFormModal :show="!!editing" :building-id="building.id" :activity="editing?.id ? editing : null"
                       :options="options" @close="editing = null" />
    <ConfirmDialog :show="!!deleting" title="Delete activity" confirm-text="Delete"
                   :processing="deleteProcessing"
                   :message="deleteError || (deleting ? `Delete [${deleting.description}]? Its history is kept in the audit trail.` : '')"
                   @close="deleting = null" @confirm="confirmDelete" />
    <BuildingDatesModal :show="showBuildingDates" :project-id="project.id" :building="building"
                        @close="showBuildingDates = false" />
  </AppLayout>
</template>
