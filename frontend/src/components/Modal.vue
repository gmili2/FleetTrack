<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { X } from 'lucide-vue-next'

/** Fenêtre modale (formulaires). Échap ou clic extérieur pour fermer. */
const props = withDefaults(defineProps<{ open: boolean; title: string; subtitle?: string; width?: string }>(), { width: 'max-w-2xl' })
const emit = defineEmits<{ close: [] }>()
const panel = ref<HTMLElement | null>(null)
watch(() => props.open, async (o) => { if (o) { await nextTick(); panel.value?.querySelector<HTMLElement>('input,select,textarea')?.focus() } })
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-[1000] flex items-end justify-center bg-ink/50 sm:items-center sm:p-4" @click.self="emit('close')" @keydown.esc="emit('close')">
      <div ref="panel" class="flex max-h-[92dvh] w-full flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl" :class="width" role="dialog" aria-modal="true" :aria-label="title">
        <div class="flex items-start justify-between gap-3 border-b border-line px-6 py-4">
          <div><h2 class="m-0 text-lg font-bold">{{ title }}</h2><p v-if="subtitle" class="m-0 mt-0.5 text-[13px] text-muted">{{ subtitle }}</p></div>
          <button type="button" class="btn-icon" aria-label="Fermer" @click="emit('close')"><X :size="18" /></button>
        </div>
        <div class="flex-1 overflow-y-auto px-6 py-5"><slot /></div>
        <div v-if="$slots.footer" class="flex flex-wrap justify-end gap-2.5 border-t border-line px-6 py-4"><slot name="footer" /></div>
      </div>
    </div>
  </Teleport>
</template>
