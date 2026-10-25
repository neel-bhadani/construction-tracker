import { describe, expect, it } from 'vitest'
import { offsetFor, plannedDateFor, toDate, toDay } from '@/lib/schedule'

// the hand-made building of ActivityDialogTest: start 1 Jan 2026, B on work-day 30 (31 Jan)
const start = '2026-01-01'

describe('planned date and offset', () => {
  it('turns a typed planned date into the offset', () => {
    expect(offsetFor({ start, predecessorDay: 30, plannedDate: '2026-02-10' })).toBe(10)
  })

  it('turns the days after into the planned date', () => {
    expect(plannedDateFor({ start, predecessorDay: 30, offset: 10 })).toBe('2026-02-10')
  })

  it('counts the first activity from the building start', () => {
    expect(offsetFor({ start, plannedDate: '2026-01-04' })).toBe(3)
    expect(plannedDateFor({ start, offset: 3 })).toBe('2026-01-04')
  })

  it("keeps the row's own adjustment in its date, out of its offset", () => {
    // C after B (work-day 30) with +3: 20 Feb is 17 days after
    expect(offsetFor({ start, predecessorDay: 30, plannedDate: '2026-02-20', adjustment: 3 })).toBe(17)
    expect(plannedDateFor({ start, predecessorDay: 30, offset: 17, adjustment: 3 })).toBe('2026-02-20')
  })

  it('goes both ways for every offset, across month, year and leap-day ends', () => {
    for (let offset = 0; offset <= 1500; offset += 37) {
      const plannedDate = plannedDateFor({ start: '2027-12-30', predecessorDay: 50, offset, adjustment: -2 })
      expect(offsetFor({ start: '2027-12-30', predecessorDay: 50, plannedDate, adjustment: -2 })).toBe(offset)
    }
    expect(plannedDateFor({ start: '2028-02-28', offset: 1 })).toBe('2028-02-29')
  })

  it('gives a negative offset for a date before the earliest, which the dialog refuses', () => {
    expect(offsetFor({ start, predecessorDay: 30, plannedDate: '2026-01-30' })).toBe(-1)
  })

  it('reads and writes calendar dates without a timezone shift', () => {
    expect(toDate(toDay('2026-03-29'))).toBe('2026-03-29')
  })
})
