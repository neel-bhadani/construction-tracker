<script setup>
import { ref } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import PendingUsersModal from '@/Components/PendingUsersModal.vue'

/*
 | No scope exists yet: on a first deploy, before the workbook import has run.
 | An admin can still approve accounts from here.
 */
defineProps({ can: Object })

const showPending = ref(false)
</script>

<template>
  <AppLayout title="Timeline">
    <template #actions>
      <button v-if="can.approveUsers" type="button"
              class="rounded-lg px-3 py-1.5 text-sm font-medium text-slate-300 transition-colors hover:bg-white/5 hover:text-white"
              @click="showPending = true">Pending users</button>
    </template>

    <div class="card max-w-lg p-7">
      <p class="text-lg font-semibold text-slate-900">No timeline data yet.</p>
      <p class="mt-1.5 text-sm text-slate-500">
        The schedule appears here once the workbook has been imported.
      </p>
    </div>

    <PendingUsersModal v-if="can.approveUsers" :show="showPending" @close="showPending = false" />
  </AppLayout>
</template>
