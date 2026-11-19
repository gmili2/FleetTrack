import axios, { AxiosError, type InternalAxiosRequestConfig } from 'axios'

/** Client HTTP de l'API Laravel (même origine : cookies de session + jeton XSRF). */
export const http = axios.create({
  baseURL: '/',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
})

export const csrf = () => http.get('/sanctum/csrf-cookie')

let onUnauthorized: (() => void) | null = null
export const setUnauthorizedHandler = (fn: () => void) => { onUnauthorized = fn }

http.interceptors.response.use(undefined, async (error: AxiosError) => {
  const cfg = error.config as (InternalAxiosRequestConfig & { _retried?: boolean }) | undefined
  // Jeton CSRF expiré : on le renouvelle et on rejoue la requête une fois.
  if (error.response?.status === 419 && cfg && !cfg._retried) {
    cfg._retried = true
    await csrf()
    return http(cfg)
  }
  if (error.response?.status === 401 && !cfg?.url?.includes('/auth/')) onUnauthorized?.()
  return Promise.reject(error)
})

/** Message d'erreur lisible (en français) à partir d'une erreur Axios. */
export function errorMessage(e: unknown, fallback = 'Une erreur est survenue. Réessayez.'): string {
  if (e instanceof AxiosError) {
    if (!e.response) return 'Serveur injoignable. Vérifiez votre connexion.'
    const data = e.response.data as { message?: string; errors?: Record<string, string[]> } | undefined
    const first = data?.errors && Object.values(data.errors)[0]?.[0]
    if (first) return first
    if (e.response.status === 403) return 'Vous n’avez pas les droits pour cette action.'
    if (e.response.status === 429) return 'Trop de tentatives. Patientez une minute.'
    if (data?.message && e.response.status < 500) return data.message
  }
  return fallback
}

/** Erreurs de validation par champ (réponse 422). */
export function fieldErrors(e: unknown): Record<string, string> {
  if (e instanceof AxiosError && e.response?.status === 422) {
    const errs = (e.response.data as { errors?: Record<string, string[]> })?.errors ?? {}
    return Object.fromEntries(Object.entries(errs).map(([k, v]) => [k, v[0] ?? '']))
  }
  return {}
}
