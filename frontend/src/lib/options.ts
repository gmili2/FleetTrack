/** Listes de choix (identiques aux enums PHP). */
export const VEHICLE_TYPES = [
  { value: 'tractor', label: 'Tracteur' }, { value: 'rigid', label: 'Porteur' }, { value: 'tipper', label: 'Benne' },
  { value: 'tanker', label: 'Citerne' }, { value: 'van', label: 'Fourgon' },
]
export const VEHICLE_STATUSES = [{ value: 'active', label: 'Actif' }, { value: 'maintenance', label: 'Maintenance' }, { value: 'inactive', label: 'Inactif' }]
export const DRIVER_STATUSES = [{ value: 'available', label: 'Disponible' }, { value: 'on_mission', label: 'En mission' }, { value: 'rest', label: 'Repos' }, { value: 'inactive', label: 'Inactif' }]
export const LICENSE_CATEGORIES = ['B', 'C', 'CE', 'D', 'EC']
export const ZONE_TYPES = [
  { value: 'depot', label: 'Dépôt', plural: 'Dépôts', color: '#1D4ED8' }, { value: 'factory', label: 'Usine', plural: 'Usines', color: '#7C3AED' },
  { value: 'customer', label: 'Client', plural: 'Clients', color: '#15803D' }, { value: 'site', label: 'Chantier', plural: 'Chantiers', color: '#C2410C' },
  { value: 'delivery_zone', label: 'Zone de livraison', plural: 'Livraison', color: '#0E7490' },
]
export const ZONE_COLORS = ['#1D4ED8', '#15803D', '#C2410C', '#7C3AED', '#0E7490', '#B91C1C', '#0F172A']
