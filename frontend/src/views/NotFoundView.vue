<script setup lang="ts">
import { MapPinOff } from 'lucide-vue-next'
import EmptyState from '@/components/EmptyState.vue'
</script>

<template>
  <section class="card">
    <EmptyState :icon="MapPinOff" title="Page introuvable" message="L’adresse demandée n’existe pas.">
      <RouterLink to="/" class="btn btn-secondary mt-2">Retour à l’accueil</RouterLink>
    </EmptyState>
  </section>
</template>
