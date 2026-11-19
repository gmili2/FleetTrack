import type { Component } from 'vue'
import { BarChart3, ClipboardList, Hexagon, History, IdCard, LayoutDashboard, MapPin, SlidersHorizontal, Truck, Users } from 'lucide-vue-next'

export type NavItem = { name: string; label: string; to: string; icon: Component; permission: string }

/** Menu principal (ordre de la maquette) ; chaque entrée est filtrée par permission. */
export const NAV: NavItem[] = [
  { name: 'dashboard', label: 'Tableau de bord', to: '/tableau-de-bord', icon: LayoutDashboard, permission: 'dashboard.view' },
  { name: 'tracking', label: 'Suivi temps réel', to: '/suivi', icon: MapPin, permission: 'tracking.view' },
  { name: 'history', label: 'Historique', to: '/historique', icon: History, permission: 'history.view' },
  { name: 'vehicles', label: 'Camions', to: '/camions', icon: Truck, permission: 'vehicles.view' },
  { name: 'drivers', label: 'Chauffeurs', to: '/chauffeurs', icon: Users, permission: 'drivers.view' },
  { name: 'shifts', label: 'Pointages', to: '/pointages', icon: IdCard, permission: 'shifts.view' },
  { name: 'missions', label: 'Missions', to: '/missions', icon: ClipboardList, permission: 'missions.view' },
  { name: 'geofences', label: 'Zones', to: '/zones', icon: Hexagon, permission: 'geofences.view' },
  { name: 'reports', label: 'Rapports', to: '/rapports', icon: BarChart3, permission: 'reports.view' },
  { name: 'settings', label: 'Paramètres', to: '/parametres', icon: SlidersHorizontal, permission: 'settings.view' },
]
