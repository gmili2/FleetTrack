<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { Save } from 'lucide-vue-next'
import { errorMessage, fieldErrors, http } from '@/lib/http'
import { TZ } from '@/lib/format'
import type { DriverRow, MissionRow, Paginated, VehicleRow, ZoneRow } from '@/lib/types'
import Modal from './Modal.vue'

const props = defineProps<{ open: boolean; mission: MissionRow | null }>()
const emit = defineEmits<{ close: []; saved: [m: MissionRow] }>()

/** Date ISO → valeur d'un <input type="datetime-local"> à l'heure de Casablanca. */
const toLocal = (iso: string) => {
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(iso)).map((x) => [x.type, x.value]))
  return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`
}
/** Valeur locale (Casablanca) → ISO avec décalage, calculé pour cette date. */
const toIso = (local: string) => {
  const guess = new Date(local + 'Z')
  const offsetMin = Math.round((new Date(guess.toLocaleString('en-US', { timeZone: TZ })).getTime() - new Date(guess.toLocaleString('en-US', { timeZone: 'UTC' })).getTime()) / 60000)
  return new Date(guess.getTime() - offsetMin * 60000).toISOString()
}

const empty = () => {
  const start = new Date(Date.now() + 3600000)
  return { vehicle_id: 0, driver_id: 0, origin_geofence_id: 0, destination_geofence_id: 0, customer_id: null as number | null, planned_start_at: toLocal(start.toISOString()), planned_end_at: toLocal(new Date(start.getTime() + 2 * 3600000).toISOString()), cargo: '', notes: '' }
}
const form = reactive(empty())
const errors = ref<Record<string, string>>({})
const error = ref('')
const busy = ref(false)
const lists = reactive({ vehicles: [] as VehicleRow[], drivers: [] as DriverRow[], zones: [] as ZoneRow[], customers: [] as { id: number; name: string; code: string }[] })

watch(() => props.open, async (open) => {
  if (!open) return
  errors.value = {}
  error.value = ''
  const m = props.mission
  Object.assign(form, empty(), m ? {
    vehicle_id: m.vehicle?.id ?? 0, driver_id: m.driver?.id ?? 0, origin_geofence_id: m.origin?.id ?? 0, destination_geofence_id: m.destination?.id ?? 0,
    customer_id: m.customer?.id ?? null, planned_start_at: toLocal(m.planned_start_at), planned_end_at: toLocal(m.planned_end_at), cargo: m.cargo ?? '', notes: m.notes ?? '',
  } : {})
  if (!lists.vehicles.length) {
    const [v, d, z, c] = await Promise.all([
      http.get<Paginated<VehicleRow>>('/api/vehicles', { params: { per_page: 100, status: 'active' } }),
      http.get<Paginated<DriverRow>>('/api/drivers', { params: { per_page: 100 } }),
      http.get<{ data: ZoneRow[] }>('/api/geofences').catch(() => ({ data: { data: [] } })),
      http.get<{ data: typeof lists.customers }>('/api/customers').catch(() => ({ data: { data: [] } })),
    ])
    lists.vehicles = v.data.data
    lists.drivers = d.data.data.filter((x) => x.status !== 'inactive')
    lists.zones = z.data.data
    lists.customers = c.data.data
  }
  if (!m) form.origin_geofence_id = lists.zones.find((z) => z.type === 'depot')?.id ?? 0
})
// Choisir un camion propose son chauffeur affecté ; choisir une destination client propose le client.
watch(() => form.vehicle_id, (id) => { const v = lists.vehicles.find((x) => x.id === id); if (v?.driver && !props.mission) form.driver_id = v.driver.id })
watch(() => form.destination_geofence_id, (id) => { const z = lists.zones.find((x) => x.id === id); if (z?.customer) form.customer_id = z.customer.id })

async function submit() {
  busy.value = true
  errors.value = {}
  error.value = ''
  const payload = { ...form, planned_start_at: toIso(form.planned_start_at), planned_end_at: toIso(form.planned_end_at), customer_id: form.customer_id || null }
  try {
    const { data } = props.mission ? await http.put<{ data: MissionRow }>(`/api/missions/${props.mission.id}`, payload) : await http.post<{ data: MissionRow }>('/api/missions', payload)
    emit('saved', data.data)
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :open="open" :title="mission ? `Modifier ${mission.reference}` : 'Nouvelle mission'" subtitle="La référence MIS-AAAA-xxxxx est attribuée automatiquement." @close="emit('close')">
    <form id="mission-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
      <div v-if="error" class="rounded-[10px] border border-[#FECACA] bg-[#FEF2F2] px-3.5 py-2.5 text-sm text-[#991B1B] sm:col-span-2" role="alert">{{ error }}</div>
      <label class="field">Camion *<select v-model.number="form.vehicle_id" class="input"><option :value="0" disabled>Choisir…</option><option v-for="v in lists.vehicles" :key="v.id" :value="v.id">{{ v.name }} · {{ v.plate_number }}</option></select><span v-if="errors.vehicle_id" class="text-xs font-normal text-danger">{{ errors.vehicle_id }}</span></label>
      <label class="field">Chauffeur *<select v-model.number="form.driver_id" class="input"><option :value="0" disabled>Choisir…</option><option v-for="d in lists.drivers" :key="d.id" :value="d.id">{{ d.name }} ({{ d.employee_number }})</option></select><span v-if="errors.driver_id" class="text-xs font-normal text-danger">{{ errors.driver_id }}</span></label>
      <label class="field">Départ *<select v-model.number="form.origin_geofence_id" class="input"><option :value="0" disabled>Choisir…</option><option v-for="z in lists.zones" :key="z.id" :value="z.id">{{ z.name }}</option></select><span v-if="errors.origin_geofence_id" class="text-xs font-normal text-danger">{{ errors.origin_geofence_id }}</span></label>
      <label class="field">Destination *<select v-model.number="form.destination_geofence_id" class="input"><option :value="0" disabled>Choisir…</option><option v-for="z in lists.zones" :key="z.id" :value="z.id">{{ z.name }}</option></select><span v-if="errors.destination_geofence_id" class="text-xs font-normal text-danger">{{ errors.destination_geofence_id }}</span></label>
      <label class="field">Début prévu *<input v-model="form.planned_start_at" type="datetime-local" class="input" /><span v-if="errors.planned_start_at" class="text-xs font-normal text-danger">{{ errors.planned_start_at }}</span></label>
      <label class="field">Fin prévue *<input v-model="form.planned_end_at" type="datetime-local" class="input" /><span v-if="errors.planned_end_at" class="text-xs font-normal text-danger">{{ errors.planned_end_at }}</span></label>
      <label class="field">Client<select v-model="form.customer_id" class="input"><option :value="null">— Aucun —</option><option v-for="c in lists.customers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option></select></label>
      <label class="field">Chargement<input v-model="form.cargo" class="input" placeholder="Livraison 24 t de ciment" /></label>
      <label class="field sm:col-span-2">Notes<textarea v-model="form.notes" rows="2" class="input h-auto py-2" /></label>
    </form>
    <template #footer>
      <button type="button" class="btn btn-secondary" @click="emit('close')">Annuler</button>
      <button type="submit" form="mission-form" class="btn btn-primary" :disabled="busy"><Save :size="16" />{{ busy ? 'Enregistrement…' : 'Enregistrer' }}</button>
    </template>
  </Modal>
</template>
