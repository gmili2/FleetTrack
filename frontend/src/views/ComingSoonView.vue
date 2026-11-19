<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { Construction } from 'lucide-vue-next'
import { fLongDate } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import EmptyState from '@/components/EmptyState.vue'

/** Écran provisoire : l'écran réel est livré à l'étape indiquée. */
const route = useRoute()
const auth = useAuthStore()
const step = computed(() => route.meta.step as number | undefined)
</script>

<template>
  <div class="text-sm text-muted">{{ fLongDate() }}</div>
  <section class="card">
    <EmptyState :icon="Construction" :title="`${route.meta.title} — bientôt disponible`"
      :message="step ? `Cet écran sera développé à l’étape ${step} du projet. La structure, l’authentification et les droits sont déjà en place.` : undefined">
      <p v-if="route.name === 'dashboard' && auth.user" class="m-0 mt-2 text-sm text-ink">
        Connecté en tant que <b>{{ auth.user.name }}</b> · {{ auth.user.role_label }} · {{ auth.user.permissions.length }} permissions
      </p>
    </EmptyState>
  </section>
</template>
