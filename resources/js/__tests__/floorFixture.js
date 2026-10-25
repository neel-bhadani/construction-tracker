// The floor rows of FloorProgressTest's hand-made Tower 1, as the page receives them.
export const floors = () => [
  { id: 11, name: 'Ground floor', level: 0, activitiesCount: 2, doneCount: 2, workDays: 30, workDaysDone: 30, progress: 1, plannedFinish: '2026-02-10', actualFinish: '2026-02-12', forecastFinish: null, status: 'Done' },
  { id: 12, name: '1st floor', level: 1, activitiesCount: 2, doneCount: 0, workDays: 25, workDaysDone: 0, progress: 0, plannedFinish: '2026-03-07', actualFinish: null, forecastFinish: '2026-03-09', status: 'Behind' },
  { id: 13, name: '2nd floor', level: 2, activitiesCount: 1, doneCount: 0, workDays: 5, workDaysDone: 0, progress: 0, plannedFinish: '2026-03-02', actualFinish: null, forecastFinish: '2026-03-04', status: 'Not started' },
  { id: null, name: 'Whole building', level: null, activitiesCount: 2, doneCount: 1, workDays: 15, workDaysDone: 10, progress: 10 / 15, plannedFinish: '2026-03-12', actualFinish: null, forecastFinish: '2026-03-14', status: 'In progress' },
]

// its eight activities: which floor each is on (Diwali, a break, on none)
export const activityRows = () => [
  { id: 1, description: 'Excavation', floor_id: null },
  { id: 2, description: 'GF slab', floor_id: 11 },
  { id: 3, description: 'GF walls', floor_id: 11 },
  { id: 4, description: '1F slab', floor_id: 12 },
  { id: 5, description: 'Diwali', floor_id: null, is_break: true },
  { id: 6, description: '1F walls', floor_id: 12 },
  { id: 7, description: '2F slab', floor_id: 13 },
  { id: 8, description: 'Handover', floor_id: null },
]
