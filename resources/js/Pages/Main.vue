<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

/*
 | Placeholder. The main page — status row, chart, what-if, activity table —
 | replaces this in the next stage.
 */
const user = computed(() => usePage().props.auth.user)
</script>

<template>
  <AppLayout title="Timeline">
    <div class="card max-w-md p-6">
      <p class="text-sm text-slate-500">Signed in as</p>
      <p class="mt-1 text-lg font-semibold text-slate-900">{{ user.name }}</p>
      <p class="mt-0.5 text-sm capitalize text-teal-700">{{ user.role }}</p>
    </div>
  </AppLayout>
</template>
