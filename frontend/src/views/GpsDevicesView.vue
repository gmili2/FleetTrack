<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { BadgeCheck, Cpu, Pencil, Plus, Radio, Search, Settings2, Trash2, Unplug } from 'lucide-vue-next'
import { errorMessage, http } from '@/lib/http'
import { fTime } from '@/lib/format'
import { ago } from '@/lib/time'
import { toast } from '@/lib/toast'
import type { GpsConfig, GpsDeviceRow } from '@/lib/types'
import { useAuthStore } from '@/stores/auth'
import DataTable, { type Column } from '@/components/DataTable.vue'
import StatCard from '@/components/StatCard.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import GpsDeviceForm from '@/components/GpsDeviceForm.vue'

type Meta = { total: number; counts: Record<string, number>; protocols: Record<string, string>; operators: Record<string, string>; defaults: GpsConfig }

const auth = useAuthStore()
const rows = ref<GpsDeviceRow[]>([])
const meta = ref<Meta | null>(null)
const loading = ref(true)
const error = ref('')
const f = reactive({ q: '', tab: 'all', page: 1, per_page: 20 })
const counts = computed(() => meta.value?.counts ?? {})

let timer: ReturnType<typeof setTimeout> | undefined
async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await http.get<{ data: GpsDeviceRow[]; meta: Meta }>('/api/gps-devices', { params: { ...f, q: f.q || undefined } })
    rows.value = data.data
    meta.value = data.meta
  } catch (e) {
    error.value = errorMessage(e, 'Impossible de charger les cartes GPS.')
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(() => f.tab, () => { f.page = 1; load() })
watch(() => f.q, () => { clearTimeout(timer); timer = setTimeout(() => { f.page = 1; load() }, 300) })

const TABS = computed(() => [
  { key: 'all', label: 'Toutes', n: counts.value.all }, { key: 'installed', label: 'Installées', n: counts.value.installed },
  { key: 'free', label: 'Libres', n: counts.value.free }, { key: 'in_service', label: 'En service', n: counts.value.in_service },
  { key: 'inactive', label: 'Désactivées', n: counts.value.inactive },
])
const columns: Column[] = [
  { key: 'name', label: 'Carte' }, { key: 'sim', label: 'SIM · opérateur' }, { key: 'vehicle', label: 'Camion' },
  { key: 'config', label: 'Configuration' }, { key: 'signal', label: 'Dernier signal' }, { key: 'status', label: 'Statut' }, { key: 'actions', label: 'Actions' },
]

const formOpen = ref(false)
const editing = ref<GpsDeviceRow | null>(null)
function openForm(d: GpsDeviceRow | null) { editing.value = d; formOpen.value = true }
function onSaved(d: GpsDeviceRow) { formOpen.value = false; toast(`Carte ${d.name} enregistrée.`); load() }
const toDelete = ref<GpsDeviceRow | null>(null)
const deleting = ref(false)
async function confirmDelete() {
  deleting.value = true
  try {
    await http.delete(`/api/gps-devices/${toDelete.value!.id}`)
    toast(`Carte ${toDelete.value!.name} supprimée.`)
    toDelete.value = null
    load()
  } catch (e) { toast(errorMessage(e), 'error') } finally { deleting.value = false }
}
</script>

<template>
  <div class="grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-3.5">
    <StatCard label="Cartes GPS" :value="counts.all" :hint="`${counts.installed ?? 0} installées`" :icon="Cpu" :loading="loading && !rows.length" />
    <StatCard label="En service" :value="counts.in_service" hint="prises par un chauffeur" tone="green" :icon="BadgeCheck" :loading="loading && !rows.length" />
    <StatCard label="Libres" :value="counts.free" hint="disponibles au pointage" tone="blue" :icon="Unplug" :loading="loading && !rows.length" />
    <StatCard label="En ligne" :value="counts.online" hint="signal depuis moins de 10 min" tone="orange" :icon="Radio" :loading="loading && !rows.length" />
  </div>

  <section class="card p-5">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap gap-2" role="tablist" aria-label="Filtrer les cartes">
        <button v-for="t in TABS" :key="t.key" type="button" role="tab" :aria-selected="f.tab === t.key"
          class="inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3.5 text-[13px] font-semibold"
          :class="f.tab === t.key ? 'border-brand bg-brand text-white' : 'border-line bg-white text-ink hover:bg-page'" @click="f.tab = t.key">
          {{ t.label }}<span class="text-xs font-bold opacity-85">{{ t.n ?? '…' }}</span>
        </button>
      </div>
      <div class="flex flex-wrap gap-2.5">
        <label class="flex h-10 w-full items-center gap-2 rounded-[10px] border border-line bg-page px-3 text-muted sm:w-64">
          <Search :size="17" /><span class="sr-only">Rechercher une carte</span>
          <input v-model="f.q" type="search" placeholder="Nom, IMEI, SIM, camion…" class="min-w-0 flex-1 border-0 bg-transparent text-sm text-ink outline-none" />
        </label>
        <button v-if="auth.can('devices.create')" type="button" class="btn btn-primary" @click="openForm(null)"><Plus :size="17" />Ajouter une carte</button>
      </div>
    </div>

    <DataTable :columns="columns" :rows="rows" :loading="loading" :error="error" :total="meta?.total ?? 0" :page="f.page" :per-page="f.per_page" item-label="cartes"
      empty-title="Aucune carte GPS" empty-message="Aucune carte ne correspond à ces filtres." min-width="1000px" :clickable="auth.can('devices.update')"
      @update:page="(p) => { f.page = p; load() }" @retry="load" @row-click="(r) => auth.can('devices.update') && openForm(r)">
      <template #cell-name="{ row }">
        <div class="flex items-center gap-3 whitespace-nowrap">
          <span class="flex size-9 flex-none items-center justify-center rounded-lg bg-brand-soft text-brand-dark"><Cpu :size="18" /></span>
          <span><b class="block">{{ row.name }}</b><span class="font-mono text-xs text-muted">{{ row.imei }}</span><span v-if="row.model" class="text-xs text-muted"> · {{ row.model }}</span></span>
        </div>
      </template>
      <template #cell-sim="{ row }"><span class="whitespace-nowrap">{{ row.sim_number ?? '—' }}</span><span class="block text-xs text-muted">{{ row.operator_label ?? '—' }}{{ row.config.apn ? ` · APN ${row.config.apn}` : '' }}</span></template>
      <template #cell-vehicle="{ row }">
        <template v-if="row.vehicle"><RouterLink :to="`/camions/${row.vehicle.id}`" class="font-semibold whitespace-nowrap text-brand hover:underline" @click.stop>{{ row.vehicle.name }}</RouterLink><span class="block text-xs whitespace-nowrap text-muted">{{ row.vehicle.plate_number }}</span></template>
        <span v-else class="text-muted">Libre</span>
      </template>
      <template #cell-config="{ row }">
        <span class="whitespace-nowrap text-[13px]"><Settings2 :size="14" class="mr-1 inline align-[-2px] text-muted" />{{ row.protocol_label }}</span>
        <span class="block whitespace-nowrap text-xs text-muted">{{ row.config.server_host }}:{{ row.config.server_port }} · {{ row.config.interval_moving_s }} s / {{ row.config.interval_stopped_s }} s</span>
      </template>
      <template #cell-signal="{ row }">
        <span class="flex items-center gap-1.5 whitespace-nowrap"><span class="size-2 rounded-full" :class="row.online ? 'bg-moving' : 'bg-offline'" />{{ row.last_seen_at ? ago(row.last_seen_at, Date.now()) : 'Jamais' }}</span>
      </template>
      <template #cell-status="{ row }">
        <StatusBadge v-if="row.in_service" tone="green" :label="`${row.in_service.driver ?? 'En service'} · ${fTime(row.in_service.start_at)}`" :icon="BadgeCheck" />
        <StatusBadge v-else-if="!row.is_active" tone="gray" label="Désactivée" />
        <StatusBadge v-else-if="row.vehicle" tone="blue" label="Installée" />
        <StatusBadge v-else tone="orange" label="Libre" />
      </template>
      <template #cell-actions="{ row }">
        <div class="flex gap-1.5" @click.stop>
          <button v-if="auth.can('devices.update')" type="button" class="btn-icon" :aria-label="`Configurer ${row.name}`" title="Modifier / configurer" @click="openForm(row)"><Pencil :size="16" /></button>
          <button v-if="auth.can('devices.delete')" type="button" class="btn-icon hover:text-danger" :aria-label="`Supprimer ${row.name}`" title="Supprimer" :disabled="!!row.in_service" @click="toDelete = row"><Trash2 :size="16" /></button>
        </div>
      </template>
    </DataTable>
  </section>

  <GpsDeviceForm :open="formOpen" :device="editing" :protocols="meta?.protocols ?? {}" :operators="meta?.operators ?? {}" :defaults="meta?.defaults ?? null"
    @close="formOpen = false" @saved="onSaved" />
  <ConfirmDialog :open="!!toDelete" danger :title="`Supprimer la carte ${toDelete?.name} ?`" message="La carte est retirée de son camion ; les positions et services passés sont conservés." confirm-label="Supprimer" :busy="deleting" @confirm="confirmDelete" @cancel="toDelete = null" />
</template>
