<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'

/*
 | The CRM's shell, as a top bar rather than a sidebar. Same slate-900 ground,
 | brand mark and user block, so it reads as the same family of software. The
 | project switcher jumps between projects from any page; the user block opens
 | a small menu with "My profile", "Help" and "Sign out".
 */
const props = defineProps({
  title: String,
  projectId: { type: Number, default: null },   // the project this page belongs to, if any
})

const page = usePage()
const user = computed(() => page.props.auth.user)
const projects = computed(() => page.props.projects ?? [])
const menuOpen = ref(false)
const menu = ref(null)

// closes on a click anywhere outside it, and on Escape
const closeOutside = event => { if (menu.value && !menu.value.contains(event.target)) menuOpen.value = false }
const closeOnEscape = event => { if (event.key === 'Escape') menuOpen.value = false }
onMounted(() => { document.addEventListener('click', closeOutside); document.addEventListener('keydown', closeOnEscape) })
onBeforeUnmount(() => { document.removeEventListener('click', closeOutside); document.removeEventListener('keydown', closeOnEscape) })

const initials = computed(() => {
  const parts = (user.value?.name ?? '').trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  const first = Array.from(parts[0])[0] ?? ''
  const last = parts.length > 1 ? Array.from(parts[parts.length - 1])[0] ?? '' : ''
  return (first + last).toUpperCase()
})

const switchProject = event => {
  const id = event.target.value
  router.visit(id ? `/projects/${id}` : '/projects')
}
</script>

<template>
  <div class="min-h-screen bg-slate-100">
    <!-- the tab title comes from the same prop as the heading, so the two cannot drift apart -->
    <Head :title="title" />

    <header class="sticky top-0 z-30 bg-slate-900 text-white">
      <div class="mx-auto flex h-14 max-w-7xl items-center gap-3 px-4 sm:px-6">
        <Link href="/projects" class="flex shrink-0 items-center gap-2.5 font-bold">
          <img src="/images/brand/shaligram-mark.png" alt="" class="h-7 w-7 shrink-0" />
          <span class="hidden sm:inline">Shaligram Timeline</span>
        </Link>

        <!-- project switcher -->
        <label v-if="projects.length" class="ml-2 min-w-0">
          <span class="sr-only">Project</span>
          <select :value="projectId ?? ''" class="!min-h-0 max-w-[14rem] !border-white/15 !bg-slate-800 !py-1.5 !pl-3 !text-sm !text-white"
                  @change="switchProject">
            <option value="">All projects</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </label>

        <div v-if="user" ref="menu" class="relative ml-auto">
          <button type="button" class="flex items-center gap-3 rounded-lg px-2 py-1 transition-colors hover:bg-white/5"
                  aria-haspopup="menu" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-teal-700 text-xs font-semibold">
              {{ initials }}
            </span>
            <span class="hidden max-w-[12rem] truncate text-sm font-semibold md:block">{{ user.name }}</span>
            <span class="text-xs text-slate-400" aria-hidden="true">▾</span>
          </button>
          <div v-if="menuOpen" role="menu"
               class="absolute right-0 mt-2 w-48 overflow-hidden rounded-lg bg-white py-1 text-sm text-slate-700 shadow-lg ring-1 ring-slate-900/10">
            <div class="truncate border-b border-slate-100 px-4 py-2 text-xs text-slate-500 md:hidden">{{ user.name }}</div>
            <Link href="/profile" role="menuitem" class="block px-4 py-2 hover:bg-slate-50" @click="menuOpen = false">My profile</Link>
            <Link href="/help" role="menuitem" class="block px-4 py-2 hover:bg-slate-50" @click="menuOpen = false">Help</Link>
            <Link href="/logout" method="post" as="button" role="menuitem" class="block w-full px-4 py-2 text-left hover:bg-slate-50">
              Sign out
            </Link>
          </div>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
      <slot name="breadcrumb" />
      <h1 v-if="title" class="mb-5 text-xl font-semibold tracking-tight text-slate-900">{{ title }}</h1>
      <slot />
    </main>
  </div>
</template>
