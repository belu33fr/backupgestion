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

    /**
     * Enregistre qu'un appareil a bien été vu lors de ce passage de la tâche de
     * détection (CDC 4.7) : crée le rattachement (statut "pending", en attente de
     * mapping manuel ou de matcher — jalon 4) s'il n'existait pas encore pour cette
     * identité réelle (acronis_tenant_id, provider_ref), sinon se contente de
     * rafraîchir last_checked_at sans toucher à un éventuel rattachement déjà décidé
     * (auto/manual/ignored) — la détection ne doit jamais écraser un choix humain.
     *
     * @return array{id:int, created:bool}
     */
    public static function recordSeen(string $acronisTenantId, string $providerRef): array
    {
        $link = new self();
        if ($link->getFromDBByCrit(['acronis_tenant_id' => $acronisTenantId, 'provider_ref' => $providerRef])) {
            $link->update([
                'id'              => $link->fields['id'],
                'last_checked_at' => date('Y-m-d H:i:s'),
            ]);
            return ['id' => (int)$link->fields['id'], 'created' => false];
        }

        $new = new self();
        $id  = $new->add([
            'acronis_tenant_id' => $acronisTenantId,
            'provider_ref'      => $providerRef,
            'match_status'      => self::STATUS_PENDING,
            'last_checked_at'   => date('Y-m-d H:i:s'),
        ]);
        return ['id' => (int)$id, 'created' => true];
    }

    /** Nombre de rattachements dans un statut donné — utilisé par la vue de synthèse. */
    public static function countByStatus(string $status): int
    {
        return countElementsInTable(self::getTable(), ['match_status' => $status]);
    }
}
