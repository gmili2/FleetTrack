<script setup lang="ts">
import type { Component } from 'vue'

/** Carte KPI du tableau de bord (icône colorée, libellé, valeur, précision). */
type Tone = 'blue' | 'green' | 'orange' | 'gray' | 'red'
withDefaults(defineProps<{ label: string; value?: string | number | null; hint?: string; icon: Component; tone?: Tone; loading?: boolean }>(), { tone: 'blue' })

const TONES: Record<Tone, string> = {
  blue: 'bg-[#DBEAFE] text-brand',
  green: 'bg-[#DCFCE7] text-moving',
  orange: 'bg-[#FFEDD5] text-stopped',
  gray: 'bg-[#E2E8F0] text-offline',
  red: 'bg-[#FEE2E2] text-danger',
}
</script>

<template>
  <div class="card flex min-w-0 items-start gap-3.5 p-4">
    <div class="flex size-10 flex-none items-center justify-center rounded-[10px]" :class="TONES[tone]">
      <component :is="icon" :size="20" :stroke-width="1.8" aria-hidden="true" />
    </div>
    <div class="min-w-0">
      <div class="text-[13px] font-medium text-muted">{{ label }}</div>
      <div v-if="loading" class="mt-1 h-7 w-16 animate-pulse rounded bg-line" aria-label="Chargement" />
      <div v-else class="num text-[26px] leading-tight font-bold text-ink">{{ value ?? '—' }}</div>
      <div v-if="hint" class="mt-0.5 text-xs text-muted">{{ hint }}</div>
    </div>
  </div>
</template>
