<?php

namespace GlpiPlugin\Backupgestion;

use CommonDBTM;

/**
 * Rattachement léger appareil/stockage Acronis natif <-> équipement GLPI (CDC 2.1) —
 * aucune donnée descriptive mirrorée, seul le résultat du rattachement est persisté.
 * Dédupliqué par identité réelle côté Acronis (acronis_tenant_id + provider_ref),
 * jamais par providers_id local (CDC 4.2 ter) : un même appareil réel garde le même
 * lien quel que soit le provider qui l'a détecté.
 *
 * Squelette posé au jalon 3 (schéma + table) ; alimenté par la tâche périodique de
 * détection (CDC 4.7, jalon 3) et exploité par le Matcher / écran de mapping manuel
 * (CDC 4.5, jalon 4).
 */
class DeviceLink extends CommonDBTM
{
    public static $rightname = 'plugin_backupgestion_provider';

    public const STATUS_PENDING = 'pending';
    public const STATUS_AUTO    = 'auto';
    public const STATUS_MANUAL  = 'manual';
    public const STATUS_IGNORED = 'ignored';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_backupgestion_device_links';
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Rattachement appareil', 'Rattachements appareils', $nb, 'backupgestion');
    }
}
