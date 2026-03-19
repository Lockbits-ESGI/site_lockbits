# LockBits Website

Site vitrine + espace client (PHP/MySQL) pour LockBits.

## Fonctionnalites

- Landing page moderne (`index.html`)
- Espace client avec authentification:
  - `client/login.php`
  - `client/register.php`
  - `client/dashboard.php`
  - `client/logout.php`
- Base MySQL avec schema EDR simplifie:
  - `client/database.sql`

## Prerequis

- XAMPP (Apache + MySQL + PHP 8+)
- phpMyAdmin (ou client MySQL)

## Installation locale

1. Cloner/copier le projet dans `C:\xampp\htdocs\lockbits`
2. Importer la base:
   - ouvrir phpMyAdmin
   - importer `client/database.sql`
3. Verifier la config DB dans `client/config.php`
4. Lancer Apache + MySQL depuis XAMPP

## URLs utiles

- Site: `http://localhost/lockbits/`
- Login client: `http://localhost/lockbits/client/login.php`
- Inscription: `http://localhost/lockbits/client/register.php`
- Dashboard: `http://localhost/lockbits/client/dashboard.php`

## Donnees EDR stockees

Le schema EDR actuel stocke uniquement:

- Processus (PID, nom, CPU%, RAM%, chemin executable)
- Reseau (connexions actives, ports ouverts, adresses distantes)
- Fichiers suspects
- Utilisateurs connectes/sessions actives
- Systeme (OS, kernel, uptime, hostname)

## Structure rapide

```text
lockbits/
├─ index.html
├─ logo.png
└─ client/
   ├─ auth.php
   ├─ config.php
   ├─ db.php
   ├─ database.sql
   ├─ login.php
   ├─ register.php
   ├─ dashboard.php
   └─ logout.php
```

