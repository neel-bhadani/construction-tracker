<script setup>
defineProps({ status: { type: String, required: true } })

const styles = {
  Done: 'bg-emerald-100 text-emerald-800',
  'In progress': 'bg-sky-100 text-sky-800',
  Behind: 'bg-rose-100 text-rose-800',
  'Not started': 'bg-slate-100 text-slate-600',
}
</script>

<template>
  <span class="inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold"
        :class="styles[status]">{{ status }}</span>
</template>
