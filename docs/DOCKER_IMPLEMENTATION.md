# 🐳 Implementation Docker — Documentation

> **LockBits** — Site vitrine + espace client (PHP/MySQL)  
> Containerisation complète avec Docker & docker-compose  
> *Dernière mise à jour : 19 mars 2026*

---

## 📋 Table des matières

1. [✨ Contexte & Objectifs](#-contexte--objectifs)
2. [🏗️ Architecture Docker](#️-architecture-docker)
3. [📦 Fichiers créés](#-fichiers-créés)
4. [🔄 Fichiers modifiés](#-fichiers-modifiés)
5. [⚙️ Configuration initiale](#️-configuration-initiale)
6. [🚀 Démarrage rapide](#-démarrage-rapide)
7. [🔧 Variables d'environnement](#-variables-denvironnement)
8. [🐛 Dépannage](#-dépannage)
9. [🚢 Pour la production](#-pour-la-production)
10. [🔍 Maintenance & Logs](#-maintenance--logs)
11. [📚 Ressources utiles](#-ressources-utiles)

---

## ✨ Contexte & Objectifs

### Le problème

L'application LockBits était conçue pour fonctionner uniquement en environnement local XAMPP (Apache + MySQL). Cela posait plusieurs défis :

- ❌ **Difficulté de déploiement** : configuration manuelle longue et sujette aux erreurs
- ❌ **Incohérence des environnements** : "Works on my machine" syndrome
- ❌ **Isolation** : pas de séparation des services, risques de conflits
- ❌ **Portabilité** : impossible de déployer facilement sur un serveur

### La solution 🎯

Containeriser l'application avec Docker pour :

- ✅ **Déploiement unifié** : `docker compose up -d` et c'est prêt
- ✅ **Environnements reproductibles** : même config partout (dev / staging / prod)
- ✅ **Isolation des services** : PHP Apache et MySQL dans des conteneurs séparés
- ✅ **Configuration centralisée** : variables d'environnement dans un seul fichier `.env`
- ✅ **Facile à maintenir** : mises à jour, rollback, scaling

---

## 🏗️ Architecture Docker

```
┌─────────────────────────────────────────────────────────┐
│                    Hôte (Votre machine)                 │
│                                                         │
│  ┌───────────────────────────────────────────────────┐ │
│  │           Conteneur lockbits_web (PHP 8.2)       │ │
│  │  ┌─────────────────────────────────────────────┐ │ │
│  │  │ Apache (port 80)                           │ │ │
│  │  │ ├─ index.html                              │ │ │
│  │  │ ├─ client/                                 │ │ │
│  │  │ │  ├─ login.php                           │ │ │
│  │  │ │  ├─ register.php                        │ │ │
│  │  │ │  ├─ dashboard.php                       │ │ │
│  │  │ │  ├─ auth.php                            │ │ │
│  │  │ │  ├─ config.php ⭐ modifié               │ │ │
│  │  │ │  ├─ db.php                              │ │ │
│  │  │ │  └─ ...                                 │ │ │
│  │  │ └─ docker/php.ini (config PHP)            │ │ │
│  │  └─────────────────────────────────────────────┘ │ │
│  │   Variables d'env: DB_HOST=db, DB_USER, etc.     │ │
│  └───────────────────────────────────────────────────┘ │
│                              ↖ publish 8080:80        │
│                                                         │
│  ┌───────────────────────────────────────────────────┐ │
│  │              Conteneur lockbits_db (MySQL 8.0)   │ │
│  │  ┌─────────────────────────────────────────────┐ │ │
│  │  │ /var/lib/mysql (données persistantes)       │ │ │
│  │  │ ├─ lockbits_client (base)                   │ │ │
│  │  │ ├─ tables: users, tickets, edr_*           │ │ │
│  │  └─────────────────────────────────────────────┘ │ │
│  │   Variables d'env: MYSQL_DATABASE, MYSQL_USER   │ │
│  └───────────────────────────────────────────────────┘ │
│                              ↖ publish 3307:3306       │
│                                                         │
│  (Optionnel, profil dev)                               │
│  ┌───────────────────────────────────────────────────┐ │
│  │      Conteneur lockbits_phpmyadmin (phpMyAdmin)  │ │
│  │  Port: 8081 → interface de gestion MySQL         │ │
│  └───────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────┘
```

---

## 📦 Fichiers créés

### 1. `Dockerfile` — Image de l'application PHP

```dockerfile
FROM php:8.2-apache
```

**Extensions PHP activées** (pré-compilées dans l'image de base) :
- `mysqli` & `pdo_mysql` → connexion MySQL
- `gd` → traitement d'images (si besoin futur)
- `zip` → gestion des archives
- `exif` → métadonnées images
- `opcache` → performance (cache d'opcodes)

**Optimisation majeure** : Les extensions sont activées via `docker-php-ext-enable` (instantané) au lieu d'être compilées, ce qui réduit le temps de build de ~13 min à ~2-3 min.

**Configuration Apache :**
- Module `rewrite` activé (URL rewriting)
- `apache2-foreground` comme point d'entrée

**Permissions :**
- `www-data:www-data` propriétaire des fichiers
- Permissions sécurisées (755 dossiers, 644 fichiers PHP)

**Healthcheck :**
- `curl -f http://localhost/` toutes les 30s

---

### 2. `docker-compose.yml` — Orchestration des services

**Services définis :**

#### 🌐 `web` (PHP Apache)
- **Build** : depuis le `Dockerfile`
- **Port** : `8080` (configurable via `WEB_PORT` dans `.env`)
- **Variables d'environnement** : DB, APP, PHP settings
- **Volumes** :
  - `.:/var/www/html` → code en live (développement)
  - `docker/php.ini:/usr/local/etc/php/conf.d/custom.ini` → config PHP
- **Healthcheck** : dépend de `db` (condition: service_healthy)
- **Restart** : `unless-stopped`

#### 🗄️ `db` (MySQL 8.0)
- **Image** : `mysql:8.0` officielle
- **Port** : `3307` (configurable via `DB_EXPOSE_PORT`) → éviter conflit avec MySQL local
- **Variables d'environnement** :
  - `MYSQL_DATABASE` → base créée automatiquement
  - `MYSQL_USER` / `MYSQL_PASSWORD` → utilisateur app
  - `MYSQL_ROOT_PASSWORD` → accès root
- **Volumes** :
  - `mysql_data:/var/lib/mysql` → données persistantes
  - `client/database.sql:/docker-entrypoint-initdb.d/01-schema.sql` → **schéma initial** (automatiquement importé au premier démarrage !)
- **Healthcheck** : `mysqladmin ping`

#### 🌐 `lockbits_network`
- Réseau bridge personnalisé pour communication inter-services

---

### 3. `.env.example` — Template de configuration

**Sections :**

| Variable | Default | Description |
|----------|---------|-------------|
| `WEB_PORT` | `8080` | Port d'accès au site (localhost:WEB_PORT) |
| `DB_HOST` | `db` | Nom du service MySQL dans le réseau Docker |
| `DB_PORT` | `3306` | Port interne MySQL |
| `DB_NAME` | `lockbits_client` | Nom de la base |
| `DB_USER` | `lockbits` | Utilisateur de l'app |
| `DB_PASS` | `lockbits_password` | Mot de passe |
| `DB_ROOT_PASSWORD` | `root_password` | Mot de passe root MySQL |
| `DB_EXPOSE_PORT` | `3307` | Port exposé sur la machine (optionnel) |
| `APP_NAME` | `LockBits Client Area` | Nom de l'app |
| `APP_ENV` | `development` | Environnement (dev / staging / prod) |
| `PHP_MEMORY_LIMIT` | `256M` | Limite mémoire PHP |
| `PHP_UPLOAD_MAX_FILESIZE` | `10M` | Max upload |
| `PHP_POST_MAX_SIZE` | `10M` | Max POST |

**Usage :**
```bash
cp .env.example .env
# Éditer .env selon vos besoins
```

---

### 4. `docker-compose.override.yml` — Profil de développement

**Service ajouté :**
- `phpmyadmin` (port `8081`) → interface web pour gérer la base
  - **Accès** : http://localhost:8081
  - Serveur : `db` (port 3306)
  - Activé avec : `docker compose --profile dev up -d`

---

### 5. `docker/php.ini` — Configuration PHP personnalisée

**Paramètres principaux :**
```ini
memory_limit = 256M
upload_max_filesize = 10M
post_max_size = 10M
max_execution_time = 60
date.timezone = UTC

display_errors = On     ; désactiver en production
error_reporting = E_ALL
```

*Remplacement dynamique* : les variables `${PHP_...}` sont remplacées par Docker Compose à partir du `.env`.

---

## 🔄 Fichiers modifiés

### `client/config.php` — Adaptation aux variables d'environnement

**Avant :**
```php
const DB_HOST = '127.0.0.1';
const DB_NAME = 'lockbits_client';
...
```

**Après :**
```php
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'lockbits_client';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

define('DB_HOST', $dbHost);
define('DB_PORT', $dbPort);
define('DB_NAME', $dbName);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);
```

**Avantages :**
- ✅ **Fonctionne en Docker** : lit `DB_HOST=db` (nom du service)
- ✅ **Fonctionne en XAMPP local** : fallback sur `127.0.0.1` (localhost)
- ✅ **Flexibilité** : tout est dans `.env`, pas besoin d'éditer le code

---

## ⚙️ Configuration initiale

### Étape 1 : Copier le template
```bash
cp .env.example .env
```

### Étape 2 : Personnaliser (optionnel)

Éditez `.env` selon vos besoins :

```env
# Exemple de personnalisation
WEB_PORT=8080          # Accès site : http://localhost:8080
DB_EXPOSE_PORT=3307   # Accès base externe : localhost:3307
APP_ENV=production    # Pour déploiement prod (désactive les erreurs affichées)
PHP_MEMORY_LIMIT=512M # Si besoin de plus de RAM pour PHP
```

> **Note** : Les valeurs par défaut sont généralement suffisantes pour le développement.

---

## 🚀 Démarrage rapide

### Commande de base (sans phpMyAdmin) :
```bash
docker compose up -d
```

### Avec phpMyAdmin (mode développement) :
```bash
docker compose --profile dev up -d
```

### Arrêt complet :
```bash
docker compose down
# Pour supprimer aussi les volumes (données perdues !) :
docker compose down -v
```

### Voir les logs :
```bash
docker compose logs -f          # tous les services
docker compose logs -f web      # seulement PHP
docker compose logs -f db       # seulement MySQL
```

### État des conteneurs :
```bash
docker compose ps
```

---

## 🔧 Variables d'environnement — Détails complets

### Variables accessibles dans PHP (via `getenv()`)

| Variable | Description | Exemple de valeur |
|----------|-------------|-------------------|
| `DB_HOST` | Hôte MySQL (interne Docker) | `db` |
| `DB_PORT` | Port MySQL interne | `3306` |
| `DB_NAME` | Nom de la base | `lockbits_client` |
| `DB_USER` | Utilisateur app (non-root) | `lockbits` |
| `DB_PASS` | Mot de passe utilisateur | `lockbits_password` |
| `APP_NAME` | Nom de l'application | `LockBits Client Area` |
| `APP_ENV` | Environnement | `development` \| `staging` \| `production` |
| `PHP_MEMORY_LIMIT` | Limite mémoire PHP | `256M` |
| `PHP_UPLOAD_MAX_FILESIZE` | Taille max upload | `10M` |
| `PHP_POST_MAX_SIZE` | Taille max POST | `10M` |

### Variables Docker/MySQL internes (non diffusées à PHP)

| Variable | Usage interne |
|----------|---------------|
| `MYSQL_ROOT_PASSWORD` | Mot de passe root MySQL |
| `MYSQL_DATABASE` | Création automatique de la base |
| `MYSQL_USER` / `MYSQL_PASSWORD` | Création utilisateur app |

---

## 🐛 Dépannage

### Problème : MySQL ne démarre pas (port 3307 occupé)

**Solution** : Modifier `.env` :
```env
DB_EXPOSE_PORT=3308
```
Puis `docker compose up -d`

### Problème : L'application ne se connecte pas à la base

**Vérifications :**
1. Conteneur DB en bonne santé ?
   ```bash
   docker compose ps
   # db doit afficher "healthy"
   ```
2. Variables `.env` correctes ?
3. Schéma importé ? (automatique au premier démarrage)

### Problème : Changements dans le code non visibles

**Cause** : Cache navigateur ou opcache.

**Solution** :
```bash
# Redémarrer le conteneur web
docker compose restart web
# Vider le cache navigateur (F12 → Network → Disable cache)
```

### Problème : Permissions des fichiers

**Sur Windows/macOS** : les volumes bind montent parfois avec des permissions incorrectes.

**Solution** : Vérifier que le fichier `client/config.php` est lisible par `www-data` (Apache). Docker Desktop gère généralement bien cela.

---

## 🚢 Pour la production

### Recommandations

| Élément | Configuration recommandée |
|---------|---------------------------|
| `APP_ENV` | `production` |
| `PHP_MEMORY_LIMIT` | `256M` ou selon besoins |
| `display_errors` | `Off` (dans `docker/php.ini`) |
| `PHP_UPLOAD_MAX_FILESIZE` | Adapter selon usage |
| `DB_EXPOSE_PORT` | Ne pas exposer (laisser vide) |
| phpMyAdmin | Ne pas utiliser (`--profile dev` omis) |
| HTTPS | Ajouter un reverse proxy (nginx/Traefik) avec certificat SSL |

### Build multi-stage (optionnel)

Pour réduire la taille de l'image, un `Dockerfile` multi-stage peut être implémenté :
- Stage `builder` : composer install, npm build si nécessaire
- Stage `final` : copier uniquement les fichiers nécessaires

---

## 🚀 CI/CD — GitHub Container Registry

### 🏗️ Workflow GitHub Actions

Un workflow CI/CD est configuré dans `.github/workflows/containerization.yml` pour automatiser la construction et la publication de l'image Docker sur **GitHub Container Registry (GHCR)**.

### 🔄 Ce qui est fait automatiquement

Sur chaque `push` ou `pull_request` sur les branches `main`/`master` :

1. ✅ **Checkout** du code source
2. 🏗️ **Setup Docker Buildx** (multi-plateforme)
3. 🔐 **Login** à GHCR avec le token GITHUB_TOKEN
4. 🏗️ **Build** de l'image Docker
5. 🏷️ **Tagging** intelligent :
   - `ghcr.io/lockbits-esgi/site_lockbits:latest` (branche par défaut)
   - `ghcr.io/lockbits-esgi/site_lockbits:<SHA>` (commit exact)
   - `ghcr.io/lockbits-esgi/site_lockbits:<major>.<minor>` (version semver si tag git)
6. 🚀 **Push** uniquement sur push (pas sur PR)
7. 📦 **Cache** des layers pour accélérer les builds suivants
8. 🏗️ **Multi-plateforme** : linux/amd64, linux/arm64
9. 📝 **Attestation de provenance** (optionnelle)

### 🎯 Avantages

- ✅ **Reproductibilité** : l'image est construite automatiquement à chaque changement
- ✅ **Garantie de qualité** : si le build Docker échoue, le PR/merge est bloqué
- ✅ **Disponibilité** : l'image est publiée pour tous les développeurs via GHCR
- ✅ **Rollback facile** : les tags par SHA permettent de revenir à n'importe quelle version
- ✅ **Pas de secrets supplémentaires** : utilise le GITHUB_TOKEN intégré

### 📥 Utiliser l'image CI/CD

Une fois l'image publiée, n'importe qui peut l'utiliser :

**Avec docker-compose.yml** :
```yaml
services:
  web:
    image: ghcr.io/lockbits-esgi/site_lockbits:latest
    # ... reste de la configuration
```

**En ligne de commande** :
```bash
docker pull ghcr.io/lockbits-esgi/site_lockbits:latest
docker run -d -p 8080:80 ghcr.io/lockbits-esgi/site_lockbits:latest
```

### 🛠️ Personnalisation du workflow

Pour modifier le comportement de la CI/CD, éditez `.github/workflows/containerization.yml`. Vous pouvez ajouter :

- **Étapes de test** : lancer `docker compose up` et vérifier que le site répond (healthcheck)
- **Scan de sécurité** : utilisation de `docker/scout-action` ou `aquasecurity/trivy`
- **Notification** : Slack, Discord, etc.
- **Build d'images plus légères** : multi-stage dans Dockerfile

### 📦 Résumé des fichiers CI/CD

| Fichier | Description |
|---------|-------------|
| `.github/workflows/containerization.yml` | Workflow principal (build + push GHCR) |
| `Dockerfile` | Image de production (utilisée par la CI) |
| `docker-compose.yml` | Orchestration locale (pour tests) |
| `.env.example` | Template de configuration |

---

## 🔍 Maintenance & Logs

### Voir les logs en temps réel
```bash
# Web (PHP/Apache)
docker compose logs -f --tail=50 web

# MySQL
docker compose logs -f db
```

### Accéder à un conteneur en shell
```bash
docker compose exec web bash
docker compose exec db mysql -u lockbits -p
```

### Sauvegarde de la base
```bash
docker compose exec db mysqldump -u lockbits -p lockbits_client > backup_$(date +%F).sql
```

### Restaurer une sauvegarde
```bash
cat backup_2026-03-19.sql | docker compose exec -T db mysql -u lockbits -p lockbits_client
```

### Mise à jour des images
```bash
docker compose pull      # télécharger les nouvelles images
docker compose up -d --build  # reconstruire si Dockerfile modifié
```

### Nettoyage
```bash
docker compose down -v                # supprimer volumes (données perdues)
docker system prune -a                # nettoyer toutes les images/containers inutilisés
docker volume prune                  # supprimer les volumes orphelins
```

---

## 📚 Ressources utiles

- **Docker Compose** : https://docs.docker.com/compose/
- **PHP Docker Image** : https://hub.docker.com/_/php
- **MySQL Docker Image** : https://hub.docker.com/_/mysql
- **phpMyAdmin Docker** : https://hub.docker.com/r/phpmyadmin/phpmyadmin

---

## 🤝 Besoin d'aide ?

- Consultez d'abord la section [Dépannage](#-dépannage)
- Vérifiez les logs (`docker compose logs`)
- Le README principal du projet : `README.md`

---

<p align="center">
  <i>Fait avec ❤️ par l'équipe LockBits — Mars 2026</i>
</p>
