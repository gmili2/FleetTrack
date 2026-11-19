import { fileURLToPath, URL } from 'node:url'
import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// En développement, le front appelle l'API sur la même origine (proxy) :
// les cookies de session Sanctum et le WebSocket Reverb fonctionnent sans CORS.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const api = env.VITE_API_PROXY ?? 'http://localhost:8000'
  const ws = env.VITE_REVERB_PROXY ?? 'ws://localhost:8081'

  return {
    plugins: [vue(), tailwindcss()],
    resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
    server: {
      port: 5173,
      proxy: {
        '/api': { target: api, changeOrigin: false },
        '/sanctum': { target: api, changeOrigin: false },
        '/broadcasting': { target: api, changeOrigin: false },
        '/app': { target: ws, ws: true },
      },
    },
  }
})
