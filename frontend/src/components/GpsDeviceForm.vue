<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { Save } from 'lucide-vue-next'
import { errorMessage, fieldErrors, http } from '@/lib/http'
import type { GpsConfig, GpsDeviceRow, Paginated, VehicleRow } from '@/lib/types'
import Modal from './Modal.vue'

const props = defineProps<{
  open: boolean; device: GpsDeviceRow | null
  protocols: Record<string, string>; operators: Record<string, string>; defaults: GpsConfig | null
}>()
const emit = defineEmits<{ close: []; saved: [d: GpsDeviceRow] }>()

const FALLBACK: GpsConfig = {
  protocol: 'teltonika', server_host: 'gps.fleettrack.ma', server_port: 5027, apn: null, interval_moving_s: 10, interval_stopped_s: 300,
  angle_change_deg: 30, distance_m: 200, overspeed_kmh: null, ignition_detection: true, harsh_driving: false,
}
const empty = () => ({ label: '', imei: '', model: '', sim_number: '', operator: '', vehicle_id: '' as number | '', is_active: true, notes: '' })
const form = reactive(empty())
const config = reactive<GpsConfig>({ ...FALLBACK })
const tab = ref<'card' | 'config'>('card')
const vehicles = ref<VehicleRow[]>([])
const errors = ref<Record<string, string>>({})
const error = ref('')
const busy = ref(false)

watch(() => props.open, async (open) => {
  if (!open) return
  errors.value = {}
  error.value = ''
  tab.value = 'card'
  const d = props.device
  Object.assign(form, empty(), d ? {
    label: d.label ?? '', imei: d.imei, model: d.model ?? '', sim_number: d.sim_number ?? '', operator: d.operator ?? '',
    vehicle_id: d.vehicle?.id ?? '', is_active: d.is_active, notes: d.notes ?? '',
  } : {})
  Object.assign(config, FALLBACK, props.defaults ?? {}, d?.config ?? {})
  if (!vehicles.value.length) {
    try { vehicles.value = (await http.get<Paginated<VehicleRow>>('/api/vehicles', { params: { per_page: 100 } })).data.data } catch { /* liste facultative */ }
  }
})

async function submit() {
  busy.value = true
  errors.value = {}
  error.value = ''
  const payload = {
    ...Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v])),
    config: { ...config, apn: config.apn || null, overspeed_kmh: config.overspeed_kmh || null },
  }
  try {
    const { data } = props.device
      ? await http.put<{ data: GpsDeviceRow }>(`/api/gps-devices/${props.device.id}`, payload)
      : await http.post<{ data: GpsDeviceRow }>('/api/gps-devices', payload)
    emit('saved', data.data)
  } catch (e) {
    errors.value = fieldErrors(e)
    if (Object.keys(errors.value).some((k) => k.startsWith('config.'))) tab.value = 'config'
    else if (Object.keys(errors.value).length) tab.value = 'card'
    else error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

const NUM = [
  ['interval_moving_s', 'Envoi en mouvement', 's', 'Une position toutes les N secondes quand le camion roule'],
  ['interval_stopped_s', 'Envoi à l’arrêt', 's', 'Cadence quand le moteur est coupé'],
  ['angle_change_deg', 'Changement de cap', '°', 'Position supplémentaire dans les virages (0 = désactivé)'],
  ['distance_m', 'Distance parcourue', 'm', 'Position supplémentaire tous les N mètres (0 = désactivé)'],
] as const
</script>

<template>
  <Modal :open="open" :title="device ? `Modifier ${device.name}` : 'Ajouter une carte GPS'" subtitle="Une carte est installée sur au plus un camion. Le chauffeur la choisit au pointage." @close="emit('close')">
    <div class="mb-4 flex gap-2" role="tablist">
      <button v-for="[k, l] in [['card', 'Carte et SIM'], ['config', 'Configuration']] as const" :key="k" type="button" role="tab" :aria-selected="tab === k"
        class="inline-flex min-h-9 items-center rounded-full border px-4 text-[13px] font-semibold"
        :class="tab === k ? 'border-brand bg-brand text-white' : 'border-line bg-white text-ink hover:bg-page'" @click="tab = k">
        {{ l }}<span v-if="k === 'config' && Object.keys(errors).some((e) => e.startsWith('config.'))" class="ml-1.5 size-2 rounded-full bg-danger" />
      </button>
    </div>

    <form id="device-form" novalidate @submit.prevent="submit">
      <div v-if="error" class="mb-4 rounded-[10px] border border-[#FECACA] bg-[#FEF2F2] px-3.5 py-2.5 text-sm text-[#991B1B]" role="alert">{{ error }}</div>

      <div v-show="tab === 'card'" class="grid gap-4 sm:grid-cols-2">
        <label class="field">Nom de la carte<input v-model="form.label" class="input" placeholder="GPS-16" :aria-invalid="!!errors.label" />
          <span v-if="errors.label" class="text-xs font-normal text-danger">{{ errors.label }}</span></label>
        <label class="field">IMEI * (15 chiffres)<input v-model="form.imei" class="input font-mono" inputmode="numeric" placeholder="860000000000000" :aria-invalid="!!errors.imei" />
          <span v-if="errors.imei" class="text-xs font-normal text-danger">{{ errors.imei }}</span></label>
        <label class="field">Modèle du boîtier<input v-model="form.model" class="input" placeholder="Teltonika FMB920" /></label>
        <label class="field">Numéro de SIM<input v-model="form.sim_number" type="tel" class="input" placeholder="+212 7 …" /></label>
        <label class="field">Opérateur<select v-model="form.operator" class="input"><option value="">—</option><option v-for="(l, k) in operators" :key="k" :value="k">{{ l }}</option></select></label>
        <label class="field">Installée sur<select v-model="form.vehicle_id" class="input" :aria-invalid="!!errors.vehicle_id">
          <option value="">Aucun camion (carte libre)</option>
          <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.name }} · {{ v.plate_number }}{{ v.device && v.device.id !== device?.id ? ` (remplace ${v.device.imei.slice(-6)})` : '' }}</option>
        </select><span v-if="errors.vehicle_id" class="text-xs font-normal text-danger">{{ errors.vehicle_id }}</span></label>
        <label class="field flex-row items-center gap-2.5 sm:col-span-2"><input v-model="form.is_active" type="checkbox" class="size-4" />Carte active (sinon elle n’est plus proposée au pointage)</label>
        <label class="field sm:col-span-2">Notes<textarea v-model="form.notes" rows="2" class="input h-auto py-2" /></label>
      </div>

      <div v-show="tab === 'config'" class="grid gap-4 sm:grid-cols-2">
        <label class="field">Protocole *<select v-model="config.protocol" class="input"><option v-for="(l, k) in protocols" :key="k" :value="k">{{ l }}</option></select>
          <span v-if="errors['config.protocol']" class="text-xs font-normal text-danger">{{ errors['config.protocol'] }}</span></label>
        <label class="field">APN de la SIM<input v-model="config.apn" class="input" placeholder="iam" /></label>
        <label class="field">Serveur *<input v-model="config.server_host" class="input" placeholder="gps.fleettrack.ma" :aria-invalid="!!errors['config.server_host']" />
          <span v-if="errors['config.server_host']" class="text-xs font-normal text-danger">{{ errors['config.server_host'] }}</span></label>
        <label class="field">Port *<input v-model.number="config.server_port" type="number" min="1" max="65535" class="input" :aria-invalid="!!errors['config.server_port']" />
          <span v-if="errors['config.server_port']" class="text-xs font-normal text-danger">{{ errors['config.server_port'] }}</span></label>
        <label v-for="[k, l, unit, hint] in NUM" :key="k" class="field">{{ l }} ({{ unit }}) *
          <input v-model.number="config[k]" type="number" min="0" class="input" :aria-invalid="!!errors[`config.${k}`]" />
          <span v-if="errors[`config.${k}`]" class="text-xs font-normal text-danger">{{ errors[`config.${k}`] }}</span>
          <span v-else class="text-xs font-normal text-muted">{{ hint }}</span>
        </label>
        <label class="field">Seuil de survitesse (km/h)<input v-model.number="config.overspeed_kmh" type="number" min="30" max="150" class="input" placeholder="Vitesse max du camion" />
          <span v-if="errors['config.overspeed_kmh']" class="text-xs font-normal text-danger">{{ errors['config.overspeed_kmh'] }}</span>
          <span v-else class="text-xs font-normal text-muted">Vide = vitesse maximale du camion</span></label>
        <div class="flex flex-col justify-center gap-2.5 text-sm font-semibold">
          <label class="flex items-center gap-2.5"><input v-model="config.ignition_detection" type="checkbox" class="size-4" />Détection du contact moteur</label>
          <label class="flex items-center gap-2.5"><input v-model="config.harsh_driving" type="checkbox" class="size-4" />Alertes de conduite brusque</label>
        </div>
      </div>
    </form>
    <template #footer>
      <button type="button" class="btn btn-secondary" @click="emit('close')">Annuler</button>
      <button type="submit" form="device-form" class="btn btn-primary" :disabled="busy"><Save :size="16" />{{ busy ? 'Enregistrement…' : 'Enregistrer' }}</button>
    </template>
  </Modal>
</template>
