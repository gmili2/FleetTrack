<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { Save } from 'lucide-vue-next'
import { errorMessage, fieldErrors, http } from '@/lib/http'
import { VEHICLE_STATUSES, VEHICLE_TYPES } from '@/lib/options'
import type { DriverRow, VehicleRow } from '@/lib/types'
import Modal from './Modal.vue'

/** Création / modification d'un camion (avec boîtier GPS et chauffeur affecté). */
const props = defineProps<{ open: boolean; vehicle: VehicleRow | null }>()
const emit = defineEmits<{ close: []; saved: [v: VehicleRow] }>()

const empty = () => ({
  plate_number: '', name: '', brand: '', model: '', type: 'tractor', year: new Date().getFullYear() as number | null, status: 'active',
  speed_limit_kmh: 90, odometer_km: 0 as number | null, current_driver_id: null as number | null, notes: '', imei: '', device_model: '', sim_number: '',
})
const form = reactive(empty())
const errors = ref<Record<string, string>>({})
const error = ref('')
const busy = ref(false)
const drivers = ref<DriverRow[]>([])

watch(() => props.open, async (open) => {
  if (!open) return
  errors.value = {}
  error.value = ''
  const v = props.vehicle
  Object.assign(form, empty(), v ? {
    plate_number: v.plate_number, name: v.name, brand: v.brand ?? '', model: v.model ?? '', type: v.type, year: v.year, status: v.status,
    speed_limit_kmh: v.speed_limit_kmh, odometer_km: v.odometer_km, current_driver_id: v.driver?.id ?? null, notes: v.notes ?? '',
    imei: v.device?.imei ?? '', device_model: v.device?.model ?? '', sim_number: v.device?.sim_number ?? '',
  } : {})
  if (!drivers.value.length) drivers.value = (await http.get<{ data: DriverRow[] }>('/api/drivers', { params: { per_page: 100 } })).data.data
})

async function submit() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    const payload = { ...form, imei: form.imei || null }
    const { data } = props.vehicle
      ? await http.put<{ data: VehicleRow }>(`/api/vehicles/${props.vehicle.id}`, payload)
      : await http.post<{ data: VehicleRow }>('/api/vehicles', payload)
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
  <Modal :open="open" :title="vehicle ? `Modifier ${vehicle.name}` : 'Ajouter un camion'" subtitle="Les champs marqués * sont obligatoires." @close="emit('close')">
    <form id="vehicle-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
      <div v-if="error" class="rounded-[10px] border border-[#FECACA] bg-[#FEF2F2] px-3.5 py-2.5 text-sm text-[#991B1B] sm:col-span-2" role="alert">{{ error }}</div>
      <label class="field">Immatriculation *<input v-model="form.plate_number" class="input" placeholder="48213-A-1" :aria-invalid="!!errors.plate_number" /><span v-if="errors.plate_number" class="text-xs font-normal text-danger">{{ errors.plate_number }}</span></label>
      <label class="field">Nom *<input v-model="form.name" class="input" placeholder="CAM-07" :aria-invalid="!!errors.name" /><span v-if="errors.name" class="text-xs font-normal text-danger">{{ errors.name }}</span></label>
      <label class="field">Type *<select v-model="form.type" class="input"><option v-for="t in VEHICLE_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option></select></label>
      <label class="field">Statut *<select v-model="form.status" class="input"><option v-for="t in VEHICLE_STATUSES" :key="t.value" :value="t.value">{{ t.label }}</option></select></label>
      <label class="field">Marque<input v-model="form.brand" class="input" placeholder="Volvo" /></label>
      <label class="field">Modèle<input v-model="form.model" class="input" placeholder="FH 460" /></label>
      <label class="field">Année<input v-model.number="form.year" type="number" class="input" /><span v-if="errors.year" class="text-xs font-normal text-danger">{{ errors.year }}</span></label>
      <label class="field">Vitesse maximale (km/h) *<input v-model.number="form.speed_limit_kmh" type="number" min="30" max="130" class="input" /><span v-if="errors.speed_limit_kmh" class="text-xs font-normal text-danger">{{ errors.speed_limit_kmh }}</span></label>
      <label class="field">Kilométrage<input v-model.number="form.odometer_km" type="number" min="0" class="input" /></label>
      <label class="field">Chauffeur affecté
        <select v-model="form.current_driver_id" class="input">
          <option :value="null">— Aucun —</option>
          <option v-for="d in drivers" :key="d.id" :value="d.id">{{ d.name }} ({{ d.employee_number }}){{ d.vehicle && d.vehicle.id !== vehicle?.id ? ` · actuellement sur ${d.vehicle.name}` : '' }}</option>
        </select>
      </label>
      <fieldset class="grid gap-4 rounded-xl border border-line p-4 sm:col-span-2 sm:grid-cols-3">
        <legend class="px-1 text-[13px] font-semibold">Boîtier GPS</legend>
        <label class="field">IMEI (15 chiffres)<input v-model="form.imei" inputmode="numeric" class="input" placeholder="861234567890123" /><span v-if="errors.imei" class="text-xs font-normal text-danger">{{ errors.imei }}</span></label>
        <label class="field">Modèle<input v-model="form.device_model" class="input" placeholder="Teltonika FMC130" /></label>
        <label class="field">N° de SIM<input v-model="form.sim_number" class="input" /></label>
      </fieldset>
      <label class="field sm:col-span-2">Notes<textarea v-model="form.notes" rows="2" class="input h-auto py-2" /></label>
    </form>
    <template #footer>
      <button type="button" class="btn btn-secondary" @click="emit('close')">Annuler</button>
      <button type="submit" form="vehicle-form" class="btn btn-primary" :disabled="busy"><Save :size="16" />{{ busy ? 'Enregistrement…' : 'Enregistrer' }}</button>
    </template>
  </Modal>
</template>
