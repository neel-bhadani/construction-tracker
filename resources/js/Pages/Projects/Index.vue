<script setup>
import { ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ProjectFormModal from '@/Components/ProjectFormModal.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import { formatDate, signed, percent } from '@/lib/format'

/*
 | Home after sign-in. Every figure is ProjectSummaryService output: % done is
 | weighted by work-days across the project's buildings, most behind is the
 | lowest current lead, expected handover the latest building forecast.
 */
defineProps({ projects: Array, timezones: Array, defaultTimezone: String })

const page = usePage()
const editing = ref(undefined)     // undefined: closed, null: new, object: edit
const deleting = ref(null)
const deleteError = ref('')
const deleteProcessing = ref(false)

const openDelete = project => { deleteError.value = ''; deleting.value = project }

const deleteMessage = project => project.buildingsCount
  ? `[${project.name}] still has buildings: ${project.buildings.map(b => b.name).join(', ')}. Delete them first.`
  : `Delete [${project.name}]? Its history is kept in the audit trail.`

const confirmDelete = () => {
  deleteProcessing.value = true
  router.delete(`/projects/${deleting.value.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      if (page.props.errors.project) deleteError.value = page.props.errors.project
      else deleting.value = null
    },
    onError: errors => { deleteError.value = errors.project ?? 'The project could not be deleted.' },
    onFinish: () => { deleteProcessing.value = false },
  })
}
</script>

<template>
  <AppLayout title="Projects">
    <div class="-mt-12 mb-5 flex justify-end">
      <button class="btn" @click="editing = null">+ New project</button>
    </div>

    <p v-if="!projects.length" class="card p-7 text-sm text-slate-500">
      <span class="block text-lg font-semibold text-slate-900">No projects yet.</span>
      Create one with “+ New project”, then add its buildings.
    </p>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <div v-for="p in projects" :key="p.id" class="card flex flex-col p-5">
        <div class="mb-3 flex items-start gap-2">
          <Link :href="`/projects/${p.id}`" class="min-w-0 flex-1 text-lg font-semibold text-slate-900 hover:text-teal-700">{{ p.name }}</Link>
          <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
            {{ p.buildingsCount }} {{ p.buildingsCount === 1 ? 'building' : 'buildings' }}
          </span>
        </div>
        <p v-if="p.details" class="mb-3 line-clamp-2 text-sm text-slate-500">{{ p.details }}</p>

        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          <dt class="text-slate-500">Done</dt>
          <dd class="font-semibold text-slate-900">{{ p.progress === null ? '—' : percent(p.progress) }}</dd>
          <dt class="text-slate-500">Most behind</dt>
          <dd>
            <template v-if="p.mostBehind">
              {{ p.mostBehind.name }}
              <span class="font-semibold" :class="p.mostBehind.lead >= 0 ? 'text-teal-700' : 'text-rose-700'">{{ signed(p.mostBehind.lead) }} d</span>
            </template>
            <span v-else class="text-slate-400">No actuals yet</span>
          </dd>
          <dt class="text-slate-500">Expected handover</dt>
          <dd class="font-semibold text-slate-900">{{ formatDate(p.expectedHandover) }}</dd>
        </dl>

        <div class="mt-auto flex gap-2 pt-4">
          <Link :href="`/projects/${p.id}`" class="btn-ghost flex-1">Open</Link>
          <button class="btn-ghost" @click="editing = p">Edit</button>
          <button class="btn-ghost hover:!text-rose-700" @click="openDelete(p)">Delete</button>
        </div>
      </div>
    </div>

    <ProjectFormModal :show="editing !== undefined" :project="editing" :timezones="timezones"
                      :default-timezone="defaultTimezone" @close="editing = undefined" />
    <ConfirmDialog :show="!!deleting" title="Delete project" :processing="deleteProcessing"
                   :message="deleteError || (deleting ? deleteMessage(deleting) : '')"
                   @close="deleting = null" @confirm="confirmDelete" />
  </AppLayout>
</template>
