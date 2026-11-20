<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Activity, Gauge, KeyRound, LockOpen, Pause, Pencil, Play, Plus, Save, ScrollText, Trash2, Users } from 'lucide-vue-next'
import { errorMessage, fieldErrors, http } from '@/lib/http'
import { fDateTime } from '@/lib/format'
import { ago } from '@/lib/time'
import { toast } from '@/lib/toast'
import type { DriverRow, Paginated } from '@/lib/types'
import { useAuthStore } from '@/stores/auth'
import StatusBadge from '@/components/StatusBadge.vue'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import Modal from '@/components/Modal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import DataTable, { type Column } from '@/components/DataTable.vue'

type Sim = { running: boolean; process_alive: boolean; heartbeat_at: string | null; interval_seconds: number; vehicles: number; positions_last_hour: number }
type UserRow = { id: number; name: string; email: string; phone: string | null; initials: string; is_active: boolean; role: string; role_label: string; driver: { id: number; employee_number: string } | null; last_login_at: string | null; locked: boolean; can: { update: boolean; delete: boolean } }
type Role = { value: string; label: string; assignable: boolean }

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const TABS = computed(() => [
  { key: 'thresholds', label: 'Seuils et alertes', icon: Gauge, show: auth.can('settings.view') },
  { key: 'simulator', label: 'Simulateur GPS', icon: Activity, show: auth.can('settings.view') },
  { key: 'users', label: 'Utilisateurs et rôles', icon: Users, show: auth.can('users.view') },
  { key: 'audit', label: 'Journal d’audit', icon: ScrollText, show: auth.can('settings.view') },
].filter((t) => t.show))
const tab = ref(String(route.query.tab ?? 'thresholds'))
watch(tab, (t) => router.replace({ query: { tab: t } }))

// ── Seuils & simulateur ────────────────────────────────────────────────────
const thresholds = reactive({ moving_kmh: 5, offline_minutes: 10, stop_minutes: 5 })
const sim = ref<Sim | null>(null)
const tErrors = ref<Record<string, string>>({})
const busy = ref(false)
const error = ref('')
async function loadSettings() {
  try {
    const { data } = await http.get<{ data: { thresholds: typeof thresholds; simulator: Sim } }>('/api/settings')
    Object.assign(thresholds, data.data.thresholds)
    sim.value = data.data.simulator
    error.value = ''
  } catch (e) { error.value = errorMessage(e) }
}
async function saveThresholds() {
  busy.value = true
  tErrors.value = {}
  try { await http.put('/api/settings/thresholds', thresholds); toast('Seuils enregistrés : ils s’appliquent immédiatement.') }
  catch (e) { tErrors.value = fieldErrors(e); if (!Object.keys(tErrors.value).length) toast(errorMessage(e), 'error') } finally { busy.value = false }
}
async function toggleSim() {
  busy.value = true
  try { sim.value = (await http.post<{ data: Sim; message: string }>('/api/settings/simulator', { running: !sim.value?.running })).data.data; toast(sim.value.running ? 'Simulateur démarré.' : 'Simulateur en pause.') }
  catch (e) { toast(errorMessage(e), 'error') } finally { busy.value = false }
}

// ── Utilisateurs ───────────────────────────────────────────────────────────
const users = ref<UserRow[]>([])
const roles = ref<Role[]>([])
const drivers = ref<DriverRow[]>([])
const usersLoading = ref(false)
async function loadUsers() {
  usersLoading.value = true
  try { const { data } = await http.get<{ data: UserRow[]; meta: { roles: Role[] } }>('/api/users'); users.value = data.data; roles.value = data.meta.roles }
  catch (e) { toast(errorMessage(e), 'error') } finally { usersLoading.value = false }
}
const userCols: Column[] = [{ key: 'name', label: 'Utilisateur' }, { key: 'email', label: 'E-mail' }, { key: 'role', label: 'Rôle' }, { key: 'last', label: 'Dernière connexion' }, { key: 'state', label: 'État' }, { key: 'actions', label: 'Actions' }]
const uOpen = ref(false)
const uEditing = ref<UserRow | null>(null)
const uForm = reactive({ name: '', email: '', phone: '', role: 'operator', driver_id: null as number | null, is_active: true, password: '' })
const uErrors = ref<Record<string, string>>({})
async function openUser(u: UserRow | null) {
  uEditing.value = u
  uErrors.value = {}
  Object.assign(uForm, u ? { name: u.name, email: u.email, phone: u.phone ?? '', role: u.role, driver_id: u.driver?.id ?? null, is_active: u.is_active, password: '' } : { name: '', email: '', phone: '', role: 'operator', driver_id: null, is_active: true, password: '' })
  uOpen.value = true
  if (!drivers.value.length && auth.can('drivers.view')) drivers.value = (await http.get<Paginated<DriverRow>>('/api/drivers', { params: { per_page: 100 } })).data.data
}
async function saveUser() {
  busy.value = true
  uErrors.value = {}
  const payload = { ...uForm, password: uForm.password || undefined, driver_id: uForm.role === 'driver' ? uForm.driver_id : null }
  try {
    if (uEditing.value) await http.put(`/api/users/${uEditing.value.id}`, payload)
    else await http.post('/api/users', payload)
    toast(uEditing.value ? 'Utilisateur modifié.' : 'Utilisateur créé.')
    uOpen.value = false
    loadUsers()
  } catch (e) { uErrors.value = fieldErrors(e); if (!Object.keys(uErrors.value).length) toast(errorMessage(e), 'error') } finally { busy.value = false }
}
const toDelete = ref<UserRow | null>(null)
async function deleteUser() {
  try { await http.delete(`/api/users/${toDelete.value!.id}`); toast('Utilisateur supprimé.'); toDelete.value = null; loadUsers() } catch (e) { toast(errorMessage(e), 'error') }
}
async function unlock(u: UserRow) {
  try { await http.post(`/api/users/${u.id}/unlock`); toast(`Compte de ${u.name} déverrouillé.`); loadUsers() } catch (e) { toast(errorMessage(e), 'error') }
}

// ── Journal d'audit ────────────────────────────────────────────────────────
type Audit = { id: number; action: string; description: string | null; user: string; subject: string | null; ip: string | null; created_at: string }
const audit = ref<Audit[]>([])
const auditMeta = ref({ total: 0, per_page: 20 })
const auditPage = ref(1)
const auditLoading = ref(false)
async function loadAudit() {
  auditLoading.value = true
  try { const { data } = await http.get<Paginated<Audit>>('/api/audit-logs', { params: { page: auditPage.value } }); audit.value = data.data; auditMeta.value = data.meta } finally { auditLoading.value = false }
}
const auditCols: Column[] = [{ key: 'created_at', label: 'Date' }, { key: 'user', label: 'Utilisateur' }, { key: 'action', label: 'Action' }, { key: 'description', label: 'Détail' }, { key: 'ip', label: 'Adresse IP' }]
const ACTIONS: Record<string, [string, 'green' | 'blue' | 'red' | 'gray']> = { created: ['Création', 'green'], updated: ['Modification', 'blue'], deleted: ['Suppression', 'red'], settings: ['Paramètres', 'gray'] }

let poll: ReturnType<typeof setInterval> | null = null
const nowTick = ref(Date.now())
watch(tab, (t) => { if (t === 'users' && !users.value.length) loadUsers(); if (t === 'audit') loadAudit() }, { immediate: true })
onMounted(() => {
  if (!TABS.value.find((t) => t.key === tab.value)) tab.value = TABS.value[0]?.key ?? 'users'
  if (auth.can('settings.view')) { loadSettings(); poll = setInterval(() => { nowTick.value = Date.now(); if (tab.value === 'simulator') loadSettings() }, 5000) }
})
onBeforeUnmount(() => { if (poll) clearInterval(poll) })
const canEditSettings = computed(() => auth.can('settings.update'))
</script>

<template>
  <div class="grid gap-5 lg:grid-cols-[240px_minmax(0,1fr)]">
    <nav class="card self-start p-2" aria-label="Sections des paramètres">
      <button v-for="t in TABS" :key="t.key" type="button" class="flex w-full items-center gap-3 rounded-[10px] px-3 py-2.5 text-left text-sm" :aria-current="tab === t.key ? 'page' : undefined"
        :class="tab === t.key ? 'bg-brand font-semibold text-white' : 'text-ink hover:bg-page'" @click="tab = t.key"><component :is="t.icon" :size="17" />{{ t.label }}</button>
    </nav>

    <div class="min-w-0">
      <ErrorState v-if="error && tab !== 'users'" :message="error" @retry="loadSettings" />

      <section v-if="tab === 'thresholds'" class="card p-6">
        <h2 class="m-0 text-lg font-bold">Seuils et alertes</h2>
        <p class="m-0 mt-1 mb-5 text-sm text-muted">Utilisés pour les statuts en direct, les arrêts des trajets et les rapports. Les changements s’appliquent immédiatement, simulateur compris.</p>
        <form class="grid max-w-3xl gap-4 sm:grid-cols-3" novalidate @submit.prevent="saveThresholds">
          <label class="field">En mouvement au-delà de (km/h)<input v-model.number="thresholds.moving_kmh" type="number" min="1" max="30" class="input" :disabled="!canEditSettings" /><span v-if="tErrors.moving_kmh" class="text-xs font-normal text-danger">{{ tErrors.moving_kmh }}</span></label>
          <label class="field">Hors ligne après (min)<input v-model.number="thresholds.offline_minutes" type="number" min="2" max="180" class="input" :disabled="!canEditSettings" /><span v-if="tErrors.offline_minutes" class="text-xs font-normal text-danger">{{ tErrors.offline_minutes }}</span></label>
          <label class="field">Arrêt à partir de (min)<input v-model.number="thresholds.stop_minutes" type="number" min="1" max="120" class="input" :disabled="!canEditSettings" /><span v-if="tErrors.stop_minutes" class="text-xs font-normal text-danger">{{ tErrors.stop_minutes }}</span></label>
          <div class="sm:col-span-3"><button v-if="canEditSettings" type="submit" class="btn btn-primary" :disabled="busy"><Save :size="16" />Enregistrer</button><span v-else class="text-sm text-muted">Lecture seule : votre rôle ne permet pas de modifier les paramètres.</span></div>
        </form>
      </section>

      <section v-else-if="tab === 'simulator'" class="card p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 class="m-0 text-lg font-bold">Simulateur GPS</h2>
            <p class="m-0 mt-1 text-sm text-muted">Fait rouler les camions virtuels (une position toutes les {{ sim?.interval_seconds ?? 5 }} s) et génère badges, visites et alertes.</p>
          </div>
          <button v-if="auth.can('simulator.manage') && sim" type="button" class="btn" :class="sim.running ? 'btn-secondary' : 'btn-primary'" :disabled="busy" @click="toggleSim">
            <Pause v-if="sim.running" :size="16" /><Play v-else :size="16" />{{ sim.running ? 'Mettre en pause' : 'Démarrer' }}
          </button>
        </div>
        <LoadingState v-if="!sim" />
        <div v-else class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div class="rounded-xl border border-line p-4"><div class="text-xs text-muted">État demandé</div><StatusBadge class="mt-1" :tone="sim.running ? 'green' : 'orange'" :label="sim.running ? 'En marche' : 'En pause'" :icon="sim.running ? Play : Pause" /></div>
          <div class="rounded-xl border border-line p-4"><div class="text-xs text-muted">Processus fleet:simulate</div><StatusBadge class="mt-1" :tone="sim.process_alive ? 'green' : 'red'" :label="sim.process_alive ? 'Actif' : 'Arrêté'" :icon="Activity" /><div class="mt-1 text-xs text-muted">{{ sim.heartbeat_at ? `dernier signal ${ago(sim.heartbeat_at, nowTick)}` : 'aucun signal' }}</div></div>
          <div class="rounded-xl border border-line p-4"><div class="text-xs text-muted">Camions simulés</div><b class="num text-2xl">{{ sim.vehicles }}</b></div>
          <div class="rounded-xl border border-line p-4"><div class="text-xs text-muted">Positions (dernière heure)</div><b class="num text-2xl">{{ sim.positions_last_hour.toLocaleString('fr-FR') }}</b></div>
        </div>
        <p v-if="sim && !sim.process_alive" class="mt-4 rounded-xl border border-[#FED7AA] bg-[#FFF7ED] px-4 py-3 text-sm text-[#9A3412]">
          Le processus n’est pas lancé : démarrez-le sur le serveur avec <code class="font-semibold">php artisan fleet:simulate</code> (ou <code class="font-semibold">./demo.sh</code>). Le bouton ci-dessus met seulement en pause / relance un processus actif.
        </p>
      </section>

      <section v-else-if="tab === 'users'" class="card p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div><h2 class="m-0 text-lg font-bold">Utilisateurs et rôles</h2><p class="m-0 text-sm text-muted">Un administrateur ne peut ni créer ni modifier de super administrateur.</p></div>
          <button v-if="auth.can('users.create')" type="button" class="btn btn-primary" @click="openUser(null)"><Plus :size="16" />Ajouter un utilisateur</button>
        </div>
        <DataTable :columns="userCols" :rows="users" :loading="usersLoading" :total="users.length" :per-page="100" item-label="utilisateurs" min-width="860px">
          <template #cell-name="{ row }"><div class="flex items-center gap-2.5"><span class="flex size-8 items-center justify-center rounded-full bg-brand-soft text-xs font-bold text-brand-dark">{{ row.initials }}</span><b class="whitespace-nowrap">{{ row.name }}</b></div></template>
          <template #cell-role="{ row }"><span class="whitespace-nowrap">{{ row.role_label }}</span><span v-if="row.driver" class="block text-xs text-muted">fiche {{ row.driver.employee_number }}</span></template>
          <template #cell-last="{ row }"><span class="whitespace-nowrap">{{ row.last_login_at ? fDateTime(row.last_login_at) : 'jamais' }}</span></template>
          <template #cell-state="{ row }">
            <StatusBadge v-if="row.locked" tone="red" label="Verrouillé" :icon="KeyRound" />
            <StatusBadge v-else :tone="row.is_active ? 'green' : 'gray'" :label="row.is_active ? 'Actif' : 'Désactivé'" :icon="Users" />
          </template>
          <template #cell-actions="{ row }">
            <div class="flex gap-1.5">
              <button v-if="row.locked && row.can.update" type="button" class="btn-icon" title="Déverrouiller" :aria-label="`Déverrouiller ${row.name}`" @click="unlock(row)"><LockOpen :size="16" /></button>
              <button v-if="row.can.update" type="button" class="btn-icon" title="Modifier" :aria-label="`Modifier ${row.name}`" @click="openUser(row)"><Pencil :size="16" /></button>
              <button v-if="row.can.delete" type="button" class="btn-icon hover:text-danger" title="Supprimer" :aria-label="`Supprimer ${row.name}`" @click="toDelete = row"><Trash2 :size="16" /></button>
            </div>
          </template>
        </DataTable>
      </section>

      <section v-else-if="tab === 'audit'" class="card p-5">
        <h2 class="m-0 mb-1 text-lg font-bold">Journal d’audit</h2>
        <p class="m-0 mb-4 text-sm text-muted">Créations, modifications et suppressions effectuées par les utilisateurs.</p>
        <DataTable :columns="auditCols" :rows="audit" :loading="auditLoading" :total="auditMeta.total" :page="auditPage" :per-page="auditMeta.per_page" item-label="actions" min-width="760px" empty-title="Journal vide" @update:page="(p) => { auditPage = p; loadAudit() }">
          <template #cell-created_at="{ row }"><span class="whitespace-nowrap">{{ fDateTime(row.created_at) }}</span></template>
          <template #cell-action="{ row }"><StatusBadge :tone="(ACTIONS[row.action] ?? [row.action, 'gray'])[1]" :label="(ACTIONS[row.action] ?? [row.action])[0]" :icon="ScrollText" /></template>
          <template #cell-ip="{ row }"><span class="font-mono text-xs text-muted">{{ row.ip ?? '—' }}</span></template>
        </DataTable>
      </section>
    </div>
  </div>

  <Modal :open="uOpen" :title="uEditing ? `Modifier ${uEditing.name}` : 'Ajouter un utilisateur'" @close="uOpen = false">
    <form id="user-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="saveUser">
      <label class="field">Nom *<input v-model="uForm.name" class="input" /><span v-if="uErrors.name" class="text-xs font-normal text-danger">{{ uErrors.name }}</span></label>
      <label class="field">Adresse e-mail *<input v-model="uForm.email" type="email" class="input" /><span v-if="uErrors.email" class="text-xs font-normal text-danger">{{ uErrors.email }}</span></label>
      <label class="field">Téléphone<input v-model="uForm.phone" class="input" /></label>
      <label class="field">Rôle *<select v-model="uForm.role" class="input"><option v-for="r in roles" :key="r.value" :value="r.value" :disabled="!r.assignable">{{ r.label }}{{ r.assignable ? '' : ' (non autorisé)' }}</option></select><span v-if="uErrors.role" class="text-xs font-normal text-danger">{{ uErrors.role }}</span></label>
      <label v-if="uForm.role === 'driver'" class="field sm:col-span-2">Fiche chauffeur liée *
        <select v-model="uForm.driver_id" class="input"><option :value="null">Choisir…</option><option v-for="d in drivers" :key="d.id" :value="d.id">{{ d.name }} ({{ d.employee_number }} · badge {{ d.badge_number }})</option></select>
        <span v-if="uErrors.driver_id" class="text-xs font-normal text-danger">{{ uErrors.driver_id }}</span>
      </label>
      <label class="field">{{ uEditing ? 'Nouveau mot de passe' : 'Mot de passe *' }}<input v-model="uForm.password" type="password" autocomplete="new-password" class="input" :placeholder="uEditing ? 'laisser vide pour ne pas changer' : '8 caractères, lettres et chiffres'" /><span v-if="uErrors.password" class="text-xs font-normal text-danger">{{ uErrors.password }}</span></label>
      <label class="inline-flex items-center gap-2 self-end pb-2 text-sm"><input v-model="uForm.is_active" type="checkbox" class="size-[18px] accent-brand" />Compte actif</label>
    </form>
    <template #footer>
      <button type="button" class="btn btn-secondary" @click="uOpen = false">Annuler</button>
      <button type="submit" form="user-form" class="btn btn-primary" :disabled="busy"><Save :size="16" />Enregistrer</button>
    </template>
  </Modal>
  <ConfirmDialog :open="!!toDelete" danger :title="`Supprimer ${toDelete?.name} ?`" message="Ce compte ne pourra plus se connecter." confirm-label="Supprimer" @confirm="deleteUser" @cancel="toDelete = null" />
</template>
