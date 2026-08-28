<?php

use GlpiPlugin\Backupgestion\Credential;
use GlpiPlugin\Backupgestion\Provider;
use GlpiPlugin\Backupgestion\Right;

function plugin_backupgestion_install(): bool
{
    global $DB;

    $default_charset   = \DBConnection::getDefaultCharset();
    $default_collation = \DBConnection::getDefaultCollation();

    $migration = new \Migration(PLUGIN_BACKUPGESTION_VERSION);

    // Table des comptes provider
    $table = Provider::getTable();
    if (!$DB->tableExists($table)) {
        $DB->doQuery("
            CREATE TABLE `$table` (
                `id`                                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`                                 VARCHAR(255) NOT NULL DEFAULT '',
                `entities_id`                          INT UNSIGNED NOT NULL DEFAULT 0,
                `is_recursive`                         TINYINT(1) NOT NULL DEFAULT 0,
                `comment`                               TEXT,
                `provider_type`                        VARCHAR(50) NOT NULL DEFAULT 'acronis',
                `backupgestion_providers_id_parent`    INT UNSIGNED NOT NULL DEFAULT 0,
                `acronis_tenant_id`                    VARCHAR(255) NOT NULL DEFAULT '',
                `connection_failure_since`              DATETIME DEFAULT NULL,
                `connection_failure_days`               INT UNSIGNED NOT NULL DEFAULT 0,
                `failure_threshold_days`                INT UNSIGNED NOT NULL DEFAULT 3,
                `key_salt`                              VARCHAR(255) NOT NULL DEFAULT '',
                `users_id_keyowner`                     INT UNSIGNED NOT NULL DEFAULT 0,
                `keyowner_name`                         VARCHAR(255) NOT NULL DEFAULT '',
                `keyowner_email`                        VARCHAR(255) NOT NULL DEFAULT '',
                `entity_name_snapshot`                  VARCHAR(255) NOT NULL DEFAULT '',
                `date_creation`                          DATETIME DEFAULT NULL,
                `date_mod`                               DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `backupgestion_providers_id_parent` (`backupgestion_providers_id_parent`),
                KEY `acronis_tenant_id` (`acronis_tenant_id`)
            ) ENGINE=InnoDB
            DEFAULT CHARSET={$default_charset}
            COLLATE={$default_collation}
        ");
    }

    // Table des identifiants API chiffrés (catégorie a — CDC 3.3/4.2).
    // La clé qui protège ces valeurs n'est JAMAIS stockée ici ni ailleurs : elle est
    // recalculée à la volée par KeyDerivation à partir des colonnes ci-dessus (CDC 4.4 quater).
    $credTable = Credential::getTable();
    if (!$DB->tableExists($credTable)) {
        $DB->doQuery("
            CREATE TABLE `$credTable` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `providers_id` INT UNSIGNED NOT NULL,
                `cred_key`    VARCHAR(100) NOT NULL DEFAULT '',
                `cred_value`  TEXT NOT NULL,
                `date_mod`    DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_provider_key` (`providers_id`, `cred_key`)
            ) ENGINE=InnoDB
            DEFAULT CHARSET={$default_charset}
            COLLATE={$default_collation}
        ");
    }

    // Initialiser les droits par défaut (5 droits — CDC 4.6)
    Right::addDefaultProfileRights();

    $migration->executeMigration();
    plugin_backupgestion_migrate();

    return true;
}

function plugin_backupgestion_uninstall(): bool
{
    global $DB;

    $tables = [
        Credential::getTable(),
        Provider::getTable(),
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    // Supprimer les droits — ne touche JAMAIS au plugin Accounts (principe de
    // fragmentation, CDC 3.3) : aucune table/compte Accounts n'est référencée ici.
    Right::removeProfileRights();

    return true;
}

/**
 * Appelée à chaque activation du plugin — applique les migrations.
 */
function plugin_backupgestion_activate(): void
{
    plugin_backupgestion_migrate();
    Right::addDefaultProfileRights();
}

/**
 * Migrations de schéma cumulatives.
 * Chaque colonne est ajoutée seulement si absente — permet de faire évoluer une
 * installation existante (jalon 1 → 2) sans réinstaller le plugin, exactement
 * comme dans DNSManager.
 */
function plugin_backupgestion_migrate(): void
{
    global $DB;

    $default_charset   = \DBConnection::getDefaultCharset();
    $default_collation = \DBConnection::getDefaultCollation();

    $providerTable = Provider::getTable();
    if ($DB->tableExists($providerTable)) {
        // v0.2.0 (jalon 2) — hiérarchie de tenants, identité réelle du tenant,
        // suivi des échecs de connexion, dérivation de clé locale (CDC 4.2/4.4 quater).
        $cols = [
            'provider_type'                     => "ALTER TABLE `$providerTable` ADD COLUMN `provider_type` VARCHAR(50) NOT NULL DEFAULT 'acronis' AFTER `comment`",
            'backupgestion_providers_id_parent'  => "ALTER TABLE `$providerTable` ADD COLUMN `backupgestion_providers_id_parent` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `provider_type`",
            'acronis_tenant_id'                  => "ALTER TABLE `$providerTable` ADD COLUMN `acronis_tenant_id` VARCHAR(255) NOT NULL DEFAULT '' AFTER `backupgestion_providers_id_parent`",
            'connection_failure_since'           => "ALTER TABLE `$providerTable` ADD COLUMN `connection_failure_since` DATETIME DEFAULT NULL AFTER `acronis_tenant_id`",
            'connection_failure_days'            => "ALTER TABLE `$providerTable` ADD COLUMN `connection_failure_days` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `connection_failure_since`",
            'failure_threshold_days'             => "ALTER TABLE `$providerTable` ADD COLUMN `failure_threshold_days` INT UNSIGNED NOT NULL DEFAULT 3 AFTER `connection_failure_days`",
            'key_salt'                           => "ALTER TABLE `$providerTable` ADD COLUMN `key_salt` VARCHAR(255) NOT NULL DEFAULT '' AFTER `failure_threshold_days`",
            'users_id_keyowner'                  => "ALTER TABLE `$providerTable` ADD COLUMN `users_id_keyowner` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `key_salt`",
            'keyowner_name'                      => "ALTER TABLE `$providerTable` ADD COLUMN `keyowner_name` VARCHAR(255) NOT NULL DEFAULT '' AFTER `users_id_keyowner`",
            'keyowner_email'                     => "ALTER TABLE `$providerTable` ADD COLUMN `keyowner_email` VARCHAR(255) NOT NULL DEFAULT '' AFTER `keyowner_name`",
            'entity_name_snapshot'               => "ALTER TABLE `$providerTable` ADD COLUMN `entity_name_snapshot` VARCHAR(255) NOT NULL DEFAULT '' AFTER `keyowner_email`",

            // v0.3.0 (jalon 2, intégration Accounts — CDC 4.4 bis) — "Valeurs par défaut
            // Accounts" : ne stockent jamais de secret, uniquement des références de
            // pré-remplissage utilisées lors de la création manuelle d'un compte Accounts
            // (catégorie b) pour ce provider (voir AccountsVault::buildProviderPrefillQuery()).
            'accounts_hash_id'                   => "ALTER TABLE `$providerTable` ADD COLUMN `accounts_hash_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `entity_name_snapshot`",
            'accounts_accounttype_id'            => "ALTER TABLE `$providerTable` ADD COLUMN `accounts_accounttype_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `accounts_hash_id`",
            'accounts_accountstates_id'          => "ALTER TABLE `$providerTable` ADD COLUMN `accounts_accountstates_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `accounts_accounttype_id`",
            'accounts_users_id'                  => "ALTER TABLE `$providerTable` ADD COLUMN `accounts_users_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `accounts_accountstates_id`",
            'accounts_users_id_tech'             => "ALTER TABLE `$providerTable` ADD COLUMN `accounts_users_id_tech` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `accounts_users_id`",
            'accounts_groups_id'                 => "ALTER TABLE `$providerTable` ADD COLUMN `accounts_groups_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `accounts_users_id_tech`",
            'accounts_groups_id_tech'            => "ALTER TABLE `$providerTable` ADD COLUMN `accounts_groups_id_tech` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `accounts_groups_id`",
            'accounts_is_helpdesk_visible'       => "ALTER TABLE `$providerTable` ADD COLUMN `accounts_is_helpdesk_visible` TINYINT(1) NOT NULL DEFAULT 0 AFTER `accounts_groups_id_tech`",

            // v0.4.0 — types de compte dédiés pour "Administrateur" et "Clé de cryptage",
            // configurables explicitement (plutôt qu'un type auto-provisionné retrouvé par
            // nom à l'exécution, source d'un bug si le provisionnement n'avait pas encore
            // tourné — retour de Luc). accounts_accounttype_id reste le type "utilisateur".
            'accounts_accounttype_admin_id'      => "ALTER TABLE `$providerTable` ADD COLUMN `accounts_accounttype_admin_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `accounts_is_helpdesk_visible`",
            'accounts_accounttype_cryptkey_id'   => "ALTER TABLE `$providerTable` ADD COLUMN `accounts_accounttype_cryptkey_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `accounts_accounttype_admin_id`",

            // v0.5.0 — corbeille GLPI standard (is_deleted). Sans cette colonne,
            // CommonDBTM::maybeDeleted() est faux et delete() se comporte toujours
            // comme une purge définitive, quel que soit le bouton cliqué — jamais de
            // mise à la corbeille possible (constaté et bien diagnostiqué par Luc).
            'is_deleted'                          => "ALTER TABLE `$providerTable` ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `accounts_accounttype_cryptkey_id`",
        ];
        foreach ($cols as $col => $sql) {
            if (!$DB->fieldExists($providerTable, $col)) {
                $DB->doQuery($sql);
            }
        }

        // Index utiles à la recherche/déduplication par tenant réel.
        $indexes = [
            'backupgestion_providers_id_parent' => "ALTER TABLE `$providerTable` ADD INDEX `backupgestion_providers_id_parent` (`backupgestion_providers_id_parent`)",
            'acronis_tenant_id'                  => "ALTER TABLE `$providerTable` ADD INDEX `acronis_tenant_id` (`acronis_tenant_id`)",
            'is_deleted'                          => "ALTER TABLE `$providerTable` ADD INDEX `is_deleted` (`is_deleted`)",
        ];
        foreach ($indexes as $col => $sql) {
            $exists = $DB->request([
                'FROM'  => 'information_schema.STATISTICS',
                'WHERE' => ['TABLE_SCHEMA' => $DB->dbdefault, 'TABLE_NAME' => $providerTable, 'INDEX_NAME' => $col],
            ])->current();
            if (!$exists) {
                $DB->doQuery($sql);
            }
        }
    }

    // Table des identifiants API chiffrés (catégorie a) — créée ici aussi pour une
    // installation qui n'aurait que le squelette jalon 1 (git pull sans réinstallation).
    $credTable = Credential::getTable();
    if (!$DB->tableExists($credTable)) {
        $DB->doQuery("
            CREATE TABLE `$credTable` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `providers_id` INT UNSIGNED NOT NULL,
                `cred_key`    VARCHAR(100) NOT NULL DEFAULT '',
                `cred_value`  TEXT NOT NULL,
                `date_mod`    DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_provider_key` (`providers_id`, `cred_key`)
            ) ENGINE=InnoDB
            DEFAULT CHARSET={$default_charset}
            COLLATE={$default_collation}
        ");
    }

    // Jalon 3 (CDC 2.1/4.7) — espaces de stockage (objets GLPI persistés, multi-backend),
    // liaison stockage <-> comptes Accounts, rattachement léger appareil/stockage <->
    // équipement GLPI (pas de mirroir), et journal de la tâche périodique de détection.
    $storageTable = \GlpiPlugin\Backupgestion\StorageSpace::getTable();

    // Bugfix (retour de Luc, jalon 3) : la table doit se résoudre en la classe
    // "StorageSpace" via la convention GLPI (nom de classe en minuscules + "s") pour que
    // Toolbox::getItemTypeForTable() fonctionne — "glpi_plugin_backupgestion_storages" ne
    // se résout qu'en "Storage" (classe inexistante), et Search::show() plantait alors
    // avec "Class name must be a valid object or a string" (confirmé par les logs et le
    // mode debug GLPI). Renommage transparent depuis l'ancien nom de table si nécessaire —
    // ne touche à rien si l'installation est neuve (l'ancienne table n'existe pas).
    $oldStorageTable = 'glpi_plugin_backupgestion_storages';
    if ($oldStorageTable !== $storageTable && $DB->tableExists($oldStorageTable) && !$DB->tableExists($storageTable)) {
        $DB->doQuery("RENAME TABLE `$oldStorageTable` TO `$storageTable`");
    }

    if (!$DB->tableExists($storageTable)) {
        $DB->doQuery("
            CREATE TABLE `$storageTable` (
                `id`                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`                        VARCHAR(255) NOT NULL DEFAULT '',
                `entities_id`                 INT UNSIGNED NOT NULL DEFAULT 0,
                `is_recursive`                TINYINT(1) NOT NULL DEFAULT 0,
                `comment`                     TEXT,
                `storage_type`                VARCHAR(50) NOT NULL DEFAULT '',
                `connection_params`           TEXT,
                `backupgestion_providers_id`  INT UNSIGNED NOT NULL DEFAULT 0,
                `provider_ref`                VARCHAR(255) NOT NULL DEFAULT '',
                `acronis_tenant_id`           VARCHAR(255) NOT NULL DEFAULT '',
                `itemtype`                    VARCHAR(100) NOT NULL DEFAULT '',
                `items_id`                    INT UNSIGNED NOT NULL DEFAULT 0,
                `key_salt`                    VARCHAR(255) NOT NULL DEFAULT '',
                `users_id_keyowner`           INT UNSIGNED NOT NULL DEFAULT 0,
                `keyowner_name`               VARCHAR(255) NOT NULL DEFAULT '',
                `keyowner_email`              VARCHAR(255) NOT NULL DEFAULT '',
                `entity_name_snapshot`        VARCHAR(255) NOT NULL DEFAULT '',
                `is_deleted`                  TINYINT(1) NOT NULL DEFAULT 0,
                `date_creation`               DATETIME DEFAULT NULL,
                `date_mod`                    DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`),
                KEY `backupgestion_providers_id` (`backupgestion_providers_id`),
                KEY `item` (`itemtype`, `items_id`),
                KEY `acronis_tenant_id` (`acronis_tenant_id`),
                KEY `is_deleted` (`is_deleted`)
            ) ENGINE=InnoDB
            DEFAULT CHARSET={$default_charset}
            COLLATE={$default_collation}
        ");
    }

    $storageAccountsTable = \GlpiPlugin\Backupgestion\StorageAccount::getTable();
    if (!$DB->tableExists($storageAccountsTable)) {
        $DB->doQuery("
            CREATE TABLE `$storageAccountsTable` (
                `id`                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `backupgestion_storages_id`   INT UNSIGNED NOT NULL,
                `role`                        VARCHAR(50) NOT NULL DEFAULT '',
                `plugin_accounts_accounts_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_creation`               DATETIME DEFAULT NULL,
                `date_mod`                    DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `backupgestion_storages_id` (`backupgestion_storages_id`),
                UNIQUE KEY `uniq_storage_role_account` (`backupgestion_storages_id`, `role`, `plugin_accounts_accounts_id`)
            ) ENGINE=InnoDB
            DEFAULT CHARSET={$default_charset}
            COLLATE={$default_collation}
        ");
    }

    // Rattachement léger (CDC 2.1) : aucune donnée descriptive mirrorée, seul le résultat
    // du rattachement est persisté — dédupliqué par identité réelle du tenant/élément côté
    // Acronis (acronis_tenant_id + provider_ref), jamais par providers_id local (CDC 4.2 ter).
    $deviceLinksTable = \GlpiPlugin\Backupgestion\DeviceLink::getTable();
    if (!$DB->tableExists($deviceLinksTable)) {
        $DB->doQuery("
            CREATE TABLE `$deviceLinksTable` (
                `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `acronis_tenant_id` VARCHAR(255) NOT NULL DEFAULT '',
                `provider_ref`      VARCHAR(255) NOT NULL DEFAULT '',
                `itemtype`          VARCHAR(100) NOT NULL DEFAULT '',
                `items_id`          INT UNSIGNED NOT NULL DEFAULT 0,
                `match_status`      VARCHAR(50) NOT NULL DEFAULT 'pending',
                `last_checked_at`   DATETIME DEFAULT NULL,
                `date_creation`     DATETIME DEFAULT NULL,
                `date_mod`          DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_tenant_ref` (`acronis_tenant_id`, `provider_ref`),
                KEY `item` (`itemtype`, `items_id`),
                KEY `match_status` (`match_status`)
            ) ENGINE=InnoDB
            DEFAULT CHARSET={$default_charset}
            COLLATE={$default_collation}
        ");
    }

    $discoveryLogsTable = \GlpiPlugin\Backupgestion\DiscoveryLog::getTable();
    if (!$DB->tableExists($discoveryLogsTable)) {
        $DB->doQuery("
            CREATE TABLE `$discoveryLogsTable` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `providers_id`  INT UNSIGNED NOT NULL DEFAULT 0,
                `status`        VARCHAR(50) NOT NULL DEFAULT '',
                `found`         INT UNSIGNED NOT NULL DEFAULT 0,
                `matched`       INT UNSIGNED NOT NULL DEFAULT 0,
                `pending`       INT UNSIGNED NOT NULL DEFAULT 0,
                `errors`        TEXT,
                `date_creation` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `providers_id` (`providers_id`),
                KEY `date_creation` (`date_creation`)
            ) ENGINE=InnoDB
            DEFAULT CHARSET={$default_charset}
            COLLATE={$default_collation}
        ");
    }

    // Tâche périodique de détection des rattachements (CDC 4.7) — CronTask::register()
    // vérifie lui-même l'existence avant de créer, donc sûr à rappeler à chaque
    // migrate()/activate() (même logique que pour les tables ci-dessus).
    \CronTask::register(
        \GlpiPlugin\Backupgestion\DiscoveryTask::class,
        'detection',
        HOUR_TIMESTAMP,
        [
            'comment' => __('Détecte les appareils Acronis non encore rattachés à un équipement GLPI.', 'backupgestion'),
            'mode'    => \CronTask::MODE_EXTERNAL,
        ]
    );

    // Type de compte Accounts dédié (catégorie b — CDC 4.4/4.4 bis), créé de façon
    // best-effort : si le plugin Accounts est absent ou si la table n'a pas la forme
    // attendue, on ignore silencieusement plutôt que de bloquer install/activate — un
    // administrateur peut toujours créer ce type manuellement dans Accounts.
    if (class_exists('\GlpiPlugin\Accounts\Account')) {
        try {
            \GlpiPlugin\Backupgestion\AccountsVault::provisionDefaultAccountType();
        } catch (\Throwable $e) {
            // best-effort — ne jamais faire échouer install/activate pour ça.
        }
    }
}
