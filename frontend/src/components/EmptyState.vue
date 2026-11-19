<script setup lang="ts">
import type { Component } from 'vue'
import { Inbox } from 'lucide-vue-next'

withDefaults(defineProps<{ title?: string; message?: string; icon?: Component }>(), { title: 'Aucun résultat', icon: () => Inbox })
</script>

<template>
  <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center">
    <div class="flex size-12 items-center justify-center rounded-full bg-page text-offline"><component :is="icon" :size="22" aria-hidden="true" /></div>
    <div class="text-[15px] font-semibold text-ink">{{ title }}</div>
    <p v-if="message" class="m-0 max-w-md text-sm text-muted">{{ message }}</p>
    <slot />
  </div>
</template>
