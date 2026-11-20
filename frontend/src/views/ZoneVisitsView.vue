<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue'
import { useRoute } from 'vue-router'
import L from 'leaflet'
import { Bar } from 'vue-chartjs'
import { BarElement, CategoryScale, Chart as ChartJS, LinearScale, Tooltip } from 'chart.js'
import { Clock, FileDown, FileSpreadsheet, Radio, Timer, Truck, Hexagon } from 'lucide-vue-next'
import { errorMessage, http } from '@/lib/http'
import { download } from '@/lib/download'
import { fDateTime, fDuration, TZ } from '@/lib/format'
import { toast } from '@/lib/toast'
import type { Paginated, VehicleRow } from '@/lib/types'
import { useAuthStore } from '@/stores/auth'
import { useFleetStore } from '@/stores/fleet'
import MapView from '@/components/map/MapView.vue'
import { vehicleIcon, zoneLayer } from '@/components/map/vehicleMarker'
import DataTable, { type Column } from '@/components/DataTable.vue'
import StatCard from '@/components/StatCard.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip)

type VisitRow = { id: number; vehicle: { id: number; name: string; plate_number: string } | null; driver: string | null; entered_at: string; exited_at: string | null; duration_seconds: number; ongoing: boolean; mission: { id: number; reference: string; status: string } | null }
type Resp = Paginated<VisitRow> & {
  zone: { id: number; name: string; type: string; type_label: string; shape: 'circle' | 'polygon'; shape_label: string; radius_m: number | null; address: string | null; customer: { code: string; name: string } | null; min_visit_seconds: number; center: [number, number]; coordinates: [number, number][] | null; color: string }
  stats: { visits: number; ongoing: number; avg_seconds: number; median_seconds: number; total_seconds: number; vehicles: number; fleet_size: number; by_vehicle: { vehicle: string; vehicle_id: number | null; seconds: number; visits: number }[] }
}

const route = useRoute()
const auth = useAuthStore()
const fleet = useFleetStore()
const id = Number(route.params.id)
const day = (offset: number) => new Intl.DateTimeFormat('en-CA', { timeZone: TZ }).format(new Date(Date.now() - offset * 86400000))
const f = reactive({ preset: '7d', from: day(6), to: day(0), vehicle_id: 0, page: 1 })
const res = ref<Resp | null>(null)
const loading = ref(true)
const error = ref('')
const vehicles = ref<VehicleRow[]>([])

async function load() {
  loading.value = true
  try {
    res.value = (await http.get<Resp>(`/api/geofences/${id}/visits`, { params: { from: f.from, to: f.to, vehicle_id: f.vehicle_id || undefined, page: f.page } })).data
    route.meta.title = `Visites — ${res.value.zone.name}`
    error.value = ''
    drawMap()
  } catch (e) {
    error.value = errorMessage(e, 'Impossible de charger les visites.')
  } finally {
    loading.value = false
  }
}
const PRESETS = [{ key: 'today', label: 'Aujourd’hui', days: 0 }, { key: '7d', label: '7 derniers jours', days: 6 }, { key: '30d', label: '30 jours', days: 29 }]
function preset(key: string, days: number) { f.preset = key; f.from = day(days); f.to = day(0) }
watch(() => [f.from, f.to, f.vehicle_id], () => { f.page = 1; load() })

// Graphique : temps passé par camion (barres horizontales)
const chart = computed(() => {
  const b = res.value?.stats.by_vehicle ?? []
  return { labels: b.map((x) => x.vehicle), datasets: [{ data: b.map((x) => +(x.seconds / 3600).toFixed(2)), backgroundColor: '#1D4ED8', borderRadius: 4, maxBarThickness: 22 }] }
})
const chartOptions = {
  indexAxis: 'y' as const, responsive: true, maintainAspectRatio: false,
  plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c: { dataIndex: number }) => { const b = res.value!.stats.by_vehicle[c.dataIndex]; return `${fDuration(b.seconds)} · ${b.visits} visite(s)` } } } },
  scales: { x: { beginAtZero: true, title: { display: true, text: 'heures' }, grid: { color: '#EEF2F6' } }, y: { grid: { display: false } } },
}

// Mini-carte : la zone et les camions actuellement dedans
const map = shallowRef<L.Map | null>(null)
const layer = L.layerGroup()
function drawMap() {
  if (!map.value || !res.value) return
  layer.clearLayers()
  const z = res.value.zone
  const shape = zoneLayer({ ...z, radius_m: z.radius_m })
  shape.addTo(layer)
  fleet.list.filter((v) => v.zone?.id === z.id && v.latitude != null).forEach((v) => L.marker([v.latitude!, v.longitude!], { icon: vehicleIcon(v) }).addTo(layer))
  map.value.invalidateSize()
  map.value.fitBounds((shape as L.Polygon).getBounds().pad(0.4))
}
function onMap(m: L.Map) { map.value = m; layer.addTo(m); requestAnimationFrame(drawMap) }

const columns: Column[] = [
  { key: 'vehicle', label: 'Camion' }, { key: 'driver', label: 'Chauffeur' }, { key: 'entered_at', label: 'Entrée' }, { key: 'exited_at', label: 'Sortie' },
  { key: 'duration', label: 'Durée', align: 'right' }, { key: 'mission', label: 'Mission' }, { key: 'source', label: 'Source' },
]

async function exportAs(format: 'pdf' | 'xlsx') {
  try { await download(`/api/geofences/${id}/visits/export`, { format, from: f.from, to: f.to, vehicle_id: f.vehicle_id || undefined }, `visites.${format}`) }
  catch (e) { toast(errorMessage(e, 'Export impossible.'), 'error') }
}

// Temps réel : une entrée / sortie dans cette zone recharge la page (au plus toutes les 3 s).
let pending: ReturnType<typeof setTimeout> | null = null
let tick: ReturnType<typeof setInterval> | null = null
const now = ref(Date.now())
watch(() => fleet.activity[0], (a) => {
  if (a && (a.kind === 'zone_enter' || a.kind === 'zone_exit') && Number(a.data?.geofence_id) === id && !pending) pending = setTimeout(() => { pending = null; load() }, 3000)
})
onMounted(async () => {
  if (auth.can('tracking.view')) { fleet.start(); if (!fleet.list.length) fleet.load().then(drawMap) }
  load()
  tick = setInterval(() => { now.value = Date.now() }, 30000)
  vehicles.value = (await http.get<Paginated<VehicleRow>>('/api/vehicles', { params: { per_page: 100 } }).catch(() => ({ data: { data: [] } }))).data.data as VehicleRow[]
})
onBeforeUnmount(() => { if (tick) clearInterval(tick); if (pending) clearTimeout(pending) })
const liveDuration = (r: VisitRow) => (r.ongoing ? Math.max(r.duration_seconds, Math.floor((now.value - new Date(r.entered_at).getTime()) / 1000)) : r.duration_seconds)
</script>

<template>
  <nav class="text-[13px] text-muted" aria-label="Fil d’Ariane"><RouterLink to="/zones">Zones</RouterLink> / <RouterLink :to="`/zones?z=${id}`">{{ res?.zone.name ?? '…' }}</RouterLink> / Visites</nav>
  <ErrorState v-if="error && !res" :message="error" @retry="load" />
  <LoadingState v-else-if="!res" />
  <template v-else>
    <section class="card flex flex-wrap items-center justify-between gap-4 p-5">
      <div>
        <div class="flex items-center gap-2.5"><h2 class="m-0 text-2xl font-bold">{{ res.zone.name }}</h2><StatusBadge tone="blue" :label="res.zone.type_label" :icon="Hexagon" /></div>
        <div class="mt-1 text-sm text-muted">
          {{ res.zone.shape === 'circle' ? `Cercle de ${res.zone.radius_m} m` : 'Polygone' }}<template v-if="res.zone.address"> · {{ res.zone.address }}</template><template v-if="res.zone.customer"> · client {{ res.zone.customer.code }}</template> · durée minimale {{ Math.round(res.zone.min_visit_seconds / 60) }} min
        </div>
      </div>
      <div v-if="auth.can('geofences.export')" class="flex gap-2">
        <button type="button" class="btn btn-secondary" @click="exportAs('pdf')"><FileDown :size="16" />PDF</button>
        <button type="button" class="btn btn-secondary" @click="exportAs('xlsx')"><FileSpreadsheet :size="16" />Excel</button>
      </div>
    </section>

    <section class="card flex flex-wrap items-end gap-3 p-4">
      <div class="field">Période
        <div class="flex flex-wrap gap-1.5">
          <button v-for="p in PRESETS" :key="p.key" type="button" class="rounded-full border px-3 py-1.5 text-[13px] font-semibold" :aria-pressed="f.preset === p.key"
            :class="f.preset === p.key ? 'border-brand bg-brand text-white' : 'border-line hover:bg-page'" @click="preset(p.key, p.days)">{{ p.label }}</button>
        </div>
      </div>
      <label class="field">Du<input v-model="f.from" type="date" class="input" :max="f.to" @change="f.preset = 'custom'" /></label>
      <label class="field">Au<input v-model="f.to" type="date" class="input" :min="f.from" :max="day(0)" @change="f.preset = 'custom'" /></label>
      <label class="field min-w-48">Camion<select v-model.number="f.vehicle_id" class="input"><option :value="0">Tous les camions</option><option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.name }}</option></select></label>
    </section>

    <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3.5">
      <StatCard label="Visites" :value="res.stats.visits" :hint="`dont ${res.stats.ongoing} en cours`" :icon="Hexagon" />
      <StatCard label="Durée moyenne" :value="fDuration(res.stats.avg_seconds)" :hint="`médiane ${fDuration(res.stats.median_seconds)}`" tone="orange" :icon="Timer" />
      <StatCard label="Temps total" :value="fDuration(res.stats.total_seconds)" hint="sur la période" tone="blue" :icon="Clock" />
      <StatCard label="Camions distincts" :value="res.stats.vehicles" :hint="`sur ${res.stats.fleet_size}`" tone="green" :icon="Truck" />
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
      <section class="card p-5">
        <h3 class="m-0 mb-3 text-base font-bold">Temps passé dans la zone par camion</h3>
        <div v-if="res.stats.by_vehicle.length" class="h-64" role="img" :aria-label="res.stats.by_vehicle.map((b) => `${b.vehicle} ${fDuration(b.seconds)}`).join(', ')"><Bar :data="chart" :options="chartOptions as never" /></div>
        <p v-else class="text-sm text-muted">Aucune visite sur la période.</p>
      </section>
      <section class="card flex flex-col overflow-hidden">
        <div class="flex items-center justify-between border-b border-line px-5 py-3"><h3 class="m-0 text-base font-bold">Zone</h3><span class="flex items-center gap-1.5 text-xs text-muted"><Radio :size="13" />camions présents en direct</span></div>
        <div class="relative h-64 flex-1"><MapView @ready="onMap" /></div>
      </section>
    </div>

    <section class="card p-5">
      <h3 class="m-0 text-base font-bold">Historique des visites</h3>
      <p class="m-0 mb-3 text-[13px] text-muted">{{ res.meta.total }} visites · tri par entrée, plus récentes d’abord</p>
      <DataTable :columns="columns" :rows="res.data" :loading="loading" :total="res.meta.total" :page="f.page" :per-page="res.meta.per_page" item-label="visites" empty-title="Aucune visite" empty-message="Aucune visite sur cette période." min-width="860px" @update:page="(p) => { f.page = p; load() }">
        <template #cell-vehicle="{ row }"><RouterLink v-if="row.vehicle" :to="`/camions/${row.vehicle.id}`" class="font-bold text-ink">{{ row.vehicle.name }}</RouterLink></template>
        <template #cell-driver="{ row }">{{ row.driver ?? '—' }}</template>
        <template #cell-entered_at="{ row }"><span class="whitespace-nowrap">{{ fDateTime(row.entered_at) }}</span></template>
        <template #cell-exited_at="{ row }"><span class="whitespace-nowrap">{{ row.exited_at ? fDateTime(row.exited_at) : '—' }}</span></template>
        <template #cell-duration="{ row }"><span class="num" :class="row.ongoing ? 'font-semibold text-brand' : ''">{{ fDuration(liveDuration(row)) }}</span></template>
        <template #cell-mission="{ row }"><span class="whitespace-nowrap">{{ row.mission?.reference ?? '—' }}</span></template>
        <template #cell-source="{ row }"><StatusBadge v-if="row.ongoing" tone="blue" label="En direct" :icon="Radio" /><StatusBadge v-else tone="green" label="Événement" :icon="Hexagon" /></template>
      </DataTable>
    </section>
  </template>
</template>
