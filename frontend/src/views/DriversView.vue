<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { BadgeCheck, Crosshair, History, Search, TriangleAlert, Users } from 'lucide-vue-next'
import { errorMessage, http } from '@/lib/http'
import { fTime } from '@/lib/format'
import StatCard from '@/components/StatCard.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import EmptyState from '@/components/EmptyState.vue'

type DriverRow = {
  id: number; name: string; initials: string; employee_number: string; badge_number: string; phone: string | null
  license_number: string | null; license_category: string | null; license_expires_at: string | null; license_expired: boolean; license_expires_soon: boolean
  status: string; status_label: string; status_tone: 'green' | 'blue' | 'gray'
  vehicle: { id: number; name: string; plate_number: string } | null
  on_shift: { id: number; start_at: string; source: 'badge' | 'app'; vehicle_id: number } | null
  shifts_this_month: number
}

const router = useRouter()
const rows = ref<DriverRow[]>([])
const meta = ref({ total: 0, on_shift: 0, on_mission: 0, license_alerts: 0 })
const loading = ref(true)
const error = ref('')
const q = ref('')
const tab = ref<'all' | 'on_shift' | 'available' | 'rest' | 'inactive'>('all')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await http.get<{ data: DriverRow[]; meta: typeof meta.value }>('/api/drivers')
    rows.value = data.data
    meta.value = data.meta
  } catch (e) {
    error.value = errorMessage(e, 'Impossible de charger les chauffeurs.')
  } finally {
    loading.value = false
  }
}
onMounted(load)

const TABS = computed(() => [
  { key: 'all', label: 'Tous', n: rows.value.length },
  { key: 'on_shift', label: 'En service', n: rows.value.filter((r) => r.on_shift).length },
  { key: 'available', label: 'Disponibles', n: rows.value.filter((r) => r.status === 'available' && !r.on_shift).length },
  { key: 'rest', label: 'Repos', n: rows.value.filter((r) => r.status === 'rest').length },
  { key: 'inactive', label: 'Inactifs', n: rows.value.filter((r) => r.status === 'inactive').length },
] as const)

const filtered = computed(() => {
  const s = q.value.trim().toLowerCase()
  return rows.value
    .filter((r) => tab.value === 'all' || (tab.value === 'on_shift' ? !!r.on_shift : tab.value === 'available' ? r.status === 'available' && !r.on_shift : r.status === tab.value))
    .filter((r) => !s || [r.name, r.employee_number, r.badge_number, r.phone, r.vehicle?.name].some((x) => x?.toLowerCase().includes(s)))
})

const expiry = (d: string | null) => (d ? new Date(d).toLocaleDateString('fr-FR', { month: '2-digit', year: 'numeric' }) : '—')
const follow = (r: DriverRow) => router.push({ path: '/suivi', query: { v: String(r.on_shift!.vehicle_id), follow: '1' } })
</script>

<template>
  <div class="grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-3.5">
    <StatCard label="Chauffeurs" :value="meta.total" :icon="Users" :loading="loading" />
    <StatCard label="En service" :value="meta.on_shift" hint="badge ou application" tone="green" :icon="BadgeCheck" :loading="loading" />
    <StatCard label="En mission" :value="meta.on_mission" tone="blue" :icon="Crosshair" :loading="loading" />
    <StatCard label="Permis à renouveler" :value="meta.license_alerts" hint="dans les 30 jours ou expiré" tone="orange" :icon="TriangleAlert" :loading="loading" />
  </div>

  <section class="card p-5">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap gap-2" role="tablist" aria-label="Filtrer par statut">
        <button v-for="t in TABS" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key"
          class="inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3.5 text-[13px] font-semibold"
          :class="tab === t.key ? 'border-brand bg-brand text-white' : 'border-line bg-white text-ink hover:bg-page'" @click="tab = t.key">
          {{ t.label }}<span class="text-xs font-bold opacity-85">{{ t.n }}</span>
        </button>
      </div>
      <label class="flex h-10 w-full items-center gap-2 rounded-[10px] border border-line bg-page px-3 text-muted sm:w-72">
        <Search :size="17" /><span class="sr-only">Rechercher un chauffeur</span>
        <input v-model="q" type="search" placeholder="Nom, matricule, badge, camion…" class="min-w-0 flex-1 border-0 bg-transparent text-sm text-ink outline-none" />
      </label>
    </div>

    <ErrorState v-if="error" :message="error" @retry="load" />
    <LoadingState v-else-if="loading" />
    <EmptyState v-else-if="!filtered.length" :icon="Users" title="Aucun chauffeur" message="Aucun chauffeur ne correspond à ces filtres." />
    <div v-else class="-mx-5 overflow-x-auto">
      <table class="w-full min-w-[1080px] border-collapse text-sm">
        <thead>
          <tr class="bg-[#F8FAFC] text-left text-xs font-semibold tracking-wide text-muted uppercase">
            <th class="border-b border-line px-5 py-3">Chauffeur</th><th class="border-b border-line px-3 py-3">Téléphone</th><th class="border-b border-line px-3 py-3">Badge</th>
            <th class="border-b border-line px-3 py-3">Permis · catégorie</th><th class="border-b border-line px-3 py-3">Expiration</th><th class="border-b border-line px-3 py-3">Camion</th>
            <th class="border-b border-line px-3 py-3">Statut</th><th class="border-b border-line px-3 py-3 text-right">Services (mois)</th><th class="border-b border-line px-5 py-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in filtered" :key="r.id" class="cursor-pointer hover:bg-page" @click="router.push(`/chauffeurs/${r.id}`)">
            <td class="border-b border-line-soft px-5 py-3">
              <div class="flex items-center gap-3">
                <span class="flex size-9 flex-none items-center justify-center rounded-full bg-brand-soft text-xs font-bold text-brand-dark">{{ r.initials }}</span>
                <span class="whitespace-nowrap"><b class="block">{{ r.name }}</b><span class="text-xs text-muted">Matricule {{ r.employee_number }}</span></span>
              </div>
            </td>
            <td class="border-b border-line-soft px-3 py-3 whitespace-nowrap">{{ r.phone ?? '—' }}</td>
            <td class="border-b border-line-soft px-3 py-3 font-mono text-[13px] whitespace-nowrap">{{ r.badge_number }}</td>
            <td class="border-b border-line-soft px-3 py-3 whitespace-nowrap">{{ r.license_number }} · {{ r.license_category }}</td>
            <td class="border-b border-line-soft px-3 py-3 whitespace-nowrap">
              <span :class="r.license_expired ? 'font-semibold text-danger' : r.license_expires_soon ? 'font-semibold text-stopped' : ''">{{ expiry(r.license_expires_at) }}</span>
              <span v-if="r.license_expired" class="ml-1 text-xs text-danger">· expiré</span>
              <span v-else-if="r.license_expires_soon" class="ml-1 text-xs text-stopped">· bientôt</span>
            </td>
            <td class="border-b border-line-soft px-3 py-3 whitespace-nowrap">{{ r.vehicle?.name ?? '—' }}</td>
            <td class="border-b border-line-soft px-3 py-3">
              <StatusBadge v-if="r.on_shift" tone="green" :label="`En service · ${fTime(r.on_shift.start_at)}`" :icon="BadgeCheck" />
              <StatusBadge v-else :tone="r.status_tone" :label="r.status_label" />
            </td>
            <td class="num border-b border-line-soft px-3 py-3 text-right">{{ r.shifts_this_month }}</td>
            <td class="border-b border-line-soft px-5 py-3" @click.stop>
              <div class="flex gap-1.5">
                <RouterLink :to="`/chauffeurs/${r.id}`" class="btn-icon" :aria-label="`Historique de ${r.name}`" title="Historique"><History :size="16" /></RouterLink>
                <button v-if="r.on_shift" type="button" class="btn-icon text-moving" :aria-label="`Suivre ${r.name} sur la carte`" title="Suivre sur la carte" @click="follow(r)"><Crosshair :size="16" /></button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
