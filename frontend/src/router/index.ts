import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { NAV } from '@/lib/navigation'
import { useAuthStore } from '@/stores/auth'
import AppLayout from '@/layouts/AppLayout.vue'

declare module 'vue-router' {
  interface RouteMeta { title?: string; subtitle?: string; permission?: string; guest?: boolean; step?: number; fullBleed?: boolean }
}

const ComingSoon = () => import('@/views/ComingSoonView.vue')

/** Écrans du menu : titre, sous-titre et étape de livraison. */
const SCREENS: Record<string, { subtitle: string; step?: number }> = {
  'driver-portal': { subtitle: 'Pointage et suivi de mon service' },
  dashboard: { subtitle: 'Vue d’ensemble de la flotte' },
  tracking: { subtitle: 'Position des camions en direct', step: 3 },
  history: { subtitle: 'Trajets et GPS Replay' },
  vehicles: { subtitle: 'Parc de camions et boîtiers GPS' },
  drivers: { subtitle: 'Matricules, badges, permis et historique' },
  shifts: { subtitle: 'Prises et fins de service par badge' },
  missions: { subtitle: 'Planification et suivi des missions', step: 7 },
  geofences: { subtitle: 'Dépôts, clients, chantiers et zones de livraison' },
  reports: { subtitle: 'Kilométrage, conduite, visites et activité', step: 7 },
  settings: { subtitle: 'Seuils, utilisateurs et simulateur', step: 7 },
}

/** Écrans déjà livrés (les autres affichent « bientôt disponible »). */
const VIEWS: Record<string, { component: RouteRecordRaw['component']; fullBleed?: boolean }> = {
  tracking: { component: () => import('@/views/TrackingView.vue'), fullBleed: true },
  'driver-portal': { component: () => import('@/views/DriverPortalView.vue') },
  dashboard: { component: () => import('@/views/DashboardView.vue') },
  vehicles: { component: () => import('@/views/VehiclesView.vue') },
  history: { component: () => import('@/views/HistoryView.vue') },
  geofences: { component: () => import('@/views/ZonesView.vue'), fullBleed: true },
  shifts: { component: () => import('@/views/ShiftsView.vue') },
  drivers: { component: () => import('@/views/DriversView.vue') },
}

const appRoutes: RouteRecordRaw[] = NAV.map((item) => ({
  path: item.to.slice(1),
  name: item.name,
  component: VIEWS[item.name]?.component ?? ComingSoon,
  meta: { title: item.label, permission: item.permission, fullBleed: VIEWS[item.name]?.fullBleed, ...SCREENS[item.name] },
}) as RouteRecordRaw)

const routes: RouteRecordRaw[] = [
  { path: '/connexion', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guest: true, title: 'Connexion' } },
  {
    path: '/',
    component: AppLayout,
    children: [
      { path: '', name: 'home', redirect: () => {
        const auth = useAuthStore()
        return NAV.find((i) => auth.canSee(i))?.to ?? '/acces-refuse'
      } },
      ...appRoutes,
      { path: 'zones/:id/visites', name: 'zone-visits', component: () => import('@/views/ZoneVisitsView.vue'), meta: { title: 'Visites de la zone', subtitle: 'Visites et durées dans une zone', permission: 'geofences.view' } },
      { path: 'chauffeurs/:id', name: 'driver-history', component: () => import('@/views/DriverHistoryView.vue'), meta: { title: 'Historique du chauffeur', subtitle: 'Services, pointages et trajets', permission: 'drivers.view' } },
      { path: 'camions/:id', name: 'vehicle-detail', component: () => import('@/views/VehicleDetailView.vue'), meta: { title: 'Fiche camion', subtitle: 'Position, indicateurs 30 jours, trajets et affectations', permission: 'vehicles.view' } },
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
