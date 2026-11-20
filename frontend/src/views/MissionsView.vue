<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Ban, CheckCheck, ClipboardList, Clock, Download, Map, MessageSquare, Pencil, Play, Plus, Search, Trash2, TriangleAlert, X } from 'lucide-vue-next'
import { errorMessage, fieldErrors, http } from '@/lib/http'
import { download } from '@/lib/download'
import { fDateTime, fKm, fTime } from '@/lib/format'
import { toast } from '@/lib/toast'
import type { MissionRow, Paginated } from '@/lib/types'
import { useAuthStore } from '@/stores/auth'
import { useFleetStore } from '@/stores/fleet'
import DataTable, { type Column } from '@/components/DataTable.vue'
import StatCard from '@/components/StatCard.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import EmptyState from '@/components/EmptyState.vue'
import LoadingState from '@/components/LoadingState.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import Modal from '@/components/Modal.vue'
import MissionForm from '@/components/MissionForm.vue'

type Meta = Paginated<MissionRow>['meta'] & { counts: Record<string, number>; late: number; next_pending_at: string | null; completed_today: number; completed_today_on_time: number; cancelled_month: number }
const auth = useAuthStore()
const fleet = useFleetStore()
const route = useRoute()
const router = useRouter()
const rows = ref<MissionRow[]>([])
const meta = ref<Meta | null>(null)
const loading = ref(true)
const error = ref('')
const f = reactive({ status: 'all', q: '', date: '', page: 1, per_page: 20 })

let timer: ReturnType<typeof setTimeout> | undefined
async function load() {
  loading.value = true
  try {
    const { data } = await http.get<{ data: MissionRow[]; meta: Meta }>('/api/missions', { params: { ...f, q: f.q || undefined, date: f.date || undefined } })
    rows.value = data.data
    meta.value = data.meta
    error.value = ''
  } catch (e) {
    error.value = errorMessage(e, 'Impossible de charger les missions.')
  } finally {
    loading.value = false
  }
}
watch(() => [f.status, f.date], () => { f.page = 1; load() })
watch(() => f.q, () => { clearTimeout(timer); timer = setTimeout(() => { f.page = 1; load() }, 300) })

const TABS = computed(() => [['all', 'Toutes'], ['pending', 'En attente'], ['in_progress', 'En cours'], ['completed', 'Terminées'], ['cancelled', 'Annulées']].map(([key, label]) => ({ key, label, n: meta.value?.counts[key] ?? 0 })))
const columns: Column[] = [
  { key: 'reference', label: 'Référence' }, { key: 'vehicle', label: 'Camion' }, { key: 'driver', label: 'Chauffeur' }, { key: 'route', label: 'Départ → destination' },
  { key: 'start', label: 'Début' }, { key: 'end', label: 'Fin prévue' }, { key: 'status', label: 'Statut' },
]

// ── Détail ─────────────────────────────────────────────────────────────────
const selected = ref<MissionRow | null>(null)
const detailLoading = ref(false)
async function open(id: number) {
  detailLoading.value = true
  router.replace({ query: { ...route.query, m: String(id) } })
  try { selected.value = (await http.get<{ data: MissionRow }>(`/api/missions/${id}`)).data.data } catch (e) { toast(errorMessage(e), 'error') } finally { detailLoading.value = false }
}
function closeDetail() { selected.value = null; router.replace({ query: {} }) }

const acting = ref('')
async function act(action: 'start' | 'complete' | 'cancel' | 'comment', body: Record<string, string> = {}) {
  if (!selected.value) return
  acting.value = action
  try {
    selected.value = (await http.post<{ data: MissionRow }>(`/api/missions/${selected.value.id}/${action}`, body)).data.data
    toast({ start: 'Mission démarrée.', complete: 'Mission terminée.', cancel: 'Mission annulée.', comment: 'Commentaire ajouté.' }[action])
    load()
    return true
  } catch (e) {
    const fe = fieldErrors(e)
    toast(fe.mission ?? fe.reason ?? fe.text ?? errorMessage(e), 'error')
    return false
  } finally {
    acting.value = ''
  }
}
const cancelOpen = ref(false)
const cancelReason = ref('')
async function confirmCancel() { if (await act('cancel', { reason: cancelReason.value })) { cancelOpen.value = false; cancelReason.value = '' } }
const comment = ref('')
async function sendComment() { if (comment.value.trim() && (await act('comment', { text: comment.value.trim() }))) comment.value = '' }

const formOpen = ref(false)
const editing = ref<MissionRow | null>(null)
function openForm(m: MissionRow | null) { editing.value = m; formOpen.value = true }
function onSaved(m: MissionRow) { formOpen.value = false; toast(editing.value ? `${m.reference} modifiée.` : `${m.reference} créée.`); load(); open(m.id) }
const askDelete = ref(false)
async function remove() {
  try { await http.delete(`/api/missions/${selected.value!.id}`); toast('Mission supprimée.'); askDelete.value = false; closeDetail(); load() } catch (e) { toast(errorMessage(e), 'error') }
}
async function exportExcel() {
  try { await download('/api/missions/export', { status: f.status, q: f.q || undefined, date: f.date || undefined }, 'missions.xlsx') } catch (e) { toast(errorMessage(e, 'Export impossible.'), 'error') }
}

// Temps réel : un événement de mission recharge la liste (et le détail ouvert).
watch(() => fleet.activity[0], (a) => {
  if (a?.kind === 'mission' || a?.kind === 'zone_enter' || a?.kind === 'zone_exit') { load(); if (selected.value && (a.data?.mission_id === selected.value.id || a.vehicleId === selected.value.vehicle?.id)) open(selected.value.id) }
})
onMounted(() => { load(); if (auth.can('tracking.view')) fleet.start(); if (route.query.m) open(Number(route.query.m)) })
const evIcon = (t: string) => ({ created: Plus, started: Play, completed: CheckCheck, cancelled: Ban, comment: MessageSquare, zone_enter: Map, zone_exit: Map }[t] ?? ClipboardList)
</script>

<template>
  <div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3.5">
    <StatCard label="En attente" :value="meta?.counts.pending ?? 0" :hint="meta?.next_pending_at ? `prochaine à ${fTime(meta.next_pending_at)}` : 'aucune planifiée'" tone="gray" :icon="Clock" :loading="!meta" />
    <StatCard label="En cours" :value="meta?.counts.in_progress ?? 0" :hint="meta?.late ? `${meta.late} en retard` : 'aucune en retard'" tone="blue" :icon="Play" :loading="!meta" />
    <StatCard label="Terminées aujourd’hui" :value="meta?.completed_today ?? 0" :hint="`à l’heure : ${meta?.completed_today_on_time ?? 0}`" tone="green" :icon="CheckCheck" :loading="!meta" />
    <StatCard label="Annulées (mois)" :value="meta?.cancelled_month ?? 0" tone="red" :icon="Ban" :loading="!meta" />
  </div>

  <div class="grid gap-5" :class="selected || detailLoading ? 'xl:grid-cols-[minmax(0,1fr)_400px]' : ''">
    <section class="card p-5">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Statut">
          <button v-for="t in TABS" :key="t.key" type="button" role="tab" :aria-selected="f.status === t.key" class="inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3.5 text-[13px] font-semibold"
            :class="f.status === t.key ? 'border-brand bg-brand text-white' : 'border-line bg-white hover:bg-page'" @click="f.status = t.key">{{ t.label }}<span class="text-xs font-bold opacity-85">{{ t.n }}</span></button>
        </div>
        <div class="flex flex-wrap gap-2">
          <input v-model="f.date" type="date" class="input w-auto" aria-label="Date de début prévue" />
          <label class="flex h-10 items-center gap-2 rounded-[10px] border border-line bg-page px-3 text-muted"><Search :size="16" /><span class="sr-only">Rechercher</span><input v-model="f.q" type="search" placeholder="Référence, camion, chauffeur…" class="w-48 border-0 bg-transparent text-sm text-ink outline-none" /></label>
          <button v-if="auth.can('missions.export')" type="button" class="btn btn-secondary" @click="exportExcel"><Download :size="16" />Excel</button>
          <button v-if="auth.can('missions.create')" type="button" class="btn btn-primary" @click="openForm(null)"><Plus :size="16" />Nouvelle mission</button>
        </div>
      </div>
      <DataTable :columns="columns" :rows="rows" :loading="loading" :error="error" :total="meta?.total ?? 0" :page="f.page" :per-page="f.per_page" item-label="missions" clickable min-width="900px"
        empty-title="Aucune mission" empty-message="Aucune mission pour ces filtres." @update:page="(p) => { f.page = p; load() }" @retry="load" @row-click="(m) => open(m.id)">
        <template #cell-reference="{ row }"><b class="whitespace-nowrap" :class="selected?.id === row.id ? 'text-brand' : ''">{{ row.reference }}</b></template>
        <template #cell-vehicle="{ row }"><span class="whitespace-nowrap">{{ row.vehicle?.name }}</span></template>
        <template #cell-driver="{ row }"><span class="whitespace-nowrap">{{ row.driver?.name }}</span></template>
        <template #cell-route="{ row }"><span class="text-[13px]">{{ row.origin?.name }} <span class="text-muted">→</span> {{ row.destination?.name }}</span></template>
        <template #cell-start="{ row }"><span class="whitespace-nowrap">{{ fDateTime(row.actual_start_at ?? row.planned_start_at) }}</span></template>
        <template #cell-end="{ row }"><span class="whitespace-nowrap" :class="row.late ? 'font-semibold text-danger' : ''">{{ fDateTime(row.planned_end_at) }}</span></template>
        <template #cell-status="{ row }"><span class="flex items-center gap-1.5"><StatusBadge :tone="row.status_tone" :label="row.status_label" :icon="ClipboardList" /><StatusBadge v-if="row.late" tone="red" label="En retard" :icon="TriangleAlert" /></span></template>
      </DataTable>
    </section>

    <!-- Panneau détail -->
    <aside v-if="selected || detailLoading" class="card flex flex-col gap-4 self-start p-5 xl:sticky xl:top-24" aria-label="Détail de la mission">
      <LoadingState v-if="detailLoading && !selected" />
      <template v-else-if="selected">
        <div class="flex items-start justify-between gap-2">
          <div><h2 class="m-0 text-lg font-bold">{{ selected.reference }}</h2><div class="mt-1 flex flex-wrap gap-1.5"><StatusBadge :tone="selected.status_tone" :label="selected.status_label" :icon="ClipboardList" /><StatusBadge v-if="selected.late" tone="red" label="En retard" :icon="TriangleAlert" /></div></div>
          <button type="button" class="btn-icon" aria-label="Fermer le détail" @click="closeDetail"><X :size="16" /></button>
        </div>
        <div class="rounded-xl bg-page p-3 text-sm font-semibold">{{ selected.origin?.name }} <span class="text-muted">→</span> {{ selected.destination?.name }}</div>
        <dl class="m-0 grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-[13px]">
          <dt class="text-muted">Camion</dt><dd class="m-0 text-right font-semibold"><RouterLink v-if="selected.vehicle" :to="`/camions/${selected.vehicle.id}`">{{ selected.vehicle.name }} · {{ selected.vehicle.plate_number }}</RouterLink></dd>
          <dt class="text-muted">Chauffeur</dt><dd class="m-0 text-right font-semibold">{{ selected.driver?.name }}</dd>
          <dt v-if="selected.customer" class="text-muted">Client</dt><dd v-if="selected.customer" class="m-0 text-right font-semibold">{{ selected.customer.name }}</dd>
          <dt class="text-muted">{{ selected.actual_start_at ? 'Début' : 'Début prévu' }}</dt><dd class="m-0 text-right font-semibold">{{ fDateTime(selected.actual_start_at ?? selected.planned_start_at) }}</dd>
          <dt class="text-muted">{{ selected.actual_end_at ? 'Fin' : 'Fin prévue' }}</dt><dd class="m-0 text-right font-semibold" :class="selected.late ? 'text-danger' : ''">{{ fDateTime(selected.actual_end_at ?? selected.planned_end_at) }}</dd>
          <dt v-if="selected.distance_km != null" class="text-muted">Distance parcourue</dt><dd v-if="selected.distance_km != null" class="m-0 text-right font-semibold">{{ fKm(selected.distance_km) }}</dd>
          <dt v-if="selected.cargo" class="text-muted">Chargement</dt><dd v-if="selected.cargo" class="m-0 text-right font-semibold">{{ selected.cargo }}</dd>
          <dt v-if="selected.cancel_reason" class="text-muted">Motif</dt><dd v-if="selected.cancel_reason" class="m-0 text-right font-semibold text-danger">{{ selected.cancel_reason }}</dd>
        </dl>

        <div v-if="selected.can.operate || selected.can.update || selected.can.delete" class="flex flex-wrap gap-2">
          <button v-if="selected.can.operate && selected.status === 'pending'" type="button" class="btn btn-primary flex-1" :disabled="!!acting" @click="act('start')"><Play :size="16" />Démarrer</button>
          <button v-if="selected.can.operate && selected.status === 'in_progress'" type="button" class="btn btn-primary flex-1" :disabled="!!acting" @click="act('complete')"><CheckCheck :size="16" />Terminer</button>
          <RouterLink v-if="selected.status === 'in_progress' && selected.vehicle && auth.can('tracking.view')" :to="`/suivi?v=${selected.vehicle.id}&follow=1`" class="btn btn-secondary flex-1"><Map :size="16" />Voir sur la carte</RouterLink>
          <RouterLink v-else-if="selected.status === 'completed' && selected.vehicle && auth.can('history.view')" :to="{ path: '/historique', query: { vehicle: String(selected.vehicle.id), from: selected.actual_start_at ?? selected.planned_start_at, to: selected.actual_end_at ?? selected.planned_end_at } }" class="btn btn-secondary flex-1"><Map :size="16" />Rejouer le trajet</RouterLink>
          <button v-if="selected.can.update" type="button" class="btn-icon size-10" aria-label="Modifier" title="Modifier" @click="openForm(selected)"><Pencil :size="16" /></button>
          <button v-if="selected.can.operate && ['pending', 'in_progress'].includes(selected.status)" type="button" class="btn btn-secondary w-full text-danger" @click="cancelOpen = true"><Ban :size="16" />Annuler la mission</button>
          <button v-if="selected.can.delete" type="button" class="btn btn-secondary w-full text-danger" @click="askDelete = true"><Trash2 :size="16" />Supprimer</button>
        </div>

        <div>
          <h3 class="m-0 mb-2 text-sm font-bold">Journal des événements</h3>
          <form v-if="selected.can.operate" class="mb-3 flex gap-2" @submit.prevent="sendComment">
            <input v-model="comment" class="input" placeholder="Ajouter un commentaire…" maxlength="500" aria-label="Commentaire" />
            <button type="submit" class="btn btn-secondary px-3" :disabled="!comment.trim() || !!acting" aria-label="Ajouter le commentaire"><MessageSquare :size="16" /></button>
          </form>
          <EmptyState v-if="!selected.events?.length" title="Journal vide" />
          <ol v-else class="m-0 flex list-none flex-col gap-0 border-l-2 border-line p-0 pl-4">
            <li v-for="e in selected.events" :key="e.id" class="relative pb-3">
              <span class="absolute top-0.5 -left-[25px] flex size-4 items-center justify-center rounded-full bg-white text-brand ring-2 ring-line"><component :is="evIcon(e.type)" :size="10" /></span>
              <div class="flex justify-between gap-2 text-[13px]"><b>{{ e.message }}</b><span class="num flex-none text-xs text-muted">{{ fTime(e.occurred_at) }}</span></div>
              <div class="text-xs text-muted">{{ e.automatic ? 'automatique (GPS)' : e.author }}</div>
            </li>
          </ol>
        </div>
      </template>
    </aside>
  </div>

  <MissionForm :open="formOpen" :mission="editing" @close="formOpen = false" @saved="onSaved" />
  <Modal :open="cancelOpen" :title="`Annuler ${selected?.reference} ?`" width="max-w-md" @close="cancelOpen = false">
    <label class="field">Motif d’annulation *<textarea v-model="cancelReason" rows="3" class="input h-auto py-2" placeholder="Client absent, livraison reportée…" /></label>
    <template #footer>
      <button type="button" class="btn btn-secondary" @click="cancelOpen = false">Retour</button>
      <button type="button" class="btn btn-danger" :disabled="cancelReason.trim().length < 3 || !!acting" @click="confirmCancel"><Ban :size="16" />Annuler la mission</button>
    </template>
  </Modal>
  <ConfirmDialog :open="askDelete" danger :title="`Supprimer ${selected?.reference} ?`" message="La mission sera retirée de la liste." confirm-label="Supprimer" @confirm="remove" @cancel="askDelete = false" />
</template>
