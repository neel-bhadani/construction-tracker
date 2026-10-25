/*
 | The progress chart's ECharts option, built from BuildingChartService::chart().
 | Every point is the server's; this file only maps them onto lines, picks the
 | axis window and formats the tooltip. The colours and dashes are the client's
 | own Excel chart's.
 */
import { formatDate } from './format'

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const DAY = 24 * 3600 * 1000

export const SERIES = [
  { key: 'planned', label: 'Planned', color: '#2E75B6', width: 2, dash: 'solid', symbolSize: 5, visible: true },
  { key: 'actual', label: 'Actual', color: '#00A651', width: 3.5, dash: 'solid', symbolSize: 7, visible: true },
  { key: 'averagePace', label: 'Average pace', color: '#ED7D31', width: 2, dash: [6, 4], symbolSize: 5, visible: false },
  { key: 'recentPace', label: 'Recent pace', color: '#C00000', width: 2, dash: [2, 3], symbolSize: 5, visible: false },
  { key: 'holdLead', label: 'Hold current lead', color: '#7030A0', width: 2.5, dash: [6, 4], symbolSize: 5, visible: true },
]

const PROJECTIONS = ['averagePace', 'recentPace', 'holdLead']

/** Tickbox state on first draw: average and recent pace off, the rest on. */
export function defaultVisibility() {
  return Object.fromEntries(SERIES.map(s => [s.key, s.visible]))
}

/** The series this chart can show: with no actual date, only the plan. */
export function availableSeries(chart) {
  return SERIES.filter(s => chart.series[s.key] !== null && chart.series[s.key] !== undefined)
}

/** A projection's legend label carries its finish, which may lie beyond the axis. */
export function legendLabel(key, chart) {
  const series = SERIES.find(s => s.key === key)
  return PROJECTIONS.includes(key) && chart.finishes[key]
    ? `${series.label} – finish ${formatDate(chart.finishes[key])}`
    : series.label
}

/** 'YYYY-MM-DD' as a local-midnight timestamp, the way ECharts reads the same string. */
function time(ymd) {
  const [y, m, d] = ymd.split('-').map(Number)
  return new Date(y, m - 1, d).getTime()
}

/**
 * The y-axis top, in percent. The full timeline always shows 0–100%. The first
 * twelve months show 0–50%, as the client's zoom chart does, unless a visible
 * point inside that window is above 50%.
 */
export function yAxisMax(chart, range, visible) {
  if (range !== 'firstYear') return 100

  const { start, end } = chart.ranges.firstYear
  const above = availableSeries(chart)
    .filter(s => visible[s.key])
    .some(s => chart.series[s.key].some(p => p.date >= start && p.date <= end && p.percent > 0.5))

  return above ? 100 : 50
}

/**
 * What the tooltip says at a date: each visible line's percentage there, and
 * the activities whose points sit on that date. The plan and the actuals are read
 * as the Calc sheet defines them — the percentage reached by that date, the last
 * point on or before it. A projection is listed only at its own points.
 */
export function tooltipAt(chart, visible, date) {
  const lines = []
  const activities = []

  for (const s of availableSeries(chart).filter(s => visible[s.key])) {
    const points = chart.series[s.key]
    const exact = points.filter(p => p.date === date)
    const reached = PROJECTIONS.includes(s.key) ? exact.at(-1) : points.filter(p => p.date <= date).at(-1)

    if (reached) lines.push({ key: s.key, label: s.label, color: s.color, percent: reached.percent })
    for (const p of exact) for (const name of p.activities) if (!activities.includes(name)) activities.push(name)
  }

  return { date, lines, activities }
}

function escape(text) {
  return String(text).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])
}

export function tooltipHtml(chart, visible, date) {
  const { lines, activities } = tooltipAt(chart, visible, date)
  const rows = lines.map(l =>
    `<div style="display:flex;gap:8px;justify-content:space-between"><span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${l.color};margin-right:6px"></span>${escape(l.label)}</span><b>${(l.percent * 100).toFixed(1)}%</b></div>`).join('')
  const names = activities.length
    ? `<div style="margin-top:6px;color:#475569;white-space:normal">${activities.map(escape).join('<br>')}</div>`
    : ''

  return `<div style="font-weight:600;margin-bottom:4px">${escape(formatDate(date))}</div>${rows}${names}`
}

/** "Oct 2026" */
export function monthLabel(timestamp) {
  const d = new Date(timestamp)
  return `${MONTHS[d.getMonth()]} ${d.getFullYear()}`
}

export function buildOption(chart, { range, visible }) {
  const window = chart.ranges[range]
  const shown = availableSeries(chart).filter(s => visible[s.key])

  return {
    animation: false,
    grid: { left: 8, right: 16, top: 24, bottom: 8, containLabel: true },
    tooltip: {
      trigger: 'item',
      triggerOn: 'mousemove|click',   // a tap opens it on a phone
      confine: true,
      enterable: false,
      formatter: param => tooltipHtml(chart, visible, param.data.date),
    },
    xAxis: {
      type: 'time',
      min: time(window.start),
      max: time(window.end),
      // quarterly on the full timeline, monthly on the first twelve months
      minInterval: (range === 'firstYear' ? 28 : 89) * DAY,
      maxInterval: (range === 'firstYear' ? 31 : 92) * DAY,
      axisLabel: { formatter: monthLabel, hideOverlap: true, color: '#64748b' },
      splitLine: { show: true, lineStyle: { color: '#f1f5f9' } },
    },
    yAxis: {
      type: 'value',
      min: 0,
      max: yAxisMax(chart, range, visible),
      interval: range === 'firstYear' ? 10 : 20,
      axisLabel: { formatter: '{value}%', color: '#64748b' },
      splitLine: { lineStyle: { color: '#f1f5f9' } },
    },
    series: [
      ...shown.map(s => ({
        id: s.key,
        name: s.label,
        type: 'line',
        clip: true,
        color: s.color,
        symbol: 'circle',
        symbolSize: s.symbolSize,
        showSymbol: true,
        lineStyle: { width: s.width, type: s.dash, color: s.color },
        z: s.key === 'actual' ? 5 : 3,
        data: chart.series[s.key].map(p => ({ value: [p.date, +(p.percent * 100).toFixed(6)], date: p.date })),
      })),
      {
        id: 'markers',
        type: 'line',
        data: [],
        silent: true,
        markLine: {
          symbol: 'none',
          silent: true,
          label: { position: 'insideEndTop', color: '#475569', fontSize: 11 },
          data: [
            { xAxis: chart.today, name: 'Today', label: { formatter: 'Today' }, lineStyle: { color: '#0f766e', type: 'solid', width: 1.5 } },
            ...(chart.finishes.planned
              ? [{ xAxis: chart.finishes.planned, name: 'Planned finish', label: { formatter: 'Planned finish' }, lineStyle: { color: '#2E75B6', type: 'dashed', width: 1 } }]
              : []),
          ],
        },
      },
    ],
  }
}
