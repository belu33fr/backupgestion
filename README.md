# BackupGestion — Plugin GLPI 11

Plugin de visualisation des sauvegardes multi-provider pour GLPI 11 (Acronis Cyber Protect Cloud en V1).

[![GLPI](https://img.shields.io/badge/GLPI-11.0%2B-blue)](https://glpi-project.org)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-green)](LICENSE)
[![Version](https://img.shields.io/badge/Version-0.1.0--dev-orange)](https://github.com/belu33fr/backupgestion/releases)

## Périmètre V1 (lecture seule)

- Visualisation des espaces de stockage de sauvegarde
- Visualisation des appareils protégés
- Visualisation des plans de sauvegarde
- Volumétrie / statistiques
- Rattachement des sauvegardes et des espaces de stockage aux équipements GLPI, quand c'est identifiable

Le détail complet de l'architecture, du modèle de sécurité (fragmentation des secrets,
dérivation locale de clé HKDF-SHA256) et des jalons de développement est dans
[`docs/BackupGestion_CDC_v1.docx`](docs/BackupGestion_CDC_v1.docx).

## État actuel

**Jalon 1 — Squelette du plugin** : install/uninstall, droits (5 droits indépendants,
CDC 4.6), menu "Outils > Sauvegardes", CRUD minimal du provider (nom + entité
uniquement). Aucune connexion Acronis à ce stade.

Prochaine étape (jalon 2) : hiérarchie des tenants, identifiants API chiffrés
localement, dérivation de clé HKDF-SHA256, intégration Accounts.

## Installation (développement)

```bash
cd /var/glpi/plugins/
git clone https://github.com/belu33fr/backupgestion.git
chown -R www-data:www-data backupgestion/
```

Puis dans GLPI : **Configuration → Plugins → BackupGestion → Installer → Activer**.

## Documentation

- 📖 [Cahier des charges / stratégie de dev / stratégie de test](docs/BackupGestion_CDC_v1.docx)
- 🐛 [Signaler un bug](https://github.com/belu33fr/backupgestion/issues)
