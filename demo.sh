#!/bin/bash
# Lance toute la démo FleetTrack en local (sans Docker). Ctrl+C arrête tout.
#   ./demo.sh          lancer
#   ./demo.sh --reset  remettre les données de démo à zéro avant de lancer
set -e
cd "$(dirname "$0")"
ROOT=$(pwd)
mkdir -p "$ROOT/.logs"

# MariaDB (Homebrew)
if ! mariadb -e "select 1" >/dev/null 2>&1; then
  echo "▶ Démarrage de MariaDB…"
  brew services start mariadb@11.4 >/dev/null
  sleep 3
fi

# Libère les ports si une ancienne démo tourne encore.
for port in 8000 8081 5173; do
  pid=$(lsof -ti tcp:$port -sTCP:LISTEN 2>/dev/null || true)
  [ -n "$pid" ] && kill $pid 2>/dev/null || true
done

cd "$ROOT/backend"
if [ "$1" = "--reset" ]; then
  echo "▶ Réinitialisation des données de démo…"
  php artisan migrate:fresh --seed --force
else
  php artisan migrate --force >/dev/null
fi

pids=()
start() { echo "▶ $1"; (cd "$2" && eval "$3") > "$ROOT/.logs/$4.log" 2>&1 & pids+=($!); }
start "API Laravel (port 8000)"   "$ROOT/backend"  "php artisan serve --port=8000"          api
start "Reverb WebSocket (8081)"   "$ROOT/backend"  "php artisan reverb:start --port=8081"   reverb
start "File d'attente"            "$ROOT/backend"  "php artisan queue:work --sleep=1"       queue
start "Tâches planifiées"         "$ROOT/backend"  "php artisan schedule:work"              scheduler
if php artisan list --raw 2>/dev/null | grep -q '^fleet:simulate'; then
  start "Simulateur GPS"          "$ROOT/backend"  "php artisan fleet:simulate"             simulator
fi
start "Front Vue (port 5173)"     "$ROOT/frontend" "npx vite --port 5173 --strictPort"      front

trap 'echo; echo "■ Arrêt de la démo…"; kill ${pids[@]} 2>/dev/null; exit 0' INT TERM

sleep 4
echo
echo "✅ FleetTrack est prêt : http://localhost:5173"
echo "   Comptes (mot de passe « password ») : superadmin@demo.ma · admin@demo.ma · flotte@demo.ma · operateur@demo.ma"
echo "   Journaux : $ROOT/.logs/   —   Ctrl+C pour tout arrêter"
open http://localhost:5173 2>/dev/null || true
wait
