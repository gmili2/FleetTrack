import { ref } from 'vue'

/** Petites notifications d'action (« Camion enregistré »). */
export type Toast = { id: number; text: string; tone: 'success' | 'error' }
export const toasts = ref<Toast[]>([])
let seq = 0
export function toast(text: string, tone: Toast['tone'] = 'success') {
  const id = ++seq
  toasts.value.push({ id, text, tone })
  setTimeout(() => { toasts.value = toasts.value.filter((t) => t.id !== id) }, 4000)
}
