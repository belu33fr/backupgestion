<?php

namespace GlpiPlugin\Backupgestion;

use CommonDBTM;

/**
 * Liaison espace de stockage <-> compte Accounts (0..N), avec un rôle libre
 * ("login", "chiffrement"…) — CDC : "permet de gérer plusieurs comptes Accounts
 * pour un même espace de stockage (ex. S3 : un compte d'accès + un compte de
 * chiffrement)". Table interne, jamais exposée en menu/recherche propre : gérée
 * directement depuis la fiche StorageSpace.
 */
class StorageAccount extends CommonDBTM
{
    public static $rightname = 'plugin_backupgestion_provider';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_backupgestion_storage_accounts';
    }

    public static function linkAccount(int $storageId, string $role, int $accountId): int
    {
        $existing = new self();
        if ($existing->getFromDBByCrit([
            'backupgestion_storages_id'   => $storageId,
            'role'                        => $role,
            'plugin_accounts_accounts_id' => $accountId,
        ])) {
            return (int)$existing->fields['id'];
        }

        $new = new self();
        $id  = $new->add([
            'backupgestion_storages_id'   => $storageId,
            'role'                        => $role,
            'plugin_accounts_accounts_id' => $accountId,
        ]);

        return (int)$id;
    }

    /** @return array<int, array{id:int, role:string, plugin_accounts_accounts_id:int}> */
    public static function getForStorage(int $storageId): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['backupgestion_storages_id' => $storageId]]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function deleteForStorage(int $storageId): void
    {
        global $DB;
        $DB->delete(self::getTable(), ['backupgestion_storages_id' => $storageId]);
    }
}
