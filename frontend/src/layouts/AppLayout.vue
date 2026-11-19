<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { Bell, LogOut, Menu, Search, Truck, X } from 'lucide-vue-next'
import { NAV } from '@/lib/navigation'
import { useAuthStore } from '@/stores/auth'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

/** Gabarit de l'application : sidebar bleu nuit, barre du haut (titre, recherche, notifications, avatar). */
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const menu = computed(() => NAV.filter((i) => auth.can(i.permission)))
const title = computed(() => (route.meta.title as string) ?? 'FleetTrack')
const subtitle = computed(() => route.meta.subtitle as string | undefined)

const drawer = ref(false)
watch(() => route.fullPath, () => { drawer.value = false })

const search = ref('')
const unread = ref(0) // Notifications : branchées à l'étape 3 (temps réel).

const askLogout = ref(false)
const loggingOut = ref(false)
async function logout() {
  loggingOut.value = true
  try { await auth.logout() } finally {
    loggingOut.value = false
    askLogout.value = false
    router.replace({ name: 'login' })
  }
}

const isActive = (to: string) => route.path === to || route.path.startsWith(to + '/')
</script>

<template>
  <div class="flex min-h-screen bg-page text-ink">
    <!-- Voile mobile -->
    <div v-if="drawer" class="fixed inset-0 z-40 bg-ink/50 lg:hidden" aria-hidden="true" @click="drawer = false" />

    <nav
      aria-label="Navigation principale"
      class="fixed inset-y-0 left-0 z-50 flex w-[248px] flex-col gap-6 overflow-y-auto bg-navy px-4 py-5 transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
      :class="drawer ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex items-center justify-between px-1.5 py-1">
        <RouterLink to="/" class="flex items-center gap-2.5 no-underline">
          <span class="flex size-9 items-center justify-center rounded-[10px] bg-brand"><Truck :size="20" color="#fff" :stroke-width="2" aria-hidden="true" /></span>
          <span class="text-lg font-bold tracking-tight text-white">FleetTrack</span>
        </RouterLink>
        <button type="button" class="flex size-9 items-center justify-center rounded-lg text-[#A9B8CC] lg:hidden" aria-label="Fermer le menu" @click="drawer = false"><X :size="20" /></button>
      </div>

      <div class="flex flex-col gap-1">
        <RouterLink
          v-for="item in menu" :key="item.name" :to="item.to"
          class="flex min-h-11 items-center gap-3 rounded-[10px] px-3.5 text-sm no-underline transition-colors"
          :class="isActive(item.to) ? 'bg-brand font-semibold text-white hover:text-white' : 'font-medium text-[#CBD5E1] hover:bg-navy-2 hover:text-white'"
          :aria-current="isActive(item.to) ? 'page' : undefined"
        >
          <component :is="item.icon" :size="19" :stroke-width="1.8" aria-hidden="true" class="flex-none" />
          <span>{{ item.label }}</span>
        </RouterLink>
      </div>

      <div v-if="auth.user" class="mt-auto flex items-center gap-2.5 rounded-xl bg-navy-2 p-3">
        <div class="flex size-9 flex-none items-center justify-center rounded-full bg-navy-3 text-[13px] font-semibold text-white">{{ auth.user.initials }}</div>
        <div class="min-w-0 flex-1">
          <div class="truncate text-[13px] font-semibold text-white">{{ auth.user.name }}</div>
          <div class="truncate text-xs text-[#A9B8CC]">{{ auth.user.role_label }}</div>
        </div>
        <button type="button" class="flex rounded-md p-1.5 text-[#A9B8CC] hover:bg-navy-3 hover:text-white" aria-label="Se déconnecter" title="Se déconnecter" @click="askLogout = true">
          <LogOut :size="18" :stroke-width="1.8" />
        </button>
      </div>
    </nav>

    <div class="flex min-w-0 flex-1 flex-col">
      <header class="sticky top-0 z-30 flex flex-wrap items-center gap-3 border-b border-line bg-white px-4 py-3.5 sm:gap-4 sm:px-7">
        <button type="button" class="btn-icon size-11 lg:hidden" aria-label="Ouvrir le menu" @click="drawer = true"><Menu :size="20" /></button>
        <div class="min-w-0 flex-[1_1_200px]">
          <h1 class="m-0 truncate text-xl font-bold text-ink">{{ title }}</h1>
          <div v-if="subtitle" class="truncate text-[13px] text-muted">{{ subtitle }}</div>
        </div>
        <label class="order-last flex h-10 w-full items-center gap-2 rounded-[10px] border border-line bg-page px-3 text-muted sm:order-none sm:w-auto sm:flex-[0_1_360px]">
          <Search :size="17" :stroke-width="1.8" aria-hidden="true" class="flex-none" />
          <span class="sr-only">Rechercher</span>
          <input v-model="search" type="search" placeholder="Rechercher un camion, un chauffeur, une mission…" class="min-w-0 flex-1 border-0 bg-transparent text-sm text-ink outline-none" />
        </label>
        <button type="button" class="relative flex size-11 items-center justify-center rounded-[10px] border border-line bg-white text-ink hover:bg-page" :aria-label="unread ? `Notifications, ${unread} non lues` : 'Notifications'">
          <Bell :size="19" :stroke-width="1.8" />
          <span v-if="unread" class="absolute top-1.5 right-1.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-danger px-1 text-[11px] font-bold text-white">{{ unread }}</span>
        </button>
        <div v-if="auth.user" class="hidden size-9 items-center justify-center rounded-full bg-brand-soft sm:flex text-[13px] font-bold text-brand-dark" :title="`${auth.user.name} — ${auth.user.role_label}`">
          {{ auth.user.initials }}
        </div>
      </header>

      <main class="flex min-w-0 flex-1 flex-col gap-5 px-4 py-6 sm:px-7">
        <RouterView />
      </main>
    </div>

    <ConfirmDialog
      :open="askLogout" title="Se déconnecter ?" message="Vous devrez saisir à nouveau vos identifiants pour revenir."
      confirm-label="Se déconnecter" :busy="loggingOut" @confirm="logout" @cancel="askLogout = false"
    />
  </div>
</template>
