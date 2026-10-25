/*
 | ECharts, only what the progress chart draws. Imported dynamically by
 | ProgressChart.vue, so it is a chunk of its own, fetched only on a building page.
 */
import * as echarts from 'echarts/core'
import { LineChart } from 'echarts/charts'
import { GridComponent, TooltipComponent, MarkLineComponent } from 'echarts/components'
import { SVGRenderer } from 'echarts/renderers'

echarts.use([LineChart, GridComponent, TooltipComponent, MarkLineComponent, SVGRenderer])

export const init = (element) => echarts.init(element, null, { renderer: 'svg' })
