# FleetTrack

Application de **démonstration** pour gérer une flotte de camions et la suivre par GPS. Il n'y a pas de vrais boîtiers : un simulateur intégré produit les positions GPS et les passages de badge autour de Casablanca.

- **Backend** : Laravel 13 (PHP 8.3), API REST JSON, Sanctum (authentification SPA par cookie), Reverb (temps réel), queue `database`, scheduler, spatie/laravel-permission, laravel-excel, dompdf.
- **Frontend** : Vue 3, TypeScript, Vite, Vue Router, Pinia, Axios, Tailwind CSS 4, Leaflet, Leaflet-Geoman, Chart.js (vue-chartjs), icônes Lucide.
- **Base de données** : MariaDB 11 (InnoDB, utf8mb4). Les dates sont stockées en UTC et affichées dans le fuseau Africa/Casablanca.

```
backend/    API Laravel
frontend/   SPA Vue 3
docker/     images PHP et nginx
docs/maquettes/  maquettes HTML des 13 écrans
```

## Comptes de démo

Le mot de passe de tous les comptes est `password`.

| Rôle | E-mail | Droits |
|---|---|---|
| Super Administrateur | superadmin@demo.ma | Tous les droits |
| Administrateur | admin@demo.ma | Tous les droits, sauf la gestion des super administrateurs |
| Responsable de flotte | flotte@demo.ma | Camions, chauffeurs, zones, missions, rapports. Pas d'utilisateurs ni de paramètres |
| Opérateur | operateur@demo.ma | Lecture et missions. Pas de suppression ni d'export |

Après 5 échecs de connexion d'affilée, le compte est verrouillé pendant 15 minutes.

## Données de démo

`php artisan migrate:fresh --seed` crée :

- 12 camions (de CAM-01 à CAM-24), chacun avec son boîtier GPS ;
- 14 chauffeurs, avec un matricule (CH-1012…) et un badge (B-4410…) ;
- 4 clients ;
- 8 zones : le dépôt Aïn Sebaâ, l'usine de Mohammedia, 4 clients, le chantier de Lissasfa et la zone de livraison du port ;
- 33 missions, réparties sur les 4 statuts (en attente, en cours, terminée, annulée), chacune avec son journal d'événements.

Au départ, tous les camions sont garés au dépôt. CAM-24 est en maintenance et hors ligne.

## Lancement rapide (sans Docker)

```bash
./demo.sh            # lance MariaDB, l'API, Reverb, la queue, le scheduler (et le simulateur) puis le front
./demo.sh --reset    # idem, en remettant les données de démo à zéro
```

L'application s'ouvre sur http://localhost:5173. `Ctrl+C` arrête tout. Les journaux sont dans `.logs/`.

## Lancement avec Docker

```bash
docker compose up -d --build
```

Ouvrez ensuite http://localhost:8080.

Au premier démarrage, le conteneur `app` installe les dépendances Composer, applique les migrations et remplit la base. Services lancés :

| Service | Rôle |
|---|---|
| `nginx` | Sert le front compilé et relaie `/api`, `/sanctum` et `/broadcasting` vers PHP-FPM, et `/app` vers Reverb (WebSocket) |
| `app` | PHP-FPM 8.3 (API Laravel) |
| `mariadb` | MariaDB 11, exposé sur le port 3307 de la machine hôte |
| `reverb` | Serveur WebSocket Laravel Reverb |
| `queue` | `php artisan queue:work` |
| `scheduler` | `php artisan schedule:work` |
| `simulator` | Simulateur GPS, livré à l'étape 3 (déjà prévu, en commentaire) |

Commandes utiles :

```bash
docker compose exec app php artisan migrate:fresh --seed   # réinitialiser la démo
docker compose exec app ./vendor/bin/pest                    # tests
docker compose logs -f reverb queue
```

## Lancement sans Docker

Prérequis : PHP 8.3 ou plus récent (avec pdo_mysql, intl, gd, zip, bcmath et pcntl), Composer 2, Node 20 ou plus récent, MariaDB 11.

**1. Base de données**

```sql
CREATE DATABASE fleettrack CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'fleettrack'@'127.0.0.1' IDENTIFIED BY 'fleettrack';
GRANT ALL ON fleettrack.* TO 'fleettrack'@'127.0.0.1';
-- pour les tests :
CREATE DATABASE fleettrack_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON fleettrack_test.* TO 'fleettrack'@'127.0.0.1';
```

**2. Backend**

```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
```

**3. Frontend**

```bash
cd frontend
npm install
cp .env.example .env
```

**4. Lancement**

Ouvrez un terminal par processus :

```bash
cd backend  && php artisan serve --port=8000             # API
cd backend  && php artisan reverb:start --port=8081      # WebSocket
cd backend  && php artisan queue:work                    # file d'attente
cd backend  && php artisan schedule:work                 # tâches planifiées
cd backend  && php artisan fleet:simulate                # simulateur (étape 3)
cd frontend && npm run dev                               # http://localhost:5173
```

En développement, Vite relaie `/api`, `/sanctum`, `/broadcasting` (vers le port 8000) et `/app` (WebSocket, vers le port 8081). Le front et l'API sont donc sur la même origine : le cookie de session Sanctum fonctionne sans configuration CORS.

## Tests

```bash
cd backend && ./vendor/bin/pest
```

## Avancement

- [x] Étape 1 : structure, Docker, authentification (verrouillage après 5 échecs, « Se souvenir de moi »), rôles et permissions, layout Vue
- [x] Étape 2 : migrations, modèles, seeders
- [ ] Étape 3 : simulateur, temps réel, suivi GPS en direct
- [ ] Étape 4 : tableau de bord, camions, chauffeurs
- [ ] Étape 5 : historique et GPS Replay
- [ ] Étape 6 : zones, visites, pointages
- [ ] Étape 7 : missions, rapports, exports, paramètres
- [ ] Étape 8 : tests, README, vérification finale
