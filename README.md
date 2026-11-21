# FleetTrack

Application de **démonstration** pour gérer une flotte de camions et la suivre par GPS. Il n'y a pas de vrais boîtiers : un simulateur intégré produit les positions GPS et les passages de badge autour de Casablanca.

- **Backend** : Laravel 13 (PHP 8.3), API REST JSON, Sanctum (authentification SPA par cookie), Reverb et Echo (temps réel), queue `database`, scheduler, spatie/laravel-permission, laravel-excel, dompdf.
- **Frontend** : Vue 3 (`<script setup>`, TypeScript), Vite, Vue Router, Pinia, Axios, Tailwind CSS 4, Leaflet, Leaflet-Geoman, Chart.js (vue-chartjs), icônes Lucide.
- **Base de données** : MariaDB 11 (InnoDB, utf8mb4). Les dates sont stockées en UTC et affichées dans le fuseau Africa/Casablanca.
- **Interface** entièrement en français. Distances en km, vitesses en km/h.

```
backend/          API Laravel (app/, database/, routes/, tests/)
frontend/         SPA Vue 3 (src/views, src/components, src/stores, src/lib)
docker/           images PHP-FPM et nginx
docs/maquettes/   maquettes HTML des 13 écrans
demo.sh           lancement de toute la démo en une commande (sans Docker)
```

## Démarrage rapide

**Sans Docker**, avec PHP, Composer, Node et MariaDB installés en local :

```bash
./demo.sh --reset   # base remise à zéro, 30 jours d'historique, puis lancement de tous les services
./demo.sh           # relance sans toucher aux données
```

La commande lance MariaDB, l'API, Reverb, la file d'attente, le scheduler, le simulateur GPS et le front. L'application s'ouvre sur **http://localhost:5173**. `Ctrl+C` arrête tout. Les journaux sont dans `.logs/`.

**Avec Docker** :

```bash
docker compose up -d --build
```

L'application est ensuite sur **http://localhost:8080** (détails plus bas).

## Comptes de démo

Le mot de passe de tous les comptes est `password`. Sur l'écran de connexion, un clic sur un compte remplit les champs.

| Rôle | E-mail | Droits |
|---|---|---|
| Super Administrateur | superadmin@demo.ma | Tous les droits |
| Administrateur | admin@demo.ma | Tous les droits, sauf la gestion des super administrateurs |
| Responsable de flotte | flotte@demo.ma | Camions, cartes GPS, chauffeurs, zones, missions, rapports. Pas d'utilisateurs ni de paramètres |
| Opérateur | operateur@demo.ma | Lecture partout et missions (créer, démarrer, terminer). Pas de suppression ni d'export |
| Chauffeur | chauffeur@demo.ma | Écran « Mon service » uniquement. Compte lié à Jamal Ouazzani (CH-1096, badge B-4446) |

Après 5 échecs de connexion d'affilée, le compte est verrouillé 15 minutes. Un administrateur peut le déverrouiller depuis Paramètres, puis Utilisateurs.

## Écrans

| Écran | Contenu |
|---|---|
| **Connexion** | E-mail et mot de passe, « Se souvenir de moi », verrouillage après 5 échecs |
| **Tableau de bord** | 7 indicateurs en direct, carte de la flotte, dernières activités, km sur 7 jours, top 5 du jour |
| **Suivi temps réel** | Liste filtrable, carte avec marqueurs orientés selon le cap et déplacement animé, zones, panneau de détail (vitesse, cap, adresse, chauffeur en service, mission, journée), boutons Suivre, Historique et Fiche |
| **Historique / GPS Replay** | Camion et période, tracé avec départ A, arrivée B et arrêts numérotés, synthèse, chronologie cliquable, lecteur animé x1 à x20 (x1 = 30 s de trajet par seconde), export Excel |
| **Camions** | Création, modification et suppression (avec boîtier GPS et chauffeur affecté), filtres, tri et pagination côté serveur, export Excel |
| **Fiche camion** | Mini-carte en direct, indicateurs sur 30 jours, onglets Trajets (bouton « Rejouer »), Visites, Missions, Affectations, Boîtier |
| **Cartes GPS** | Création, modification et suppression des cartes (IMEI, SIM, opérateur, camion où elle est installée), filtres Installées / Libres / En service / Désactivées, et **configuration de chaque carte** : protocole, serveur et port, APN, cadence d'envoi en mouvement et à l'arrêt, changement de cap, distance, seuil de survitesse, contact moteur, conduite brusque |
| **Chauffeurs** | Création, modification et suppression, alertes de permis, statut en service, « Suivre sur la carte », export Excel. L'historique d'un chauffeur liste ses services et le trajet de chacun |
| **Pointages** | Prises et fins de service (badge ou application), anomalies en rouge, flux des badges en direct, export Excel |
| **Missions** | Compteurs par statut, liste, détail avec journal d'événements, actions Démarrer, Terminer et Annuler, commentaires, export Excel |
| **Zones** | Liste, carte où l'on dessine un cercle ou un polygone (Geoman) et où l'on ajuste la zone à la souris, formulaire |
| **Visites d'une zone** | Indicateurs (visites, durée moyenne et médiane, temps total, camions distincts), temps par camion, visites en cours en direct, export PDF et Excel |
| **Rapports** | Kilométrage (jour, semaine, mois), conduite et arrêt, temps chez les clients, visites par zone, activité par chauffeur, activité par camion. Chaque rapport a un graphique, un tableau et un export PDF et Excel |
| **Paramètres** | Seuils (mouvement, hors ligne, arrêt), état du simulateur (pause et reprise), utilisateurs et rôles, journal d'audit |
| **Mon service** (chauffeur) | Pointer l'entrée en choisissant un camion puis une carte GPS, suivi de son camion sur la carte, pointer la sortie, ses derniers services avec leur trajet |

La cloche de la barre du haut reçoit les **notifications en temps réel** : entrées et sorties de zone, survitesse, perte de signal, anomalies de pointage. Tous les écrans s'affichent correctement sur téléphone, sans défilement horizontal.

## Règles métier

- **Statuts GPS** :
  - en mouvement au-delà de 5 km/h ;
  - hors ligne sans position depuis 10 min ;
  - un arrêt compte à partir de 5 min.
  - Ces 3 seuils se modifient dans Paramètres.
- **Zones** :
  - anti-rebond : une visite n'est comptée que si le camion reste dans la zone au moins `min_visit_seconds` ;
  - en sortie, une marge de 25 m absorbe l'imprécision du GPS ;
  - les entrées et sorties créent des `gps_events`, des notifications et des lignes dans le journal de la mission concernée.
- **Pointage** :
  - le badge d'entrée ouvre un service (chauffeur, camion, heure, lieu du boîtier) ;
  - le badge de sortie le clôture et calcule la durée et les km ;
  - anomalies : **prise hors dépôt**, **badge inconnu**, **sortie non pointée** (camion revenu au dépôt et moteur coupé depuis 15 min sans badge de sortie).
- **Missions** : en attente, puis en cours, puis terminée, ou bien annulée (motif obligatoire). **Un camion ou un chauffeur n'a jamais plus d'une mission en cours** : un verrou en base, posé au démarrage, le garantit.
- **Cartes GPS** :
  - une carte est installée sur au plus un camion ; installer une carte sur un camion retire l'ancienne carte de ce camion ;
  - une carte en service (prise par un chauffeur) ne peut être ni déplacée, ni désactivée, ni supprimée ;
  - le seuil de survitesse de la carte, s'il est renseigné, remplace la vitesse maximale du camion pour les alertes.
- **Espace chauffeur** :
  - le chauffeur pointe depuis son téléphone sur un camion à l'arrêt, en choisissant une carte GPS : celle du camion, ou une carte libre qui est alors installée sur le camion ;
  - dès ce moment, le boîtier du camion suit ce chauffeur, et le suivi comme l'historique affichent son nom ;
  - le simulateur continue de faire rouler ce camion, mais ne pointe jamais à la place du chauffeur.

## Simulateur GPS

```bash
php artisan fleet:simulate              # une position par camion toutes les 5 s
php artisan fleet:simulate --speed=3    # temps accéléré x3 (trajets et arrêts plus courts)
php artisan fleet:simulate --paused     # démarre en pause (reprise depuis Paramètres)
php artisan fleet:seed-history --days=30   # 30 jours d'historique cohérent, environ 15 s
php artisan fleet:daily-stats --date=2026-11-19
php artisan fleet:check-status          # hors ligne et sorties non pointées (planifié chaque minute)
```

- **Itinéraires** : les 11 camions actifs roulent sur 9 trajets routiers réels autour de Casablanca (tracés OSRM simplifiés, de 30 à 60 points, codés en dur dans `app/Simulation/RouteBook.php`).
- **Cycle d'un camion** :
  1. Badge d'entrée au dépôt.
  2. 1 à 3 trajets vers les clients, l'usine, le chantier ou le port, avec des arrêts de 5 à 60 min sur place.
  3. Badge de sortie, puis pause.

  Une mission en cours impose son départ et sa destination.
- **Traitement de chaque position** :
  1. enregistrement dans `positions` ;
  2. mise à jour de `vehicle_live_status` ;
  3. détection des zones ;
  4. diffusion via Reverb sur le canal privé `fleet` (événements `position.updated` et `activity`).
- **Événements simulés** :
  - excès de vitesse ;
  - perte de signal d'environ 15 min, à peu près toutes les 45 min ;
  - au premier lancement, une prise hors dépôt (CAM-15) et une sortie non pointée (CAM-21) ;
  - plus rarement, un badge inconnu.
- **Historique** : `fleet:seed-history` rejoue les mêmes itinéraires sur les jours passés, avec les visites, pointages, missions et excès de vitesse correspondants. Il peut être relancé sans créer de doublons. Le job `ComputeDailyStats` remplit `daily_vehicle_stats` chaque nuit à 00:15 (heure de Casablanca).

## Données de démo

`php artisan migrate:fresh --seed` crée :

- 5 utilisateurs, un par rôle ;
- 12 camions (de CAM-01 à CAM-24), chacun avec sa carte GPS (GPS-01 à GPS-12). CAM-24 est en maintenance ;
- 3 cartes de rechange : GPS-13 et GPS-14 libres, GPS-15 désactivée (en réparation) ;
- 14 chauffeurs avec matricule (CH-1012…) et badge (B-4410…). Un permis expire bientôt, un autre est déjà expiré ;
- 4 clients et 8 zones : le dépôt Aïn Sebaâ, l'usine de Mohammedia, 4 clients, le chantier de Lissasfa et la zone de livraison du port ;
- des missions dans les 4 statuts. Les missions du jour sont calées sur l'heure du chargement.

## Lancement avec Docker

```bash
docker compose up -d --build     # http://localhost:8080
```

Au premier démarrage, le conteneur `app` installe les dépendances Composer, applique les migrations, remplit la base et génère 30 jours d'historique.

| Service | Rôle |
|---|---|
| `nginx` | Sert le front compilé et relaie `/api`, `/sanctum` et `/broadcasting` vers PHP-FPM, et `/app` vers Reverb (WebSocket) |
| `app` | PHP-FPM 8.3 (API Laravel) |
| `mariadb` | MariaDB 11, exposé sur le port 3307 de la machine hôte |
| `reverb` | Serveur WebSocket Laravel Reverb |
| `queue` | `php artisan queue:work` |
| `scheduler` | `php artisan schedule:work` (statuts chaque minute, statistiques chaque nuit) |
| `simulator` | `php artisan fleet:simulate` |

```bash
docker compose exec app php artisan migrate:fresh --seed && docker compose exec app php artisan fleet:seed-history
docker compose exec app ./vendor/bin/pest
docker compose logs -f simulator reverb
```

## Lancement sans Docker, étape par étape

Prérequis : PHP 8.3 ou plus récent (avec pdo_mysql, intl, gd, zip, bcmath et pcntl), Composer 2, Node 20 ou plus récent, MariaDB 11.

```sql
CREATE DATABASE fleettrack CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE fleettrack_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'fleettrack'@'127.0.0.1' IDENTIFIED BY 'fleettrack';
GRANT ALL ON fleettrack.* TO 'fleettrack'@'127.0.0.1';
GRANT ALL ON fleettrack_test.* TO 'fleettrack'@'127.0.0.1';
```

```bash
cd backend && composer install && cp .env.example .env && php artisan key:generate
php artisan migrate --seed && php artisan fleet:seed-history --days=30
cd ../frontend && npm install && cp .env.example .env
```

Ensuite, un terminal par processus :

```bash
cd backend  && php artisan serve --port=8000             # API
cd backend  && php artisan reverb:start --port=8081      # WebSocket
cd backend  && php artisan queue:work                    # file d'attente
cd backend  && php artisan schedule:work                 # tâches planifiées
cd backend  && php artisan fleet:simulate                # simulateur GPS
cd frontend && npm run dev                               # http://localhost:5173
```

En développement, Vite relaie `/api`, `/sanctum`, `/broadcasting` (vers le port 8000) et `/app` (WebSocket, vers le port 8081). Le front et l'API sont donc sur la même origine : le cookie de session Sanctum fonctionne sans configuration CORS.

## Architecture

```
backend/app
├── Enums/                  statuts et types, avec libellés français (VehicleStatus, MissionStatus, ShiftAnomaly…)
├── Models/                 17 modèles Eloquent
├── Policies/               Vehicle, Driver, Geofence, Mission, User : droits par rôle
├── Http/Controllers/Api/   un contrôleur par écran (Live, History, Vehicle, Driver, Shift, Geofence, ZoneVisit, Mission, Report, Settings, User…)
├── Http/Requests/          validation (Form Requests) avec messages en français
├── Http/Resources/         réponses JSON (API Resources)
├── Services/Tracking/      ingestion des positions, détection des zones, pointages, statuts, analyse des trajets, historique
├── Services/Fleet/         règles métier : camions (boîtier, affectations), missions (cycle de vie)
├── Services/Reports/       les 6 rapports
├── Simulation/             simulateur temps réel, générateur d'historique, itinéraires
├── Events/                 PositionUpdated, FleetActivity (Reverb)
└── Exports/                export Excel générique (protégé contre l'injection de formules)

frontend/src
├── views/                  un écran par fichier
├── components/             AppLayout, StatCard, StatusBadge, DataTable, MapView, ConfirmDialog, Modal, formulaires
├── stores/                 auth, fleet (état en direct + Reverb), notifications
└── lib/                    client HTTP, Echo, formats (Casablanca), navigation, types
```

**Sécurité** :
- authentification Sanctum par cookie, avec CSRF ;
- verrouillage du compte après 5 échecs, et limite de débit sur la connexion ;
- toutes les routes de l'API sont protégées par une permission ou une Policy ;
- le canal temps réel `fleet` est privé (permission `tracking.view`) ;
- un journal d'audit enregistre les créations, modifications, suppressions et changements de paramètres.

## Tests

```bash
cd backend && ./vendor/bin/pest      # 73 tests
```

| Domaine | Ce qui est vérifié |
|---|---|
| Visites de zones | Durée calculée à la sortie, anti-rebond (un passage court n'est pas compté), marge de sortie, point dans un cercle ou un polygone |
| Pointages | Entrée et sortie (durée, km, lieu), prise hors dépôt, badge inconnu, sortie non pointée puis clôture au badge suivant, espace chauffeur |
| Droits par rôle | **Matrice complète : les 63 routes protégées × 5 rôles** (403 exactement là où le rôle n'a pas le droit), 401 sans connexion, règles Administrateur et Super Administrateur |
| Missions | Démarrer, terminer, annuler, une seule mission en cours par camion et par chauffeur, transitions interdites, validations |
| Le reste | Connexion et verrouillage, CRUD camions, chauffeurs et zones, historique et replay, rapports et exports PDF et Excel, paramètres, génération d'historique, protection des exports Excel |

## Limites connues

- **Démo uniquement** : il n'y a pas de vrai serveur GPS (l'onglet Traccar de la maquette n'est pas implémenté), ni de purge ou de sauvegarde automatique des données.
- **Veille de l'ordinateur** : le simulateur tourne en temps réel. Si la machine se met en veille, les camions s'arrêtent, et des services ou des missions peuvent sembler anormalement longs. `./demo.sh --reset` repart d'une démo propre.
- **Fuseau horaire** : PHP et Node embarquent une base de fuseaux de 2025 qui place Casablanca à UTC+1, alors que macOS et Chrome, plus récents, peuvent indiquer UTC+0. L'affichage suit le navigateur. Seules les bornes « minuit » des calculs serveur peuvent être décalées d'une heure.
- La recherche globale de la barre du haut n'est pas branchée : chaque écran a sa propre recherche.

## Avancement

- [x] Étape 1 : structure, Docker, authentification, rôles, layout Vue
- [x] Étape 2 : migrations, modèles, seeders
- [x] Étape 3 : simulateur, temps réel (Reverb), suivi GPS en direct
- [x] Étape 4 : tableau de bord, camions, chauffeurs, espace chauffeur, historique sur 30 jours
- [x] Étape 5 : historique et GPS Replay
- [x] Étape 6 : zones, visites, pointages
- [x] Étape 7 : missions, rapports, exports, paramètres
- [x] Étape 8 : tests (matrice des droits), README, vérification finale (mobile, sécurité des exports, Docker)
