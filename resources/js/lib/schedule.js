/*
 | The activity dialog's one piece of schedule arithmetic: turning a typed
 | planned date into the offset that is stored, and back. The server works it
 | out again from what is sent (ValidatesChain), so this only keeps the two
 | fields in step while typing.
 |
 |   planned date = building start + work-day of "Comes after" + offset + this row's adjustment
 |
 | The "Comes after" row's own adjustment is not in it: an adjustment moves
 | only its own row's date, never the work-day the next row counts from.
 |
 | Dates are whole days, split as text, so no timezone can shift them.
 */
const DAY_MS = 86400000

export function toDay(ymd) {
  const [year, month, day] = ymd.slice(0, 10).split('-').map(Number)
  return Date.UTC(year, month - 1, day) / DAY_MS
}

export function toDate(day) {
  return new Date(day * DAY_MS).toISOString().slice(0, 10)
}

export function plannedDateFor({ start, predecessorDay = 0, offset, adjustment = 0 }) {
  return toDate(toDay(start) + predecessorDay + offset + adjustment)
}

export function offsetFor({ start, predecessorDay = 0, plannedDate, adjustment = 0 }) {
  return toDay(plannedDate) - toDay(start) - predecessorDay - adjustment
}
