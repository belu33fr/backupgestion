<?php

namespace GlpiPlugin\Backupgestion;

/**
 * Passerelle vers le plugin Accounts (catégorie b — CDC 3.3/4.4).
 *
 * BackupGestion ne réimplémente JAMAIS le chiffrement interne d'Accounts
 * (AccountCrypto) : la création d'un compte Accounts (login/mot de passe,
 * empreinte, clé de chiffrement) se fait toujours via la fiche native
 * d'Accounts, pré-remplie par lien profond depuis buildProviderPrefillQuery().
 * Ce principe de fragmentation (3.3) est respecté à la lettre : aucun secret
 * de catégorie (b) ne transite jamais par le code de BackupGestion.
 *
 * Ce que cette classe fait, en revanche, en toute sécurité :
 *  - vérifier la disponibilité du plugin Accounts ;
 *  - lister les empreintes/types/statuts existants pour peupler la zone de
 *    configuration "Valeurs par défaut Accounts" de la fiche provider ;
 *  - construire l'URL de pré-remplissage vers la fiche d'ajout Accounts ;
 *  - lier (Account_Item) un compte Accounts déjà créé au provider concerné —
 *    une simple ligne de table de liaison, sans aucune donnée sensible.
 */
class AccountsVault
{
    public static function isAvailable(): bool
    {
        return class_exists('\GlpiPlugin\Accounts\Account');
    }

    // ------------------------------------------------------------------
    // Listes de référence (lecture seule, aucune donnée sensible)
    // ------------------------------------------------------------------

    /**
     * Empreintes Accounts disponibles pour une entité donnée (et ses parentes,
     * comme le fait Accounts nativement — une empreinte est définie par entité).
     */
    public static function listHashes(int $entities_id): array
    {
        return self::listDropdownRows('glpi_plugin_accounts_hashes', $entities_id);
    }

    public static function listAccountTypes(int $entities_id): array
    {
        return self::listDropdownRows('glpi_plugin_accounts_accounttypes', $entities_id);
    }

    public static function listAccountStates(int $entities_id): array
    {
        return self::listDropdownRows('glpi_plugin_accounts_accountstates', $entities_id);
    }

    private static function listDropdownRows(string $table, int $entities_id): array
    {
        global $DB;

        if (!self::isAvailable() || !$DB->tableExists($table)) {
            return [];
        }

        $where = [];
        if ($DB->fieldExists($table, 'entities_id')) {
            // Reprend le fonctionnement natif GLPI : l'entité demandée + ses parentes
            // (une empreinte/un type définis sur une entité racine restent visibles
            // pour ses sous-entités), plus l'entité racine (0, portée globale).
            $ancestors = class_exists('\Entity') ? \Entity::getAncestorsOf($entities_id) : [];
            $where['entities_id'] = array_unique(array_merge([0, $entities_id], array_values($ancestors)));
        }

        $rows = [];
        foreach ($DB->request(['FROM' => $table, 'WHERE' => $where, 'ORDER' => 'name ASC']) as $row) {
            $rows[(int)$row['id']] = $row['name'] !== '' ? $row['name'] : ('#' . $row['id']);
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // Lien profond vers la fiche d'ajout Accounts, pré-remplie
    // ------------------------------------------------------------------

    /**
     * Construit l'URL de la fiche "Ajouter un compte" d'Accounts, pré-remplie
     * avec les "Valeurs par défaut Accounts" du provider (CDC 4.4 bis) — jamais
     * le login ou le mot de passe, qui restent toujours saisis à la main dans
     * Accounts lui-même. Retourne '' si le plugin Accounts est absent.
     */
    public static function buildAdminAccountAddUrl(Provider $provider): string
    {
        if (!self::isAvailable()) {
            return '';
        }

        $params = [
            'name'                             => sprintf(__('[Sauvegarde] Admin %s', 'backupgestion'), $provider->fields['name'] ?? ''),
            'entities_id'                      => (int)($provider->fields['entities_id'] ?? 0),
            'is_recursive'                     => (int)($provider->fields['is_recursive'] ?? 0),
        ];

        $map = [
            'plugin_accounts_hashes_id'        => 'accounts_hash_id',
            'plugin_accounts_accounttypes_id'  => 'accounts_accounttype_id',
            'plugin_accounts_accountstates_id' => 'accounts_accountstates_id',
            'users_id'                         => 'accounts_users_id',
            'users_id_tech'                    => 'accounts_users_id_tech',
            'groups_id'                        => 'accounts_groups_id',
            'groups_id_tech'                   => 'accounts_groups_id_tech',
            'is_helpdesk_visible'              => 'accounts_is_helpdesk_visible',
        ];
        foreach ($map as $accountsField => $providerField) {
            $value = (int)($provider->fields[$providerField] ?? 0);
            if ($value > 0) {
                $params[$accountsField] = $value;
            }
        }

        $formUrl = \Plugin::getWebDir('accounts', true) . '/front/account.form.php';
        return $formUrl . '?' . http_build_query($params);
    }

    // ------------------------------------------------------------------
    // Association Account_Item (aucune donnée sensible — simple ligne de liaison)
    // ------------------------------------------------------------------

    /**
     * Relie un compte Accounts déjà créé (catégorie b) à un item GLPI (typiquement
     * ce Provider) via le mécanisme d'association standard d'Accounts.
     */
    public static function linkToItem(int $accountId, string $itemtype, int $itemsId): bool
    {
        if (!self::isAvailable() || !class_exists('\GlpiPlugin\Accounts\Account_Item')) {
            throw new \RuntimeException(__('Le plugin Accounts n\'est pas disponible.', 'backupgestion'));
        }

        $account = new \GlpiPlugin\Accounts\Account();
        if (!$account->getFromDB($accountId)) {
            throw new \RuntimeException(__('Compte Accounts introuvable.', 'backupgestion'));
        }

        $link = new \GlpiPlugin\Accounts\Account_Item();
        $newID = $link->add([
            'plugin_accounts_accounts_id' => $accountId,
            'itemtype'                    => $itemtype,
            'items_id'                    => $itemsId,
        ]);

        return (bool)$newID;
    }

    // ------------------------------------------------------------------
    // Provisionnement à l'installation (best-effort — CDC 4.4)
    // ------------------------------------------------------------------

    /**
     * Crée le type de compte Accounts dédié à "Admin compte client Acronis" s'il
     * n'existe pas déjà (comparaison par nom). Best-effort : toute erreur ici ne
     * doit jamais empêcher l'installation/activation de BackupGestion — appelée
     * depuis hook.php dans un try/catch.
     */
    public static function provisionDefaultAccountType(): void
    {
        global $DB;

        $table = 'glpi_plugin_accounts_accounttypes';
        if (!$DB->tableExists($table) || !$DB->fieldExists($table, 'name')) {
            return;
        }

        $name = __('BackupGestion — Admin compte client Acronis', 'backupgestion');

        $existing = $DB->request(['FROM' => $table, 'WHERE' => ['name' => $name]])->current();
        if ($existing) {
            return;
        }

        $insert = ['name' => $name];
        if ($DB->fieldExists($table, 'comment')) {
            $insert['comment'] = __('Créé automatiquement par le plugin BackupGestion — CDC 4.4.', 'backupgestion');
        }
        if ($DB->fieldExists($table, 'entities_id')) {
            $insert['entities_id'] = 0;
        }
        if ($DB->fieldExists($table, 'is_recursive')) {
            $insert['is_recursive'] = 1;
        }
        if ($DB->fieldExists($table, 'date_creation')) {
            $insert['date_creation'] = date('Y-m-d H:i:s');
        }
        if ($DB->fieldExists($table, 'date_mod')) {
            $insert['date_mod'] = date('Y-m-d H:i:s');
        }

        $DB->insert($table, $insert);
    }
}
