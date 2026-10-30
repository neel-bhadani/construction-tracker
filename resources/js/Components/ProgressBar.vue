<script setup>
/*
 | A horizontal bar with its % text: an activity's progress, or a group's.
 | value is 0 to 100, as the server gives it; it is shown, never computed here.
 */
defineProps({
  value: { type: Number, required: true },
  label: { type: String, default: 'Progress' },
  size: { type: String, default: 'sm' },   // sm: a row's bar; lg: a summary's
})
</script>

<template>
  <div class="flex items-center gap-2" data-test="progress-bar">
    <div class="flex-1 overflow-hidden rounded-full bg-slate-200" :class="size === 'lg' ? 'h-2.5' : 'h-1.5'"
         role="progressbar" :aria-label="label" :aria-valuenow="Math.round(value)" aria-valuemin="0" aria-valuemax="100">
      <div class="h-full rounded-full" :class="value >= 100 ? 'bg-emerald-600' : 'bg-teal-700'" :style="{ width: `${Math.min(100, value)}%` }" />
    </div>
    <span class="w-11 shrink-0 text-right text-[13px] font-semibold tabular-nums text-slate-700" data-test="progress-text">{{ Math.round(value) }}%</span>
  </div>
</template>
