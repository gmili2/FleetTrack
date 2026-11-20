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
| Chauffeur | chauffeur@demo.ma | Espace « Mon service » uniquement. Compte lié à Jamal Ouazzani (CH-1096, badge B-4446) |

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
| `simulator` | Simulateur GPS (`php artisan fleet:simulate`) |

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
cd backend  && php artisan fleet:simulate                # simulateur GPS
cd frontend && npm run dev                               # http://localhost:5173
```

En développement, Vite relaie `/api`, `/sanctum`, `/broadcasting` (vers le port 8000) et `/app` (WebSocket, vers le port 8081). Le front et l'API sont donc sur la même origine : le cookie de session Sanctum fonctionne sans configuration CORS.

## Espace chauffeur

Un compte du rôle **Chauffeur** est lié à une fiche chauffeur. Il n'a accès qu'à l'écran « Mon service », pensé pour le téléphone :

1. **Pointer l'entrée** : le chauffeur choisit un camion à l'arrêt. Le pointage est enregistré à la position du boîtier GPS du camion, et une prise hors dépôt est signalée.
2. Pendant le service, le boîtier du camion suit **ce chauffeur**. Sur l'écran Suivi, le camion affiche son nom et l'heure de prise. Le chauffeur voit son camion sur la carte, sa vitesse, les km parcourus et la durée du service.
3. **Pointer la sortie** : le service est clôturé (durée, km).
4. **Mes derniers services** : la liste de ses services, et le trajet de chacun sur la carte.

Si le camion était déjà en service avec un chauffeur badgé, ce chauffeur est relevé : son service est clôturé normalement. Côté back-office, l'écran **Chauffeurs** donne la liste, le statut et le bouton « Suivre sur la carte ». L'**historique d'un chauffeur** présente ses services, ses pointages, ses km et ses anomalies, avec le trajet de chaque service (départ A, arrivée B, arrêts numérotés).

Le simulateur continue de faire rouler un camion pris depuis l'application, mais il ne pointe jamais à la place du chauffeur.

## Simulateur GPS

```bash
php artisan fleet:simulate              # une position par camion toutes les 5 s
php artisan fleet:simulate --speed=3    # temps accéléré x3 (trajets et arrêts plus courts)
php artisan fleet:simulate --paused     # démarre en pause
```

Les 11 camions actifs roulent sur 9 itinéraires routiers réels autour de Casablanca (tracés OSRM simplifiés, de 30 à 60 points chacun). Chaque camion suit ce cycle :

1. Badge d'entrée au dépôt.
2. 1 à 3 trajets vers les clients, l'usine, le chantier ou le port, avec des arrêts de 5 à 60 min sur place.
3. Badge de sortie, puis pause avant le service suivant.

Une mission en cours impose son point de départ et sa destination.

Chaque position fait quatre choses : elle est enregistrée dans `positions`, elle met à jour `vehicle_live_status`, elle passe par la détection des zones, puis elle est diffusée sur le canal privé `fleet` (événement `position.updated`).

La détection des zones applique un anti-rebond : une visite n'est comptée que si le camion reste dans la zone au moins `min_visit_seconds`. En sortie, une marge de 25 m absorbe l'imprécision du GPS.

Événements simulés :
- excès de vitesse ;
- perte de signal d'environ 15 min, à peu près toutes les 45 min (le camion passe hors ligne au bout de 10 min) ;
- au premier lancement, une prise de service hors dépôt (CAM-15, à l'usine de Mohammedia) et une sortie non pointée (CAM-21) ;
- ensuite, de façon aléatoire et rare, un badge inconnu ou un oubli de badge de sortie.

`php artisan fleet:check-status` est planifié chaque minute. Il passe hors ligne les camions silencieux et signale les sorties non pointées.

## Historique et GPS Replay

Sur l'écran **Historique**, on choisit un camion, une date et une plage horaire. On peut aussi y arriver depuis le bouton « Rejouer » d'un trajet (fiche camion) ou d'un service (historique d'un chauffeur).

- **Tracé** : départ A, arrivée B et arrêts numérotés, c'est-à-dire tout arrêt d'au moins 5 min, d'après le seuil défini dans les paramètres.
- **Synthèse** : distance, temps de conduite et d'arrêt, vitesses moyenne et maximale.
- **Chronologie cliquable** : elle alterne trajets et arrêts, et indique pour chaque arrêt s'il a eu lieu dans une zone ou hors zone. Elle s'exporte en Excel.
- **Lecteur animé** : lecture et pause (barre d'espace), vitesses x1 à x20 (x1 = 30 s de trajet par seconde), curseur de temps, et une option pour suivre le camion. La partie déjà parcourue est tracée en bleu foncé.

Le stationnement de nuit n'est pas compté comme un arrêt : le trajet est cadré du premier au dernier mouvement. Une période ne peut pas dépasser 3 jours.

## Historique de démonstration

```bash
php artisan fleet:seed-history --days=30   # environ 15 s, relançable sans créer de doublons
php artisan fleet:daily-stats --date=2026-11-19
```

La commande génère, pour chaque camion actif et chaque jour ouvré :
- les trajets réels, avec une position toutes les 30 s en roulant et toutes les 3 min à l'arrêt ;
- les visites de zones et leurs événements ;
- les pointages, avec de rares anomalies ;
- les excès de vitesse ;
- environ 20 % de missions terminées.

Les missions déjà planifiées sont rejouées à leur heure. Les références `MIS-AAAA-00001…` sont ensuite renumérotées dans l'ordre chronologique.

Le job `ComputeDailyStats` remplit `daily_vehicle_stats` (km, conduite, arrêts, vitesses). Il est planifié chaque nuit à 00:15 (heure de Casablanca). `./demo.sh --reset` lance automatiquement la génération de l'historique.

## Tests

```bash
cd backend && ./vendor/bin/pest
```

## Avancement

- [x] Étape 1 : structure, Docker, authentification (verrouillage après 5 échecs, « Se souvenir de moi »), rôles et permissions, layout Vue
- [x] Étape 2 : migrations, modèles, seeders
- [x] Étape 3 : simulateur, temps réel, suivi GPS en direct
- [x] Étape 4 : tableau de bord, camions, chauffeurs (+ espace chauffeur et historique 30 jours)
- [x] Étape 5 : historique et GPS Replay
- [ ] Étape 6 : zones, visites, pointages
- [ ] Étape 7 : missions, rapports, exports, paramètres
- [ ] Étape 8 : tests, README, vérification finale
