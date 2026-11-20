export type LiveStatus = 'moving' | 'stopped' | 'offline'

export type LiveVehicle = {
  id: number; name: string; plate_number: string; brand: string | null; model: string | null
  type: string; type_label: string; vehicle_status: string; speed_limit_kmh: number
  status: LiveStatus; status_label: string; status_since: string | null
  latitude: number | null; longitude: number | null; speed_kmh: number; course: number; ignition: boolean
  address: string | null; zone: { id: number; name: string; type: string } | null
  today_distance_km: number; last_position_at: string | null
  driver: { id: number; name: string; phone: string | null; employee_number: string; badge_number: string; on_shift: boolean } | null
  shift: { id: number; start_at: string; start_place: string | null; source: 'badge' | 'app'; anomaly: string | null; unknown_badge: string | null } | null
  device: { imei: string; model: string | null } | null
  mission: { id: number; reference: string; status: string; origin: string | null; destination: string | null; actual_start_at: string | null; planned_end_at: string | null } | null
}

export type LiveDetail = LiveVehicle & {
  today: { distance_km: number; driving_seconds: number; stopped_seconds: number; stops_count: number; max_speed_kmh: number; avg_speed_kmh: number }
  events: { type: string; label: string; tone: 'green' | 'orange' | 'gray' | 'blue' | 'red'; zone: string | null; payload: Record<string, unknown> | null; occurred_at: string }[]
  trail: [number, number][]
}

export type MapZone = {
  id: number; name: string; type: string; type_label: string; shape: 'circle' | 'polygon'
  center: [number, number]; radius_m: number | null; coordinates: [number, number][] | null; color: string
}

export type Activity = { kind: string; message: string; vehicleId: number | null; data: Record<string, unknown>; at: string }

export type AppNotification = {
  id: string; read: boolean; created_at: string; kind: string; title: string; message: string
  level: 'info' | 'success' | 'warning' | 'danger'; vehicle_id: number | null; link: string | null
}

export type ShiftRow = {
  id: number; source: 'badge' | 'app'
  driver: { id: number; name: string; employee_number: string; badge_number: string } | null
  badge_number: string | null
  vehicle: { id: number; name: string; plate_number: string } | null
  start_at: string; start_place: string | null; end_at: string | null; end_place: string | null
  open: boolean; duration_seconds: number; distance_km: number | null; anomaly: string | null; anomaly_label: string | null
}

export type ShiftTrack = {
  shift: ShiftRow
  summary: { distance_km: number; driving_seconds: number; stopped_seconds: number; stops_count: number; max_speed_kmh: number; avg_speed_kmh: number; stops: { started_at: string; ended_at: string | null; duration_seconds: number; latitude: number; longitude: number }[] }
  points: [number, number, number, string][]
}

export type Paginated<T> = { data: T[]; meta: { current_page: number; last_page: number; per_page: number; total: number; counts?: Record<string, number> } }

export type VehicleRow = {
  id: number; name: string; plate_number: string; brand: string | null; model: string | null
  type: string; type_label: string; year: number | null; status: string; status_label: string; status_tone: 'green' | 'orange' | 'gray'
  speed_limit_kmh: number; odometer_km: number; notes: string | null
  driver: { id: number; name: string; employee_number: string } | null
  device: { id: number; imei: string; model: string | null; sim_number: string | null; installed_at: string | null; last_seen_at: string | null } | null
  live: { status: LiveStatus; status_label: string; latitude: number | null; longitude: number | null; speed_kmh: number; address: string | null; today_distance_km: number; last_position_at: string | null } | null
}

export type DriverRow = {
  id: number; name: string; first_name: string; last_name: string; initials: string; employee_number: string; badge_number: string; phone: string | null
  license_number: string | null; license_category: string | null; license_expires_at: string | null; license_expired: boolean; license_expires_soon: boolean
  status: string; status_label: string; status_tone: 'green' | 'blue' | 'gray'; hired_at: string | null; notes: string | null
  vehicle: { id: number; name: string; plate_number: string } | null
  on_shift: { id: number; start_at: string; source: 'badge' | 'app'; vehicle_id: number } | null
  shifts_this_month: number
}

export type ZoneRow = {
  id: number; name: string; type: string; type_label: string; shape: 'circle' | 'polygon'; shape_label: string
  center: [number, number]; center_lat: number; center_lng: number; radius_m: number | null; coordinates: [number, number][] | null
  color: string; customer: { id: number; code: string; name: string } | null; min_visit_seconds: number; address: string | null
  is_active: boolean; visits_today?: number; vehicles_inside?: number
}

export type MissionRow = {
  id: number; reference: string; status: 'pending' | 'in_progress' | 'completed' | 'cancelled'; status_label: string; status_tone: 'gray' | 'blue' | 'green' | 'red'; late: boolean
  vehicle: { id: number; name: string; plate_number: string } | null; driver: { id: number; name: string; phone: string | null } | null
  customer: { id: number; name: string; code: string } | null; origin: { id: number; name: string } | null; destination: { id: number; name: string } | null
  planned_start_at: string; planned_end_at: string; actual_start_at: string | null; actual_end_at: string | null
  cargo: string | null; notes: string | null; cancel_reason: string | null; distance_km: number | null; created_by: string | null
  events?: { id: number; type: string; label: string; message: string; author: string | null; automatic: boolean; occurred_at: string }[]
  can: { update: boolean; operate: boolean; delete: boolean }
}
