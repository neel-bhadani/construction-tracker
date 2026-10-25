/*
 | Floor names and labels for the building page. Floor::nameForLevel() makes
 | the same names on the server; the quick option's preview uses this copy to
 | say which floors will be added and which are already there.
 */

// 0 -> 'Ground floor', 1 -> '1st floor', 12 -> '12th floor', -1 -> 'Basement 1'
export function floorNameForLevel(level) {
  if (level === 0) return 'Ground floor'
  if (level < 0) return `Basement ${Math.abs(level)}`

  const teen = [11, 12, 13].includes(level % 100)
  const suffix = teen ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' }[level % 10] ?? 'th')
  return `${level}${suffix} floor`
}

// the chip label: 'Ground floor' -> 'Ground', '1st floor' -> '1st'; other names as they are
export function shortFloorName(name) {
  return name.replace(/\s+floor$/i, '')
}

/*
 | "Ground + 1 to N", with basements below: every floor the server would make,
 | each marked skip when the building already has a floor of that name (any
 | case), as FloorEditor::addRange() does.
 */
export function quickFloors({ upTo, basements = 0, existing = [] }) {
  const have = new Set(existing.map(name => name.toLowerCase()))
  const floors = []

  for (let level = -basements; level <= upTo; level++) {
    const name = floorNameForLevel(level)
    floors.push({ name, level, skip: have.has(name.toLowerCase()) })
  }

  return floors
}

export function deleteFloorMessage(floor) {
  const n = floor.activitiesCount
  const moving = n === 0
    ? 'It has no activities.'
    : `${n} ${n === 1 ? 'activity' : 'activities'} will move to Whole building. Dates don't change.`
  return `Delete [${floor.name}]? ${moving}`
}

// the filter key of a floor row: its id, or 'whole' for the whole building
export const floorKey = floor => (floor.id === null ? 'whole' : floor.id)

// the same key for an activity row
export const activityFloorKey = row => (row.floor_id === null || row.floor_id === undefined ? 'whole' : row.floor_id)
