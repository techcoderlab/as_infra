// helpers.js

export function formatDate(date, time = false, locale = 'en') {
  if (!date) return null

  const inputDate = new Date(date)
  if (isNaN(inputDate.getTime())) return 'Invalid date'

  return new Intl.DateTimeFormat(locale, {
    dateStyle: 'medium',
    timeStyle: time ? 'short' : undefined,
  }).format(inputDate)
}

export function humanizeDate(date, time = false, locale = 'en') {
  if (!date) return null

  const inputDate = new Date(date)
  if (isNaN(inputDate.getTime())) return 'Invalid date'

  const now = new Date()
  const diffInSeconds = Math.floor((inputDate - now) / 1000)
  const absSeconds = Math.abs(diffInSeconds)
  const diffInDays = Math.floor(absSeconds / 86400)

  const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' })

  // 1. Handle "Just now" / Very recent
  if (absSeconds < 60) return 'Just now'

  // 2. Handle same-day or yesterday specifically
  if (diffInDays === 0) {
    // If more than 1 minute but less than 1 hour ago, use RTF for minutes

    if (absSeconds < 3600) {
      return rtf.format(Math.floor(diffInSeconds / 60), 'minute').replace('minute', 'min')
    }
    // return 'Today'
  }

  if (diffInDays === 1 && diffInSeconds < 0) return 'Yesterday'

  // 3. Use Relative Time for anything under 30 days
  if (diffInDays < 30) {
    const units = [
      { unit: 'week', seconds: 604800 },
      { unit: 'day', seconds: 86400 },
      { unit: 'hour', seconds: 3600 },
    ]

    for (const { unit, seconds } of units) {
      if (absSeconds >= seconds) {
        return rtf.format(Math.floor(diffInSeconds / seconds), unit)
      }
    }
  }

  // 4. Fallback to static date for older entries
  return new Intl.DateTimeFormat(locale, {
    dateStyle: 'medium',
    timeStyle: time ? 'short' : undefined,
  }).format(inputDate)
}

const smallWords = /^(a|an|and|as|at|but|by|en|for|if|in|nor|of|on|or|per|the|to|v\.?|vs\.?|via)$/i
export function toTitleCase(str) {
  // 1. Force conversion to string
  // 2. Check if the input was null, undefined, or empty after conversion
  if (str === null || str === undefined || str === '') return ''
  str = String(str)
  str = str.replace(/_/g, ' ')

  return str.toLowerCase().replace(/\b(\w+)\b/g, (word, index, fullString) => {
    const isSmallWord = smallWords.test(word)
    const isFirstWord = index === 0
    const isLastWord = index + word.length === fullString.length

    if (isSmallWord && !isFirstWord && !isLastWord) {
      return word.toLowerCase()
    }

    return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase()
  })
}

// TEST CASES:
// "state-of-the-art"   -> "State-of-the-Art"
// "the lord of the-rings" -> "The Lord of the-Rings"
// "step-by-step"       -> "Step-by-Step"
