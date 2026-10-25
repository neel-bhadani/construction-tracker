<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'

/*
 | The CRM's shell, as a top bar rather than a sidebar: this app has one page,
 | and a sidebar holding one link is clutter. Same slate-900 ground, brand mark
 | and user block, so it reads as the same family of software.
 */
defineProps({ title: String })

const page = usePage()
const user = computed(() => page.props.auth.user)

const initials = computed(() => {
  const parts = (user.value?.name ?? '').trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  const first = Array.from(parts[0])[0] ?? ''
  const last = parts.length > 1 ? Array.from(parts[parts.length - 1])[0] ?? '' : ''
  return (first + last).toUpperCase()
})

const roleLabel = computed(() => {
  const role = (user.value?.role ?? '').trim()
  return role ? role.charAt(0).toUpperCase() + role.slice(1) : ''
})
</script>

<template>
  <div class="min-h-screen bg-slate-100">
    <!-- the tab title comes from the same prop as the heading, so the two cannot drift apart -->
    <Head :title="title" />

    <header class="sticky top-0 z-30 bg-slate-900 text-white">
      <div class="mx-auto flex h-14 max-w-7xl items-center gap-3 px-4 sm:px-6">
        <Link href="/" class="flex items-center gap-2.5 font-bold">
          <img src="/images/brand/shaligram-mark.png" alt="" class="h-7 w-7 shrink-0" /> Shaligram Timeline
        </Link>

        <div v-if="user" class="ml-auto flex items-center gap-3">
          <!-- page-level actions, e.g. the admin's pending-users button -->
          <slot name="actions" />
          <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-teal-700 text-xs font-semibold">
            {{ initials }}
          </div>
          <div class="hidden min-w-0 leading-tight sm:block">
            <div class="truncate text-sm font-semibold">{{ user.name }}</div>
            <div class="text-xs text-slate-400">{{ roleLabel }}</div>
          </div>
          <Link href="/logout" method="post" as="button"
                class="ml-2 rounded-lg px-3 py-1.5 text-sm font-medium text-slate-400 transition-colors hover:bg-white/5 hover:text-white">
            Sign out
          </Link>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
      <h1 v-if="title" class="mb-5 text-xl font-semibold tracking-tight text-slate-900">{{ title }}</h1>
      <slot />
    </main>
  </div>
</template>
