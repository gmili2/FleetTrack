<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import L from 'leaflet'
import { BadgeCheck, ChevronRight, Clock, LogIn, LogOut, MapPin, Route, TriangleAlert, Truck, X } from 'lucide-vue-next'
import { errorMessage, http } from '@/lib/http'
import { fDate, fDuration, fKm, fTime } from '@/lib/format'
import { ago } from '@/lib/time'
import type { LiveVehicle, ShiftRow, ShiftTrack } from '@/lib/types'
import MapView from '@/components/map/MapView.vue'
import ShiftTrackMap from '@/components/map/ShiftTrackMap.vue'
import { animateTo, setCourse, vehicleIcon } from '@/components/map/vehicleMarker'
import StatusBadge from '@/components/StatusBadge.vue'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

type Portal = {
  driver: { id: number; name: string; employee_number: string; badge_number: string; phone: string | null; license_expires_at: string | null; license_expires_soon: boolean }
  shift: (ShiftRow & { distance_km: number }) | null
  vehicle: LiveVehicle | null
  recent: ShiftRow[]
}
type PickVehicle = LiveVehicle & { available: boolean; unavailable_reason: string | null; at_depot: boolean }

const data = ref<Portal | null>(null)
const error = ref('')
const now = ref(Date.now())
const flash = ref('')

async function load() {
  try {
    const { data: r } = await http.get<{ data: Portal }>('/api/me/driver')
    data.value = r.data
    error.value = ''
    syncMarker()
  } catch (e) {
    error.value = errorMessage(e, 'Impossible de charger votre service.')
  }
}

// ── Prise de service ───────────────────────────────────────────────────────
const picking = ref(false)
const vehicles = ref<PickVehicle[]>([])
const vehiclesLoading = ref(false)
const chosen = ref<PickVehicle | null>(null)
const busy = ref(false)
const actionError = ref('')

async function openPicker() {
  picking.value = true
  chosen.value = null
  actionError.value = ''
  vehiclesLoading.value = true
  try {
    vehicles.value = (await http.get<{ data: PickVehicle[] }>('/api/me/driver/vehicles')).data.data
  } catch (e) {
    actionError.value = errorMessage(e)
  } finally {
    vehiclesLoading.value = false
  }
}

async function clockIn() {
  if (!chosen.value) return
  busy.value = true
  actionError.value = ''
  try {
    const { data: r } = await http.post<{ message: string }>('/api/me/driver/clock-in', { vehicle_id: chosen.value.id })
    flash.value = r.message
    picking.value = false
    await load()
    centerOnTruck()
  } catch (e) {
    actionError.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

const askOut = ref(false)
async function clockOut() {
  busy.value = true
  try {
    const { data: r } = await http.post<{ message: string }>('/api/me/driver/clock-out')
    flash.value = r.message
    askOut.value = false
    marker?.remove()
    marker = null
    await load()
  } catch (e) {
    flash.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

// ── Carte du camion en service ─────────────────────────────────────────────
const map = shallowRef<L.Map | null>(null)
let marker: L.Marker | null = null
let lastStatus = ''
function syncMarker() {
  const v = data.value?.vehicle
  if (!map.value || !v || v.latitude == null) return
  if (!marker) {
    marker = L.marker([v.latitude, v.longitude!], { icon: vehicleIcon(v, true) }).addTo(map.value)
    lastStatus = v.status
    centerOnTruck()
    return
  }
  if (v.status !== lastStatus) { marker.setIcon(vehicleIcon(v, true)); lastStatus = v.status }
  setCourse(marker, v.course)
  animateTo(marker, [v.latitude, v.longitude!])
  map.value.panTo([v.latitude, v.longitude!], { animate: true, duration: 1 })
}
function centerOnTruck() {
  const v = data.value?.vehicle
  if (map.value && v?.latitude != null) map.value.setView([v.latitude, v.longitude!], 15)
}
function onMap(m: L.Map) { map.value = m; requestAnimationFrame(syncMarker) }

// ── Historique ─────────────────────────────────────────────────────────────
const track = ref<ShiftTrack | null>(null)
const trackOpen = ref(false)
async function openTrack(s: ShiftRow) {
  trackOpen.value = true
  track.value = null
  track.value = (await http.get<{ data: ShiftTrack }>(`/api/me/driver/shifts/${s.id}/track`)).data.data
}

const shiftDuration = computed(() => data.value?.shift ? Math.max(0, Math.floor((now.value - new Date(data.value.shift.start_at).getTime()) / 1000)) : 0)

let poll: ReturnType<typeof setInterval> | null = null
let tick: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  load()
  poll = setInterval(() => { if (!document.hidden) load() }, 5000)
  tick = setInterval(() => { now.value = Date.now() }, 1000)
})
onBeforeUnmount(() => { if (poll) clearInterval(poll); if (tick) clearInterval(tick) })
</script>

<template>
  <div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
    <ErrorState v-if="error && !data" :message="error" @retry="load" />
    <LoadingState v-else-if="!data" label="Chargement de votre service…" />
    <template v-else>
      <div v-if="flash" class="flex items-center justify-between gap-3 rounded-xl border border-[#BBF7D0] bg-[#F0FDF4] px-4 py-3 text-sm text-[#166534]" role="status">
        <span class="flex items-center gap-2"><BadgeCheck :size="18" />{{ flash }}</span>
        <button type="button" aria-label="Fermer" @click="flash = ''"><X :size="16" /></button>
      </div>

      <!-- Identité -->
      <section class="card flex items-center gap-4 p-4">
        <div class="flex size-12 flex-none items-center justify-center rounded-full bg-brand-soft text-base font-bold text-brand-dark">{{ data.driver.name.split(' ').map((p) => p[0]).slice(0, 2).join('') }}</div>
        <div class="min-w-0 flex-1">
          <div class="text-lg font-bold">{{ data.driver.name }}</div>
          <div class="text-[13px] text-muted">Matricule {{ data.driver.employee_number }} · badge {{ data.driver.badge_number }}</div>
        </div>
      </section>
      <div v-if="data.driver.license_expires_soon" class="flex items-center gap-2 rounded-xl border border-[#FED7AA] bg-[#FFF7ED] px-4 py-3 text-sm text-[#9A3412]" role="alert">
        <TriangleAlert :size="18" class="flex-none" />Votre permis expire le {{ fDate(data.driver.license_expires_at) }}. Pensez à le renouveler.
      </div>

      <!-- Pas en service -->
      <section v-if="!data.shift" class="card flex flex-col items-center gap-4 p-6 text-center">
        <div class="flex size-14 items-center justify-center rounded-full bg-page text-offline"><Clock :size="26" /></div>
        <div>
          <div class="text-lg font-bold">Vous n’êtes pas en service</div>
          <p class="m-0 mt-1 text-sm text-muted">Pointez l’entrée et choisissez votre camion : sa carte GPS suivra votre service.</p>
        </div>
        <button type="button" class="btn btn-primary h-14 w-full max-w-sm text-base" @click="openPicker"><LogIn :size="20" />Pointer l’entrée</button>
      </section>

      <!-- En service -->
      <template v-else>
        <section class="card overflow-hidden">
          <div class="flex flex-wrap items-center justify-between gap-3 bg-[#F0FDF4] px-4 py-3">
            <div>
              <div class="flex items-center gap-2 text-sm font-bold text-[#166534]"><span class="size-2.5 animate-pulse rounded-full bg-moving" />En service depuis {{ fTime(data.shift.start_at) }}</div>
              <div class="text-[13px] text-[#166534]">Prise à {{ data.shift.start_place }} · {{ fDuration(shiftDuration) }}</div>
            </div>
            <StatusBadge v-if="data.shift.anomaly" tone="red" :label="data.shift.anomaly_label ?? ''" :icon="TriangleAlert" />
          </div>
          <div class="flex items-center gap-3 border-b border-line px-4 py-3">
            <Truck :size="22" class="text-brand" />
            <div class="min-w-0 flex-1">
              <div class="font-bold">{{ data.shift.vehicle?.name }} <span class="font-medium text-muted">· {{ data.shift.vehicle?.plate_number }}</span></div>
              <div class="text-[13px] text-muted">{{ data.vehicle?.brand }} {{ data.vehicle?.model }} · boîtier {{ data.vehicle?.device?.model }}</div>
            </div>
            <StatusBadge v-if="data.vehicle" :status="data.vehicle.status" />
          </div>
          <div class="grid grid-cols-3 divide-x divide-line text-center">
            <div class="p-3"><div class="text-xs text-muted">Vitesse</div><div class="num text-xl font-bold">{{ data.vehicle?.speed_kmh ?? 0 }} <span class="text-xs text-muted">km/h</span></div></div>
            <div class="p-3"><div class="text-xs text-muted">Parcouru</div><div class="num text-xl font-bold">{{ fKm(data.shift.distance_km) }}</div></div>
            <div class="p-3"><div class="text-xs text-muted">Durée</div><div class="num text-xl font-bold">{{ fDuration(shiftDuration) }}</div></div>
          </div>
          <div class="flex items-center gap-2 border-t border-line px-4 py-2.5 text-[13px] text-muted">
            <MapPin :size="15" class="flex-none" /><span class="truncate">{{ data.vehicle?.address ?? '—' }}</span><span class="ml-auto flex-none">{{ ago(data.vehicle?.last_position_at, now) }}</span>
          </div>
          <div class="relative h-[42vh] min-h-[260px] border-t border-line"><MapView :zoom="14" @ready="onMap" /></div>
        </section>
        <button type="button" class="btn btn-danger h-14 text-base" @click="askOut = true"><LogOut :size="20" />Pointer la sortie</button>
      </template>

      <!-- Historique -->
      <section class="card p-4">
        <h2 class="m-0 mb-3 text-base font-bold">Mes derniers services</h2>
        <EmptyState v-if="!data.recent.length" :icon="Route" title="Aucun service terminé" message="Vos services apparaîtront ici après votre première fin de service." />
        <ul v-else class="m-0 flex list-none flex-col p-0">
          <li v-for="s in data.recent" :key="s.id">
            <button type="button" class="flex w-full items-center gap-3 border-b border-line-soft py-3 text-left last:border-0 hover:bg-page" @click="openTrack(s)">
              <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold">{{ fDate(s.start_at) }} · {{ s.vehicle?.name }}</div>
                <div class="text-[13px] text-muted">{{ fTime(s.start_at) }} → {{ fTime(s.end_at) }} · {{ fDuration(s.duration_seconds) }} · {{ fKm(s.distance_km) }}</div>
              </div>
              <StatusBadge v-if="s.anomaly" tone="red" :label="s.anomaly_label ?? ''" :icon="TriangleAlert" />
              <ChevronRight :size="18" class="text-muted" />
            </button>
          </li>
        </ul>
      </section>
    </template>

    <!-- Choix du camion -->
    <Teleport to="body">
      <div v-if="picking" class="fixed inset-0 z-[1000] flex items-end justify-center bg-ink/50 sm:items-center sm:p-4" @click.self="picking = false">
        <div class="flex max-h-[90dvh] w-full max-w-lg flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" role="dialog" aria-modal="true" aria-labelledby="pick-title">
          <div class="flex items-center justify-between border-b border-line px-5 py-4">
            <h2 id="pick-title" class="m-0 text-lg font-bold">Quel camion prenez-vous ?</h2>
            <button type="button" class="btn-icon" aria-label="Fermer" @click="picking = false"><X :size="18" /></button>
          </div>
          <div class="flex-1 overflow-y-auto px-5 py-3">
            <LoadingState v-if="vehiclesLoading" label="Recherche des camions…" />
            <ul v-else class="m-0 flex list-none flex-col gap-2 p-0">
              <li v-for="v in vehicles" :key="v.id">
                <button type="button" :disabled="!v.available" :aria-pressed="chosen?.id === v.id"
                  class="flex w-full items-center gap-3 rounded-xl border p-3 text-left disabled:cursor-not-allowed disabled:opacity-50"
                  :class="chosen?.id === v.id ? 'border-brand bg-[#EFF6FF]' : 'border-line hover:bg-page'" @click="chosen = v">
                  <Truck :size="22" class="flex-none text-brand" />
                  <div class="min-w-0 flex-1">
                    <div class="font-bold">{{ v.name }} <span class="text-[13px] font-medium text-muted">· {{ v.plate_number }} · {{ v.type_label }}</span></div>
                    <div class="truncate text-[13px] text-muted">{{ v.unavailable_reason ?? v.address ?? '—' }}</div>
                  </div>
                  <StatusBadge v-if="v.available && v.at_depot" tone="green" label="Au dépôt" :icon="MapPin" />
                  <StatusBadge v-else-if="v.available" tone="orange" label="Hors dépôt" :icon="MapPin" />
                </button>
              </li>
            </ul>
          </div>
          <div class="flex flex-col gap-2 border-t border-line px-5 py-4">
            <p v-if="chosen" class="m-0 text-[13px] text-muted">
              Le pointage est enregistré à la position du boîtier GPS de {{ chosen.name }} : <b class="text-ink">{{ chosen.address }}</b>.
              <span v-if="!chosen.at_depot" class="text-[#9A3412]">Prise hors dépôt : elle sera signalée.</span>
            </p>
            <div v-if="actionError" class="text-sm text-danger" role="alert">{{ actionError }}</div>
            <button type="button" class="btn btn-primary h-12" :disabled="!chosen || busy" @click="clockIn"><LogIn :size="18" />{{ busy ? 'Pointage…' : chosen ? `Pointer l’entrée sur ${chosen.name}` : 'Choisissez un camion' }}</button>
          </div>
        </div>
      </div>

      <!-- Trajet d'un service passé -->
      <div v-if="trackOpen" class="fixed inset-0 z-[1000] flex items-center justify-center bg-ink/50 p-4" @click.self="trackOpen = false">
        <div class="flex h-[85dvh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-label="Trajet du service">
          <div class="flex items-center justify-between border-b border-line px-5 py-3">
            <div v-if="track"><b>{{ fDate(track.shift.start_at) }} · {{ track.shift.vehicle?.name }}</b><div class="text-[13px] text-muted">{{ track.shift.start_place }} {{ fTime(track.shift.start_at) }} → {{ track.shift.end_place ?? '—' }} {{ fTime(track.shift.end_at) }}</div></div>
            <span v-else class="text-sm text-muted">Chargement…</span>
            <button type="button" class="btn-icon" aria-label="Fermer" @click="trackOpen = false"><X :size="18" /></button>
          </div>
          <div v-if="track" class="grid grid-cols-2 gap-px bg-line text-center sm:grid-cols-4">
            <div class="bg-white p-2.5"><div class="text-xs text-muted">Distance</div><b class="num">{{ fKm(track.summary.distance_km) }}</b></div>
            <div class="bg-white p-2.5"><div class="text-xs text-muted">Conduite</div><b class="num">{{ fDuration(track.summary.driving_seconds) }}</b></div>
            <div class="bg-white p-2.5"><div class="text-xs text-muted">Arrêts</div><b class="num">{{ track.summary.stops_count }}</b></div>
            <div class="bg-white p-2.5"><div class="text-xs text-muted">Vitesse max</div><b class="num">{{ track.summary.max_speed_kmh }} km/h</b></div>
          </div>
          <div class="relative min-h-0 flex-1"><ShiftTrackMap :track="track" /></div>
        </div>
      </div>
    </Teleport>

    <ConfirmDialog :open="askOut" title="Pointer la sortie ?" :message="`Votre service sur ${data?.shift?.vehicle?.name ?? 'ce camion'} sera clôturé maintenant.`"
      confirm-label="Pointer la sortie" danger :busy="busy" @confirm="clockOut" @cancel="askOut = false" />
  </div>
</template>
