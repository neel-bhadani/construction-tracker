<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import RowMenu from './RowMenu.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import FloorFormModal from './FloorFormModal.vue'
import FloorStatusBadge from './FloorStatusBadge.vue'
import { formatDate, percent } from '@/lib/format'
import { deleteFloorMessage, floorKey } from '@/lib/floors'

/*
 | The building's floors, above the activity list: one row per floor, lowest
 | first, then "Whole building". Every figure is ProgressService output.
 | Clicking a row filters the list to it; clicking it again shows everything.
 |
 | A building without floors shows only a small "+ Add floors" link, so its
 | page looks as it did before floors existed.
 */
const props = defineProps({
  floors: Array,          // FloorProgress rows, "Whole building" last; empty without floors
  projectId: Number,
  buildingId: Number,
  active: { type: [String, Number], default: 'all' },   // 'all', 'whole' or a floor id
})
const emit = defineEmits(['select'])

const realFloors = computed(() => props.floors.filter(f => f.id !== null))

const adding = ref(false)
const renaming = ref(null)
const deleting = ref(null)
const deleteProcessing = ref(false)

const select = floor => emit('select', props.active === floorKey(floor) ? 'all' : floorKey(floor))

const menuItems = floor => [
  { label: 'Rename', action: () => { renaming.value = floor } },
  { label: 'Delete', action: () => { deleting.value = floor }, danger: true },
]

const confirmDelete = () => {
  const floor = deleting.value
  deleteProcessing.value = true
  router.delete(`/projects/${props.projectId}/buildings/${props.buildingId}/floors/${floor.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      deleting.value = null
      if (props.active === floor.id) emit('select', 'all')
    },
    onFinish: () => { deleteProcessing.value = false },
  })
}

// one activity on the floor: its state in words, not a 0% / 100% bar
const single = floor => floor.activitiesCount === 1
const finish = floor => floor.actualFinish
  ? { label: 'Actual', date: floor.actualFinish }
  : floor.forecastFinish ? { label: 'Forecast', date: floor.forecastFinish } : null
</script>

<template>
  <section v-if="floors.length" class="mb-6" aria-labelledby="floors-heading">
    <div class="mb-2 flex items-center gap-3">
      <h2 id="floors-heading" class="text-base font-semibold text-slate-900">Floors</h2>
      <button type="button" class="ml-auto text-sm font-semibold text-teal-700 hover:underline" @click="adding = true">+ Add floors</button>
    </div>

    <div class="card overflow-hidden">
      <!-- column heads, wide screens only; below 1024 px each floor is a card -->
      <div class="hidden grid-cols-[minmax(0,1fr)_10rem_6.5rem_7.5rem_9.5rem_6.5rem_2.5rem] gap-3 border-b border-slate-200 px-4 py-2.5 text-xs font-semibold text-slate-500 lg:grid">
        <span>Floor</span><span>Progress</span><span>Done</span><span>Planned finish</span><span>Actual / forecast</span><span>Status</span><span />
      </div>

      <div v-for="floor in floors" :key="floor.id ?? 'whole'" data-test="floor-row"
           class="cursor-pointer border-b border-slate-100 px-4 py-3 last:border-0 hover:bg-slate-50 lg:grid lg:grid-cols-[minmax(0,1fr)_10rem_6.5rem_7.5rem_9.5rem_6.5rem_2.5rem] lg:items-center lg:gap-3 lg:py-2.5"
           :class="active === floorKey(floor) ? 'bg-teal-50 ring-1 ring-inset ring-teal-300 hover:!bg-teal-50' : ''"
           @click="select(floor)">
        <!-- name; on cards the status and menu sit beside it -->
        <div class="flex min-w-0 items-center gap-2">
          <button type="button" class="min-w-0 truncate text-left font-medium text-slate-900"
                  :class="floor.id === null ? 'italic text-slate-600' : ''"
                  :aria-pressed="active === floorKey(floor)" :title="`Show only ${floor.name}`"
                  @click.stop="select(floor)">{{ floor.name }}</button>
          <span class="ml-auto flex items-center gap-2 lg:hidden" @click.stop>
            <FloorStatusBadge :status="floor.status" />
            <RowMenu v-if="floor.id !== null" :items="menuItems(floor)" :label="`More for ${floor.name}`" />
          </span>
        </div>

        <div class="mt-2 lg:mt-0">
          <span v-if="single(floor)" class="text-sm text-slate-600">{{ floor.doneCount ? 'Done' : 'Not started' }}</span>
          <span v-else-if="floor.progress === null" class="text-sm text-slate-400">—</span>
          <div v-else class="flex items-center gap-2" :aria-label="`${percent(floor.progress)} done`">
            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
              <div class="h-full rounded-full bg-teal-700" :style="{ width: `${Math.min(100, floor.progress * 100)}%` }" />
            </div>
            <span class="w-12 text-right text-xs tabular-nums text-slate-600">{{ percent(floor.progress) }}</span>
          </div>
        </div>

        <!-- cards: the three figures in a row under the bar -->
        <dl class="mt-2 grid grid-cols-3 gap-2 text-xs lg:contents lg:text-sm">
          <div class="lg:block">
            <dt class="text-slate-500 lg:sr-only">Done</dt>
            <dd class="text-slate-700">{{ floor.doneCount }} of {{ floor.activitiesCount }} done</dd>
          </div>
          <div>
            <dt class="text-slate-500 lg:sr-only">Planned finish</dt>
            <dd class="text-slate-700">{{ formatDate(floor.plannedFinish) }}</dd>
          </div>
          <div>
            <dt class="text-slate-500 lg:sr-only">{{ finish(floor)?.label ?? 'Finish' }}</dt>
            <dd class="text-slate-700">
              <template v-if="finish(floor)"><span class="hidden text-slate-500 lg:inline">{{ `${finish(floor).label} ` }}</span>{{ formatDate(finish(floor).date) }}</template>
              <template v-else>—</template>
            </dd>
          </div>
        </dl>

        <span class="hidden lg:block"><FloorStatusBadge :status="floor.status" /></span>

        <span class="hidden justify-end lg:flex" @click.stop>
          <RowMenu v-if="floor.id !== null" :items="menuItems(floor)" :label="`More for ${floor.name}`" />
        </span>
      </div>
    </div>
  </section>

  <div v-else class="mb-3 flex justify-end">
    <button type="button" class="text-sm font-semibold text-teal-700 hover:underline" @click="adding = true">+ Add floors</button>
  </div>

  <FloorFormModal :show="adding || !!renaming" :project-id="projectId" :building-id="buildingId"
                  :floor="renaming" :existing="realFloors" @close="adding = false; renaming = null" />
  <ConfirmDialog :show="!!deleting" title="Delete floor" :processing="deleteProcessing"
                 :message="deleting ? deleteFloorMessage(deleting) : ''"
                 @close="deleting = null" @confirm="confirmDelete" />
</template>
