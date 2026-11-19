<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { TriangleAlert } from 'lucide-vue-next'

/** Boîte de confirmation (suppression, annulation…). Fermeture par Échap ou clic extérieur. */
const props = withDefaults(defineProps<{
  open: boolean; title: string; message?: string; confirmLabel?: string; cancelLabel?: string; danger?: boolean; busy?: boolean
}>(), { confirmLabel: 'Confirmer', cancelLabel: 'Annuler', danger: false, busy: false })
const emit = defineEmits<{ confirm: []; cancel: [] }>()

const cancelBtn = ref<HTMLButtonElement | null>(null)
watch(() => props.open, async (o) => { if (o) { await nextTick(); cancelBtn.value?.focus() } })
const close = () => { if (!props.busy) emit('cancel') }
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-[1000] flex items-center justify-center bg-ink/50 p-4" @click.self="close" @keydown.esc="close">
      <div role="alertdialog" aria-modal="true" :aria-labelledby="'cd-title'" class="card w-full max-w-md p-6 shadow-xl">
        <div class="flex gap-4">
          <div class="flex size-10 flex-none items-center justify-center rounded-full" :class="danger ? 'bg-[#FEE2E2] text-danger' : 'bg-brand-soft text-brand'">
            <TriangleAlert :size="20" aria-hidden="true" />
          </div>
          <div class="min-w-0">
            <h2 id="cd-title" class="m-0 text-lg font-bold text-ink">{{ title }}</h2>
            <p v-if="message" class="mt-1.5 text-sm text-muted">{{ message }}</p>
            <slot />
          </div>
        </div>
        <div class="mt-6 flex justify-end gap-2.5">
          <button ref="cancelBtn" type="button" class="btn btn-secondary" :disabled="busy" @click="close">{{ cancelLabel }}</button>
          <button type="button" class="btn" :class="danger ? 'btn-danger' : 'btn-primary'" :disabled="busy" @click="emit('confirm')">
            {{ busy ? 'Patientez…' : confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
