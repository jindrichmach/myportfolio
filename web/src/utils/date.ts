import { shallowRef } from 'vue'

function dateFormatter(timeZone: string): Intl.DateTimeFormat {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  })
}

const appDateParts = shallowRef(dateFormatter('Europe/Prague'))

export function setAppTimeZone(timeZone: string): void {
  appDateParts.value = dateFormatter(timeZone)
}

export function appIsoDate(date: Date = new Date()): string {
  const parts = appDateParts.value.formatToParts(date)
  const value = (type: Intl.DateTimeFormatPartTypes) =>
    parts.find(part => part.type === type)?.value ?? ''
  return `${value('year')}-${value('month')}-${value('day')}`
}

/** Celé kalendářní dny po splatnosti, nezávisle na změnách letního času. */
export function overdueDays(dueDate: string, now: Date = new Date()): number {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(dueDate)) return 0
  const days = (Date.parse(appIsoDate(now)) - Date.parse(dueDate)) / 86_400_000
  return Number.isFinite(days) ? Math.max(0, days) : 0
}
