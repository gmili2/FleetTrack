<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { useRouter } from 'vue-router'
import L from 'leaflet'
import { Bar } from 'vue-chartjs'
import { BarElement, CategoryScale, Chart as ChartJS, LinearScale, Tooltip } from 'chart.js'
import { ArrowRight, Clock, ClipboardList, Gauge, IdCard, MapPin, Navigation, Radio, Route, Square, Truck, WifiOff } from 'lucide-vue-next'
import { errorMessage, http } from '@/lib/http'
import { fDuration, fLongDate, fNumber } from '@/lib/format'
import { ago } from '@/lib/time'
import { useFleetStore } from '@/stores/fleet'
import MapView from '@/components/map/MapView.vue'
import { animateTo, setCourse, vehicleIcon, zoneLayer } from '@/components/map/vehicleMarker'
import StatCard from '@/components/StatCard.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import ErrorState from '@/components/ErrorState.vue'
import EmptyState from '@/components/EmptyState.vue'
import type { LiveVehicle } from '@/lib/types'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip)

type Dashboard = {
  kpis: { total: number; active: number; maintenance: number; moving: number; stopped: number; stopped_at_customer: number; offline: number; missions_in_progress: number; missions_late: number; missions_pending: number; stops_today: number; stops_avg_seconds: number; distance_today_km: number; distance_yesterday_km: number }
  week: { date: string; distance_km: number }[]
  top: { id: number; name: string; driver: string | null; distance_km: number; status: 'moving' | 'stopped' | 'offline' }[]
  activities: { kind: string; text: string; detail: string | null; vehicle_id: number | null; at: string }[]
}

const router = useRouter()
const fleet = useFleetStore()
const data = ref<Dashboard | null>(null)
const error = ref('')

async function load() {
  try {
    data.value = (await http.get<{ data: Dashboard }>('/api/dashboard')).data.data
    error.value = ''
  } catch (e) {
    error.value = errorMessage(e, 'Impossible de charger le tableau de bord.')
  }
}

// Les compteurs de statut et la distance du jour suivent le temps réel ; le reste est rafraîchi chaque minute.
const k = computed(() => data.value?.kpis)
const liveCount = (s: 'moving' | 'stopped' | 'offline') => (fleet.list.length ? fleet.counts[s] : k.value?.[s] ?? 0)
const distanceToday = computed(() => (fleet.list.length ? Math.round(fleet.list.reduce((s, v) => s + (v.today_distance_km ?? 0), 0)) : k.value?.distance_today_km ?? 0))
const pct = (n: number) => (k.value?.total ? Math.round((n / k.value.total) * 100) : 0)
const vsYesterday = computed(() => {
  const y = k.value?.distance_yesterday_km ?? 0
  if (!y) return 'premier jour de données'
  // La comparaison en % n'a de sens qu'en fin de journée : avant 17 h on affiche la référence.
  if (new Date().getHours() < 17) return `hier : ${fNumber(y)} km`
  const d = Math.round(((distanceToday.value - y) / y) * 100)
  return `${d >= 0 ? '+' : ''}${d} % vs hier (${fNumber(y)} km)`
})

// Activités : flux temps réel en tête, puis l'historique de l'API.
const ACT_ICON: Record<string, unknown> = { zone: MapPin, zone_enter: MapPin, zone_exit: MapPin, offline: WifiOff, online: Radio, overspeed: Gauge, badge: IdCard, badge_in: IdCard, badge_out: IdCard, mission: ClipboardList, anomaly: IdCard }
const ACT_TONE: Record<string, string> = { overspeed: 'bg-[#FEE2E2] text-danger', anomaly: 'bg-[#FEE2E2] text-danger', offline: 'bg-[#E2E8F0] text-offline', mission: 'bg-brand-soft text-brand', badge: 'bg-[#DCFCE7] text-moving', badge_in: 'bg-[#DCFCE7] text-moving', badge_out: 'bg-[#DCFCE7] text-moving' }
const activities = computed(() => {
  const live = fleet.activity.map((a) => ({ kind: a.kind, text: a.message, detail: (a.data?.driver as string) ?? null, vehicle_id: a.vehicleId, at: a.at }))
  const seen = new Set(live.map((a) => a.text))
  return [...live, ...(data.value?.activities ?? []).filter((a) => !seen.has(a.text))].slice(0, 8)
})

// Graphique 7 jours
const chart = computed(() => {
  const week = data.value?.week ?? []
  return {
    labels: week.map((d) => new Date(d.date + 'T12:00:00').toLocaleDateString('fr-FR', { weekday: 'short' }).replace('.', '')),
    datasets: [{
      data: week.map((d, i) => (i === week.length - 1 ? distanceToday.value : d.distance_km)),
      backgroundColor: week.map((_, i) => (i === week.length - 1 ? '#1D4ED8' : '#BFDBFE')),
      borderRadius: 6, maxBarThickness: 44,
    }],
  }
})
const chartOptions = {
  responsive: true, maintainAspectRatio: false, animation: { duration: 300 },
  plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c: { parsed: { y: number } }) => `${fNumber(c.parsed.y)} km` } } },
  scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: '#EEF2F6' }, ticks: { callback: (v: number | string) => fNumber(Number(v)) } } },
}
const top = computed(() => {
  if (!fleet.list.length) return data.value?.top ?? []
  return [...fleet.list].sort((a, b) => b.today_distance_km - a.today_distance_km).slice(0, 5)
    .map((v) => ({ id: v.id, name: v.name, driver: v.driver?.name ?? null, distance_km: Math.round(v.today_distance_km), status: v.status }))
})

// Carte de la flotte
const map = shallowRef<L.Map | null>(null)
const markers = new Map<number, { m: L.Marker; status: string }>()
function sync(v: LiveVehicle) {
  if (!map.value || v.latitude == null) return
  const e = markers.get(v.id)
  if (!e) { markers.set(v.id, { m: L.marker([v.latitude, v.longitude!], { icon: vehicleIcon(v) }).on('click', () => router.push(`/suivi?v=${v.id}`)).addTo(map.value), status: v.status }); return }
  if (e.status !== v.status) { e.m.setIcon(vehicleIcon(v)); e.status = v.status }
  setCourse(e.m, v.course)
  animateTo(e.m, [v.latitude, v.longitude!])
}
function onMap(m: L.Map) {
  map.value = m
  fleet.zones.forEach((z) => zoneLayer(z).bindTooltip(z.name).addTo(m))
  fleet.list.forEach(sync)
  requestAnimationFrame(() => {
    m.invalidateSize()
    const pts = fleet.list.filter((v) => v.latitude != null).map((v) => [v.latitude!, v.longitude!] as [number, number])
    if (pts.length) m.fitBounds(L.latLngBounds(pts).pad(0.15), { maxZoom: 13 })
  })
}
watch(() => fleet.zones.length, () => { if (map.value) fleet.zones.forEach((z) => zoneLayer(z).bindTooltip(z.name).addTo(map.value!)) })

let off: (() => void) | null = null
let refresh: ReturnType<typeof setInterval> | null = null
onMounted(async () => {
  load()
  fleet.start()
  if (!fleet.list.length) await fleet.load()
  if (map.value) onMap(map.value)
  off = fleet.onUpdate(sync)
  refresh = setInterval(load, 60000)
})
onBeforeUnmount(() => { off?.(); if (refresh) clearInterval(refresh) })
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div class="text-sm text-muted">{{ fLongDate() }} · données en direct</div>
    <RouterLink to="/suivi" class="btn btn-secondary"><Navigation :size="16" />Ouvrir le suivi</RouterLink>
  </div>
  <ErrorState v-if="error && !data" :message="error" @retry="load" />

  <div class="grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-3.5">
    <StatCard label="Total camions" :value="k?.total" :hint="`${k?.active ?? 0} actifs, ${k?.maintenance ?? 0} en maintenance`" :icon="Truck" :loading="!data" />
    <StatCard label="En mouvement" :value="liveCount('moving')" :hint="`${pct(liveCount('moving'))} % de la flotte`" tone="green" :icon="Navigation" :loading="!data" />
    <StatCard label="À l’arrêt" :value="liveCount('stopped')" :hint="`dont ${k?.stopped_at_customer ?? 0} chez un client`" tone="orange" :icon="Square" :loading="!data" />
    <StatCard label="Hors ligne" :value="liveCount('offline')" hint="sans position depuis 10 min" tone="gray" :icon="WifiOff" :loading="!data" />
    <StatCard label="Missions en cours" :value="k?.missions_in_progress" :hint="`${k?.missions_pending ?? 0} en attente${k?.missions_late ? ` · ${k.missions_late} en retard` : ''}`" tone="blue" :icon="ClipboardList" :loading="!data" />
    <StatCard label="Arrêts aujourd’hui" :value="k?.stops_today" :hint="`durée moyenne ${fDuration(k?.stops_avg_seconds)}`" tone="orange" :icon="Clock" :loading="!data" />
    <StatCard label="Distance du jour" :value="`${fNumber(distanceToday)} km`" :hint="vsYesterday" tone="green" :icon="Route" :loading="!data" />
  </div>

  <div class="grid gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
    <section class="card flex flex-col overflow-hidden">
      <div class="flex items-center justify-between border-b border-line px-5 py-3.5">
        <h2 class="m-0 text-base font-bold">Carte de la flotte</h2>
        <RouterLink to="/suivi" class="inline-flex items-center gap-1 text-sm font-semibold">Ouvrir le suivi<ArrowRight :size="15" /></RouterLink>
      </div>
      <div class="relative h-[380px]"><MapView @ready="onMap" /></div>
    </section>

    <section class="card flex flex-col p-5">
      <div class="mb-3 flex items-center justify-between"><h2 class="m-0 text-base font-bold">Dernières activités</h2><span class="flex items-center gap-1.5 text-xs text-muted"><span class="size-2 rounded-full" :class="fleet.connection === 'connected' ? 'bg-moving' : 'bg-stopped'" />en direct</span></div>
      <EmptyState v-if="!activities.length" title="Aucune activité" message="Les entrées en zone, pointages et alertes apparaîtront ici." />
      <ul v-else class="m-0 flex list-none flex-col p-0">
        <li v-for="(a, i) in activities" :key="i" class="flex gap-3 border-b border-line-soft py-2.5 last:border-0">
          <span class="flex size-8 flex-none items-center justify-center rounded-lg" :class="ACT_TONE[a.kind] ?? 'bg-brand-soft text-brand'"><component :is="ACT_ICON[a.kind] ?? Radio" :size="16" aria-hidden="true" /></span>
          <div class="min-w-0">
            <div class="text-[13px] font-semibold text-ink">{{ a.text }}</div>
            <div class="text-xs text-muted">{{ a.detail ? `${a.detail} · ` : '' }}{{ ago(a.at, fleet.now) }}</div>
          </div>
        </li>
      </ul>
    </section>
  </div>

  <div class="grid gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
    <section class="card p-5">
      <h2 class="m-0 text-base font-bold">Distance parcourue — 7 derniers jours</h2>
      <p class="m-0 mb-3 text-[13px] text-muted">km, toute la flotte · aujourd’hui en direct</p>
      <div class="h-64" role="img" :aria-label="`Distance des 7 derniers jours : ${chart.datasets[0].data.map((d, i) => `${chart.labels[i]} ${d} km`).join(', ')}`">
        <Bar v-if="data" :data="chart" :options="chartOptions as never" />
      </div>
    </section>
    <section class="card p-5">
      <h2 class="m-0 mb-3 text-base font-bold">Top 5 camions du jour</h2>
      <table class="w-full border-collapse text-sm">
        <thead><tr class="text-left text-xs font-semibold tracking-wide text-muted uppercase"><th class="pb-2">Camion</th><th class="pb-2">Chauffeur</th><th class="pb-2 text-right">Km</th><th class="pb-2 pl-3">Statut</th></tr></thead>
        <tbody>
          <tr v-for="t in top" :key="t.id" class="cursor-pointer border-t border-line-soft hover:bg-page" @click="router.push(`/camions/${t.id}`)">
            <td class="py-2.5 font-bold">{{ t.name }}</td><td class="py-2.5">{{ t.driver ?? '—' }}</td>
            <td class="num py-2.5 text-right font-semibold">{{ fNumber(t.distance_km) }}</td><td class="py-2.5 pl-3"><StatusBadge :status="t.status" /></td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>
