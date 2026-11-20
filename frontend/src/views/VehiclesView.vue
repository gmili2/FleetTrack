<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Download, Eye, Pencil, Plus, Search, Trash2 } from 'lucide-vue-next'
import { errorMessage, http } from '@/lib/http'
import { download } from '@/lib/download'
import { fTime } from '@/lib/format'
import { VEHICLE_STATUSES, VEHICLE_TYPES } from '@/lib/options'
import { toast } from '@/lib/toast'
import type { Paginated, VehicleRow } from '@/lib/types'
import { useAuthStore } from '@/stores/auth'
import DataTable, { type Column } from '@/components/DataTable.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import VehicleForm from '@/components/VehicleForm.vue'

const auth = useAuthStore()
const router = useRouter()
const rows = ref<VehicleRow[]>([])
const total = ref(0)
const counts = ref<Record<string, number>>({})
const loading = ref(true)
const error = ref('')
const f = reactive({ q: '', type: '', gps: '', status: '', page: 1, per_page: 10, sort: 'name', dir: 'asc' as 'asc' | 'desc' })

let timer: ReturnType<typeof setTimeout> | undefined
async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await http.get<Paginated<VehicleRow>>('/api/vehicles', { params: { ...f, q: f.q || undefined, type: f.type || undefined, gps: f.gps || undefined, status: f.status || undefined } })
    rows.value = data.data
    total.value = data.meta.total
    counts.value = data.meta.counts ?? {}
  } catch (e) {
    error.value = errorMessage(e, 'Impossible de charger les camions.')
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(() => [f.type, f.gps, f.status, f.per_page], () => { f.page = 1; load() })
watch(() => f.q, () => { clearTimeout(timer); timer = setTimeout(() => { f.page = 1; load() }, 300) })

const TABS = computed(() => [
  { key: '', label: 'Tous', n: counts.value.total }, { key: 'moving', label: 'En mouvement', n: counts.value.moving },
  { key: 'stopped', label: 'À l’arrêt', n: counts.value.stopped }, { key: 'offline', label: 'Hors ligne', n: counts.value.offline },
  { key: 'no_device', label: 'Sans boîtier', n: counts.value.no_device },
])

const columns: Column[] = [
  { key: 'plate_number', label: 'Immatriculation', sortable: true }, { key: 'name', label: 'Nom', sortable: true }, { key: 'type', label: 'Type', sortable: true },
  { key: 'brand', label: 'Marque / modèle' }, { key: 'driver', label: 'Chauffeur' }, { key: 'gps', label: 'Statut' }, { key: 'imei', label: 'IMEI' },
  { key: 'position', label: 'Dernière position' }, { key: 'today_km', label: 'Km jour', align: 'right', sortable: true }, { key: 'actions', label: 'Actions' },
]

// ── Formulaire & suppression ───────────────────────────────────────────────
const formOpen = ref(false)
const editing = ref<VehicleRow | null>(null)
function openForm(v: VehicleRow | null) { editing.value = v; formOpen.value = true }
function onSaved(v: VehicleRow) {
  formOpen.value = false
  toast(editing.value ? `${v.name} enregistré.` : `${v.name} ajouté.`)
  load()
}
const toDelete = ref<VehicleRow | null>(null)
const deleting = ref(false)
async function confirmDelete() {
  if (!toDelete.value) return
  deleting.value = true
  try {
    await http.delete(`/api/vehicles/${toDelete.value.id}`)
    toast(`${toDelete.value.name} supprimé.`)
    toDelete.value = null
    load()
  } catch (e) {
    toast(errorMessage(e), 'error')
  } finally {
    deleting.value = false
  }
}
const exporting = ref(false)
async function exportExcel() {
  exporting.value = true
  try { await download('/api/vehicles/export', { q: f.q || undefined, type: f.type || undefined, gps: f.gps || undefined, status: f.status || undefined, sort: f.sort, dir: f.dir }, 'camions.xlsx') }
  catch (e) { toast(errorMessage(e, 'Export impossible.'), 'error') } finally { exporting.value = false }
}
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap gap-2" role="tablist" aria-label="Statut GPS">
      <button v-for="t in TABS" :key="t.key" type="button" role="tab" :aria-selected="f.gps === t.key"
        class="inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3.5 text-[13px] font-semibold"
        :class="f.gps === t.key ? 'border-brand bg-brand text-white' : 'border-line bg-white text-ink hover:bg-page'" @click="f.gps = t.key">
        {{ t.label }}<span class="text-xs font-bold opacity-85">{{ t.n ?? '…' }}</span>
      </button>
    </div>
    <div class="flex flex-wrap gap-2.5">
      <button v-if="auth.can('vehicles.export')" type="button" class="btn btn-secondary" :disabled="exporting" @click="exportExcel"><Download :size="17" />{{ exporting ? 'Export…' : 'Exporter Excel' }}</button>
      <button v-if="auth.can('vehicles.create')" type="button" class="btn btn-primary" @click="openForm(null)"><Plus :size="17" />Ajouter un camion</button>
    </div>
  </div>

  <section class="card p-5">
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <label class="field">Recherche
        <span class="relative"><Search :size="16" class="absolute top-3 left-3 text-muted" /><input v-model="f.q" type="search" class="input pl-9" placeholder="Immatriculation, nom, chauffeur, IMEI…" /></span>
      </label>
      <label class="field">Type<select v-model="f.type" class="input"><option value="">Tous les types</option><option v-for="t in VEHICLE_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option></select></label>
      <label class="field">Statut administratif<select v-model="f.status" class="input"><option value="">Tous</option><option v-for="t in VEHICLE_STATUSES" :key="t.value" :value="t.value">{{ t.label }}</option></select></label>
      <label class="field">Par page<select v-model.number="f.per_page" class="input"><option :value="10">10</option><option :value="20">20</option><option :value="50">50</option></select></label>
    </div>

    <DataTable :columns="columns" :rows="rows" :loading="loading" :error="error" :total="total" :page="f.page" :per-page="f.per_page"
      :sort="f.sort" :dir="f.dir" item-label="camions" empty-title="Aucun camion" empty-message="Aucun camion ne correspond à ces filtres." min-width="1040px" clickable
      @update:page="(p) => { f.page = p; load() }" @update:sort="(s, d) => { f.sort = s; f.dir = d; load() }" @retry="load" @row-click="(v) => router.push(`/camions/${v.id}`)">
      <template #cell-plate_number="{ row }"><b class="whitespace-nowrap">{{ row.plate_number }}</b></template>
      <template #cell-name="{ row }"><span class="whitespace-nowrap">{{ row.name }}</span></template>
      <template #cell-type="{ row }">{{ row.type_label }}</template>
      <template #cell-brand="{ row }"><span class="whitespace-nowrap">{{ [row.brand, row.model].filter(Boolean).join(' ') || '—' }}</span><StatusBadge v-if="row.status !== 'active'" class="ml-1.5" :tone="row.status_tone" :label="row.status_label" /></template>
      <template #cell-driver="{ row }"><span class="whitespace-nowrap">{{ row.driver?.name ?? '—' }}</span></template>
      <template #cell-gps="{ row }"><StatusBadge :status="row.live?.status ?? 'offline'" /></template>
      <template #cell-imei="{ row }"><span class="font-mono whitespace-nowrap text-muted">{{ row.device ? `…${row.device.imei.slice(-4)}` : 'Sans boîtier' }}</span></template>
      <template #cell-position="{ row }"><span class="block max-w-48 truncate text-muted">{{ row.live?.address ?? '—' }}<template v-if="row.live?.last_position_at"> · {{ fTime(row.live.last_position_at) }}</template></span></template>
      <template #cell-today_km="{ row }"><span class="num">{{ Math.round(row.live?.today_distance_km ?? 0) }}</span></template>
      <template #cell-actions="{ row }">
        <div class="flex gap-1.5" @click.stop>
          <RouterLink :to="`/camions/${row.id}`" class="btn-icon" :aria-label="`Voir ${row.name}`" title="Voir"><Eye :size="16" /></RouterLink>
          <button v-if="auth.can('vehicles.update')" type="button" class="btn-icon" :aria-label="`Modifier ${row.name}`" title="Modifier" @click="openForm(row)"><Pencil :size="16" /></button>
          <button v-if="auth.can('vehicles.delete')" type="button" class="btn-icon hover:text-danger" :aria-label="`Supprimer ${row.name}`" title="Supprimer" @click="toDelete = row"><Trash2 :size="16" /></button>
        </div>
      </template>
    </DataTable>
  </section>

  <VehicleForm :open="formOpen" :vehicle="editing" @close="formOpen = false" @saved="onSaved" />
  <ConfirmDialog :open="!!toDelete" danger :title="`Supprimer ${toDelete?.name} ?`" message="Le camion disparaît de la flotte ; son historique (trajets, missions, pointages) est conservé." confirm-label="Supprimer" :busy="deleting" @confirm="confirmDelete" @cancel="toDelete = null" />
</template>
