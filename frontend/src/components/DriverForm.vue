<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { Save } from 'lucide-vue-next'
import { errorMessage, fieldErrors, http } from '@/lib/http'
import { DRIVER_STATUSES, LICENSE_CATEGORIES } from '@/lib/options'
import type { DriverRow } from '@/lib/types'
import Modal from './Modal.vue'

const props = defineProps<{ open: boolean; driver: DriverRow | null }>()
const emit = defineEmits<{ close: []; saved: [d: DriverRow] }>()

const empty = () => ({
  employee_number: '', badge_number: '', first_name: '', last_name: '', phone: '', license_number: '', license_category: 'C',
  license_expires_at: '', status: 'available', hired_at: '', notes: '',
})
const form = reactive(empty())
const errors = ref<Record<string, string>>({})
const error = ref('')
const busy = ref(false)

watch(() => props.open, (open) => {
  if (!open) return
  errors.value = {}
  error.value = ''
  const d = props.driver
  Object.assign(form, empty(), d ? {
    employee_number: d.employee_number, badge_number: d.badge_number, first_name: d.first_name, last_name: d.last_name, phone: d.phone ?? '',
    license_number: d.license_number ?? '', license_category: d.license_category ?? 'C', license_expires_at: d.license_expires_at ?? '',
    status: d.status, hired_at: d.hired_at ?? '', notes: d.notes ?? '',
  } : {})
})

async function submit() {
  busy.value = true
  errors.value = {}
  error.value = ''
  const payload = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))
  try {
    const { data } = props.driver
      ? await http.put<{ data: DriverRow }>(`/api/drivers/${props.driver.id}`, payload)
      : await http.post<{ data: DriverRow }>('/api/drivers', payload)
    emit('saved', data.data)
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
const F = [
  ['first_name', 'Prénom *', 'text', 'Youssef'], ['last_name', 'Nom *', 'text', 'Amrani'], ['employee_number', 'Matricule *', 'text', 'CH-1012'],
  ['badge_number', 'N° de badge *', 'text', 'B-4410'], ['phone', 'Téléphone', 'tel', '+212 6 …'], ['license_number', 'N° de permis', 'text', 'B-284113'],
] as const
</script>

<template>
  <Modal :open="open" :title="driver ? `Modifier ${driver.name}` : 'Ajouter un chauffeur'" subtitle="Le badge est celui que le chauffeur passe sur le lecteur du camion." @close="emit('close')">
    <form id="driver-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
      <div v-if="error" class="rounded-[10px] border border-[#FECACA] bg-[#FEF2F2] px-3.5 py-2.5 text-sm text-[#991B1B] sm:col-span-2" role="alert">{{ error }}</div>
      <label v-for="[key, label, type, ph] in F" :key="key" class="field">{{ label }}
        <input v-model="form[key]" :type="type" class="input" :placeholder="ph" :aria-invalid="!!errors[key]" />
        <span v-if="errors[key]" class="text-xs font-normal text-danger">{{ errors[key] }}</span>
      </label>
      <label class="field">Catégorie de permis<select v-model="form.license_category" class="input"><option v-for="c in LICENSE_CATEGORIES" :key="c">{{ c }}</option></select></label>
      <label class="field">Expiration du permis<input v-model="form.license_expires_at" type="date" class="input" /><span v-if="errors.license_expires_at" class="text-xs font-normal text-danger">{{ errors.license_expires_at }}</span></label>
      <label class="field">Statut *<select v-model="form.status" class="input"><option v-for="s in DRIVER_STATUSES" :key="s.value" :value="s.value">{{ s.label }}</option></select></label>
      <label class="field">Date d’embauche<input v-model="form.hired_at" type="date" class="input" /><span v-if="errors.hired_at" class="text-xs font-normal text-danger">{{ errors.hired_at }}</span></label>
      <label class="field sm:col-span-2">Notes<textarea v-model="form.notes" rows="2" class="input h-auto py-2" /></label>
    </form>
    <template #footer>
      <button type="button" class="btn btn-secondary" @click="emit('close')">Annuler</button>
      <button type="submit" form="driver-form" class="btn btn-primary" :disabled="busy"><Save :size="16" />{{ busy ? 'Enregistrement…' : 'Enregistrer' }}</button>
    </template>
  </Modal>
</template>
