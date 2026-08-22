<?php

namespace GlpiPlugin\Backupgestion;

use CommonDBTM;

/**
 * Journal des exécutions de la tâche périodique de détection de rattachement
 * (CDC 4.7) — seul traitement de fond du plugin. Squelette posé au jalon 3
 * (schéma + table) ; alimenté par la tâche CronTask elle-même.
 */
class DiscoveryLog extends CommonDBTM
{
    public static $rightname = 'plugin_backupgestion_provider';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_backupgestion_discovery_logs';
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Journal de détection', 'Journaux de détection', $nb, 'backupgestion');
    }
}
