<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { BadgeCheck, Crosshair, Download, History, Pencil, Plus, Search, Trash2, TriangleAlert, Users } from 'lucide-vue-next'
import { errorMessage, http } from '@/lib/http'
import { download } from '@/lib/download'
import { fTime } from '@/lib/format'
import { toast } from '@/lib/toast'
import type { DriverRow, Paginated } from '@/lib/types'
import { useAuthStore } from '@/stores/auth'
import DataTable, { type Column } from '@/components/DataTable.vue'
import StatCard from '@/components/StatCard.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import DriverForm from '@/components/DriverForm.vue'

const auth = useAuthStore()
const router = useRouter()
const rows = ref<DriverRow[]>([])
const total = ref(0)
const counts = ref<Record<string, number>>({})
const loading = ref(true)
const error = ref('')
const f = reactive({ q: '', tab: 'all', page: 1, per_page: 10 })

let timer: ReturnType<typeof setTimeout> | undefined
async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await http.get<Paginated<DriverRow>>('/api/drivers', { params: { ...f, q: f.q || undefined } })
    rows.value = data.data
    total.value = data.meta.total
    counts.value = data.meta.counts ?? {}
  } catch (e) {
    error.value = errorMessage(e, 'Impossible de charger les chauffeurs.')
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(() => f.tab, () => { f.page = 1; load() })
watch(() => f.q, () => { clearTimeout(timer); timer = setTimeout(() => { f.page = 1; load() }, 300) })

const TABS = computed(() => [
  { key: 'all', label: 'Tous', n: counts.value.all }, { key: 'on_shift', label: 'En service', n: counts.value.on_shift },
  { key: 'on_mission', label: 'En mission', n: counts.value.on_mission }, { key: 'available', label: 'Disponibles', n: counts.value.available },
  { key: 'rest', label: 'Repos', n: counts.value.rest }, { key: 'inactive', label: 'Inactifs', n: counts.value.inactive },
])
const columns: Column[] = [
  { key: 'name', label: 'Chauffeur' }, { key: 'phone', label: 'Téléphone' }, { key: 'badge_number', label: 'Badge' }, { key: 'license', label: 'Permis · catégorie' },
  { key: 'expiry', label: 'Expiration' }, { key: 'vehicle', label: 'Camion' }, { key: 'status', label: 'Statut' },
  { key: 'shifts_this_month', label: 'Services (mois)', align: 'right' }, { key: 'actions', label: 'Actions' },
]
const expiry = (d: string | null) => (d ? new Date(d).toLocaleDateString('fr-FR', { month: '2-digit', year: 'numeric' }) : '—')
const follow = (r: DriverRow) => router.push({ path: '/suivi', query: { v: String(r.on_shift!.vehicle_id), follow: '1' } })

const formOpen = ref(false)
const editing = ref<DriverRow | null>(null)
function openForm(d: DriverRow | null) { editing.value = d; formOpen.value = true }
function onSaved(d: DriverRow) { formOpen.value = false; toast(`${d.name} enregistré.`); load() }
const toDelete = ref<DriverRow | null>(null)
const deleting = ref(false)
async function confirmDelete() {
  deleting.value = true
  try {
    await http.delete(`/api/drivers/${toDelete.value!.id}`)
    toast(`${toDelete.value!.name} supprimé.`)
    toDelete.value = null
    load()
  } catch (e) { toast(errorMessage(e), 'error') } finally { deleting.value = false }
}
const exporting = ref(false)
async function exportExcel() {
  exporting.value = true
  try { await download('/api/drivers/export', { q: f.q || undefined, tab: f.tab }, 'chauffeurs.xlsx') }
  catch (e) { toast(errorMessage(e, 'Export impossible.'), 'error') } finally { exporting.value = false }
}
</script>

<template>
  <div class="grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-3.5">
    <StatCard label="Chauffeurs" :value="counts.all" :hint="`${(counts.all ?? 0) - (counts.inactive ?? 0)} actifs`" :icon="Users" :loading="loading && !rows.length" />
    <StatCard label="En service" :value="counts.on_shift" hint="badge ou application" tone="green" :icon="BadgeCheck" :loading="loading && !rows.length" />
    <StatCard label="Disponibles" :value="counts.available" tone="blue" :icon="Crosshair" :loading="loading && !rows.length" />
    <StatCard label="Permis à renouveler" :value="counts.license_alerts" hint="dans les 30 jours ou expiré" tone="orange" :icon="TriangleAlert" :loading="loading && !rows.length" />
  </div>

  <section class="card p-5">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap gap-2" role="tablist" aria-label="Filtrer par statut">
        <button v-for="t in TABS" :key="t.key" type="button" role="tab" :aria-selected="f.tab === t.key"
          class="inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3.5 text-[13px] font-semibold"
          :class="f.tab === t.key ? 'border-brand bg-brand text-white' : 'border-line bg-white text-ink hover:bg-page'" @click="f.tab = t.key">
          {{ t.label }}<span class="text-xs font-bold opacity-85">{{ t.n ?? '…' }}</span>
        </button>
      </div>
      <div class="flex flex-wrap gap-2.5">
        <label class="flex h-10 w-full items-center gap-2 rounded-[10px] border border-line bg-page px-3 text-muted sm:w-64">
          <Search :size="17" /><span class="sr-only">Rechercher un chauffeur</span>
          <input v-model="f.q" type="search" placeholder="Nom, matricule, badge…" class="min-w-0 flex-1 border-0 bg-transparent text-sm text-ink outline-none" />
        </label>
        <button v-if="auth.can('drivers.export')" type="button" class="btn btn-secondary" :disabled="exporting" @click="exportExcel"><Download :size="17" />Exporter Excel</button>
        <button v-if="auth.can('drivers.create')" type="button" class="btn btn-primary" @click="openForm(null)"><Plus :size="17" />Ajouter un chauffeur</button>
      </div>
    </div>

    <DataTable :columns="columns" :rows="rows" :loading="loading" :error="error" :total="total" :page="f.page" :per-page="f.per_page" item-label="chauffeurs"
      empty-title="Aucun chauffeur" empty-message="Aucun chauffeur ne correspond à ces filtres." min-width="1060px" clickable
      @update:page="(p) => { f.page = p; load() }" @retry="load" @row-click="(r) => router.push(`/chauffeurs/${r.id}`)">
      <template #cell-name="{ row }">
        <div class="flex items-center gap-3 whitespace-nowrap">
          <span class="flex size-9 flex-none items-center justify-center rounded-full bg-brand-soft text-xs font-bold text-brand-dark">{{ row.initials }}</span>
          <span><b class="block">{{ row.name }}</b><span class="text-xs text-muted">Matricule {{ row.employee_number }}</span></span>
        </div>
      </template>
      <template #cell-phone="{ row }"><span class="whitespace-nowrap">{{ row.phone ?? '—' }}</span></template>
      <template #cell-badge_number="{ row }"><span class="font-mono text-[13px] whitespace-nowrap">{{ row.badge_number }}</span></template>
      <template #cell-license="{ row }"><span class="whitespace-nowrap">{{ row.license_number ?? '—' }} · {{ row.license_category ?? '—' }}</span></template>
      <template #cell-expiry="{ row }">
        <span class="whitespace-nowrap" :class="row.license_expired ? 'font-semibold text-danger' : row.license_expires_soon ? 'font-semibold text-stopped' : ''">
          <TriangleAlert v-if="row.license_expires_soon" :size="14" class="mr-1 inline align-[-2px]" aria-hidden="true" />{{ expiry(row.license_expires_at) }}{{ row.license_expired ? ' · expiré' : row.license_expires_soon ? ' · bientôt' : '' }}
        </span>
      </template>
      <template #cell-vehicle="{ row }"><span class="whitespace-nowrap">{{ row.vehicle?.name ?? '—' }}</span></template>
      <template #cell-status="{ row }">
        <StatusBadge v-if="row.on_shift" tone="green" :label="`En service · ${fTime(row.on_shift.start_at)}`" :icon="BadgeCheck" />
        <StatusBadge v-else :tone="row.status_tone" :label="row.status_label" />
      </template>
      <template #cell-shifts_this_month="{ row }"><span class="num">{{ row.shifts_this_month }}</span></template>
      <template #cell-actions="{ row }">
        <div class="flex gap-1.5" @click.stop>
          <RouterLink :to="`/chauffeurs/${row.id}`" class="btn-icon" :aria-label="`Historique de ${row.name}`" title="Historique"><History :size="16" /></RouterLink>
          <button v-if="row.on_shift" type="button" class="btn-icon text-moving" :aria-label="`Suivre ${row.name} sur la carte`" title="Suivre sur la carte" @click="follow(row)"><Crosshair :size="16" /></button>
          <button v-if="auth.can('drivers.update')" type="button" class="btn-icon" :aria-label="`Modifier ${row.name}`" title="Modifier" @click="openForm(row)"><Pencil :size="16" /></button>
          <button v-if="auth.can('drivers.delete')" type="button" class="btn-icon hover:text-danger" :aria-label="`Supprimer ${row.name}`" title="Supprimer" @click="toDelete = row"><Trash2 :size="16" /></button>
        </div>
      </template>
    </DataTable>
  </section>

  <DriverForm :open="formOpen" :driver="editing" @close="formOpen = false" @saved="onSaved" />
  <ConfirmDialog :open="!!toDelete" danger :title="`Supprimer ${toDelete?.name} ?`" message="Le chauffeur est retiré de la liste ; ses services et pointages passés sont conservés." confirm-label="Supprimer" :busy="deleting" @confirm="confirmDelete" @cancel="toDelete = null" />
</template>
