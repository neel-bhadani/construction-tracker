<script setup>
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { SERIES, availableSeries, buildOption, defaultVisibility, legendLabel } from '@/lib/progressChart'

/*
 | "Planned vs actual progress": the client's Chart-sheet graph, drawn from
 | BuildingChartService::chart(). ECharts is fetched only when this mounts. The
 | chart is a page prop, so recording or clearing an actual — an Inertia visit,
 | not a page load — hands it new points and it redraws in place.
 */
const props = defineProps({ chart: { type: Object, required: true } })

const range = ref('full')
const visible = ref(defaultVisibility())
const element = ref(null)
const instance = shallowRef(null)
const failed = ref(false)
let resizeObserver = null

const series = computed(() => availableSeries(props.chart))
const legend = computed(() => series.value.map(s => ({ ...s, text: legendLabel(s.key, props.chart) })))

function draw() {
  if (!instance.value) return
  instance.value.setOption(buildOption(props.chart, { range: range.value, visible: visible.value }), { notMerge: true })
}

async function mount() {
  if (!props.chart.hasActivities || instance.value || !element.value) return

  try {
    const { init } = await import('@/lib/echarts')
    instance.value = init(element.value)
    draw()
    resizeObserver = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(() => instance.value?.resize())
    resizeObserver?.observe(element.value)
  } catch {
    failed.value = true
  }
}

onMounted(mount)
onBeforeUnmount(() => unmount())

function unmount() {
  resizeObserver?.disconnect()
  resizeObserver = null
  instance.value?.dispose()
  instance.value = null
}

watch(() => props.chart, (chart) => {
  if (!chart.hasActivities) unmount()
  else if (instance.value) draw()
  else mount()
}, { deep: true, flush: 'post' })
watch([range, visible], draw, { deep: true })
</script>

<template>
  <section class="card mb-6 p-4 sm:p-5" aria-labelledby="progress-chart-title">
    <div class="mb-3 flex flex-wrap items-center gap-3">
      <h2 id="progress-chart-title" class="text-xl font-semibold text-slate-900">Planned vs actual progress</h2>

      <div v-if="chart.hasActivities" class="ml-auto flex rounded-lg border border-slate-200 p-0.5 text-sm" role="group" aria-label="Time range">
        <button v-for="r in [{ key: 'full', label: 'Full timeline' }, { key: 'firstYear', label: 'First 12 months' }]" :key="r.key"
                type="button" class="rounded-md px-3 py-1 font-medium transition-colors"
                :class="range === r.key ? 'bg-teal-700 text-white' : 'text-slate-600 hover:bg-slate-50'"
                :aria-pressed="range === r.key" :data-range="r.key" @click="range = r.key">{{ r.label }}</button>
      </div>
    </div>

    <p v-if="!chart.hasActivities" class="py-8 text-center text-sm text-slate-500" data-empty>
      No activities yet. The chart appears once this building has a plan.
    </p>

    <template v-else>
      <!-- the legend is ours, not ECharts': it wraps on a phone and each line has a real tickbox -->
      <div class="mb-2 flex flex-wrap gap-x-4 gap-y-1.5 text-[13px] text-slate-700">
        <label v-for="s in legend" :key="s.key" class="flex cursor-pointer items-center gap-2" :data-series="s.key">
          <input v-model="visible[s.key]" type="checkbox" class="!min-h-0 !w-auto rounded" />
          <svg width="22" height="8" aria-hidden="true">
            <line x1="1" y1="4" x2="21" y2="4" :stroke="s.color" :stroke-width="Math.min(s.width, 3)"
                  :stroke-dasharray="Array.isArray(s.dash) ? s.dash.join(' ') : null" />
          </svg>
          {{ s.text }}
        </label>
        <span class="flex items-center gap-2 text-slate-500">
          <svg width="22" height="8" aria-hidden="true"><line x1="11" y1="0" x2="11" y2="8" stroke="#0f766e" stroke-width="2" /></svg>
          Today
        </span>
      </div>

      <p v-if="!chart.hasActuals" class="mb-2 text-xs text-slate-500" data-no-actuals>
        Nothing finished yet: the actual line and the projections appear with the first actual date.
      </p>

      <div ref="element" class="h-[300px] w-full sm:h-[360px]" role="img"
           :aria-label="`Planned versus actual progress, ${range === 'full' ? 'full timeline' : 'first 12 months'}`" />
      <p v-if="failed" class="text-sm text-rose-700">The chart could not be loaded. Refresh the page to try again.</p>
    </template>
  </section>
</template>
