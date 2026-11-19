<script setup lang="ts">
import { ShieldX } from 'lucide-vue-next'
import EmptyState from '@/components/EmptyState.vue'
</script>

<template>
  <section class="card">
    <EmptyState :icon="ShieldX" title="Accès refusé" message="Votre rôle ne donne pas accès à cet écran. Contactez un administrateur si besoin.">
      <RouterLink to="/" class="btn btn-secondary mt-2">Retour à l’accueil</RouterLink>
    </EmptyState>
  </section>
</template>
