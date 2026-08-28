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

    /** Enregistre le résultat d'un passage de la tâche de détection pour un provider. */
    public static function logRun(int $providersId, string $status, int $found, int $matched, int $pending, string $errors = ''): int
    {
        $log = new self();
        return (int)$log->add([
            'providers_id' => $providersId,
            'status'       => $status,
            'found'        => $found,
            'matched'      => $matched,
            'pending'      => $pending,
            'errors'       => $errors,
        ]);
    }

    /** Dernières entrées du journal, toutes providers confondus — vue de synthèse. */
    public static function getRecent(int $limit = 10): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'ORDER' => 'date_creation DESC',
            'LIMIT' => $limit,
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }
}
