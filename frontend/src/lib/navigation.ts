import type { Component } from 'vue'
import { BarChart3, CircleUserRound, ClipboardList, Cpu, Hexagon, History, IdCard, LayoutDashboard, MapPin, SlidersHorizontal, Truck, Users } from 'lucide-vue-next'

export type NavItem = { name: string; label: string; to: string; icon: Component; permission: string; driverOnly?: boolean }

/** Menu principal (ordre de la maquette) ; chaque entrée est filtrée par permission. */
export const NAV: NavItem[] = [
  { name: 'driver-portal', label: 'Mon service', to: '/mon-service', icon: CircleUserRound, permission: 'driver_portal.use', driverOnly: true },
  { name: 'dashboard', label: 'Tableau de bord', to: '/tableau-de-bord', icon: LayoutDashboard, permission: 'dashboard.view' },
  { name: 'tracking', label: 'Suivi temps réel', to: '/suivi', icon: MapPin, permission: 'tracking.view' },
  { name: 'history', label: 'Historique', to: '/historique', icon: History, permission: 'history.view' },
  { name: 'vehicles', label: 'Camions', to: '/camions', icon: Truck, permission: 'vehicles.view' },
  { name: 'devices', label: 'Cartes GPS', to: '/cartes-gps', icon: Cpu, permission: 'devices.view' },
  { name: 'drivers', label: 'Chauffeurs', to: '/chauffeurs', icon: Users, permission: 'drivers.view' },
  { name: 'shifts', label: 'Pointages', to: '/pointages', icon: IdCard, permission: 'shifts.view' },
  { name: 'missions', label: 'Missions', to: '/missions', icon: ClipboardList, permission: 'missions.view' },
  { name: 'geofences', label: 'Zones', to: '/zones', icon: Hexagon, permission: 'geofences.view' },
  { name: 'reports', label: 'Rapports', to: '/rapports', icon: BarChart3, permission: 'reports.view' },
  { name: 'settings', label: 'Paramètres', to: '/parametres', icon: SlidersHorizontal, permission: 'settings.view' },
]
