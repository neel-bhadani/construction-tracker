import { describe, expect, it } from 'vitest'
import { cleanCode, initials } from '@/lib/projectCode'

describe('project code', () => {
  it('takes the initials of the name, as the server does', () => {
    expect(initials('Shaligram Vrundavan')).toBe('SV')
    expect(initials('test')).toBe('T')
    expect(initials('Hand-made Heights')).toBe('HMH')
    expect(initials('Tower 7 phase two')).toBe('T7PT')
    expect(initials('a b c d e f g h i j')).toBe('ABCDEFGH')
    expect(initials('')).toBe('')
  })

  it('keeps only letters and digits, upper case, at most 8', () => {
    expect(cleanCode(' sv-2 ')).toBe('SV2')
    expect(cleanCode('abcdefghij')).toBe('ABCDEFGH')
  })
})
