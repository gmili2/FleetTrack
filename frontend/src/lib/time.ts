/** « il y a 5 s », « il y a 3 min », « il y a 2 h ». */
export function ago(iso: string | null | undefined, now = Date.now()): string {
  if (!iso) return '—'
  const s = Math.max(0, Math.round((now - new Date(iso).getTime()) / 1000))
  if (s < 60) return `il y a ${s} s`
  if (s < 3600) return `il y a ${Math.floor(s / 60)} min`
  if (s < 86400) return `il y a ${Math.floor(s / 3600)} h`
  return `il y a ${Math.floor(s / 86400)} j`
}

/** Durée écoulée depuis iso : « 18 min », « 1 h 05 ». */
export function since(iso: string | null | undefined, now = Date.now()): string {
  if (!iso) return '—'
  const m = Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60000))
  return m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}`
}

const CARDINALS = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO']
export const cardinal = (deg: number) => `${CARDINALS[Math.round(((deg % 360) + 360) % 360 / 45) % 8]} ${Math.round(deg)}°`
