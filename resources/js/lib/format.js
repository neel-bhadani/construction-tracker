/*
 | Display formatting only. Every figure arrives computed from the server;
 | nothing here does schedule arithmetic.
 */

// Three letters for every month. Intl's en-GB short month gives 'Sept', the odd one out.
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

// '2028-08-05' -> '5 Aug 2028'. Split as text, so no timezone can shift the calendar date.
export function formatDate(ymd) {
  if (!ymd) return '—'
  const [year, month, day] = ymd.slice(0, 10).split('-').map(Number)
  return `${day} ${MONTHS[month - 1]} ${year}`
}

// 12 -> '+12', -3 -> '−3', 0 -> '0'
export function signed(n) {
  if (n === null || n === undefined) return '—'
  if (n > 0) return `+${n}`
  if (n < 0) return `−${Math.abs(n)}`
  return '0'
}

export function percent(fraction) {
  return `${(fraction * 100).toFixed(1)}%`
}

// 'ahead' / 'behind' / 'on plan' for a lead in days
export function leadWord(n) {
  if (n > 0) return 'ahead'
  if (n < 0) return 'behind'
  return 'on plan'
}
