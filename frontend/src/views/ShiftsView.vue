<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { BadgeCheck, CheckCheck, Clock, Download, IdCard, LogIn, LogOut, Radio, Search, Smartphone, TriangleAlert } from 'lucide-vue-next'
import { errorMessage, http } from '@/lib/http'
import { download } from '@/lib/download'
import { fDate, fDuration, fKm, fTime, TZ } from '@/lib/format'
import { ago } from '@/lib/time'
import { toast } from '@/lib/toast'
import type { Paginated, ShiftRow } from '@/lib/types'
import { useAuthStore } from '@/stores/auth'
import { useFleetStore } from '@/stores/fleet'
import DataTable, { type Column } from '@/components/DataTable.vue'
import StatCard from '@/components/StatCard.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import EmptyState from '@/components/EmptyState.vue'

type Badge = { id: number | string; type: 'in' | 'out'; source: string; badge: string; driver: string | null; vehicle: string | null; place: string | null; anomaly: string | null; at: string }
type Resp = Paginated<ShiftRow> & { meta: { counts: Record<string, number>; avg_duration_seconds: number; total_km: number } }

const auth = useAuthStore()
const fleet = useFleetStore()
const today = new Intl.DateTimeFormat('en-CA', { timeZone: TZ }).format(new Date())
const f = reactive({ date: today, tab: 'all', q: '', page: 1, per_page: 20 })
const res = ref<Resp | null>(null)
const loading = ref(true)
const error = ref('')
const badges = ref<Badge[]>([])

let timer: ReturnType<typeof setTimeout> | undefined
async function load() {
  loading.value = true
  try {
    res.value = (await http.get<Resp>('/api/shifts', { params: { ...f, q: f.q || undefined } })).data
    error.value = ''
  } catch (e) {
    error.value = errorMessage(e, 'Impossible de charger les pointages.')
  } finally {
    loading.value = false
  }
}
watch(() => [f.date, f.tab], () => { f.page = 1; load() })
watch(() => f.q, () => { clearTimeout(timer); timer = setTimeout(() => { f.page = 1; load() }, 300) })

const TABS = computed(() => [
  { key: 'all', label: 'Tous' }, { key: 'open', label: 'En service' }, { key: 'closed', label: 'Terminés' }, { key: 'anomalies', label: 'Anomalies' },
].map((t) => ({ ...t, n: res.value?.meta.counts[t.key] })))
const columns: Column[] = [
  { key: 'driver', label: 'Chauffeur' }, { key: 'vehicle', label: 'Camion' }, { key: 'state', label: 'État' },
  { key: 'start', label: 'Entrée (prise)' }, { key: 'end', label: 'Sortie (fin)' }, { key: 'duration', label: 'Durée', align: 'right' }, { key: 'km', label: 'Km', align: 'right' },
]

// Flux des badges : chargement initial puis temps réel.
const liveBadges = computed<Badge[]>(() => fleet.activity.filter((a) => a.kind === 'badge_in' || a.kind === 'badge_out').map((a, i) => ({
  id: `live-${i}-${a.at}`, type: a.kind === 'badge_in' ? 'in' : 'out', source: String(a.data.source ?? 'badge'), badge: String(a.data.badge ?? ''),
  driver: (a.data.driver as string) ?? null, vehicle: a.message.match(/CAM-\d+/)?.[0] ?? null, place: (a.data.place as string) ?? null, anomaly: (a.data.anomaly as string) ?? null, at: a.at,
})))
const feed = computed(() => {
  const seen = new Set(liveBadges.value.map((b) => `${b.type}${b.badge}${b.at.slice(0, 16)}`))
  return [...liveBadges.value, ...badges.value.filter((b) => !seen.has(`${b.type}${b.badge}${b.at.slice(0, 16)}`))].slice(0, 15)
})
let pending: ReturnType<typeof setTimeout> | null = null
watch(() => liveBadges.value.length, () => {
  if (f.date === today && !pending) pending = setTimeout(() => { pending = null; load() }, 2000)
})

const exporting = ref(false)
async function exportExcel() {
  exporting.value = true
  try { await download('/api/shifts/export', { date: f.date, tab: f.tab, q: f.q || undefined }, 'pointages.xlsx') }
  catch (e) { toast(errorMessage(e, 'Export impossible.'), 'error') } finally { exporting.value = false }
}
onMounted(async () => {
  load()
  if (auth.can('tracking.view')) fleet.start()
  badges.value = (await http.get<{ data: Badge[] }>('/api/badge-events').catch(() => ({ data: { data: [] } }))).data.data
})
onBeforeUnmount(() => { if (pending) clearTimeout(pending) })
const isToday = computed(() => f.date === today)
</script>

<template>
  <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3.5">
    <StatCard label="Chauffeurs en service" :value="res?.meta.counts.open" :hint="isToday ? 'badge pointé, pas encore sorti' : 'services restés ouverts'" tone="green" :icon="BadgeCheck" :loading="!res" />
    <StatCard label="Services terminés" :value="res?.meta.counts.closed" hint="retour pointé" tone="blue" :icon="CheckCheck" :loading="!res" />
    <StatCard label="Durée moyenne de service" :value="fDuration(res?.meta.avg_duration_seconds)" :hint="`${fKm(res?.meta.total_km)} parcourus`" tone="gray" :icon="Clock" :loading="!res" />
    <StatCard label="Anomalies" :value="res?.meta.counts.anomalies" hint="à vérifier" tone="red" :icon="TriangleAlert" :loading="!res" />
  </div>

  <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
    <section class="card min-w-0 p-5">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Filtrer les pointages">
          <button v-for="t in TABS" :key="t.key" type="button" role="tab" :aria-selected="f.tab === t.key"
            class="inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3.5 text-[13px] font-semibold"
            :class="f.tab === t.key ? (t.key === 'anomalies' ? 'border-danger bg-danger text-white' : 'border-brand bg-brand text-white') : 'border-line bg-white hover:bg-page'" @click="f.tab = t.key">
            {{ t.label }}<span class="text-xs font-bold opacity-85">{{ t.n ?? '…' }}</span>
          </button>
        </div>
        <div class="flex flex-wrap gap-2">
          <input v-model="f.date" type="date" :max="today" class="input w-auto" aria-label="Date" />
          <label class="order-first flex h-10 min-w-0 basis-full items-center gap-2 rounded-[10px] border border-line bg-page px-3 text-muted sm:order-none sm:basis-auto">
            <Search :size="16" /><span class="sr-only">Rechercher</span><input v-model="f.q" type="search" placeholder="Chauffeur, badge, camion…" class="w-full min-w-0 border-0 sm:w-44 bg-transparent text-sm text-ink outline-none" />
          </label>
          <button v-if="auth.can('shifts.export')" type="button" class="btn btn-secondary" :disabled="exporting" @click="exportExcel"><Download :size="16" />Exporter Excel</button>
        </div>
      </div>

      <DataTable :columns="columns" :rows="res?.data ?? []" :loading="loading" :error="error" :total="res?.meta.total ?? 0" :page="f.page" :per-page="f.per_page"
        item-label="services" empty-title="Aucun pointage" :empty-message="`Aucun service le ${fDate(f.date)}.`" min-width="920px" @update:page="(p) => { f.page = p; load() }" @retry="load">
        <template #cell-driver="{ row }">
          <div :class="row.anomaly ? 'border-l-4 border-danger pl-2.5 -ml-3.5' : ''">
            <b class="block whitespace-nowrap" :class="row.driver ? '' : 'text-danger'">{{ row.driver?.name ?? 'Badge inconnu' }}</b>
            <span class="flex items-center gap-1 text-xs whitespace-nowrap text-muted">{{ row.driver?.employee_number ? `${row.driver.employee_number} · ` : '' }}badge {{ row.badge_number }}
              <Smartphone v-if="row.source === 'app'" :size="12" aria-label="pointé depuis l’application" /></span>
          </div>
        </template>
        <template #cell-vehicle="{ row }"><b class="block">{{ row.vehicle?.name }}</b><span class="text-xs text-muted">{{ row.vehicle?.plate_number }}</span></template>
        <template #cell-state="{ row }">
          <StatusBadge v-if="row.anomaly" tone="red" :label="row.anomaly_label ?? ''" :icon="TriangleAlert" />
          <StatusBadge v-else-if="row.open" tone="blue" label="En service" :icon="BadgeCheck" />
          <StatusBadge v-else tone="green" label="Terminée" :icon="CheckCheck" />
        </template>
        <template #cell-start="{ row }"><b class="block whitespace-nowrap">{{ row.start_at.slice(0, 10) !== f.date ? `${fDate(row.start_at).slice(0, 5)} ` : '' }}{{ fTime(row.start_at) }}</b><span class="text-xs" :class="row.anomaly === 'outside_depot' ? 'font-semibold text-danger' : 'text-muted'">{{ row.start_place }}</span></template>
        <template #cell-end="{ row }">
          <template v-if="row.end_at"><b class="block whitespace-nowrap">{{ fTime(row.end_at) }}</b><span class="text-xs text-muted">{{ row.end_place ?? '—' }}</span></template>
          <span v-else class="text-muted">En service</span>
        </template>
        <template #cell-duration="{ row }"><span class="num">{{ fDuration(row.duration_seconds) }}</span></template>
        <template #cell-km="{ row }"><span class="num">{{ row.distance_km != null ? fKm(row.distance_km) : '—' }}</span></template>
      </DataTable>
    </section>

    <div class="flex min-w-0 flex-col gap-5">
      <section class="card p-5">
        <div class="mb-3 flex items-center justify-between">
          <h2 class="m-0 text-base font-bold">Passages de badge en direct</h2>
          <span class="flex items-center gap-1.5 text-xs text-muted"><span class="size-2 rounded-full" :class="fleet.connection === 'connected' ? 'animate-pulse bg-moving' : 'bg-stopped'" />En direct</span>
        </div>
        <EmptyState v-if="!feed.length" :icon="Radio" title="Aucun passage" message="Les prises et fins de service apparaîtront ici en temps réel." />
        <ul v-else class="m-0 flex list-none flex-col p-0">
          <li v-for="b in feed" :key="b.id" class="flex gap-3 border-b border-line-soft py-2.5 last:border-0">
            <span class="flex size-8 flex-none items-center justify-center rounded-lg" :class="b.anomaly ? 'bg-[#FEE2E2] text-danger' : b.type === 'in' ? 'bg-[#DCFCE7] text-moving' : 'bg-brand-soft text-brand'">
              <LogIn v-if="b.type === 'in'" :size="16" /><LogOut v-else :size="16" />
            </span>
            <div class="min-w-0 text-[13px]">
              <b class="block">{{ b.driver ?? `Badge inconnu ${b.badge}` }} {{ b.type === 'in' ? 'a badgé dans' : 'a badgé la sortie de' }} {{ b.vehicle }}</b>
              <span class="text-xs" :class="b.anomaly ? 'text-danger' : 'text-muted'">{{ b.type === 'in' ? 'Entrée' : 'Sortie' }} · {{ b.anomaly === 'outside_depot' ? `Hors dépôt : ${b.place}` : b.place }}{{ b.source === 'app' ? ' · application' : '' }} · {{ ago(b.at, fleet.now) }}</span>
            </div>
          </li>
        </ul>
      </section>
      <section class="card p-5 text-[13px] text-muted">
        <h2 class="m-0 mb-2 flex items-center gap-2 text-base font-bold text-ink"><IdCard :size="18" />Comment ça marche</h2>
        <ol class="m-0 flex flex-col gap-1.5 pl-4">
          <li>Le chauffeur passe son badge sur le lecteur du camion (ou pointe dans l’application) : prise de service avec chauffeur, camion, heure et lieu.</li>
          <li>Au retour au dépôt, il repasse son badge : fin de service, durée et kilomètres calculés.</li>
          <li>Prise hors dépôt, badge inconnu ou retour sans badge de sortie sont signalés en rouge.</li>
        </ol>
      </section>
    </div>
  </div>
</template>
