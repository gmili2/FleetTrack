<script setup lang="ts">
import { shallowRef, watch } from 'vue'
import L from 'leaflet'
import type { ShiftTrack } from '@/lib/types'
import { fDuration, fTime } from '@/lib/format'
import MapView from './MapView.vue'

/** Trajet d'un service : tracé, départ (A), position finale ou actuelle (B), arrêts numérotés. */
const props = defineProps<{ track: ShiftTrack | null; live?: [number, number] | null }>()
const map = shallowRef<L.Map | null>(null)
const layer = L.layerGroup()

const pin = (label: string, bg: string) => L.divIcon({
  className: 'ft-vehicle', iconSize: [26, 26], iconAnchor: [13, 13],
  html: `<div style="width:26px;height:26px;border-radius:50%;background:${bg};color:#fff;font:700 12px Inter,sans-serif;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 3px #fff,0 2px 6px rgba(15,23,42,.3)">${label}</div>`,
})

function draw() {
  if (!map.value) return
  layer.clearLayers()
  const pts = props.track?.points ?? []
  if (!pts.length) return
  const line = pts.map((p) => [p[0], p[1]] as [number, number])
  L.polyline(line, { color: '#1D4ED8', weight: 4, opacity: 0.8 }).addTo(layer)
  L.marker(line[0], { icon: pin('A', '#15803D'), title: 'Départ' }).bindTooltip(`Départ · ${fTime(pts[0][3])}`).addTo(layer)
  props.track!.summary.stops.forEach((s, i) => {
    L.marker([s.latitude, s.longitude], { icon: pin(String(i + 1), '#C2410C') })
      .bindTooltip(`Arrêt ${i + 1} · ${fTime(s.started_at)} · ${fDuration(s.duration_seconds)}`).addTo(layer)
  })
  const end = props.live ?? line[line.length - 1]
  L.marker(end, { icon: pin('B', props.track!.shift.open ? '#1D4ED8' : '#0F172A'), title: 'Arrivée' })
    .bindTooltip(props.track!.shift.open ? 'Position actuelle' : `Fin · ${fTime(pts[pts.length - 1][3])}`).addTo(layer)
  map.value.fitBounds(L.latLngBounds(line).pad(0.15), { maxZoom: 15 })
}

function onReady(m: L.Map) {
  map.value = m
  layer.addTo(m)
  requestAnimationFrame(draw)
}
watch(() => props.track, () => requestAnimationFrame(draw))
</script>

<template>
  <MapView @ready="onReady" />
</template>
