<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { useRoute } from 'vue-router'
import L from 'leaflet'
import { ClipboardList, Clock, Gauge, Hexagon, History, MapPin, Pencil, PlayCircle, Route, Square } from 'lucide-vue-next'
import { errorMessage, http } from '@/lib/http'
import { fDate, fDateTime, fDuration, fKm, fNumber, fTime, fTimeSec } from '@/lib/format'
import type { VehicleRow } from '@/lib/types'
import { useAuthStore } from '@/stores/auth'
import { useFleetStore } from '@/stores/fleet'
import MapView from '@/components/map/MapView.vue'
import { vehicleIcon, animateTo, setCourse } from '@/components/map/vehicleMarker'
import StatCard from '@/components/StatCard.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import EmptyState from '@/components/EmptyState.vue'
import VehicleForm from '@/components/VehicleForm.vue'
import { toast } from '@/lib/toast'

type Overview = { distance_km: number; avg_km_per_day: number; driving_seconds: number; stopped_seconds: number; stops_count: number; visits: number; customer_visits: number; missions: number; missions_completed: number; missions_cancelled: number; max_speed_kmh: number; overspeed_alerts: number }
type PageMeta = { current_page: number; last_page: number; total: number }

const route = useRoute()
const auth = useAuthStore()
const fleet = useFleetStore()
const id = computed(() => Number(route.params.id))
const vehicle = ref<VehicleRow | null>(null)
const overview = ref<Overview | null>(null)
const error = ref('')

async function load() {
  error.value = ''
  try {
    const [v, o] = await Promise.all([http.get<{ data: VehicleRow }>(`/api/vehicles/${id.value}`), http.get<{ data: Overview }>(`/api/vehicles/${id.value}/overview`)])
    vehicle.value = v.data.data
    overview.value = o.data.data
    route.meta.title = v.data.data.name
    drawMarker()
  } catch (e) {
    error.value = errorMessage(e, 'Camion introuvable.')
  }
}

// ── Position en direct ─────────────────────────────────────────────────────
const live = computed(() => fleet.vehicles[id.value])
const map = shallowRef<L.Map | null>(null)
let marker: L.Marker | null = null
function drawMarker() {
  const lv = live.value
  const lat = lv?.latitude ?? vehicle.value?.live?.latitude
  const lng = lv?.longitude ?? vehicle.value?.live?.longitude
  if (!map.value || lat == null || lng == null) return
  const status = lv?.status ?? vehicle.value?.live?.status ?? 'offline'
  const icon = vehicleIcon({ ...(lv ?? {}), name: vehicle.value?.name ?? '', status, course: lv?.course ?? 0 } as never, true)
  if (!marker) { marker = L.marker([lat, lng], { icon }).addTo(map.value); map.value.setView([lat, lng], 14); return }
  marker.setIcon(icon)
  setCourse(marker, lv?.course ?? 0)
  animateTo(marker, [lat, lng])
  map.value.panTo([lat, lng])
}
watch(() => live.value?.last_position_at, drawMarker)

// ── Onglets ────────────────────────────────────────────────────────────────
const TABS = [
  { key: 'trips', label: 'Trajets' }, { key: 'visits', label: 'Visites de zones' }, { key: 'missions', label: 'Missions' },
  { key: 'assignments', label: 'Affectations' }, { key: 'device', label: 'Boîtier GPS' },
] as const
const tab = ref<(typeof TABS)[number]['key']>('trips')
const tabRows = ref<Record<string, unknown>[]>([])
const tabMeta = ref<PageMeta | null>(null)
const tabPage = ref(1)
const tabLoading = ref(false)
const tabError = ref('')
async function loadTab() {
  if (tab.value === 'device') return
  tabLoading.value = true
  tabError.value = ''
  try {
    const { data } = await http.get<{ data: Record<string, unknown>[]; meta?: PageMeta }>(`/api/vehicles/${id.value}/${tab.value}`, { params: { page: tabPage.value } })
    tabRows.value = data.data
    tabMeta.value = data.meta ?? null
  } catch (e) {
    tabError.value = errorMessage(e)
  } finally {
    tabLoading.value = false
  }
}
watch(tab, () => { tabPage.value = 1; tabRows.value = []; loadTab() })
watch(tabPage, loadTab)

const formOpen = ref(false)
function onSaved() { formOpen.value = false; toast('Camion enregistré.'); load(); if (tab.value === 'assignments') loadTab() }

const replay = (r: Record<string, unknown>) => ({ path: '/historique', query: { vehicle: String(id.value), from: String(r.started_at), to: String(r.ended_at ?? '') } })

onMounted(() => { if (auth.can('tracking.view')) { fleet.start(); if (!fleet.list.length) fleet.load() } load(); loadTab() })
onBeforeUnmount(() => { marker = null })
const lv = computed(() => live.value ?? null)
</script>

<template>
  <ErrorState v-if="error" :message="error" @retry="load" />
  <LoadingState v-else-if="!vehicle" />
  <template v-else>
    <nav class="text-[13px] text-muted" aria-label="Fil d’Ariane"><RouterLink to="/camions">Camions</RouterLink> / {{ vehicle.name }}</nav>

    <section class="card flex flex-wrap items-center justify-between gap-4 p-5">
      <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2.5">
          <h2 class="m-0 text-2xl font-bold">{{ vehicle.name }}</h2>
          <StatusBadge :status="lv?.status ?? vehicle.live?.status ?? 'offline'" />
          <StatusBadge :tone="vehicle.status_tone" :label="vehicle.status_label" />
        </div>
        <div class="mt-1 text-sm text-muted">{{ vehicle.plate_number }} · {{ vehicle.brand }} {{ vehicle.model }} · {{ vehicle.type_label }}<template v-if="vehicle.year"> · {{ vehicle.year }}</template> · limite {{ vehicle.speed_limit_kmh }} km/h</div>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <div class="flex items-center gap-2.5 rounded-xl bg-page px-3 py-2">
          <span class="flex size-9 items-center justify-center rounded-full bg-brand-soft text-xs font-bold text-brand-dark">{{ (lv?.driver?.name ?? vehicle.driver?.name ?? '—').split(' ').map((p) => p[0]).slice(0, 2).join('') }}</span>
          <div><div class="text-xs text-muted">{{ lv?.driver?.on_shift ? 'Chauffeur en service' : 'Chauffeur affecté' }}</div><b class="text-sm">{{ lv?.driver?.name ?? vehicle.driver?.name ?? 'Aucun' }}</b></div>
        </div>
        <button v-if="auth.can('vehicles.update')" type="button" class="btn btn-secondary" @click="formOpen = true">Changer de chauffeur</button>
        <RouterLink :to="`/historique?vehicle=${vehicle.id}`" class="btn btn-secondary"><History :size="16" />Historique</RouterLink>
        <button v-if="auth.can('vehicles.update')" type="button" class="btn btn-primary" @click="formOpen = true"><Pencil :size="16" />Modifier</button>
      </div>
    </section>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)]">
      <section class="card flex flex-col overflow-hidden">
        <div class="flex items-center justify-between border-b border-line px-5 py-3">
          <h3 class="m-0 text-sm font-bold">Position actuelle</h3>
          <span class="text-[13px] text-muted">{{ fTimeSec(lv?.last_position_at ?? vehicle.live?.last_position_at) }} · {{ lv?.speed_kmh ?? vehicle.live?.speed_kmh ?? 0 }} km/h</span>
        </div>
        <div class="relative h-72 xl:h-auto xl:flex-1"><MapView :zoom="14" @ready="(m) => { map = m; drawMarker() }" /></div>
        <div class="flex items-center gap-2 border-t border-line px-5 py-2.5 text-[13px] text-muted"><MapPin :size="15" />{{ lv?.address ?? vehicle.live?.address ?? '—' }}</div>
      </section>

      <div class="grid grid-cols-2 gap-3.5 lg:grid-cols-3">
        <StatCard label="Distance (30 j)" :value="fKm(overview?.distance_km)" :hint="`moyenne ${fNumber(overview?.avg_km_per_day)} km/jour`" :icon="Route" :loading="!overview" />
        <StatCard label="Temps de conduite" :value="fDuration(overview?.driving_seconds)" hint="30 derniers jours" tone="green" :icon="Clock" :loading="!overview" />
        <StatCard label="Temps d’arrêt" :value="fDuration(overview?.stopped_seconds)" :hint="`${overview?.stops_count ?? 0} arrêts`" tone="orange" :icon="Square" :loading="!overview" />
        <StatCard label="Visites de zones" :value="overview?.visits" :hint="`dont ${overview?.customer_visits ?? 0} chez des clients`" tone="blue" :icon="Hexagon" :loading="!overview" />
        <StatCard label="Missions" :value="overview?.missions" :hint="`${overview?.missions_completed ?? 0} terminées, ${overview?.missions_cancelled ?? 0} annulées`" tone="gray" :icon="ClipboardList" :loading="!overview" />
        <StatCard label="Vitesse max" :value="`${overview?.max_speed_kmh ?? 0} km/h`" :hint="`${overview?.overspeed_alerts ?? 0} alertes de survitesse`" tone="red" :icon="Gauge" :loading="!overview" />
      </div>
    </div>

    <section class="card p-5">
      <div class="mb-4 flex flex-wrap gap-1 border-b border-line" role="tablist">
        <button v-for="t in TABS" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key"
          class="-mb-px border-b-2 px-3.5 py-2.5 text-sm font-semibold" :class="tab === t.key ? 'border-brand text-brand' : 'border-transparent text-muted hover:text-ink'" @click="tab = t.key">{{ t.label }}</button>
      </div>

      <div v-if="tab === 'device'" class="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
        <template v-if="vehicle.device">
          <div><div class="text-xs text-muted">Modèle</div><b>{{ vehicle.device.model ?? '—' }}</b></div>
          <div><div class="text-xs text-muted">IMEI</div><b class="font-mono">{{ vehicle.device.imei }}</b></div>
          <div><div class="text-xs text-muted">SIM</div><b>{{ vehicle.device.sim_number ?? '—' }}</b></div>
          <div><div class="text-xs text-muted">Installé le</div><b>{{ fDate(vehicle.device.installed_at) }}</b></div>
          <div><div class="text-xs text-muted">Dernière émission</div><b>{{ fDateTime(lv?.last_position_at ?? vehicle.device.last_seen_at) }}</b></div>
        </template>
        <EmptyState v-else title="Aucun boîtier GPS" message="Renseignez un IMEI dans « Modifier » pour équiper ce camion." class="sm:col-span-2" />
      </div>
      <template v-else>
        <ErrorState v-if="tabError" :message="tabError" @retry="loadTab" />
        <LoadingState v-else-if="tabLoading && !tabRows.length" />
        <EmptyState v-else-if="!tabRows.length" title="Rien à afficher" message="Aucune donnée sur les 30 derniers jours." />
        <div v-else class="-mx-5 overflow-x-auto">
          <table class="w-full min-w-[760px] border-collapse text-sm">
            <thead class="bg-[#F8FAFC] text-left text-xs font-semibold tracking-wide text-muted uppercase">
              <tr v-if="tab === 'trips'"><th class="px-5 py-2.5">Date</th><th class="px-3 py-2.5">Départ</th><th class="px-3 py-2.5">Arrivée</th><th class="px-3 py-2.5 text-right">Distance</th><th class="px-3 py-2.5 text-right">Durée</th><th class="px-3 py-2.5 text-right">Vit. moy.</th><th class="px-3 py-2.5 text-right">Vit. max</th><th class="px-5 py-2.5" /></tr>
              <tr v-else-if="tab === 'visits'"><th class="px-5 py-2.5">Zone</th><th class="px-3 py-2.5">Type</th><th class="px-3 py-2.5">Chauffeur</th><th class="px-3 py-2.5">Entrée</th><th class="px-3 py-2.5">Sortie</th><th class="px-5 py-2.5 text-right">Durée</th></tr>
              <tr v-else-if="tab === 'missions'"><th class="px-5 py-2.5">Référence</th><th class="px-3 py-2.5">Chauffeur</th><th class="px-3 py-2.5">Départ → destination</th><th class="px-3 py-2.5">Début prévu</th><th class="px-3 py-2.5 text-right">Km</th><th class="px-5 py-2.5">Statut</th></tr>
              <tr v-else><th class="px-5 py-2.5">Chauffeur</th><th class="px-3 py-2.5">Matricule</th><th class="px-3 py-2.5">Du</th><th class="px-5 py-2.5">Au</th></tr>
            </thead>
            <tbody>
              <tr v-for="(r, i) in tabRows" :key="i" class="border-t border-line-soft">
                <template v-if="tab === 'trips'">
                  <td class="px-5 py-2.5 whitespace-nowrap">{{ fDate(r.started_at as string).slice(0, 5) }} · {{ fTime(r.started_at as string) }}</td>
                  <td class="px-3 py-2.5">{{ r.from ?? '—' }}</td><td class="px-3 py-2.5">{{ r.ongoing ? 'En cours…' : (r.to ?? '—') }}</td>
                  <td class="num px-3 py-2.5 text-right">{{ fKm(r.distance_km as number) }}</td><td class="num px-3 py-2.5 text-right">{{ fDuration(r.duration_seconds as number) }}</td>
                  <td class="num px-3 py-2.5 text-right">{{ r.avg_speed_kmh }} km/h</td><td class="num px-3 py-2.5 text-right" :class="(r.max_speed_kmh as number) > vehicle.speed_limit_kmh ? 'font-semibold text-danger' : ''">{{ r.max_speed_kmh }} km/h</td>
                  <td class="px-5 py-2.5"><RouterLink :to="replay(r)" class="inline-flex items-center gap-1 font-semibold"><PlayCircle :size="15" />Rejouer</RouterLink></td>
                </template>
                <template v-else-if="tab === 'visits'">
                  <td class="px-5 py-2.5 font-semibold">{{ r.zone }}</td><td class="px-3 py-2.5">{{ r.zone_type }}</td><td class="px-3 py-2.5">{{ r.driver ?? '—' }}</td>
                  <td class="px-3 py-2.5 whitespace-nowrap">{{ fDateTime(r.entered_at as string) }}</td>
                  <td class="px-3 py-2.5 whitespace-nowrap">{{ r.ongoing ? 'Sur place' : fDateTime(r.exited_at as string) }}</td>
                  <td class="num px-5 py-2.5 text-right">{{ fDuration(r.duration_seconds as number) }}</td>
                </template>
                <template v-else-if="tab === 'missions'">
                  <td class="px-5 py-2.5 font-semibold whitespace-nowrap">{{ r.reference }}</td><td class="px-3 py-2.5">{{ r.driver ?? '—' }}</td>
                  <td class="px-3 py-2.5">{{ r.origin }} → {{ r.destination }}</td><td class="px-3 py-2.5 whitespace-nowrap">{{ fDateTime(r.planned_start_at as string) }}</td>
                  <td class="num px-3 py-2.5 text-right">{{ r.distance_km != null ? fKm(r.distance_km as number) : '—' }}</td>
                  <td class="px-5 py-2.5"><StatusBadge :tone="r.status_tone as never" :label="r.status_label as string" :icon="ClipboardList" /></td>
                </template>
                <template v-else>
                  <td class="px-5 py-2.5 font-semibold">{{ (r.driver as { name: string } | null)?.name ?? '—' }}</td><td class="px-3 py-2.5">{{ (r.driver as { employee_number: string } | null)?.employee_number }}</td>
                  <td class="px-3 py-2.5">{{ fDate(r.started_at as string) }}</td><td class="px-5 py-2.5">{{ r.ended_at ? fDate(r.ended_at as string) : 'en cours' }}</td>
                </template>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="tabMeta && tabMeta.last_page > 1" class="mt-4 flex items-center justify-between text-[13px] text-muted">
          <span>Page {{ tabMeta.current_page }} / {{ tabMeta.last_page }} · {{ tabMeta.total }} lignes</span>
          <div class="flex gap-2"><button type="button" class="btn btn-secondary min-h-9" :disabled="tabPage <= 1" @click="tabPage--">Précédent</button><button type="button" class="btn btn-secondary min-h-9" :disabled="tabPage >= tabMeta.last_page" @click="tabPage++">Suivant</button></div>
        </div>
      </template>
    </section>

    <VehicleForm :open="formOpen" :vehicle="vehicle" @close="formOpen = false" @saved="onSaved" />
  </template>
</template>
