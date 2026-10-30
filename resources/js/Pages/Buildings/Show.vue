<script setup>
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import TimelineView from '@/Components/TimelineView.vue'

/*
 | One building's timeline (TimelineView), with the project's building tabs.
 | Only the project's owner ever reaches it, and every endpoint checks that
 | again. The Common activities are a tab of the project page instead.
 */
defineProps({
  project: Object,
  buildings: Array,
  building: Object,
  metrics: Object,
  projection: Object,
  chart: Object,
  activities: Array,
  today: String,
})
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

    <TimelineView :project-id="project.id" :building="building" :metrics="metrics" :projection="projection"
                  :chart="chart" :activities="activities" :today="today">
      <!-- building selector: each building has its own URL, so a link opens the same view -->
      <template #nav>
        <nav class="flex flex-wrap gap-1 rounded-xl border border-slate-200 bg-white p-1" aria-label="Building">
          <Link v-for="b in buildings" :key="b.id" :href="`/projects/${project.id}/buildings/${b.id}`"
                class="rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors"
                :class="b.id === building.id ? 'bg-teal-700 text-white' : 'text-slate-600 hover:bg-slate-50'"
                :aria-current="b.id === building.id ? 'page' : undefined">{{ b.name }}</Link>
        </nav>
      </template>
    </TimelineView>
  </AppLayout>
</template>
