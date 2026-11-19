<script setup lang="ts">
import { LoaderCircle } from 'lucide-vue-next'

withDefaults(defineProps<{ label?: string }>(), { label: 'Chargement…' })
</script>

<template>
  <div class="flex items-center justify-center gap-2.5 py-12 text-sm text-muted" role="status">
    <LoaderCircle :size="20" class="animate-spin text-brand" aria-hidden="true" />{{ label }}
  </div>
</template>
