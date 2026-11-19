import Echo from 'laravel-echo'
import Pusher, { type ChannelAuthorizationCallback } from 'pusher-js'
import { http } from './http'

declare global {
  interface Window { Pusher: typeof Pusher }
}
window.Pusher = Pusher

let echo: Echo<'reverb'> | null = null

/**
 * Connexion temps réel à Laravel Reverb. Le WebSocket passe par la même origine
 * que l'application (/app/*, relayé par Vite en dev et par nginx en Docker).
 */
export function useEcho(): Echo<'reverb'> {
  if (echo) return echo
  const secure = location.protocol === 'https:'
  const port = Number(location.port) || (secure ? 443 : 80)
  echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY ?? 'fleettrack-key',
    wsHost: location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: secure,
    enabledTransports: ['ws', 'wss'],
    // Autorisation des canaux privés avec le cookie de session et le jeton XSRF.
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: ChannelAuthorizationCallback) => {
        http.post('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
          .then((r) => callback(null, r.data))
          .catch((e) => callback(e, null))
      },
    }),
  })
  return echo
}

export function disconnectEcho() {
  echo?.disconnect()
  echo = null
}
