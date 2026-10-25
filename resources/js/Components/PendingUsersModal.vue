<script setup>
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import { requestJson } from '@/lib/http'
import { formatDate } from '@/lib/format'

/*
 | Admin only. Lists accounts awaiting approval, from the stage 5 endpoints;
 | both are refused to anyone but an admin whatever this modal shows.
 */
const props = defineProps({ show: Boolean })
const emit = defineEmits(['close'])

const users = ref([])
const loading = ref(false)
const failed = ref(false)
const approving = ref(null)

async function load() {
  loading.value = true
  failed.value = false
  const { ok, data } = await requestJson('GET', '/users/pending')
  loading.value = false
  if (ok) users.value = data
  else failed.value = true
}

watch(() => props.show, (open) => { if (open) load() })

function approve(user) {
  approving.value = user.id
  router.patch(`/users/${user.id}/approve`, {}, {
    preserveScroll: true,
    preserveState: true,
    onFinish: () => { approving.value = null; load() },
  })
}
</script>

<template>
  <Modal :show="show" title="Accounts awaiting approval" max-width="max-w-lg" @close="emit('close')">
    <p v-if="loading && !users.length" class="text-sm text-slate-500">Loading…</p>
    <div v-else-if="failed" class="warn-box !text-sm">The list could not be loaded. Try again.</div>
    <p v-else-if="!users.length" class="text-sm text-slate-500">Nobody is waiting for approval.</p>

    <ul v-else class="divide-y divide-slate-100">
      <li v-for="u in users" :key="u.id" class="flex items-center gap-3 py-3">
        <div class="min-w-0 flex-1">
          <div class="truncate font-medium text-slate-900">{{ u.name }}</div>
          <div class="truncate text-xs text-slate-500">
            {{ u.email }} · signed up {{ formatDate(u.signed_up_at?.slice(0, 10)) }}
          </div>
        </div>
        <button class="btn" :disabled="approving === u.id" @click="approve(u)">
          {{ approving === u.id ? 'Approving…' : 'Approve' }}
        </button>
      </li>
    </ul>

    <p class="mt-4 text-xs text-slate-400">Approved accounts start as viewers.</p>
  </Modal>
</template>
