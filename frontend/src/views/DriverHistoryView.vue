<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, BadgeCheck, Clock, Crosshair, IdCard, Route, Smartphone, TriangleAlert } from 'lucide-vue-next'
import { errorMessage, http } from '@/lib/http'
import { fDate, fDuration, fKm, fTime } from '@/lib/format'
import type { ShiftRow, ShiftTrack } from '@/lib/types'
import StatCard from '@/components/StatCard.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ShiftTrackMap from '@/components/map/ShiftTrackMap.vue'

type Resp = {
  driver: { id: number; name: string; employee_number: string; badge_number: string; phone: string | null }
  data: ShiftRow[]
  meta: { current_page: number; last_page: number; total: number; stats: { shifts: number; total_seconds: number; total_km: number; anomalies: number } }
}

const route = useRoute()
const router = useRouter()
const res = ref<Resp | null>(null)
const loading = ref(true)
const error = ref('')
const page = ref(1)
const from = ref('')
const to = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await http.get<Resp>(`/api/drivers/${route.params.id}/shifts`, { params: { page: page.value, from: from.value || undefined, to: to.value || undefined } })
    res.value = data
    if (!selected.value && data.data[0]) openTrack(data.data[0])
  } catch (e) {
    error.value = errorMessage(e, 'Impossible de charger l’historique.')
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch([from, to], () => { page.value = 1; selected.value = null; load() })
watch(page, load)

const selected = ref<ShiftRow | null>(null)
const track = ref<ShiftTrack | null>(null)
const trackLoading = ref(false)
async function openTrack(s: ShiftRow) {
  selected.value = s
  trackLoading.value = true
  try {
    track.value = (await http.get<{ data: ShiftTrack }>(`/api/shifts/${s.id}/track`)).data.data
  } catch { track.value = null } finally { trackLoading.value = false }
}

const openShift = computed(() => res.value?.data.find((s) => s.open) ?? null)
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-3">
    <button type="button" class="btn btn-secondary" @click="router.push('/chauffeurs')"><ArrowLeft :size="16" />Chauffeurs</button>
    <div v-if="res" class="flex flex-wrap items-center gap-2 text-sm text-muted">
      <b class="text-base text-ink">{{ res.driver.name }}</b> · {{ res.driver.employee_number }} · badge {{ res.driver.badge_number }} · {{ res.driver.phone }}
    </div>
  </div>

  <div v-if="openShift" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[#BBF7D0] bg-[#F0FDF4] px-4 py-3 text-sm text-[#166534]">
    <span class="flex items-center gap-2"><span class="size-2.5 animate-pulse rounded-full bg-moving" />En service sur <b>{{ openShift.vehicle?.name }}</b> depuis {{ fTime(openShift.start_at) }} ({{ openShift.start_place }})</span>
    <RouterLink :to="{ path: '/suivi', query: { v: String(openShift.vehicle?.id), follow: '1' } }" class="btn btn-primary min-h-9"><Crosshair :size="16" />Suivre en direct</RouterLink>
  </div>

  <div class="grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-3.5">
    <StatCard label="Services terminés" :value="res?.meta.stats.shifts" :icon="IdCard" :loading="!res" />
    <StatCard label="Temps de service" :value="fDuration(res?.meta.stats.total_seconds)" tone="blue" :icon="Clock" :loading="!res" />
    <StatCard label="Kilomètres" :value="fKm(res?.meta.stats.total_km)" tone="green" :icon="Route" :loading="!res" />
    <StatCard label="Anomalies" :value="res?.meta.stats.anomalies" tone="red" :icon="TriangleAlert" :loading="!res" />
  </div>

  <div class="grid gap-5 2xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
    <section class="card p-5">
      <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <h2 class="m-0 text-base font-bold">Historique des services</h2>
        <div class="flex flex-wrap gap-2">
          <label class="field">Du<input v-model="from" type="date" class="input" /></label>
          <label class="field">Au<input v-model="to" type="date" class="input" /></label>
        </div>
      </div>
      <ErrorState v-if="error" :message="error" @retry="load" />
      <LoadingState v-else-if="loading && !res" />
      <EmptyState v-else-if="!res?.data.length" :icon="IdCard" title="Aucun service" message="Aucun pointage sur cette période." />
      <div v-else class="-mx-5 overflow-x-auto">
        <table class="w-full min-w-[640px] border-collapse text-sm">
          <thead>
            <tr class="bg-[#F8FAFC] text-left text-xs font-semibold tracking-wide text-muted uppercase">
              <th class="border-b border-line px-5 py-2.5">Date · camion</th><th class="border-b border-line px-3 py-2.5">Entrée</th><th class="border-b border-line px-3 py-2.5">Sortie</th>
              <th class="border-b border-line px-3 py-2.5 text-right">Durée</th><th class="border-b border-line px-3 py-2.5 text-right">Km</th><th class="border-b border-line px-5 py-2.5">État</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in res.data" :key="s.id" class="cursor-pointer" :class="selected?.id === s.id ? 'bg-[#EFF6FF]' : 'hover:bg-page'" @click="openTrack(s)">
              <td class="border-b border-line-soft px-5 py-2.5">
                <b>{{ fDate(s.start_at) }}</b>
                <div class="flex items-center gap-1 text-xs text-muted">{{ s.vehicle?.name }} · {{ s.vehicle?.plate_number }}
                  <Smartphone v-if="s.source === 'app'" :size="12" aria-label="pointé depuis l’application" /><IdCard v-else :size="12" aria-label="badge" />
                </div>
              </td>
              <td class="border-b border-line-soft px-3 py-2.5"><b>{{ fTime(s.start_at) }}</b><div class="text-xs text-muted">{{ s.start_place }}</div></td>
              <td class="border-b border-line-soft px-3 py-2.5"><template v-if="s.end_at"><b>{{ fTime(s.end_at) }}</b><div class="text-xs text-muted">{{ s.end_place ?? '—' }}</div></template><span v-else class="text-muted">En service</span></td>
              <td class="num border-b border-line-soft px-3 py-2.5 text-right">{{ fDuration(s.duration_seconds) }}</td>
              <td class="num border-b border-line-soft px-3 py-2.5 text-right">{{ s.distance_km != null ? fKm(s.distance_km) : '—' }}</td>
              <td class="border-b border-line-soft px-5 py-2.5">
                <StatusBadge v-if="s.anomaly" tone="red" :label="s.anomaly_label ?? ''" :icon="TriangleAlert" />
                <StatusBadge v-else-if="s.open" tone="blue" label="En service" :icon="BadgeCheck" />
                <StatusBadge v-else tone="green" label="Terminé" :icon="BadgeCheck" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="res && res.meta.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-muted">
        <span>Page {{ res.meta.current_page }} / {{ res.meta.last_page }} · {{ res.meta.total }} services</span>
        <div class="flex gap-2">
          <button type="button" class="btn btn-secondary min-h-9" :disabled="page <= 1" @click="page--">Précédent</button>
          <button type="button" class="btn btn-secondary min-h-9" :disabled="page >= res.meta.last_page" @click="page++">Suivant</button>
        </div>
      </div>
    </section>

    <section class="card flex min-h-[480px] flex-col overflow-hidden">
      <div class="border-b border-line px-5 py-3">
        <h2 class="m-0 text-base font-bold">Trajet du service</h2>
        <div v-if="selected" class="flex flex-wrap items-center justify-between gap-2 text-[13px] text-muted">
          <span>{{ fDate(selected.start_at) }} · {{ selected.vehicle?.name }} · {{ fTime(selected.start_at) }} → {{ selected.end_at ? fTime(selected.end_at) : 'en cours' }}</span>
          <RouterLink v-if="selected.vehicle" :to="{ path: '/historique', query: { vehicle: String(selected.vehicle.id), from: selected.start_at, to: selected.end_at ?? new Date().toISOString() } }" class="font-semibold">Rejouer le service →</RouterLink>
        </div>
      </div>
      <div v-if="track" class="grid grid-cols-2 gap-px bg-line text-center sm:grid-cols-4">
        <div class="bg-white p-2.5"><div class="text-xs text-muted">Distance</div><b class="num">{{ fKm(track.summary.distance_km) }}</b></div>
        <div class="bg-white p-2.5"><div class="text-xs text-muted">Conduite</div><b class="num">{{ fDuration(track.summary.driving_seconds) }}</b></div>
        <div class="bg-white p-2.5"><div class="text-xs text-muted">Arrêts</div><b class="num">{{ track.summary.stops_count }}</b></div>
        <div class="bg-white p-2.5"><div class="text-xs text-muted">Vitesse max</div><b class="num">{{ track.summary.max_speed_kmh }} km/h</b></div>
      </div>
      <div class="relative min-h-[380px] flex-1">
        <ShiftTrackMap :track="track" />
        <div v-if="trackLoading" class="absolute inset-0 z-[500] flex items-center justify-center bg-white/60"><LoadingState label="Chargement du trajet…" /></div>
        <div v-else-if="track && !track.points.length" class="absolute inset-x-4 top-4 z-[500] rounded-xl bg-white px-4 py-3 text-sm text-muted shadow">Aucune position GPS enregistrée pendant ce service.</div>
      </div>
    </section>
  </div>
</template>
