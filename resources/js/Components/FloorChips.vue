<script setup>
import { computed } from 'vue'
import { activityFloorKey, floorKey, shortFloorName } from '@/lib/floors'

/*
 | All | Ground | 1st | … | Whole building, each with how many activities it
 | holds. The same filter as clicking a row of the Floors section.
 */
const props = defineProps({
  floors: Array,          // FloorProgress rows, "Whole building" last
  rows: Array,            // the activity rows: { floor_id }
  modelValue: { type: [String, Number], default: 'all' },
})
const emit = defineEmits(['update:modelValue'])

const chips = computed(() => {
  const counts = {}
  for (const row of props.rows) {
    const key = activityFloorKey(row)
    counts[key] = (counts[key] ?? 0) + 1
  }

  return [
    { key: 'all', label: 'All', count: props.rows.length },
    ...props.floors.map(floor => ({
      key: floorKey(floor),
      label: floor.id === null ? 'Whole building' : shortFloorName(floor.name),
      count: counts[floorKey(floor)] ?? 0,
    })),
  ]
})
</script>

<template>
  <div class="mb-3 flex flex-wrap gap-1.5" role="group" aria-label="Show activities on">
    <button v-for="chip in chips" :key="chip.key" type="button" data-test="floor-chip"
            class="rounded-full border px-3 py-1 text-sm font-medium transition-colors"
            :class="modelValue === chip.key ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'"
            :aria-pressed="modelValue === chip.key" @click="emit('update:modelValue', chip.key)">
      {{ chip.label }} <span class="ml-0.5 tabular-nums" :class="modelValue === chip.key ? 'text-teal-100' : 'text-slate-400'">{{ chip.count }}</span>
    </button>
  </div>
</template>
