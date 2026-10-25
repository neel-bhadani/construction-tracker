import { describe, expect, it } from 'vitest'
import { availableSeries, buildOption, defaultVisibility, legendLabel, monthLabel, tooltipAt, tooltipHtml, yAxisMax } from '@/lib/progressChart'
import { chartWithNoActuals, chartWithOneActual } from './chartFixture'

describe('progress chart option', () => {
  it('starts with average and recent pace off, the rest on', () => {
    expect(defaultVisibility()).toEqual({ planned: true, actual: true, averagePace: false, recentPace: false, holdLead: true })
  })

  it('draws only the ticked lines, plus the Today and Planned finish markers', () => {
    const option = buildOption(chartWithOneActual(), { range: 'full', visible: defaultVisibility() })
    expect(option.series.map(s => s.id)).toEqual(['planned', 'actual', 'holdLead', 'markers'])
    expect(option.series.at(-1).markLine.data.map(m => [m.name, m.xAxis])).toEqual([['Today', '2026-10-15'], ['Planned finish', '2026-02-10']])
  })

  it('plots the server percentages unchanged, as 0–100', () => {
    const planned = buildOption(chartWithOneActual(), { range: 'full', visible: defaultVisibility() }).series[0]
    expect(planned.data.map(d => d.value)).toEqual([['2026-01-01', 0], ['2026-01-11', 25], ['2026-01-19', 37.5], ['2026-01-31', 75], ['2026-02-10', 100]])
  })

  it('uses the client chart styles: solid plan, thicker solid actual, dashed projections', () => {
    const all = { planned: true, actual: true, averagePace: true, recentPace: true, holdLead: true }
    const styles = Object.fromEntries(buildOption(chartWithOneActual(), { range: 'full', visible: all }).series
      .filter(s => s.id !== 'markers').map(s => [s.id, [s.lineStyle.color, s.lineStyle.type]]))
    expect(styles).toEqual({
      planned: ['#2E75B6', 'solid'], actual: ['#00A651', 'solid'], averagePace: ['#ED7D31', [6, 4]],
      recentPace: ['#C00000', [2, 3]], holdLead: ['#7030A0', [6, 4]],
    })
  })

  it('switches the x window between the full timeline and the first twelve months', () => {
    const chart = chartWithOneActual()
    const full = buildOption(chart, { range: 'full', visible: defaultVisibility() }).xAxis
    const year = buildOption(chart, { range: 'firstYear', visible: defaultVisibility() }).xAxis
    expect([full.min, full.max]).toEqual([new Date(2026, 0, 1).getTime(), new Date(2026, 2, 10).getTime()])
    expect([year.min, year.max]).toEqual([new Date(2026, 0, 1).getTime(), new Date(2027, 0, 1).getTime()])
  })

  it('shows 0–50% on the first twelve months unless a visible point there is above 50%', () => {
    const chart = chartWithOneActual()
    const onlyActual = { planned: false, actual: true, averagePace: false, recentPace: false, holdLead: false }
    expect(yAxisMax(chart, 'firstYear', onlyActual)).toBe(50)
    expect(yAxisMax(chart, 'firstYear', defaultVisibility())).toBe(100)
    expect(yAxisMax(chart, 'full', onlyActual)).toBe(100)

    // a point above 50% after the window does not count
    const late = chartWithOneActual()
    late.ranges.firstYear.end = '2026-01-20'
    expect(yAxisMax(late, 'firstYear', { ...onlyActual, planned: true })).toBe(50)
  })

  it('labels each projection with its finish, even one beyond the axis', () => {
    const chart = chartWithOneActual()
    chart.finishes.averagePace = '2030-02-03'
    expect(legendLabel('averagePace', chart)).toBe('Average pace – finish 3 Feb 2030')
    expect(legendLabel('planned', chart)).toBe('Planned')
  })

  it('offers only the plan when nothing is finished', () => {
    expect(availableSeries(chartWithNoActuals()).map(s => s.key)).toEqual(['planned'])
    const option = buildOption(chartWithNoActuals(), { range: 'full', visible: defaultVisibility() })
    expect(option.series.map(s => s.id)).toEqual(['planned', 'markers'])
  })

  it('reads the tooltip the way the Calc sheet does: the percentage reached by that date', () => {
    const chart = chartWithOneActual()
    const all = { planned: true, actual: true, averagePace: true, recentPace: true, holdLead: true }

    expect(tooltipAt(chart, all, '2026-01-09')).toEqual({
      date: '2026-01-09',
      lines: [
        { key: 'planned', label: 'Planned', color: '#2E75B6', percent: 0 },
        { key: 'actual', label: 'Actual', color: '#00A651', percent: 0.25 },
        { key: 'averagePace', label: 'Average pace', color: '#ED7D31', percent: 0.25 },
        { key: 'recentPace', label: 'Recent pace', color: '#C00000', percent: 0.25 },
        { key: 'holdLead', label: 'Hold current lead', color: '#7030A0', percent: 0.25 },
      ],
      activities: ['A'],
    })
    // hidden lines say nothing
    expect(tooltipAt(chart, defaultVisibility(), '2026-01-31').lines.map(l => l.key)).toEqual(['planned', 'actual'])
  })

  it('escapes activity names in the tooltip', () => {
    const chart = chartWithOneActual()
    chart.series.planned[1].activities = ['<b>A & B</b>']
    expect(tooltipHtml(chart, defaultVisibility(), '2026-01-11')).toContain('&lt;b&gt;A &amp; B&lt;/b&gt;')
  })

  it('confines the tooltip to the chart and opens it on a tap', () => {
    const { tooltip } = buildOption(chartWithOneActual(), { range: 'full', visible: defaultVisibility() })
    expect([tooltip.confine, tooltip.triggerOn]).toEqual([true, 'mousemove|click'])
  })

  it('labels the date axis like "Oct 2026"', () => {
    expect(monthLabel(new Date(2026, 9, 1).getTime())).toBe('Oct 2026')
  })
})
