<script setup lang="ts">
import { computed, onMounted, reactive, ref, shallowRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import L from 'leaflet'
import '@geoman-io/leaflet-geoman-free'
import '@geoman-io/leaflet-geoman-free/dist/leaflet-geoman.css'
import { ArrowRight, Circle, Hexagon, Pentagon, Plus, Save, Search, Trash2, X } from 'lucide-vue-next'
import { errorMessage, fieldErrors, http } from '@/lib/http'
import { ZONE_COLORS, ZONE_TYPES } from '@/lib/options'
import { toast } from '@/lib/toast'
import type { ZoneRow } from '@/lib/types'
import { useAuthStore } from '@/stores/auth'
import MapView from '@/components/map/MapView.vue'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

type Layer = L.Circle | L.Polygon
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const zones = ref<ZoneRow[]>([])
const counts = ref<Record<string, number>>({})
const visitsToday = ref(0)
const loading = ref(true)
const error = ref('')
const customers = ref<{ id: number; code: string; name: string }[]>([])

async function load() {
  loading.value = true
  try {
    const { data } = await http.get<{ data: ZoneRow[]; meta: { counts: Record<string, number>; visits_today: number } }>('/api/geofences')
    zones.value = data.data
    counts.value = data.meta.counts
    visitsToday.value = data.meta.visits_today
    error.value = ''
    drawAll()
  } catch (e) {
    error.value = errorMessage(e, 'Impossible de charger les zones.')
  } finally {
    loading.value = false
  }
}

// ── Liste ──────────────────────────────────────────────────────────────────
const q = ref('')
const typeFilter = ref('')
const rows = computed(() => zones.value.filter((z) => (!typeFilter.value || z.type === typeFilter.value) && (!q.value || z.name.toLowerCase().includes(q.value.toLowerCase()))))
const shapeText = (z: ZoneRow) => (z.shape === 'circle' ? `Cercle ${z.radius_m} m` : 'Polygone')

// ── Formulaire (zone sélectionnée ou brouillon) ────────────────────────────
const editable = computed(() => (form.id ? auth.can('geofences.update') : auth.can('geofences.create')))
const blank = () => ({
  id: 0, name: '', type: 'customer', shape: 'circle' as 'circle' | 'polygon', center_lat: null as number | null, center_lng: null as number | null,
  radius_m: 250 as number | null, coordinates: null as [number, number][] | null, color: '#15803D', customer_id: null as number | null,
  min_minutes: 2, address: '', is_active: true,
})
const form = reactive(blank())
const open = ref(false)
const errors = ref<Record<string, string>>({})
const formError = ref('')
const busy = ref(false)

function select(z: ZoneRow, fly = true) {
  cancelDraw()
  Object.assign(form, {
    id: z.id, name: z.name, type: z.type, shape: z.shape, center_lat: z.center_lat, center_lng: z.center_lng, radius_m: z.radius_m,
    coordinates: z.coordinates, color: z.color, customer_id: z.customer?.id ?? null, min_minutes: Math.round(z.min_visit_seconds / 60), address: z.address ?? '', is_active: z.is_active,
  })
  open.value = true
  errors.value = {}
  formError.value = ''
  router.replace({ query: { z: String(z.id) } })
  highlight()
  const layer = layerOf.get(z.id)
  if (fly && layer && map.value) map.value.flyToBounds(layer.getBounds().pad(0.6), { maxZoom: 16, duration: 0.6 })
}
function close() {
  cancelDraw()
  open.value = false
  form.id = 0
  router.replace({ query: {} })
  highlight()
}

async function save() {
  busy.value = true
  errors.value = {}
  formError.value = ''
  const payload = {
    name: form.name, type: form.type, shape: form.shape, color: form.color, customer_id: form.customer_id, address: form.address || null, is_active: form.is_active,
    min_visit_seconds: Math.round(Number(form.min_minutes) * 60),
    ...(form.shape === 'circle' ? { center_lat: form.center_lat, center_lng: form.center_lng, radius_m: form.radius_m } : { coordinates: form.coordinates }),
  }
  try {
    const { data } = form.id ? await http.put<{ data: ZoneRow }>(`/api/geofences/${form.id}`, payload) : await http.post<{ data: ZoneRow }>('/api/geofences', payload)
    toast(form.id ? `Zone « ${data.data.name} » enregistrée.` : `Zone « ${data.data.name} » créée.`)
    draftLayer?.remove()
    draftLayer = null
    await load()
    select(data.data, false)
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) formError.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

const askDelete = ref(false)
const deleting = ref(false)
async function remove() {
  deleting.value = true
  try {
    await http.delete(`/api/geofences/${form.id}`)
    toast(`Zone « ${form.name} » supprimée.`)
    askDelete.value = false
    close()
    load()
  } catch (e) { toast(errorMessage(e), 'error') } finally { deleting.value = false }
}

// ── Carte & dessin (Leaflet-Geoman) ────────────────────────────────────────
const map = shallowRef<L.Map | null>(null)
const group = L.featureGroup()
const layerOf = new Map<number, Layer>()
let draftLayer: Layer | null = null
const drawing = ref<'' | 'Circle' | 'Polygon'>('')

function styleFor(color: string, selected: boolean): L.PathOptions {
  return { color, weight: selected ? 3 : 2, dashArray: selected ? undefined : '6 5', fillColor: color, fillOpacity: selected ? 0.18 : 0.08 }
}
function drawAll() {
  if (!map.value) return
  group.clearLayers()
  layerOf.clear()
  for (const z of zones.value) {
    const layer: Layer = z.shape === 'circle' ? L.circle(z.center, { radius: z.radius_m ?? 100 }) : L.polygon(z.coordinates ?? [])
    layer.setStyle(styleFor(z.color, false))
    layer.bindTooltip(z.name, { permanent: true, direction: 'center', className: 'ft-zone-label' })
    // Pendant un dessin, un clic sur une zone existante sert à placer le point, pas à la sélectionner.
    layer.on('click', () => { if (!drawing.value && !draftLayer) select(z, false) })
    layer.on('pm:edit', () => syncFromLayer(layer))
    group.addLayer(layer)
    layerOf.set(z.id, layer)
  }
  highlight()
}
function highlight() {
  for (const [id, layer] of layerOf) {
    const z = zones.value.find((x) => x.id === id)!
    const sel = id === form.id && open.value
    layer.setStyle(styleFor(sel ? form.color : z.color, sel))
    if (sel && editable.value) layer.pm.enable({ allowSelfIntersection: false })
    else layer.pm.disable()
  }
}
/** Géométrie modifiée sur la carte → formulaire. */
function syncFromLayer(layer: Layer) {
  if (layer instanceof L.Circle) {
    const c = layer.getLatLng()
    form.center_lat = +c.lat.toFixed(6)
    form.center_lng = +c.lng.toFixed(6)
    form.radius_m = Math.round(layer.getRadius())
  } else {
    const ring = (layer.getLatLngs()[0] as L.LatLng[])
    form.coordinates = ring.map((p) => [+p.lat.toFixed(6), +p.lng.toFixed(6)])
  }
}
/** Rayon saisi dans le formulaire → cercle sur la carte. */
function onRadius() {
  const layer = form.id ? layerOf.get(form.id) : draftLayer
  if (layer instanceof L.Circle && form.radius_m) layer.setRadius(Number(form.radius_m))
}
function onColor(c: string) {
  form.color = c
  const layer = form.id ? layerOf.get(form.id) : draftLayer
  layer?.setStyle(styleFor(c, true))
}
function onType() {
  const t = ZONE_TYPES.find((x) => x.value === form.type)
  if (t && !form.id) onColor(t.color)
}

function startDraw(shape: 'Circle' | 'Polygon') {
  if (!map.value) return
  close()
  Object.assign(form, blank(), { shape: shape === 'Circle' ? 'circle' : 'polygon' })
  drawing.value = shape
  map.value.pm.enableDraw(shape, { templineStyle: { color: form.color }, hintlineStyle: { color: form.color, dashArray: '5 5' }, pathOptions: styleFor(form.color, true) } as never)
}
function cancelDraw() {
  if (drawing.value) map.value?.pm.disableDraw()
  drawing.value = ''
  draftLayer?.remove()
  draftLayer = null
}
function onMap(m: L.Map) {
  map.value = m
  group.addTo(m)
  m.pm.setLang('fr')
  m.on('pm:create', (e) => {
    drawing.value = ''
    draftLayer = e.layer as Layer
    draftLayer.setStyle(styleFor(form.color, true))
    draftLayer.on('pm:edit', () => syncFromLayer(draftLayer!))
    draftLayer.pm.enable({ allowSelfIntersection: false })
    syncFromLayer(draftLayer)
    open.value = true
  })
  drawAll()
  requestAnimationFrame(() => {
    m.invalidateSize()
    if (group.getLayers().length) m.fitBounds(group.getBounds().pad(0.05))
    const id = Number(route.query.z)
    const z = zones.value.find((x) => x.id === id)
    if (z) select(z)
  })
}

onMounted(async () => {
  await load()
  if (map.value && !route.query.z && group.getLayers().length) map.value.fitBounds(group.getBounds().pad(0.05))
  const z = zones.value.find((x) => x.id === Number(route.query.z))
  if (z) select(z)
  if (auth.can('geofences.create') || auth.can('geofences.update')) customers.value = (await http.get<{ data: typeof customers.value }>('/api/customers')).data.data
})
</script>

<template>
  <div class="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[300px_minmax(0,1fr)] xl:h-[calc(100dvh-77px)] xl:flex-none xl:grid-cols-[300px_minmax(0,1fr)_360px] xl:grid-rows-[minmax(0,1fr)]">
    <!-- Liste des zones -->
    <aside class="flex min-h-0 flex-col gap-3 border-line bg-white p-4 lg:border-r" aria-label="Liste des zones">
      <div class="flex items-center justify-between">
        <div class="text-[13px] text-muted">{{ counts.all ?? 0 }} zones · {{ visitsToday }} visites aujourd’hui</div>
      </div>
      <label class="flex h-10 items-center gap-2 rounded-[10px] border border-line bg-page px-3 text-muted">
        <Search :size="17" /><span class="sr-only">Rechercher une zone</span>
        <input v-model="q" type="search" placeholder="Rechercher une zone" class="min-w-0 flex-1 border-0 bg-transparent text-sm text-ink outline-none" />
      </label>
      <div class="flex flex-wrap gap-1.5" role="group" aria-label="Filtrer par type">
        <button type="button" class="rounded-full border px-3 py-1 text-xs font-semibold" :class="!typeFilter ? 'border-brand bg-brand text-white' : 'border-line hover:bg-page'" :aria-pressed="!typeFilter" @click="typeFilter = ''">Toutes {{ counts.all ?? 0 }}</button>
        <button v-for="t in ZONE_TYPES" :key="t.value" type="button" class="rounded-full border px-3 py-1 text-xs font-semibold" :aria-pressed="typeFilter === t.value"
          :class="typeFilter === t.value ? 'border-brand bg-brand text-white' : 'border-line hover:bg-page'" @click="typeFilter = t.value">{{ t.plural }} {{ counts[t.value] ?? 0 }}</button>
      </div>
      <ErrorState v-if="error" :message="error" @retry="load" />
      <LoadingState v-else-if="loading && !zones.length" />
      <EmptyState v-else-if="!rows.length" :icon="Hexagon" title="Aucune zone" message="Aucune zone ne correspond." />
      <ul v-else class="-mr-2 m-0 flex min-h-0 max-h-[40vh] list-none flex-col gap-2 overflow-y-auto p-0 pr-2 lg:max-h-[60vh] xl:max-h-none xl:flex-1">
        <li v-for="z in rows" :key="z.id">
          <button type="button" class="flex w-full items-center gap-3 rounded-xl border p-3 text-left" :aria-pressed="form.id === z.id && open"
            :class="form.id === z.id && open ? 'border-brand bg-[#EFF6FF]' : 'border-line hover:bg-page'" @click="select(z)">
            <span class="size-3 flex-none rounded-full" :style="{ background: z.color }" aria-hidden="true" />
            <span class="min-w-0 flex-1">
              <b class="block truncate text-sm">{{ z.name }}</b>
              <span class="text-xs text-muted">{{ z.type_label }} · {{ shapeText(z) }}<template v-if="!z.is_active"> · désactivée</template></span>
            </span>
            <span class="flex-none text-right"><b class="num block text-sm">{{ z.visits_today ?? 0 }}</b><span class="text-[11px] text-muted">visites auj.</span></span>
          </button>
        </li>
      </ul>
    </aside>

    <!-- Carte -->
    <section class="relative h-[60vh] min-h-[380px] xl:h-auto" aria-label="Carte des zones">
      <MapView @ready="onMap" />
      <div v-if="auth.can('geofences.create')" class="absolute top-3 left-3 z-[500] flex flex-wrap items-center gap-1.5 rounded-xl bg-white p-1.5 text-[13px] font-semibold shadow">
        <span class="px-1.5 text-muted"><Plus :size="15" class="inline align-[-3px]" /> Nouvelle zone :</span>
        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5" :class="drawing === 'Circle' ? 'bg-brand text-white' : 'hover:bg-page'" @click="startDraw('Circle')"><Circle :size="15" />Cercle</button>
        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5" :class="drawing === 'Polygon' ? 'bg-brand text-white' : 'hover:bg-page'" @click="startDraw('Polygon')"><Pentagon :size="15" />Polygone</button>
        <button v-if="drawing" type="button" class="rounded-lg px-2.5 py-1.5 text-muted hover:bg-page" @click="cancelDraw">Annuler</button>
      </div>
      <div v-if="drawing" class="absolute inset-x-3 bottom-6 z-[500] mx-auto max-w-md rounded-xl bg-ink/90 px-4 py-2.5 text-center text-[13px] text-white" role="status">
        {{ drawing === 'Circle' ? 'Cliquez le centre puis faites glisser pour régler le rayon.' : 'Cliquez chaque sommet, puis cliquez le premier point pour fermer le polygone.' }}
      </div>
      <div v-else-if="open && editable" class="absolute inset-x-3 bottom-6 z-[500] mx-auto max-w-md rounded-xl bg-white/95 px-4 py-2 text-center text-xs text-muted shadow">
        Faites glisser {{ form.shape === 'circle' ? 'le centre ou le bord du cercle' : 'les sommets du polygone' }} pour ajuster la zone.
      </div>
    </section>

    <!-- Formulaire -->
    <aside class="flex min-h-0 flex-col overflow-y-auto border-line bg-white lg:col-span-2 lg:border-t xl:col-span-1 xl:border-t-0 xl:border-l" aria-label="Zone sélectionnée">
      <EmptyState v-if="!open" :icon="Hexagon" title="Aucune zone sélectionnée" :message="auth.can('geofences.create') ? 'Choisissez une zone dans la liste ou dessinez-en une nouvelle (cercle ou polygone).' : 'Choisissez une zone dans la liste ou sur la carte.'" />
      <form v-else class="flex flex-col gap-4 p-5" novalidate @submit.prevent="save">
        <div class="flex items-center justify-between gap-2">
          <h2 class="m-0 text-base font-bold">{{ form.id ? 'Zone sélectionnée' : 'Nouvelle zone' }}</h2>
          <button type="button" class="btn-icon" aria-label="Fermer" @click="close(); cancelDraw()"><X :size="16" /></button>
        </div>
        <div v-if="formError" class="rounded-[10px] border border-[#FECACA] bg-[#FEF2F2] px-3 py-2 text-sm text-[#991B1B]" role="alert">{{ formError }}</div>
        <fieldset :disabled="!editable" class="m-0 flex flex-col gap-4 border-0 p-0">
          <label class="field">Nom<input v-model="form.name" class="input" placeholder="Client — Atlas Béton" /><span v-if="errors.name" class="text-xs font-normal text-danger">{{ errors.name }}</span></label>
          <label class="field">Type de zone<select v-model="form.type" class="input" @change="onType"><option v-for="t in ZONE_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option></select></label>
          <label class="field">Client associé
            <select v-model="form.customer_id" class="input"><option :value="null">— Aucun —</option><option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option></select>
            <span v-if="errors.customer_id" class="text-xs font-normal text-danger">{{ errors.customer_id }}</span>
          </label>
          <div class="grid grid-cols-2 gap-3">
            <label v-if="form.shape === 'circle'" class="field">Rayon (m)<input v-model.number="form.radius_m" type="number" min="20" max="5000" step="10" class="input" @input="onRadius" /><span v-if="errors.radius_m" class="text-xs font-normal text-danger">{{ errors.radius_m }}</span></label>
            <div v-else class="field">Forme<span class="flex h-10 items-center text-sm font-normal">Polygone · {{ form.coordinates?.length ?? 0 }} points</span></div>
            <label class="field">Durée min. (min)<input v-model.number="form.min_minutes" type="number" min="0" max="120" class="input" /><span v-if="errors.min_visit_seconds" class="text-xs font-normal text-danger">{{ errors.min_visit_seconds }}</span></label>
          </div>
          <span v-if="errors.coordinates || errors.center_lat" class="-mt-2 text-xs text-danger">{{ errors.coordinates ?? errors.center_lat }}</span>
          <div class="field">Couleur
            <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Couleur">
              <button v-for="c in ZONE_COLORS" :key="c" type="button" role="radio" :aria-checked="form.color === c" :aria-label="c" class="size-8 rounded-full"
                :style="{ background: c, boxShadow: form.color === c ? '0 0 0 2px #fff, 0 0 0 4px ' + c : 'none' }" @click="onColor(c)" />
            </div>
          </div>
          <label class="field">Adresse<input v-model="form.address" class="input" placeholder="Zone industrielle…" /></label>
          <label class="inline-flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="size-[18px] accent-brand" />Détection active pour tous les camions</label>
        </fieldset>
        <p class="m-0 text-xs text-muted">Une visite n’est comptée que si le camion reste au moins {{ form.min_minutes }} min dans la zone (anti-rebond).</p>
        <div v-if="editable" class="flex flex-wrap gap-2">
          <button type="button" class="btn btn-secondary" @click="form.id ? select(zones.find((z) => z.id === form.id)!, false) : (close(), cancelDraw())">Annuler</button>
          <button type="submit" class="btn btn-primary flex-1" :disabled="busy"><Save :size="16" />{{ busy ? 'Enregistrement…' : 'Enregistrer' }}</button>
          <button v-if="form.id && auth.can('geofences.delete')" type="button" class="btn-icon size-10 hover:text-danger" aria-label="Supprimer la zone" title="Supprimer" @click="askDelete = true"><Trash2 :size="16" /></button>
        </div>
        <RouterLink v-if="form.id" :to="`/zones/${form.id}/visites`" class="inline-flex items-center gap-1 text-sm font-semibold">Voir les visites de cette zone<ArrowRight :size="15" /></RouterLink>
      </form>
    </aside>
  </div>
  <ConfirmDialog :open="askDelete" danger :title="`Supprimer « ${form.name} » ?`" message="La détection s’arrête pour cette zone ; l’historique des visites est conservé." confirm-label="Supprimer" :busy="deleting" @confirm="remove" @cancel="askDelete = false" />
</template>
