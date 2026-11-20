/** Formats d'affichage : français, fuseau Africa/Casablanca (les dates API sont en UTC). */
export const TZ = 'Africa/Casablanca'

const fmt = (opts: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, ...opts })
const dateF = fmt({ day: '2-digit', month: '2-digit', year: 'numeric' })
const timeF = fmt({ hour: '2-digit', minute: '2-digit' })
const dateTimeF = fmt({ day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
const longF = fmt({ weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })

const toDate = (v: string | Date | null | undefined) => (v ? (v instanceof Date ? v : new Date(v)) : null)

export const fDate = (v?: string | Date | null) => { const d = toDate(v); return d ? dateF.format(d) : '—' }
export const fTime = (v?: string | Date | null) => { const d = toDate(v); return d ? timeF.format(d) : '—' }
export const fDateTime = (v?: string | Date | null) => { const d = toDate(v); return d ? dateTimeF.format(d) : '—' }
export const fLongDate = (v: string | Date = new Date()) => { const s = longF.format(toDate(v)!); return s.charAt(0).toUpperCase() + s.slice(1) }

const n0 = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 })
const n1 = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 })
export const fNumber = (v?: number | null, decimals = 0) => (v == null ? '—' : (decimals ? n1 : n0).format(v))
export const fKm = (v?: number | null) => (v == null ? '—' : `${n1.format(v)} km`)
export const fSpeed = (v?: number | null) => (v == null ? '—' : `${n0.format(v)} km/h`)

/** Durée lisible à partir de secondes : « 2 h 05 », « 12 min ». */
export function fDuration(seconds?: number | null): string {
  if (seconds == null) return '—'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  if (h === 0) return `${m} min`
  return `${h} h ${String(m).padStart(2, '0')}`
}

const timeSecF = fmt({ hour: '2-digit', minute: '2-digit', second: '2-digit' })
export const fTimeSec = (v?: string | Date | null) => { const d = toDate(v); return d ? timeSecF.format(d) : '—' }
