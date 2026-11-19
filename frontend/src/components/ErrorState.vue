<script setup lang="ts">
import { RefreshCw, TriangleAlert } from 'lucide-vue-next'

defineProps<{ message: string }>()
const emit = defineEmits<{ retry: [] }>()
</script>

<template>
  <div class="flex flex-wrap items-center gap-3 rounded-[10px] border border-[#FECACA] bg-[#FEF2F2] px-4 py-3 text-sm text-[#991B1B]" role="alert">
    <TriangleAlert :size="18" aria-hidden="true" class="flex-none" />
    <span class="min-w-0 flex-1">{{ message }}</span>
    <button type="button" class="btn btn-secondary min-h-8 px-3 text-[13px]" @click="emit('retry')"><RefreshCw :size="15" aria-hidden="true" />Réessayer</button>
  </div>
</template>
