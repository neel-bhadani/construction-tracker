<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ProjectFormModal from '@/Components/ProjectFormModal.vue'
import BuildingFormModal from '@/Components/BuildingFormModal.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import TimelineView from '@/Components/TimelineView.vue'
import AllActivities from '@/Components/AllActivities.vue'
import { formatDate, signed, percent, leadText } from '@/lib/format'
import { workIdHint } from '@/lib/projectCode'

/*
 | One project, in tabs, each at its own URL:
 | - Buildings: overall progress, a summary line and a card per building
 |   (progress, lead, forecast finish); a click on a card opens the building.
 | - Common activities: the project's own timeline of site-wide work, the same
 |   view a building's page has.
 | - All activities: every activity of the project in one list, grouped by
 |   timeline, with search and filters (groups: AllActivitiesPage output).
 | Every figure is ProjectSummaryService output, or TimelinePage's (timeline).
 */
const props = defineProps({
  project: Object,
  timezones: Array,
  copySources: Array,
  tab: { type: String, default: 'buildings' },
  timeline: { type: Object, default: null },
  groups: { type: Array, default: null },
  today: { type: String, default: null },
})

const tabs = computed(() => [
  { key: 'buildings', label: 'Buildings', href: `/projects/${props.project.id}` },
  { key: 'common', label: 'Common activities', href: `/projects/${props.project.id}/common` },
  { key: 'all', label: 'All activities', href: `/projects/${props.project.id}/activities` },
])
const tabLabel = computed(() => tabs.value.find(t => t.key === props.tab).label)

const editingProject = ref(false)
const editingBuilding = ref(undefined)   // undefined: closed, null: add, object: edit
const deleting = ref(null)
const deleteProcessing = ref(false)

const move = (building, direction) => router.patch(
  `/projects/${props.project.id}/buildings/${building.id}/move`, { direction }, { preserveScroll: true },
)

const open = building => router.visit(`/projects/${props.project.id}/buildings/${building.id}`)

const leadTone = lead => (lead === null ? 'text-slate-400' : lead > 0 ? 'text-teal-700' : lead < 0 ? 'text-rose-700' : 'text-slate-700')

const confirmDelete = () => {
  deleteProcessing.value = true
  router.delete(`/projects/${props.project.id}/buildings/${deleting.value.id}`, {
    preserveScroll: true,
    onSuccess: () => { deleting.value = null },
    onFinish: () => { deleteProcessing.value = false },
  })
}
</script>

<template>
  <AppLayout :title="tab === 'buildings' ? project.name : `${tabLabel} · ${project.name}`" :project-id="project.id">
    <template #breadcrumb>
      <nav class="mb-1 text-sm text-slate-500" aria-label="Breadcrumb">
        <Link href="/projects" class="hover:text-teal-700 hover:underline">Projects</Link>
        <span class="mx-1.5 text-slate-300">›</span>
        <span v-if="tab === 'buildings'" class="text-slate-700" aria-current="page">{{ project.name }}</span>
        <template v-else>
          <Link :href="`/projects/${project.id}`" class="hover:text-teal-700 hover:underline">{{ project.name }}</Link>
          <span class="mx-1.5 text-slate-300">›</span>
          <span class="text-slate-700" aria-current="page">{{ tabLabel }}</span>
        </template>
      </nav>
    </template>

    <div class="mb-5 flex flex-wrap items-center gap-3">
      <nav class="flex flex-wrap gap-1 rounded-xl border border-slate-200 bg-white p-1" aria-label="Project">
        <Link v-for="t in tabs" :key="t.key" :href="t.href" data-test="project-tab"
              class="inline-flex items-center rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors max-md:min-h-11"
              :class="t.key === tab ? 'bg-teal-700 text-white' : 'text-slate-600 hover:bg-slate-50'"
              :aria-current="t.key === tab ? 'page' : undefined">{{ t.label }}</Link>
      </nav>
      <span class="text-sm text-slate-500" data-test="work-id-prefix" :title="workIdHint(project.code)">Work ID prefix: <b class="font-mono text-slate-900" data-test="project-code">{{ project.code }}</b></span>
      <button class="btn-ghost ml-auto" @click="editingProject = true">Edit project</button>
    </div>

    <TimelineView v-if="tab === 'common' && timeline" :project-id="project.id" :building="timeline.building"
                  :metrics="timeline.metrics" :projection="timeline.projection" :chart="timeline.chart"
                  :activities="timeline.activities" :today="timeline.today" />

    <AllActivities v-if="tab === 'all' && groups" :project="project" :groups="groups" :today="today" />

    <template v-if="tab === 'buildings'">
      <!-- the whole project at a glance: work-days done across its buildings and common activities -->
      <section v-if="project.progress !== null" class="card mb-5 p-5" aria-label="Project progress">
        <div class="mb-2 flex items-baseline justify-between gap-3">
          <span class="text-sm font-semibold text-slate-700">Overall progress</span>
          <span class="text-2xl font-bold tracking-tight text-slate-900">{{ percent(project.progress) }}</span>
        </div>
        <div class="h-2.5 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label="Overall progress"
             :aria-valuenow="Math.round(project.progress * 100)" aria-valuemin="0" aria-valuemax="100">
          <div class="h-full rounded-full bg-teal-700" :style="{ width: `${Math.min(100, project.progress * 100)}%` }" />
        </div>
      </section>

      <div class="mb-5 flex flex-wrap items-center gap-x-4 gap-y-2">
        <p class="text-sm text-slate-600">
          {{ project.buildingsCount }} {{ project.buildingsCount === 1 ? 'building' : 'buildings' }}
          · <b class="text-slate-900">{{ project.progress === null ? '—' : percent(project.progress) }}</b> done
          <template v-if="project.mostBehind">
            · most behind: <b class="text-slate-900">{{ project.mostBehind.name }}</b>
            <b :class="project.mostBehind.lead >= 0 ? 'text-teal-700' : 'text-rose-700'">{{ signed(project.mostBehind.lead) }} d</b>
          </template>
          · expected handover <b class="text-slate-900">{{ formatDate(project.expectedHandover) }}</b>
        </p>
        <div class="ml-auto flex gap-2">
          <button class="btn" @click="editingBuilding = null">+ Add building</button>
        </div>
      </div>
      <p v-if="project.details" class="-mt-2 mb-5 whitespace-pre-line text-sm text-slate-500">{{ project.details }}</p>

      <!-- the common activities have their own tab; this line leads there -->
      <Link v-if="project.common" :href="`/projects/${project.id}/common`" data-test="common-summary"
            class="card mb-5 flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-sm transition-shadow hover:shadow-md">
        <span class="font-semibold text-slate-900">Common activities</span>
        <span class="text-slate-600">{{ project.common.activitiesCount }} activities · <b class="text-slate-900">{{ percent(project.common.progress) }}</b> done</span>
        <span class="text-slate-600">Lead <b :class="leadTone(project.common.currentLead)">{{ leadText(project.common.currentLead) }}</b></span>
        <span class="ml-auto font-semibold text-teal-700">Open →</span>
      </Link>

      <p v-if="!project.buildings.length" class="card p-7 text-sm text-slate-500">
        This project has no buildings yet.
        Add one with “+ Add building”: start it empty, copy another building's plan, or import it from Excel.
      </p>

      <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <!-- the whole card opens the building; its buttons do their own thing -->
        <div v-for="(b, index) in project.buildings" :key="b.id" data-test="building-card"
             class="card flex cursor-pointer flex-col p-5 transition-shadow hover:shadow-md"
             @click="open(b)">
          <div class="mb-3 flex items-start gap-2">
            <Link :href="`/projects/${project.id}/buildings/${b.id}`" class="min-w-0 flex-1 text-lg font-semibold text-slate-900 hover:text-teal-700" @click.stop>{{ b.name }}</Link>
          </div>

          <div class="mb-3">
            <div class="mb-1 flex justify-between text-xs text-slate-500">
              <span>{{ percent(b.progress) }} done</span>
              <span>{{ b.daysCompleted }} / {{ b.totalPlannedDays }} days</span>
            </div>
            <div class="h-1.5 overflow-hidden rounded-full bg-slate-100">
              <div class="h-full rounded-full bg-teal-700" :style="{ width: `${Math.min(100, b.progress * 100)}%` }" />
            </div>
          </div>

          <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
            <dt class="text-slate-500">Lead</dt>
            <dd class="font-semibold" :class="leadTone(b.currentLead)">{{ leadText(b.currentLead) }}</dd>
            <dt class="text-slate-500">Next</dt>
            <dd class="truncate text-slate-900" :title="b.next">{{ b.next ?? (b.activitiesCount ? 'All done' : '—') }}</dd>
            <dt class="text-slate-500">Forecast finish</dt>
            <dd class="font-semibold text-slate-900">{{ formatDate(b.expectedHandover) }}</dd>
          </dl>

          <div class="mt-auto flex flex-wrap gap-2 pt-4" @click.stop>
            <Link :href="`/projects/${project.id}/buildings/${b.id}`" class="btn-ghost flex-1">Open timeline</Link>
            <button class="btn-ghost !px-2.5" :disabled="index === 0" title="Move up" aria-label="Move up" @click="move(b, 'up')">↑</button>
            <button class="btn-ghost !px-2.5" :disabled="index === project.buildings.length - 1" title="Move down" aria-label="Move down" @click="move(b, 'down')">↓</button>
            <button class="btn-ghost" @click="editingBuilding = b">Edit</button>
            <button class="btn-ghost hover:!text-rose-700" @click="deleting = b">Delete</button>
          </div>
        </div>
      </div>
    </template>

    <ProjectFormModal :show="editingProject" :project="project" :timezones="timezones"
                      @close="editingProject = false" />
    <BuildingFormModal :show="editingBuilding !== undefined" :project="project"
                       :building="editingBuilding" :copy-sources="copySources" @close="editingBuilding = undefined" />
    <ConfirmDialog :show="!!deleting" title="Delete building" :processing="deleteProcessing"
                   :message="deleting ? `Delete [${deleting.name}]? Its timeline leaves the app; its ${deleting.activitiesCount} activities and their history are kept.` : ''"
                   @close="deleting = null" @confirm="confirmDelete" />
  </AppLayout>
</template>
