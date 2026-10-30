<script setup>
import { computed, ref } from 'vue'
import StatusChip from './StatusChip.vue'
import ProgressBar from './ProgressBar.vue'
import ActivityFormModal from './ActivityFormModal.vue'
import SubActivityList from './SubActivityList.vue'
import SubActivityFormModal from './SubActivityFormModal.vue'
import { formatDate, percent, leadText } from '@/lib/format'

/*
 | Every activity of the project, grouped by timeline: the Common activities
 | first, then the buildings in their order (AllActivitiesPage). Each group
 | folds away and has its own % and lead; each row opens its sub-activities,
 | and its name opens the same Edit dialog as on its timeline.
 |
 | Search matches a name or Work ID, an activity's or one of its
 | sub-activities' (a row found through a sub-activity opens to show it).
 | Every figure is the server's; this only filters and lays them out.
 */
const props = defineProps({
  project: Object,         // ProjectSummaryService output, for the figures on top
  groups: Array,
  today: String,
})

const STATUSES = [
  { key: 'all', label: 'All' },
  { key: 'pending', label: 'Pending' },
  { key: 'done', label: 'Done' },
  { key: 'next', label: 'Next' },
]

const search = ref('')
const groupFilter = ref('all')
const statusFilter = ref('all')

const term = computed(() => search.value.trim().toLowerCase())
const hit = text => (text ?? '').toLowerCase().includes(term.value)
const rowHit = row => !term.value || hit(row.work_id) || hit(row.description)
const subHit = row => !!term.value && (row.sub_activities ?? []).some(s => hit(s.work_id) || hit(s.name))
const statusHit = (row, key) => key === 'all'
  || (key === 'done' ? row.status === 'Done' : key === 'next' ? row.status === 'NEXT' : row.status !== 'Done')

// the groups and rows the search and group filter leave, before the status filter
const searched = computed(() => props.groups
  .filter(g => groupFilter.value === 'all' || g.id === groupFilter.value)
  .map(g => ({ ...g, found: g.rows.filter(row => rowHit(row) || subHit(row)) })))

const counts = computed(() => Object.fromEntries(STATUSES.map(s => [s.key,
  searched.value.reduce((n, g) => n + g.found.filter(row => statusHit(row, s.key)).length, 0)])))

const shown = computed(() => searched.value
  .map(g => ({ ...g, shownRows: g.found.filter(row => statusHit(row, statusFilter.value)) }))
  .filter(g => g.shownRows.length || (!term.value && statusFilter.value === 'all')))

/* ---------------- folding ---------------- */

const folded = ref(new Set())     // group ids
const toggleGroup = id => {
  const next = new Set(folded.value)
  next.has(id) ? next.delete(id) : next.add(id)
  folded.value = next
}

const opened = ref(new Set())     // activity ids opened by hand
const isOpen = row => opened.value.has(row.id) || (subHit(row) && !rowHit(row))
const toggleRow = row => {
  const next = new Set(opened.value)
  next.has(row.id) ? next.delete(row.id) : next.add(row.id)
  opened.value = next
}

/* ---------------- dialogs ---------------- */

const editing = ref(null)         // { group, row }
const subForm = ref(null)         // { group, activity, sub }
const groupOf = id => props.groups.find(g => g.id === id)
// the row as the page has it now, so a dialog sees the latest sub-activities
const latest = (groupId, rowId) => groupOf(groupId)?.rows.find(r => r.id === rowId)
const subActivity = computed(() => subForm.value && latest(subForm.value.group.id, subForm.value.activity.id))
const addSub = (group, row) => {
  if (!opened.value.has(row.id)) toggleRow(row)
  subForm.value = { group, activity: row, sub: null }
}

const leadTone = lead => (lead === null ? 'text-slate-400' : lead > 0 ? 'text-teal-700' : lead < 0 ? 'text-rose-700' : 'text-slate-700')
const label = row => `${row.work_id} ${row.description}`
</script>

<template>
  <div>
    <!-- the whole project: the same figures as the Buildings tab -->
    <section class="card mb-5 grid gap-4 p-5 sm:grid-cols-[minmax(0,2fr)_1fr_1fr]" aria-label="Project progress">
      <div>
        <div class="mb-2 text-sm font-semibold text-slate-700">Overall progress</div>
        <ProgressBar :value="(project.progress ?? 0) * 100" label="Overall progress" size="lg" />
      </div>
      <div>
        <div class="text-sm font-semibold text-slate-700">Lead</div>
        <div class="mt-1 text-lg font-bold" :class="leadTone(project.mostBehind?.lead ?? null)" data-test="overall-lead">
          {{ project.mostBehind ? leadText(project.mostBehind.lead) : '—' }}
        </div>
        <div v-if="project.mostBehind" class="text-[13px] text-slate-500">most behind: {{ project.mostBehind.name }}</div>
      </div>
      <div>
        <div class="text-sm font-semibold text-slate-700">Forecast finish</div>
        <div class="mt-1 text-lg font-bold text-slate-900" data-test="overall-forecast">{{ formatDate(project.expectedHandover) }}</div>
      </div>
    </section>

    <div class="mb-4 flex flex-wrap items-center gap-3">
      <input v-model="search" type="search" placeholder="Search a name or Work ID" aria-label="Search activities"
             class="!w-full sm:!w-72" data-test="activity-search" />
      <select v-model="groupFilter" aria-label="Group" class="!w-full sm:!w-56" data-test="group-filter">
        <option value="all">All groups</option>
        <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
      </select>
      <div class="flex w-full gap-1 rounded-lg border border-slate-200 bg-white p-1 sm:w-auto" role="group" aria-label="Activity status">
        <button v-for="s in STATUSES" :key="s.key" type="button" data-test="status-filter"
                class="min-h-11 flex-1 rounded-md px-3 py-1.5 text-sm font-medium sm:min-h-9 sm:flex-none"
                :class="statusFilter === s.key ? 'bg-teal-700 text-white' : 'text-slate-600 hover:bg-slate-50'"
                :aria-pressed="statusFilter === s.key" @click="statusFilter = s.key">
          {{ s.label }} <span class="ml-1 tabular-nums" :class="statusFilter === s.key ? 'text-teal-100' : 'text-slate-400'">{{ counts[s.key] }}</span>
        </button>
      </div>
    </div>

    <p v-if="!shown.length" class="card p-6 text-sm text-slate-500" data-test="no-match">No activities match.</p>

    <section v-for="group in shown" :key="group.id" class="card mb-4 overflow-hidden" data-test="activity-group" :aria-label="group.name">
      <button type="button" class="flex w-full flex-wrap items-center gap-x-4 gap-y-2 p-4 text-left hover:bg-slate-50"
              :aria-expanded="!folded.has(group.id)" data-test="group-toggle" @click="toggleGroup(group.id)">
        <span class="inline-block text-slate-500 transition-transform" :class="folded.has(group.id) ? '' : 'rotate-90'" aria-hidden="true">›</span>
        <span class="min-w-0 flex-1 text-lg font-semibold text-slate-900">{{ group.name }}</span>
        <span class="text-sm text-slate-500">{{ group.shownRows.length }} of {{ group.rows.length }}</span>
        <span class="text-sm">Lead <b :class="leadTone(group.currentLead)">{{ leadText(group.currentLead) }}</b></span>
        <ProgressBar class="w-full sm:w-48" :value="group.progress * 100" :label="`Progress of ${group.name}`" />
      </button>

      <template v-if="!folded.has(group.id)">
        <!-- desktop: a table -->
        <table class="hidden w-full table-fixed border-t border-slate-200 text-sm lg:table">
          <colgroup>
            <col class="w-10" /><col class="w-24" /><col /><col span="2" class="w-28" /><col class="w-28" /><col class="w-28" />
          </colgroup>
          <thead class="bg-slate-50 text-left text-sm font-semibold text-slate-600">
            <tr>
              <th class="px-2 py-2.5"><span class="sr-only">Sub-activities</span></th>
              <th class="px-2 py-2.5">Work ID</th>
              <th class="px-3 py-2.5">Activity</th>
              <th class="px-3 py-2.5">Planned</th>
              <th class="px-3 py-2.5">Actual</th>
              <th class="px-3 py-2.5">Status</th>
              <th class="px-3 py-2.5">Forecast</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="row in group.shownRows" :key="row.id">
              <tr data-test="all-row" class="border-t border-slate-200"
                  :class="row.is_break ? 'bg-slate-50 italic text-slate-500' : row.status === 'NEXT' ? 'bg-amber-50' : row.status === 'Done' ? 'bg-emerald-50/60' : ''">
                <td class="px-2 py-2">
                  <button v-if="!row.is_break" type="button" data-test="toggle-subs"
                          class="flex h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100"
                          :aria-expanded="isOpen(row)" :aria-label="`${isOpen(row) ? 'Hide' : 'Show'} sub-activities of ${label(row)}`" @click="toggleRow(row)">
                    <span class="inline-block transition-transform" :class="isOpen(row) ? 'rotate-90' : ''" aria-hidden="true">›</span>
                  </button>
                </td>
                <td class="px-2 py-2 font-mono text-[13px] not-italic text-slate-500">{{ row.work_id }}</td>
                <td class="px-3 py-2">
                  <button type="button" class="break-words text-left font-medium hover:text-teal-700 hover:underline"
                          :class="row.is_break ? '' : 'text-slate-900'" @click="editing = { group, row }">{{ row.description }}</button>
                  <ProgressBar v-if="!row.is_break" class="mt-1 max-w-xs not-italic" :value="row.progress ?? 0" :label="`Progress of ${label(row)}`" />
                </td>
                <td class="whitespace-nowrap px-3 py-2">{{ formatDate(row.plannedDate) }}</td>
                <td class="whitespace-nowrap px-3 py-2">{{ row.actualDate ? formatDate(row.actualDate) : '-' }}</td>
                <td class="px-3 py-2"><StatusChip :status="row.is_break ? 'Break' : row.status" /></td>
                <td class="whitespace-nowrap px-3 py-2">{{ row.actualDate ? '' : formatDate(row.actualOrForecast) }}</td>
              </tr>
              <tr v-if="isOpen(row)" class="border-t border-slate-200 bg-slate-50">
                <td colspan="7" class="py-3 pl-12 pr-4">
                  <SubActivityList :activity="row" :today="today" @add="addSub(group, row)" @edit="sub => (subForm = { group, activity: row, sub })" />
                </td>
              </tr>
            </template>
          </tbody>
        </table>

        <!-- phone: cards -->
        <div class="space-y-3 border-t border-slate-200 bg-slate-50 p-3 lg:hidden">
          <div v-for="row in group.shownRows" :key="row.id" data-test="all-card" class="card p-4 text-sm"
               :class="row.is_break ? '!bg-slate-50 italic text-slate-500' : row.status === 'NEXT' ? '!border-amber-300 !bg-amber-50' : row.status === 'Done' ? '!bg-emerald-50/60' : ''">
            <span class="block font-mono text-[13px] not-italic text-slate-500">{{ row.work_id }}</span>
            <button type="button" class="break-words text-left font-semibold hover:text-teal-700 hover:underline"
                    :class="row.is_break ? '' : 'text-slate-900'" @click="editing = { group, row }">{{ row.description }}</button>
            <ProgressBar v-if="!row.is_break" class="mt-2 not-italic" :value="row.progress ?? 0" :label="`Progress of ${label(row)}`" />
            <dl class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5">
              <dt class="text-slate-500">Planned</dt><dd>{{ formatDate(row.plannedDate) }}</dd>
              <dt class="text-slate-500">Actual</dt><dd>{{ row.actualDate ? formatDate(row.actualDate) : '-' }}</dd>
              <template v-if="!row.actualDate"><dt class="text-slate-500">Forecast</dt><dd>{{ formatDate(row.actualOrForecast) }}</dd></template>
              <dt class="text-slate-500">Status</dt><dd><StatusChip :status="row.is_break ? 'Break' : row.status" /></dd>
            </dl>
            <template v-if="!row.is_break">
              <button type="button" data-test="toggle-subs" class="mt-3 flex min-h-11 w-full items-center justify-between rounded-lg border border-slate-200 bg-white px-3 font-semibold not-italic text-slate-700"
                      :aria-expanded="isOpen(row)" @click="toggleRow(row)">
                <span>Sub-activities ({{ row.sub_activities.length }})</span>
                <span class="inline-block transition-transform" :class="isOpen(row) ? 'rotate-90' : ''" aria-hidden="true">›</span>
              </button>
              <SubActivityList v-if="isOpen(row)" class="mt-3" :activity="row" :today="today" @add="addSub(group, row)" @edit="sub => (subForm = { group, activity: row, sub })" />
            </template>
          </div>
        </div>
      </template>
    </section>

    <ActivityFormModal :show="!!editing" :building="editing ? { id: editing.group.id, planned_start_date: editing.group.planned_start_date } : null"
                       :activity="editing ? latest(editing.group.id, editing.row.id) : null" :insert-after="null"
                       :rows="editing ? groupOf(editing.group.id).rows : []" :today="today" :earliest="editing?.group.earliest"
                       @close="editing = null" />
    <SubActivityFormModal :show="!!subForm" :activity="subActivity" :sub="subForm?.sub ?? null" :today="today"
                          :earliest="subForm?.group.earliest" @close="subForm = null" />
  </div>
</template>
