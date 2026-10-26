<script setup>
defineProps({ status: { type: String, required: true } })

const styles = {
  Done: 'rounded-full bg-emerald-100 px-2.5 py-0.5 text-emerald-800',
  NEXT: 'rounded-full bg-amber-100 px-2.5 py-0.5 text-amber-900',
  Pending: 'text-slate-400',
  Break: 'font-normal italic text-slate-500',
}
</script>

<template>
  <span class="inline-flex items-center text-sm font-semibold uppercase tracking-wide"
        :class="styles[status]">{{ status }}</span>
</template>
