<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import L from 'leaflet'
import { Crosshair, Download, Gauge, MapPin, Pause, Play, Route, RotateCcw, Square, Timer } from 'lucide-vue-next'
import { errorMessage, http } from '@/lib/http'
import { download } from '@/lib/download'
import { fDuration, fKm, fTime, fTimeSec, TZ } from '@/lib/format'
import { toast } from '@/lib/toast'
import type { Paginated, VehicleRow } from '@/lib/types'
import { useAuthStore } from '@/stores/auth'
import MapView from '@/components/map/MapView.vue'
import { zoneLayer } from '@/components/map/vehicleMarker'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import EmptyState from '@/components/EmptyState.vue'
import { useFleetStore } from '@/stores/fleet'

type Point = [number, number, number, number, number] // lat, lng, vitesse, cap, timestamp
type TimelineItem =
  | { kind: 'departure' | 'arrival'; at: string; place: string | null }
  | { kind: 'trip'; started_at: string; ended_at: string; duration_seconds: number; distance_km: number; avg_speed_kmh: number; max_speed_kmh: number }
  | { kind: 'stop'; n: number; started_at: string; ended_at: string | null; duration_seconds: number; place: string; in_zone: boolean; latitude: number; longitude: number }
type History = {
  vehicle: { id: number; name: string; plate_number: string; speed_limit_kmh: number }
  from: string; to: string; points: Point[]; timeline: TimelineItem[]; positions_total: number; parked_at?: string | null
  summary: null | { distance_km: number; driving_seconds: number; stopped_seconds: number; stops_count: number; max_speed_kmh: number; avg_speed_kmh: number; departure: { at: string; place: string }; arrival: { at: string; place: string; ongoing: boolean } }
}

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const fleet = useFleetStore()

// ── Filtres ────────────────────────────────────────────────────────────────
const dayFmt = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' })
const hmFmt = new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, hour: '2-digit', minute: '2-digit', hour12: false })
const today = dayFmt.format(new Date())
const f = reactive({ vehicle_id: Number(route.query.vehicle) || 0, date: today, start: '00:00', end: '23:59' })
if (route.query.from) {
  const from = new Date(String(route.query.from))
  const to = route.query.to ? new Date(String(route.query.to)) : new Date()
  f.date = dayFmt.format(from)
  f.start = hmFmt.format(from)
  f.end = dayFmt.format(to) === f.date ? hmFmt.format(new Date(to.getTime() + 60000)) : '23:59'
} else if (route.query.date) {
  f.date = String(route.query.date)
}
const vehicles = ref<VehicleRow[]>([])

// ── Chargement ─────────────────────────────────────────────────────────────
const data = ref<History | null>(null)
const loading = ref(false)
const error = ref('')
async function load() {
  if (!f.vehicle_id) return
  loading.value = true
  error.value = ''
  stop()
  router.replace({ query: { vehicle: String(f.vehicle_id), date: f.date, start: f.start, end: f.end } })
  try {
    data.value = (await http.get<{ data: History }>('/api/history', { params: { ...f } })).data.data
    cur.value = ts.value[0] ?? 0
    draw()
  } catch (e) {
    error.value = errorMessage(e, 'Impossible de charger l’historique.')
    data.value = null
  } finally {
    loading.value = false
  }
}

// ── Lecteur ────────────────────────────────────────────────────────────────
/** x1 = 30 s de trajet par seconde. */
const BASE = 30
const SPEEDS = [1, 2, 5, 10, 20]
const speed = ref(5)
const playing = ref(false)
const cur = ref(0)
const follow = ref(true)
const ts = computed(() => (data.value?.points ?? []).map((p) => p[4]))
const t0 = computed(() => ts.value[0] ?? 0)
const t1 = computed(() => ts.value[ts.value.length - 1] ?? 0)

/** Index du dernier point ≤ t (recherche dichotomique). */
function indexAt(t: number) {
  const a = ts.value
  let lo = 0, hi = a.length - 1
  while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (a[mid] <= t) lo = mid; else hi = mid - 1 }
  return lo
}
const state = computed(() => {
  const pts = data.value?.points ?? []
  if (!pts.length) return null
  const i = indexAt(cur.value)
  const p = pts[i]
  const q = pts[Math.min(i + 1, pts.length - 1)]
  const k = q[4] > p[4] ? Math.min(1, Math.max(0, (cur.value - p[4]) / (q[4] - p[4]))) : 0
  return { i, lat: p[0] + (q[0] - p[0]) * k, lng: p[1] + (q[1] - p[1]) * k, speed: Math.round(p[2] + (q[2] - p[2]) * k), course: q[3] || p[3] }
})

let raf = 0
let last = 0
function frame(now: number) {
  const dt = (now - last) / 1000
  last = now
  cur.value = Math.min(t1.value, cur.value + dt * BASE * speed.value)
  if (cur.value >= t1.value) { playing.value = false; return }
  raf = requestAnimationFrame(frame)
}
function play() {
  if (!ts.value.length) return
  if (cur.value >= t1.value) cur.value = t0.value
  playing.value = true
  last = performance.now()
  raf = requestAnimationFrame(frame)
}
function stop() { playing.value = false; cancelAnimationFrame(raf) }
const toggle = () => (playing.value ? stop() : play())
function seek(iso: string | null, latlng?: [number, number]) {
  if (!iso) return
  cur.value = Math.min(t1.value, Math.max(t0.value, Math.floor(new Date(iso).getTime() / 1000)))
  if (latlng && map.value) map.value.flyTo(latlng, 15, { duration: 0.6 })
}

// ── Carte ──────────────────────────────────────────────────────────────────
const map = shallowRef<L.Map | null>(null)
const layers = L.layerGroup()
const full = L.polyline([], { color: '#93C5FD', weight: 5, opacity: 0.9 })
const done = L.polyline([], { color: '#1D4ED8', weight: 5 })
let truck: L.Marker | null = null
let lastIndex = -1
const pin = (label: string, bg: string, size = 26) => L.divIcon({
  className: 'ft-vehicle', iconSize: [size, size], iconAnchor: [size / 2, size / 2],
  html: `<div style="width:${size}px;height:${size}px;border-radius:50%;background:${bg};color:#fff;font:700 12px Inter,sans-serif;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 3px #fff,0 2px 6px rgba(15,23,42,.3)">${label}</div>`,
})
const truckIcon = (name: string) => L.divIcon({
  className: 'ft-vehicle', iconSize: [80, 54], iconAnchor: [40, 15],
  html: `<div style="display:flex;flex-direction:column;align-items:center;gap:4px"><div style="width:30px;height:30px;border-radius:50%;background:#0B1F3A;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 3px #fff,0 2px 6px rgba(15,23,42,.35)"><span class="ft-rot" style="display:flex"><svg width="16" height="16" viewBox="0 0 24 24"><path d="M12 3l6.5 17L12 16l-6.5 4z" fill="#fff"/></svg></span></div><span style="font:700 11px Inter,sans-serif;background:#fff;padding:1px 6px;border-radius:6px;box-shadow:0 1px 3px rgba(15,23,42,.2)">${name}</span></div>`,
})

function draw() {
  if (!map.value) return
  layers.clearLayers()
  truck = null
  lastIndex = -1
  const pts = data.value?.points ?? []
  if (!pts.length) return
  const line = pts.map((p) => [p[0], p[1]] as [number, number])
  full.setLatLngs(line).addTo(layers)
  done.setLatLngs([]).addTo(layers)
  L.marker(line[0], { icon: pin('A', '#15803D') }).bindTooltip(`Départ · ${fTime(data.value!.summary?.departure.at)}`).addTo(layers)
  L.marker(line[line.length - 1], { icon: pin('B', '#0F172A') }).bindTooltip(`Arrivée · ${fTime(data.value!.summary?.arrival.at)}`).addTo(layers)
  for (const it of data.value!.timeline) {
    if (it.kind === 'stop') {
      L.marker([it.latitude, it.longitude], { icon: pin(String(it.n), '#C2410C', 24) })
        .bindTooltip(`Arrêt ${it.n} · ${it.place} · ${fDuration(it.duration_seconds)}`).on('click', () => seek(it.started_at)).addTo(layers)
    }
  }
  truck = L.marker(line[0], { icon: truckIcon(data.value!.vehicle.name), zIndexOffset: 1000 }).addTo(layers)
  map.value.invalidateSize()
  map.value.fitBounds(L.latLngBounds(line).pad(0.1))
  updateTruck()
}
function updateTruck() {
  const s = state.value
  if (!truck || !s || !map.value) return
  truck.setLatLng([s.lat, s.lng])
  const rot = truck.getElement()?.querySelector<HTMLElement>('.ft-rot')
  if (rot) rot.style.transform = `rotate(${s.course}deg)`
  if (s.i !== lastIndex) {
    lastIndex = s.i
    done.setLatLngs([...(data.value!.points.slice(0, s.i + 1).map((p) => [p[0], p[1]] as [number, number])), [s.lat, s.lng]])
  }
  if (follow.value && playing.value && !map.value.getBounds().pad(-0.15).contains([s.lat, s.lng])) map.value.panTo([s.lat, s.lng])
}
watch(cur, updateTruck)
function onMap(m: L.Map) {
  map.value = m
  layers.addTo(m)
  fleet.zones.forEach((z) => zoneLayer(z).bindTooltip(z.name).addTo(m))
  draw()
}

// Élément de chronologie en cours de lecture
const activeIndex = computed(() => {
  const items = data.value?.timeline ?? []
  for (let i = items.length - 1; i >= 0; i--) {
    const it = items[i]
    const start = 'at' in it ? it.at : it.started_at
    if (new Date(start).getTime() / 1000 <= cur.value) return i
  }
  return 0
})

const exporting = ref(false)
async function exportExcel() {
  exporting.value = true
  try { await download('/api/history/export', { ...f }, 'trajet.xlsx') } catch (e) { toast(errorMessage(e, 'Export impossible.'), 'error') } finally { exporting.value = false }
}

function onKey(e: KeyboardEvent) {
  if ((e.target as HTMLElement)?.closest('input,select,textarea')) return
  if (e.code === 'Space' && data.value?.points.length) { e.preventDefault(); toggle() }
}
onMounted(async () => {
  window.addEventListener('keydown', onKey)
  if (auth.can('tracking.view') && !fleet.zones.length) fleet.load().then(() => map.value && fleet.zones.forEach((z) => zoneLayer(z).bindTooltip(z.name).addTo(map.value!)))
  try {
    vehicles.value = (await http.get<Paginated<VehicleRow>>('/api/vehicles', { params: { per_page: 100 } })).data.data
    if (!f.vehicle_id && vehicles.value[0]) f.vehicle_id = vehicles.value[0].id
  } catch { /* la liste reste vide */ }
  load()
})
onBeforeUnmount(() => { stop(); window.removeEventListener('keydown', onKey) })
const pct = computed(() => (t1.value > t0.value ? ((cur.value - t0.value) / (t1.value - t0.value)) * 100 : 0))
</script>

<template>
  <section class="card p-4">
    <form class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.4fr)_repeat(3,minmax(0,1fr))_auto]" @submit.prevent="load">
      <label class="field">Camion
        <select v-model.number="f.vehicle_id" class="input"><option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.name }} · {{ v.plate_number }}{{ v.driver ? ` · ${v.driver.name}` : '' }}</option></select>
      </label>
      <label class="field">Date<input v-model="f.date" type="date" :max="today" class="input" required /></label>
      <label class="field">Heure de début<input v-model="f.start" type="time" class="input" required /></label>
      <label class="field">Heure de fin<input v-model="f.end" type="time" class="input" required /></label>
      <button type="submit" class="btn btn-primary" :disabled="loading || !f.vehicle_id"><Route :size="17" />{{ loading ? 'Chargement…' : 'Afficher le trajet' }}</button>
    </form>
  </section>

  <ErrorState v-if="error" :message="error" @retry="load" />

  <div class="grid gap-5 xl:grid-cols-[300px_minmax(0,1fr)_340px]">
    <!-- Synthèse -->
    <section class="card p-5">
      <h2 class="m-0 mb-3 text-base font-bold">Synthèse</h2>
      <LoadingState v-if="loading && !data" />
      <EmptyState v-else-if="data && !data.summary" :icon="Square" title="Aucun déplacement" :message="data.positions_total ? `Le camion est resté à l’arrêt${data.parked_at ? ` (${data.parked_at})` : ''} sur cette période.` : 'Aucune position GPS sur cette période.'" />
      <dl v-else-if="data?.summary" class="m-0 grid gap-3 text-sm">
        <div class="rounded-xl bg-page p-3"><dt class="text-xs text-muted">Distance</dt><dd class="num m-0 text-2xl font-bold">{{ fKm(data.summary.distance_km) }}</dd></div>
        <div class="flex justify-between gap-2"><dt class="text-muted">Départ</dt><dd class="m-0 text-right font-semibold">{{ fTime(data.summary.departure.at) }} · {{ data.summary.departure.place }}</dd></div>
        <div class="flex justify-between gap-2"><dt class="text-muted">Arrivée</dt><dd class="m-0 text-right font-semibold">{{ data.summary.arrival.ongoing ? 'en cours' : fTime(data.summary.arrival.at) }} · {{ data.summary.arrival.place }}</dd></div>
        <div class="flex justify-between gap-2"><dt class="text-muted">Temps de conduite</dt><dd class="num m-0 font-semibold">{{ fDuration(data.summary.driving_seconds) }}</dd></div>
        <div class="flex justify-between gap-2"><dt class="text-muted">Temps d’arrêt</dt><dd class="num m-0 font-semibold">{{ fDuration(data.summary.stopped_seconds) }}</dd></div>
        <div class="flex justify-between gap-2"><dt class="text-muted">Vitesse moyenne</dt><dd class="num m-0 font-semibold">{{ data.summary.avg_speed_kmh }} km/h</dd></div>
        <div class="flex justify-between gap-2"><dt class="text-muted">Vitesse maximale</dt><dd class="num m-0 font-semibold" :class="data.summary.max_speed_kmh > data.vehicle.speed_limit_kmh ? 'text-danger' : ''">{{ data.summary.max_speed_kmh }} km/h</dd></div>
        <div class="flex justify-between gap-2"><dt class="text-muted">Arrêts (≥ 5 min)</dt><dd class="num m-0 font-semibold">{{ data.summary.stops_count }}</dd></div>
      </dl>
    </section>

    <!-- Carte + lecteur -->
    <section class="card flex min-h-[560px] flex-col overflow-hidden">
      <div class="relative min-h-[420px] flex-1">
        <MapView @ready="onMap" />
        <div v-if="loading" class="absolute inset-0 z-[500] flex items-center justify-center bg-white/60"><LoadingState label="Chargement du trajet…" /></div>
      </div>
      <div v-if="data?.points.length" class="flex flex-col gap-3 border-t border-line p-4">
        <div class="flex flex-wrap items-center gap-2">
          <button type="button" class="btn btn-primary size-11 rounded-full p-0" :aria-label="playing ? 'Pause' : 'Lecture'" @click="toggle"><Pause v-if="playing" :size="20" /><Play v-else :size="20" /></button>
          <button type="button" class="btn-icon size-11 rounded-full" aria-label="Revenir au départ" @click="stop(); cur = t0"><RotateCcw :size="17" /></button>
          <div class="flex rounded-[10px] border border-line p-0.5" role="group" aria-label="Vitesse de lecture" title="x1 = 30 s de trajet par seconde">
            <button v-for="s in SPEEDS" :key="s" type="button" class="rounded-lg px-2.5 py-1.5 text-[13px] font-semibold" :aria-pressed="speed === s" :class="speed === s ? 'bg-brand text-white' : 'text-muted hover:bg-page'" @click="speed = s">x{{ s }}</button>
          </div>
          <button type="button" class="btn ml-auto min-h-9 px-3 text-[13px]" :class="follow ? 'btn-primary' : 'btn-secondary'" :aria-pressed="follow" @click="follow = !follow"><Crosshair :size="15" />Suivre</button>
        </div>
        <div>
          <label class="sr-only" for="replay-time">Position dans le trajet</label>
          <input id="replay-time" v-model.number="cur" type="range" :min="t0" :max="t1" step="1" class="w-full accent-brand" :style="{ '--p': pct + '%' }" @pointerdown="stop" />
          <div class="flex justify-between text-xs text-muted"><span>{{ fTime(new Date(t0 * 1000)) }}</span><span>{{ fTime(new Date(t1 * 1000)) }}</span></div>
        </div>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
          <div class="rounded-xl bg-page px-3 py-2"><div class="flex items-center gap-1 text-xs text-muted"><Timer :size="13" />Heure</div><b class="num text-lg">{{ fTimeSec(new Date(cur * 1000)) }}</b></div>
          <div class="rounded-xl bg-page px-3 py-2"><div class="flex items-center gap-1 text-xs text-muted"><Gauge :size="13" />Vitesse</div><b class="num text-lg" :class="(state?.speed ?? 0) > (data?.vehicle.speed_limit_kmh ?? 999) ? 'text-danger' : ''">{{ state?.speed ?? 0 }} km/h</b></div>
          <div class="col-span-2 rounded-xl bg-page px-3 py-2"><div class="flex items-center gap-1 text-xs text-muted"><MapPin :size="13" />Étape</div><b class="block truncate text-sm">{{ (() => { const it = data?.timeline[activeIndex]; return !it ? '—' : it.kind === 'stop' ? `Arrêt ${it.n} — ${it.place}` : it.kind === 'trip' ? `En route · ${fKm(it.distance_km)}` : it.kind === 'departure' ? `Départ — ${it.place}` : `Arrivée — ${it.place}` })() }}</b></div>
        </div>
      </div>
    </section>

    <!-- Chronologie -->
    <section class="card flex max-h-[760px] flex-col p-5">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="m-0 text-base font-bold">Chronologie</h2>
        <button v-if="auth.can('reports.export') && data?.timeline.length" type="button" class="btn btn-secondary min-h-9 px-3 text-[13px]" :disabled="exporting" @click="exportExcel"><Download :size="15" />Exporter</button>
      </div>
      <EmptyState v-if="!data?.timeline.length" title="Pas de chronologie" message="Choisissez un camion et une période puis « Afficher le trajet »." />
      <ol v-else class="m-0 -mr-2 list-none overflow-y-auto p-0 pr-2">
        <li v-for="(it, i) in data.timeline" :key="i">
          <button type="button" class="flex w-full gap-3 rounded-xl p-2 text-left" :class="i === activeIndex ? 'bg-[#EFF6FF]' : 'hover:bg-page'"
            @click="seek('at' in it ? it.at : it.started_at, it.kind === 'stop' ? [it.latitude, it.longitude] : undefined)">
            <span v-if="it.kind === 'departure'" class="flex size-7 flex-none items-center justify-center rounded-full bg-moving text-xs font-bold text-white">A</span>
            <span v-else-if="it.kind === 'arrival'" class="flex size-7 flex-none items-center justify-center rounded-full bg-ink text-xs font-bold text-white">B</span>
            <span v-else-if="it.kind === 'stop'" class="flex size-7 flex-none items-center justify-center rounded-full bg-stopped text-xs font-bold text-white">{{ it.n }}</span>
            <span v-else class="flex w-7 flex-none justify-center"><span class="w-0.5 self-stretch bg-[#93C5FD]" /></span>
            <span class="min-w-0 text-[13px]">
              <template v-if="it.kind === 'departure' || it.kind === 'arrival'"><b class="block">{{ it.kind === 'departure' ? 'Départ' : 'Arrivée' }} — {{ it.place }}</b><span class="text-muted">{{ fTime(it.at) }}</span></template>
              <template v-else-if="it.kind === 'stop'"><b class="block">Arrêt — {{ it.place }}</b><span class="text-muted">{{ fTime(it.started_at) }} → {{ it.ended_at ? fTime(it.ended_at) : 'en cours' }} · Durée {{ fDuration(it.duration_seconds) }} · {{ it.in_zone ? 'dans la zone' : 'hors zone' }}</span></template>
              <template v-else-if="it.kind === 'trip'"><b class="block text-muted">Trajet · {{ fKm(it.distance_km) }} · {{ fDuration(it.duration_seconds) }}</b><span class="text-muted">{{ fTime(it.started_at) }} → {{ fTime(it.ended_at) }} · moy. {{ it.avg_speed_kmh }} km/h · max <span :class="it.max_speed_kmh > (data?.vehicle.speed_limit_kmh ?? 999) ? 'font-semibold text-danger' : ''">{{ it.max_speed_kmh }} km/h</span></span></template>
            </span>
          </button>
        </li>
      </ol>
    </section>
  </div>
</template>
