<script setup>
defineProps({ status: { type: String, required: true } })

const styles = {
  Done: 'bg-slate-100 text-slate-600',
  NEXT: 'bg-teal-700 text-white',
  Pending: 'bg-white text-slate-500 ring-1 ring-inset ring-slate-200',
}
</script>

<template>
  <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide"
        :class="styles[status]">{{ status }}</span>
</template>
