import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { NAV } from '@/lib/navigation'
import { useAuthStore } from '@/stores/auth'
import AppLayout from '@/layouts/AppLayout.vue'

declare module 'vue-router' {
  interface RouteMeta { title?: string; subtitle?: string; permission?: string; guest?: boolean; step?: number }
}

const ComingSoon = () => import('@/views/ComingSoonView.vue')

/** Écrans du menu : titre, sous-titre et étape de livraison. */
const SCREENS: Record<string, { subtitle: string; step: number }> = {
  dashboard: { subtitle: 'Vue d’ensemble de la flotte', step: 4 },
  tracking: { subtitle: 'Position des camions en direct', step: 3 },
  history: { subtitle: 'Trajets et GPS Replay', step: 5 },
  vehicles: { subtitle: 'Parc de camions et boîtiers GPS', step: 4 },
  drivers: { subtitle: 'Matricules, badges et permis', step: 4 },
  shifts: { subtitle: 'Prises et fins de service par badge', step: 6 },
  missions: { subtitle: 'Planification et suivi des missions', step: 7 },
  geofences: { subtitle: 'Dépôts, clients, chantiers et zones de livraison', step: 6 },
  reports: { subtitle: 'Kilométrage, conduite, visites et activité', step: 7 },
  settings: { subtitle: 'Seuils, utilisateurs et simulateur', step: 7 },
}

const appRoutes: RouteRecordRaw[] = NAV.map((item) => ({
  path: item.to.slice(1),
  name: item.name,
  component: ComingSoon,
  meta: { title: item.label, permission: item.permission, ...SCREENS[item.name] },
}))

const routes: RouteRecordRaw[] = [
  { path: '/connexion', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guest: true, title: 'Connexion' } },
  {
    path: '/',
    component: AppLayout,
    children: [
      { path: '', name: 'home', redirect: () => {
        const auth = useAuthStore()
        return NAV.find((i) => auth.can(i.permission))?.to ?? '/acces-refuse'
      } },
      ...appRoutes,
      { path: 'acces-refuse', name: 'forbidden', component: () => import('@/views/ForbiddenView.vue'), meta: { title: 'Accès refusé' } },
      { path: ':pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue'), meta: { title: 'Page introuvable' } },
    ],
  },
]

export const router = createRouter({ history: createWebHistory(), routes })

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await auth.init()
  if (to.meta.guest) return auth.user ? { name: 'home' } : true
  if (!auth.user) return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  if (to.meta.permission && !auth.can(to.meta.permission)) return { name: 'forbidden' }
  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} — FleetTrack` : 'FleetTrack'
})
