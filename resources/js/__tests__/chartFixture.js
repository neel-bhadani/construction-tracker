// The hand-made plan from BuildingChartTest, one actual (A on 9 Jan), as the server sends it.
export function chartWithOneActual() {
  return {
    hasActivities: true,
    hasActuals: true,
    series: {
      planned: [
        { date: '2026-01-01', percent: 0, activities: [] },
        { date: '2026-01-11', percent: 0.25, activities: ['A'] },
        { date: '2026-01-19', percent: 0.375, activities: ['C'] },
        { date: '2026-01-31', percent: 0.75, activities: ['B'] },
        { date: '2026-02-10', percent: 1, activities: ['D'] },
      ],
      actual: [
        { date: '2026-01-01', percent: 0, activities: [] },
        { date: '2026-01-09', percent: 0.25, activities: ['A'] },
      ],
      averagePace: [
        { date: '2026-01-09', percent: 0.25, activities: [] },
        { date: '2026-02-02', percent: 1, activities: [] },
      ],
      recentPace: [
        { date: '2026-01-09', percent: 0.25, activities: [] },
        { date: '2026-02-02', percent: 1, activities: [] },
      ],
      holdLead: [
        { date: '2026-01-09', percent: 0.25, activities: ['A'] },
        { date: '2026-01-17', percent: 0.375, activities: ['C'] },
        { date: '2026-01-29', percent: 0.75, activities: ['B'] },
        { date: '2026-02-08', percent: 1, activities: ['D'] },
      ],
    },
    finishes: { planned: '2026-02-10', averagePace: '2026-02-02', recentPace: '2026-02-02', holdLead: '2026-02-08' },
    today: '2026-10-15',
    ranges: { full: { start: '2026-01-01', end: '2026-03-10' }, firstYear: { start: '2026-01-01', end: '2027-01-01' } },
  }
}

export function chartWithNoActuals() {
  const chart = chartWithOneActual()
  chart.hasActuals = false
  for (const key of ['actual', 'averagePace', 'recentPace', 'holdLead']) chart.series[key] = null
  for (const key of ['averagePace', 'recentPace', 'holdLead']) chart.finishes[key] = null
  return chart
}

export function chartWithNoActivities() {
  const chart = chartWithNoActuals()
  chart.hasActivities = false
  chart.series.planned = [{ date: '2026-01-01', percent: 0, activities: [] }]
  chart.finishes.planned = null
  return chart
}
