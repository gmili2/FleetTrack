<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import L from 'leaflet'
import { ClipboardList, Crosshair, History, Phone, Radio, Search, Smartphone, Truck, WifiOff } from 'lucide-vue-next'
import { http } from '@/lib/http'
import { fDuration, fKm, fTime, fTimeSec } from '@/lib/format'
import { ago, cardinal, since } from '@/lib/time'
import type { LiveDetail, LiveStatus, LiveVehicle } from '@/lib/types'
import { useFleetStore } from '@/stores/fleet'
import MapView from '@/components/map/MapView.vue'
import { animateTo, setCourse, vehicleIcon, zoneLayer } from '@/components/map/vehicleMarker'
import StatusBadge from '@/components/StatusBadge.vue'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import EmptyState from '@/components/EmptyState.vue'

const fleet = useFleetStore()
const route = useRoute()
const router = useRouter()

// ── Liste & filtres ────────────────────────────────────────────────────────
const q = ref('')
const filter = ref<'all' | LiveStatus>('all')
const ORDER: Record<LiveStatus, number> = { moving: 0, stopped: 1, offline: 2 }
const rows = computed(() => {
  const s = q.value.trim().toLowerCase()
  return fleet.list
    .filter((v) => filter.value === 'all' || v.status === filter.value)
    .filter((v) => !s || [v.name, v.plate_number, v.driver?.name, v.address].some((x) => x?.toLowerCase().includes(s)))
    .sort((a, b) => ORDER[a.status] - ORDER[b.status] || a.name.localeCompare(b.name))
})
const CHIPS = [
  { key: 'all', label: 'Tous' }, { key: 'moving', label: 'En mouvement' }, { key: 'stopped', label: 'Arrêt' }, { key: 'offline', label: 'Hors ligne' },
] as const

function freshness(v: LiveVehicle) {
  if (v.status === 'stopped') return `arrêt ${since(v.status_since, fleet.now)}`
  return ago(v.last_position_at, fleet.now)
}

// ── Sélection & détail ─────────────────────────────────────────────────────
const selectedId = ref<number | null>(route.query.v ? Number(route.query.v) : null)
const selected = computed(() => (selectedId.value ? fleet.vehicles[selectedId.value] : null))
const detail = ref<LiveDetail | null>(null)
const detailLoading = ref(false)
const follow = ref(route.query.follow === '1')

async function loadDetail() {
  if (!selectedId.value) return
  detailLoading.value = !detail.value || detail.value.id !== selectedId.value
  try {
    const { data } = await http.get<{ data: LiveDetail }>(`/api/live/${selectedId.value}`)
    if (data.data.id === selectedId.value) {
      detail.value = data.data
      drawTrail(data.data.trail)
    }
  } catch { /* le panneau garde les données temps réel */ } finally {
    detailLoading.value = false
  }
}

function select(id: number, fly = true) {
  if (selectedId.value !== id) { follow.value = route.query.follow === '1' && String(id) === route.query.v; detail.value = null }
  selectedId.value = id
  router.replace({ query: { ...route.query, v: String(id) } })
  const v = fleet.vehicles[id]
  if (fly && map.value && v?.latitude != null) map.value.flyTo([v.latitude, v.longitude!], Math.max(map.value.getZoom(), 14), { duration: 0.8 })
  loadDetail()
}
watch(selectedId, (id, old) => { [id, old].forEach((x) => x && refreshIcon(x)) })

// ── Carte ──────────────────────────────────────────────────────────────────
const map = shallowRef<L.Map | null>(null)
const markers = new Map<number, { marker: L.Marker; status: LiveStatus; selected: boolean }>()
const zoneGroup = L.layerGroup()
const trail = L.polyline([], { color: '#1D4ED8', weight: 4, opacity: 0.55 })
const showZones = ref(true)
const showNames = ref(true)
const basemap = ref<'standard' | 'light'>('standard')

function drawZones() {
  zoneGroup.clearLayers()
  for (const z of fleet.zones) {
    const layer = zoneLayer(z)
    if (showNames.value) layer.bindTooltip(z.name, { permanent: true, direction: 'center', className: 'ft-zone-label' })
    else layer.bindTooltip(`${z.name} · ${z.type_label}`)
    zoneGroup.addLayer(layer)
  }
}
watch(showNames, drawZones)
watch(showZones, (on) => { if (map.value) on ? zoneGroup.addTo(map.value) : zoneGroup.remove() })

function sync(v: LiveVehicle) {
  if (!map.value || v.latitude == null || v.longitude == null) return
  const isSel = v.id === selectedId.value
  const m = markers.get(v.id)
  if (!m) {
    const marker = L.marker([v.latitude, v.longitude], { icon: vehicleIcon(v, isSel), zIndexOffset: isSel ? 1000 : 0, keyboard: true, title: v.name })
      .on('click', () => select(v.id, false)).addTo(map.value)
    markers.set(v.id, { marker, status: v.status, selected: isSel })
    return
  }
  if (m.status !== v.status || m.selected !== isSel) {
    m.marker.setIcon(vehicleIcon(v, isSel)).setZIndexOffset(isSel ? 1000 : 0)
    m.status = v.status
    m.selected = isSel
  }
  setCourse(m.marker, v.course)
  animateTo(m.marker, [v.latitude, v.longitude])
  if (isSel) {
    trail.addLatLng([v.latitude, v.longitude])
    if (follow.value) map.value.panTo([v.latitude, v.longitude], { animate: true, duration: 1 })
  }
}
function refreshIcon(id: number) {
  const v = fleet.vehicles[id]
  const m = markers.get(id)
  if (v && m) { m.selected = id === selectedId.value; m.marker.setIcon(vehicleIcon(v, m.selected)).setZIndexOffset(m.selected ? 1000 : 0) }
}
function drawTrail(points: [number, number][]) {
  trail.setLatLngs(points)
  if (map.value && !map.value.hasLayer(trail)) trail.addTo(map.value)
}

function onMapReady(m: L.Map) {
  map.value = m
  zoneGroup.addTo(m)
  drawZones()
  fleet.list.forEach(sync)
  // Attendre la taille définitive du conteneur avant de cadrer.
  requestAnimationFrame(() => { fitAll(); if (selectedId.value) select(selectedId.value) })
}
function fitAll() {
  map.value?.invalidateSize()
  const pts = fleet.list.filter((v) => v.latitude != null).map((v) => [v.latitude!, v.longitude!] as [number, number])
  fleet.zones.forEach((z) => pts.push(z.center))
  if (map.value && pts.length) map.value.fitBounds(L.latLngBounds(pts).pad(0.08), { maxZoom: 13 })
}

// ── Cycle de vie ───────────────────────────────────────────────────────────
let unsubscribe: (() => void) | null = null
let detailTimer: ReturnType<typeof setInterval> | null = null
onMounted(async () => {
  fleet.start()
  if (!fleet.list.length) await fleet.load()
  else fleet.load()
  if (map.value) { drawZones(); fleet.list.forEach(sync); if (!selectedId.value) requestAnimationFrame(fitAll) }
  unsubscribe = fleet.onUpdate(sync)
  detailTimer = setInterval(loadDetail, 30000)
})
onBeforeUnmount(() => { unsubscribe?.(); if (detailTimer) clearInterval(detailTimer) })

const liveLabel = computed(() => {
  if (fleet.connection === 'unavailable') return 'Temps réel indisponible'
  if (fleet.connection === 'connecting') return 'Connexion au temps réel…'
  return `En direct · mis à jour ${ago(fleet.lastUpdate ? new Date(fleet.lastUpdate).toISOString() : null, fleet.now)}`
})
const simulatorDown = computed(() => {
  const hb = fleet.simulator.heartbeat_at
  return !fleet.loading && fleet.list.length > 0 && (!hb || fleet.now - new Date(hb).getTime() > 60000) && !fleet.list.some((v) => v.status === 'moving')
})
</script>

<template>
  <div class="grid min-h-0 flex-1 grid-cols-1 xl:flex-none lg:grid-cols-[320px_minmax(0,1fr)] xl:h-[calc(100dvh-77px)] xl:grid-cols-[320px_minmax(0,1fr)_340px] xl:grid-rows-[minmax(0,1fr)]">
    <!-- Liste des camions -->
    <aside class="flex min-h-0 flex-col gap-3 border-line bg-white p-4 lg:border-r" aria-label="Liste des camions">
      <label class="flex h-10 items-center gap-2 rounded-[10px] border border-line bg-page px-3 text-muted">
        <Search :size="17" aria-hidden="true" /><span class="sr-only">Filtrer les camions</span>
        <input v-model="q" type="search" placeholder="Filtrer les camions" class="min-w-0 flex-1 border-0 bg-transparent text-sm text-ink outline-none" />
      </label>
      <div class="flex flex-wrap gap-2" role="group" aria-label="Filtrer par statut">
        <button v-for="c in CHIPS" :key="c.key" type="button" :aria-pressed="filter === c.key"
          class="inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3.5 text-[13px] font-semibold"
          :class="filter === c.key ? 'border-brand bg-brand text-white' : 'border-line bg-white text-ink hover:bg-page'" @click="filter = c.key">
          {{ c.label }}<span class="text-xs font-bold opacity-85">{{ fleet.counts[c.key] }}</span>
        </button>
      </div>
      <div class="flex justify-between text-xs text-muted"><span>{{ rows.length }} camion{{ rows.length > 1 ? 's' : '' }}</span><span>Tri : statut</span></div>

      <ErrorState v-if="fleet.error" :message="fleet.error" @retry="fleet.load()" />
      <LoadingState v-else-if="fleet.loading && !fleet.list.length" label="Chargement de la flotte…" />
      <EmptyState v-else-if="!rows.length" :icon="Truck" title="Aucun camion" message="Aucun camion ne correspond à ces filtres." />
      <ul v-else class="-mr-2 flex max-h-[45vh] min-h-0 flex-col gap-2 overflow-y-auto pr-2 lg:max-h-[60vh] xl:max-h-none xl:flex-1">
        <li v-for="v in rows" :key="v.id">
          <button type="button" :aria-pressed="v.id === selectedId"
            class="flex w-full flex-col gap-1.5 rounded-xl border p-3 text-left transition-colors"
            :class="v.id === selectedId ? 'border-brand bg-[#EFF6FF]' : 'border-line bg-white hover:bg-page'" @click="select(v.id)">
            <span class="flex w-full items-center justify-between gap-2 [&>*:last-child]:flex-none">
              <span class="min-w-0 truncate text-[15px] font-bold whitespace-nowrap text-ink">{{ v.name }} <span class="text-[13px] font-medium text-muted">· {{ v.plate_number }}</span></span>
              <StatusBadge :status="v.status" />
            </span>
            <span class="flex items-center justify-between gap-2 text-[13px] text-ink">
              <span class="truncate">{{ v.driver?.name ?? (v.shift?.unknown_badge ? `Badge inconnu ${v.shift.unknown_badge}` : 'Sans chauffeur') }}</span>
              <span v-if="v.shift" class="inline-flex flex-none items-center gap-1 text-xs text-muted"><Smartphone v-if="v.shift.source === 'app'" :size="12" aria-label="pointé depuis l’application" />service {{ fTime(v.shift.start_at) }}</span>
            </span>
            <span class="flex w-full justify-between gap-2 text-xs text-muted">
              <span class="truncate">{{ v.address ?? '—' }}</span>
              <span class="num font-semibold whitespace-nowrap text-ink">{{ v.status === 'offline' ? '—' : `${v.speed_kmh} km/h` }}</span>
            </span>
            <span class="text-xs text-muted">{{ freshness(v) }}</span>
          </button>
        </li>
      </ul>
    </aside>

    <!-- Carte -->
    <section class="relative h-[60vh] min-h-[360px] xl:h-auto" aria-label="Carte de la flotte">
      <MapView :basemap="basemap" @ready="onMapReady" />
      <div class="pointer-events-none absolute inset-x-3 top-3 z-[500] flex flex-wrap items-start justify-between gap-2">
        <div class="pointer-events-auto inline-flex items-center gap-2 rounded-full bg-white px-3 py-1.5 text-[13px] font-semibold text-ink shadow" role="status" aria-live="polite">
          <span class="relative flex size-2.5">
            <span v-if="fleet.connection === 'connected'" class="absolute inline-flex size-full animate-ping rounded-full bg-moving opacity-60" />
            <span class="relative inline-flex size-2.5 rounded-full" :class="fleet.connection === 'connected' ? 'bg-moving' : fleet.connection === 'unavailable' ? 'bg-danger' : 'bg-stopped'" />
          </span>
          {{ liveLabel }}
        </div>
        <div class="pointer-events-auto flex flex-wrap gap-1.5 rounded-xl bg-white p-1.5 text-[13px] font-semibold shadow" role="group" aria-label="Options de la carte">
          <button type="button" class="rounded-lg px-2.5 py-1.5" :class="showZones ? 'bg-brand-soft text-brand-dark' : 'text-muted hover:bg-page'" :aria-pressed="showZones" @click="showZones = !showZones">Zones</button>
          <button type="button" class="rounded-lg px-2.5 py-1.5" :class="showNames ? 'bg-brand-soft text-brand-dark' : 'text-muted hover:bg-page'" :aria-pressed="showNames" @click="showNames = !showNames">Noms</button>
          <button type="button" class="rounded-lg px-2.5 py-1.5 text-muted hover:bg-page" @click="basemap = basemap === 'standard' ? 'light' : 'standard'">Fond de carte : {{ basemap === 'standard' ? 'standard' : 'clair' }}</button>
          <button type="button" class="rounded-lg px-2.5 py-1.5 text-muted hover:bg-page" @click="fitAll">Toute la flotte</button>
        </div>
      </div>
      <div v-if="simulatorDown" class="absolute inset-x-3 top-16 z-[500] mx-auto max-w-md rounded-xl border border-[#FED7AA] bg-[#FFF7ED] px-4 py-3 text-[13px] text-[#9A3412] shadow" role="alert">
        Aucun camion ne roule : le simulateur ne semble pas lancé. Lancez <code class="font-semibold">php artisan fleet:simulate</code> (ou <code class="font-semibold">./demo.sh</code>).
      </div>
      <div class="absolute bottom-6 left-3 z-[500] flex flex-col gap-1.5 rounded-xl bg-white/95 px-3 py-2.5 text-xs text-ink shadow" aria-label="Légende">
        <span class="flex items-center gap-2"><span class="flex size-4 items-center justify-center rounded-full bg-moving"><svg width="9" height="9" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l6.5 17L12 16l-6.5 4z" fill="#fff" /></svg></span>En mouvement (flèche = cap)</span>
        <span class="flex items-center gap-2"><span class="flex size-4 items-center justify-center rounded-full bg-stopped"><span class="size-1.5 rounded-[1px] bg-white" /></span>À l’arrêt</span>
        <span class="flex items-center gap-2"><span class="flex size-4 items-center justify-center rounded-full bg-offline"><WifiOff :size="9" color="#fff" aria-hidden="true" /></span>Hors ligne</span>
      </div>
    </section>

    <!-- Panneau détail -->
    <aside class="flex min-h-0 flex-col gap-4 overflow-y-auto border-line bg-white p-5 lg:col-span-2 lg:border-t xl:col-span-1 xl:border-t-0 xl:border-l" aria-label="Détail du camion">
      <EmptyState v-if="!selected" :icon="Crosshair" title="Sélectionnez un camion" message="Cliquez sur un camion dans la liste ou sur la carte pour voir sa position, son chauffeur et sa journée." />
      <template v-else>
        <div>
          <div class="flex items-start justify-between gap-2">
            <h2 class="m-0 text-xl font-bold text-ink">{{ selected.name }}</h2>
            <StatusBadge :status="selected.status" />
          </div>
          <div class="mt-0.5 text-[13px] text-muted">{{ selected.plate_number }} · {{ selected.brand }} {{ selected.model }} · {{ selected.type_label }}</div>
        </div>

        <div class="grid grid-cols-2 gap-2.5">
          <div class="rounded-xl bg-page p-3"><div class="text-xs text-muted">Vitesse</div><div class="num text-2xl font-bold" :class="selected.speed_kmh > selected.speed_limit_kmh ? 'text-danger' : 'text-ink'">{{ selected.status === 'offline' ? '—' : selected.speed_kmh }} <span class="text-sm font-semibold text-muted">km/h</span></div></div>
          <div class="rounded-xl bg-page p-3"><div class="text-xs text-muted">Direction</div><div class="text-lg font-bold text-ink">{{ cardinal(selected.course) }}</div></div>
        </div>

        <dl class="m-0 grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-[13px]">
          <dt class="text-muted">Dernière position</dt><dd class="m-0 text-right font-semibold">{{ fTimeSec(selected.last_position_at) }} <span class="font-normal text-muted">({{ ago(selected.last_position_at, fleet.now) }})</span></dd>
          <dt class="text-muted">Adresse</dt><dd class="m-0 text-right font-semibold">{{ selected.address ?? '—' }}</dd>
          <dt class="text-muted">Coordonnées</dt><dd class="num m-0 text-right font-semibold">{{ selected.latitude?.toFixed(4) }}, {{ selected.longitude?.toFixed(4) }}</dd>
          <dt class="text-muted">Contact moteur</dt><dd class="m-0 text-right font-semibold">{{ selected.ignition ? 'Allumé' : 'Coupé' }}</dd>
          <dt class="text-muted">Boîtier</dt><dd class="m-0 text-right font-semibold">{{ selected.device?.model ?? '—' }} <span v-if="selected.device" class="font-normal text-muted">· …{{ selected.device.imei.slice(-4) }}</span></dd>
        </dl>

        <div v-if="selected.driver" class="flex items-center gap-3 rounded-xl border border-line p-3">
          <div class="flex size-10 flex-none items-center justify-center rounded-full bg-brand-soft text-[13px] font-bold text-brand-dark">{{ selected.driver.name.split(' ').map((p) => p[0]).slice(0, 2).join('') }}</div>
          <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-semibold">{{ selected.driver.name }}</div>
            <div class="truncate text-xs text-muted">{{ selected.driver.on_shift ? `En service depuis ${fTime(selected.shift?.start_at)}${selected.shift?.source === 'app' ? ' (application)' : ''}` : 'Chauffeur affecté · pas de pointage' }} · {{ selected.driver.employee_number }}</div>
          </div>
          <a v-if="selected.driver.phone" :href="`tel:${selected.driver.phone.replace(/\s/g, '')}`" class="btn-icon" :aria-label="`Appeler ${selected.driver.name}`"><Phone :size="16" /></a>
        </div>
        <div v-else class="rounded-xl border border-dashed border-field p-3 text-[13px] text-muted">{{ selected.shift?.unknown_badge ? `Badge inconnu ${selected.shift.unknown_badge} : conduite non identifiée.` : 'Aucun chauffeur affecté.' }}</div>
        <RouterLink v-if="selected.driver" :to="`/chauffeurs/${selected.driver.id}`" class="-mt-2 text-[13px] font-semibold">Historique de {{ selected.driver.name }} →</RouterLink>

        <div v-if="selected.mission" class="rounded-xl border border-line p-3">
          <div class="flex items-center justify-between gap-2"><b class="text-sm">{{ selected.mission.reference }}</b><StatusBadge tone="blue" label="En cours" :icon="ClipboardList" /></div>
          <div class="mt-1.5 text-[13px] text-ink">{{ selected.mission.origin }} → {{ selected.mission.destination }}</div>
          <div class="mt-1 flex justify-between text-xs text-muted"><span>Départ {{ fTime(selected.mission.actual_start_at) }}</span><span>Fin prévue {{ fTime(selected.mission.planned_end_at) }}</span></div>
        </div>

        <div>
          <h3 class="m-0 mb-2 text-sm font-bold">Aujourd’hui</h3>
          <LoadingState v-if="detailLoading" label="Calcul de la journée…" />
          <div v-else class="grid grid-cols-3 gap-2 text-center">
            <div class="rounded-xl bg-page p-2.5"><div class="text-xs text-muted">Distance</div><div class="num text-[15px] font-bold">{{ fKm(selected.today_distance_km) }}</div></div>
            <div class="rounded-xl bg-page p-2.5"><div class="text-xs text-muted">Conduite</div><div class="num text-[15px] font-bold">{{ fDuration(detail?.today.driving_seconds) }}</div></div>
            <div class="rounded-xl bg-page p-2.5"><div class="text-xs text-muted">Arrêts</div><div class="num text-[15px] font-bold">{{ detail?.today.stops_count ?? '—' }}</div></div>
          </div>
        </div>

        <div v-if="detail?.events.length">
          <h3 class="m-0 mb-2 text-sm font-bold">Derniers événements</h3>
          <ul class="m-0 flex list-none flex-col gap-1.5 p-0 text-[13px]">
            <li v-for="(e, i) in detail.events" :key="i" class="flex items-center justify-between gap-2">
              <span class="min-w-0 truncate"><StatusBadge :tone="e.tone" :label="e.label" :icon="Radio" /> <span v-if="e.zone" class="text-muted">{{ e.zone }}</span></span>
              <span class="num flex-none text-xs text-muted">{{ fTime(e.occurred_at) }}</span>
            </li>
          </ul>
        </div>

        <div class="mt-auto grid grid-cols-3 gap-2">
          <button type="button" class="btn px-2" :class="follow ? 'btn-primary' : 'btn-secondary'" :aria-pressed="follow" @click="follow = !follow; if (follow && selected.latitude != null) map?.panTo([selected.latitude, selected.longitude!])"><Crosshair :size="16" />Suivre</button>
          <RouterLink :to="`/historique?vehicle=${selected.id}`" class="btn btn-secondary px-2"><History :size="16" />Historique</RouterLink>
          <RouterLink :to="`/camions/${selected.id}`" class="btn btn-secondary px-2"><Truck :size="16" />Fiche</RouterLink>
        </div>
      </template>
    </aside>
  </div>
</template>

<style>
.ft-vehicle { background: none; border: 0; }
.ft-zone-label { background: rgba(255,255,255,.9); border: 0; box-shadow: 0 1px 3px rgba(15,23,42,.15); color: #334155; font: 600 11px Inter, system-ui, sans-serif; padding: 1px 6px; }
.ft-zone-label::before { display: none; }
.leaflet-container { font-family: Inter, system-ui, sans-serif; }
</style>
