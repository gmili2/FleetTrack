<script setup lang="ts">
import { computed, type Component } from 'vue'
import { CircleSlash, Navigation, Square, WifiOff } from 'lucide-vue-next'

/**
 * Badge de statut : toujours une icône + un libellé (jamais la couleur seule).
 * Raccourci `status` pour les camions, ou `tone` + `label` (+ `icon`) pour le reste.
 */
type Tone = 'green' | 'orange' | 'gray' | 'blue' | 'red'
const props = defineProps<{
  status?: 'moving' | 'stopped' | 'offline'
  tone?: Tone
  label?: string
  icon?: Component
}>()

const VEHICLE: Record<string, { tone: Tone; label: string; icon: Component }> = {
  moving: { tone: 'green', label: 'En mouvement', icon: Navigation },
  stopped: { tone: 'orange', label: 'À l’arrêt', icon: Square },
  offline: { tone: 'gray', label: 'Hors ligne', icon: WifiOff },
}

const TONES: Record<Tone, string> = {
  green: 'bg-[#DCFCE7] text-[#166534]',
  orange: 'bg-[#FFEDD5] text-[#9A3412]',
  gray: 'bg-[#E2E8F0] text-[#334155]',
  blue: 'bg-[#DBEAFE] text-[#1E40AF]',
  red: 'bg-[#FEE2E2] text-[#991B1B]',
}

const view = computed(() => {
  const v = props.status ? VEHICLE[props.status] : undefined
  return {
    tone: props.tone ?? v?.tone ?? 'gray',
    label: props.label ?? v?.label ?? '—',
    icon: props.icon ?? v?.icon ?? CircleSlash,
  }
})
</script>

<template>
  <span class="inline-flex h-6 items-center gap-1.5 rounded-xl px-2.5 text-xs font-semibold whitespace-nowrap" :class="TONES[view.tone]">
    <component :is="view.icon" :size="13" :stroke-width="2.2" aria-hidden="true" />
    {{ view.label }}
  </span>
</template>
