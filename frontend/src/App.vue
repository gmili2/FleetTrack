<script setup lang="ts">
import { RouterView } from 'vue-router'
import { CircleCheck, CircleX } from 'lucide-vue-next'
import { toasts } from '@/lib/toast'
</script>

<template>
  <RouterView />
  <div class="pointer-events-none fixed right-4 bottom-4 z-[1100] flex flex-col gap-2" aria-live="polite">
    <div v-for="t in toasts" :key="t.id" class="pointer-events-auto flex items-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold text-white shadow-lg" :class="t.tone === 'success' ? 'bg-moving' : 'bg-danger'" role="status">
      <CircleCheck v-if="t.tone === 'success'" :size="18" /><CircleX v-else :size="18" />{{ t.text }}
    </div>
  </div>
</template>
