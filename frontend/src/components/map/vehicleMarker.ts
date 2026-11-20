import L from 'leaflet'
import type { LiveStatus, LiveVehicle } from '@/lib/types'

/** Marqueurs des camions : flèche orientée selon le cap (en mouvement), carré (à l'arrêt), signal coupé (hors ligne). */
const COLORS: Record<LiveStatus, string> = { moving: '#15803D', stopped: '#C2410C', offline: '#64748B' }
const LABELS: Record<LiveStatus, string> = { moving: 'en mouvement', stopped: 'à l’arrêt', offline: 'hors ligne' }

const GLYPH: Record<LiveStatus, string> = {
  moving: '<svg class="ft-arrow" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l6.5 17L12 16l-6.5 4z" fill="#fff"/></svg>',
  stopped: '<svg width="12" height="12" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2" fill="#fff"/></svg>',
  offline: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M2 2l20 20M8.5 16.5a5 5 0 0 1 7 0M5 13a10 10 0 0 1 5.2-2.8M19 13a10 10 0 0 0-2.3-1.7M12 20h.01"/></svg>',
}

export function vehicleIcon(v: LiveVehicle, selected = false): L.DivIcon {
  const ring = selected ? '0 0 0 3px #fff, 0 0 0 6px #1D4ED8' : '0 0 0 3px #fff, 0 2px 6px rgba(15,23,42,.3)'
  return L.divIcon({
    className: 'ft-vehicle',
    iconSize: [80, 54],
    iconAnchor: [40, 15],
    html: `<div style="display:flex;flex-direction:column;align-items:center;gap:4px" title="${v.name} — ${LABELS[v.status]}">
      <div class="ft-dot" style="width:30px;height:30px;border-radius:50%;background:${COLORS[v.status]};display:flex;align-items:center;justify-content:center;box-shadow:${ring}">
        <span class="ft-rot" style="display:flex;transform:rotate(${v.course}deg);transition:transform .8s">${GLYPH[v.status]}</span>
      </div>
      <span style="font:700 11px Inter,system-ui,sans-serif;color:#0F172A;background:#fff;padding:1px 6px;border-radius:6px;box-shadow:0 1px 3px rgba(15,23,42,.2);white-space:nowrap">${v.name}</span>
    </div>`,
  })
}

/** Rotation du marqueur sans recréer l'icône. */
export function setCourse(marker: L.Marker, course: number) {
  const el = marker.getElement()?.querySelector<HTMLElement>('.ft-rot')
  if (el) el.style.transform = `rotate(${course}deg)`
}

/** Déplacement animé d'un marqueur vers sa nouvelle position (saut direct si > 2 km). */
export function animateTo(marker: L.Marker, to: L.LatLngExpression, duration = 4500) {
  const from = marker.getLatLng()
  const target = L.latLng(to)
  const anim = (marker as unknown as { _ftAnim?: number })
  if (anim._ftAnim) cancelAnimationFrame(anim._ftAnim)
  if (from.distanceTo(target) > 2000 || document.hidden) {
    marker.setLatLng(target)
    return
  }
  const t0 = performance.now()
  const step = (t: number) => {
    const k = Math.min(1, (t - t0) / duration)
    marker.setLatLng([from.lat + (target.lat - from.lat) * k, from.lng + (target.lng - from.lng) * k])
    if (k < 1) anim._ftAnim = requestAnimationFrame(step)
  }
  anim._ftAnim = requestAnimationFrame(step)
}

/** Couche Leaflet d'une zone (cercle ou polygone, en pointillés). */
export function zoneLayer(z: { shape: string; center: [number, number]; radius_m: number | null; coordinates: [number, number][] | null; color: string; name: string }): L.Path {
  const style = { color: z.color, weight: 2, dashArray: '6 5', fillColor: z.color, fillOpacity: 0.08 }
  return z.shape === 'circle'
    ? L.circle(z.center, { radius: z.radius_m ?? 100, ...style })
    : L.polygon(z.coordinates ?? [], style)
}
