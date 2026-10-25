import { describe, expect, it } from 'vitest'
import { deleteFloorMessage, floorNameForLevel, quickFloors, shortFloorName } from '@/lib/floors'

describe('floor names', () => {
  it('names floors the way Floor::nameForLevel does', () => {
    expect([-2, -1, 0, 1, 2, 3, 4, 11, 12, 13, 21, 22, 111].map(floorNameForLevel)).toEqual([
      'Basement 2', 'Basement 1', 'Ground floor', '1st floor', '2nd floor', '3rd floor', '4th floor',
      '11th floor', '12th floor', '13th floor', '21st floor', '22nd floor', '111th floor',
    ])
  })

  it('shortens names for the chips', () => {
    expect(['Ground floor', '1st floor', 'Basement 1', 'Terrace'].map(shortFloorName)).toEqual(['Ground', '1st', 'Basement 1', 'Terrace'])
  })
})

describe('quick floors', () => {
  it('lists Ground + 1 to N, skipping the names the building has in any case', () => {
    expect(quickFloors({ upTo: 3, basements: 1, existing: ['1ST FLOOR'] })).toEqual([
      { name: 'Basement 1', level: -1, skip: false },
      { name: 'Ground floor', level: 0, skip: false },
      { name: '1st floor', level: 1, skip: true },
      { name: '2nd floor', level: 2, skip: false },
      { name: '3rd floor', level: 3, skip: false },
    ])
  })

  it('makes fourteen floors for Ground + 1 to 13', () => {
    expect(quickFloors({ upTo: 13 })).toHaveLength(14)
  })
})

describe('deleting a floor', () => {
  it('says how many activities move and that dates stay', () => {
    expect(deleteFloorMessage({ name: '1st floor', activitiesCount: 2 }))
      .toBe("Delete [1st floor]? 2 activities will move to Whole building. Dates don't change.")
    expect(deleteFloorMessage({ name: '2nd floor', activitiesCount: 1 }))
      .toBe("Delete [2nd floor]? 1 activity will move to Whole building. Dates don't change.")
    expect(deleteFloorMessage({ name: 'Terrace', activitiesCount: 0 })).toBe('Delete [Terrace]? It has no activities.')
  })
})
