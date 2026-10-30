/*
 | A project's code, from its name's initials: "Shaligram Vrundavan" → SV.
 | The same rule as App\\Models\\Project::initials(); the server gives a code
 | of its own (SV2) when this one is taken.
 */
export const initials = name => {
  const words = (name ?? '').match(/[\p{L}\p{N}]+/gu) ?? []
  // an empty name gives an empty code: the server then takes the initials itself
  return words.map(word => word[0]).join('').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8)
}

// the code explained: on the project page, and under its field in Edit project
export const workIdHint = code => `Every activity in this project gets an ID like ${code}-001. Change it in Edit project; existing IDs stay the same.`
export const editWorkIdHint = code => `Every activity in this project gets an ID like ${code}-001. Changing it only affects new activities; existing IDs stay the same.`

// what the code field accepts: letters A–Z and digits, at most 8
export const cleanCode = value => (value ?? '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8)
