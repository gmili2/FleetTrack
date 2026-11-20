<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import L from 'leaflet'

/**
 * Carte Leaflet (OpenStreetMap) réutilisable. Émet `ready` avec l'instance de carte ;
 * les écrans y ajoutent leurs couches (marqueurs, zones, tracés).
 */
const props = withDefaults(defineProps<{ center?: [number, number]; zoom?: number; basemap?: 'standard' | 'light' }>(), {
  center: () => [33.585, -7.58],
  zoom: 12,
  basemap: 'standard',
})
const emit = defineEmits<{ ready: [map: L.Map] }>()

const el = ref<HTMLDivElement | null>(null)
const map = shallowRef<L.Map | null>(null)
let tiles: L.TileLayer | null = null

const LAYERS = {
  standard: ['https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'],
  light: ['https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> · © CARTO'],
} as const

function setBasemap(kind: 'standard' | 'light') {
  if (!map.value) return
  tiles?.remove()
  const [url, attribution] = LAYERS[kind]
  tiles = L.tileLayer(url, { attribution, maxZoom: 19, subdomains: 'abc' }).addTo(map.value)
}

let resize: ResizeObserver | null = null
onMounted(() => {
  map.value = L.map(el.value!, { zoomControl: false, attributionControl: true }).setView(props.center, props.zoom)
  L.control.zoom({ position: 'bottomright', zoomInTitle: 'Zoomer', zoomOutTitle: 'Dézoomer' }).addTo(map.value)
  map.value.attributionControl.setPrefix(false)
  setBasemap(props.basemap)
  resize = new ResizeObserver(() => map.value?.invalidateSize())
  resize.observe(el.value!)
  emit('ready', map.value)
})
watch(() => props.basemap, setBasemap)
onBeforeUnmount(() => { resize?.disconnect(); map.value?.remove() })

defineExpose({ map })
</script>

<template>
  <div ref="el" class="h-full w-full bg-[#EAEFF3]" role="region" aria-label="Carte" />
</template>
