<?php

namespace GlpiPlugin\Backupgestion;

use CommonDBTM;
use Plugin;
use Session;

/**
 * Espace de stockage de sauvegarde (CDC 2.1/4.3 bis) — objet GLPI à part entière,
 * multi-backend (Acronis natif découvert via l'API, NAS, fichier, S3/Wasabi…).
 *
 * Seuls les paramètres de connexion sont conservés côté GLPI (chiffrés localement,
 * même mécanisme de dérivation de clé autonome que Provider — CDC 4.4 quater) : les
 * données d'usage (volume, taux d'occupation) ne sont jamais mirrorées, elles sont
 * interrogées en direct à chaque affichage (jalon 3, pages dashboard).
 *
 * Un espace de stockage Acronis natif (storage_type = 'acronis') est créé
 * automatiquement par la tâche de détection (CDC 4.7, jalon 3) — jamais saisi à la
 * main : son formulaire de création manuel n'offre donc que nas/fichier/s3.
 */
class StorageSpace extends CommonDBTM
{
    public static $rightname = 'plugin_backupgestion_provider';
    public $dohistory = true;

    /** Types de backend saisissables manuellement (Acronis natif = découverte auto). */
    public const MANUAL_TYPES = ['nas', 'file', 's3'];

    /**
     * Contexte HKDF constant (pas de suffixe id) : le chiffrement des paramètres de
     * connexion a lieu dans prepareInputForAdd(), donc AVANT que l'id définitif ne soit
     * connu (l'INSERT n'a pas encore eu lieu) — un contexte basé sur l'id serait
     * différent entre le chiffrement et le déchiffrement ultérieur. La sécurité repose
     * de toute façon sur le sel (256 bits, unique par fiche), pas sur ce contexte.
     */
    private const KEY_CONTEXT = 'backupgestion-storage';

    public function maybeRecursive(): bool
    {
        return true;
    }

    public function isEntityAssign(): bool
    {
        return true;
    }

    public static function canView(): bool
    {
        return Session::haveRight('plugin_backupgestion_admin', READ)
            || Session::haveRight('plugin_backupgestion_tenant_admin', READ)
            || Session::haveRight('plugin_backupgestion_technician', READ)
            || Session::haveRight('plugin_backupgestion_financial', READ);
    }

    public static function canCreate(): bool
    {
        return Right::canManageTenant() || Right::canManageTechnician();
    }

    public static function canUpdate(): bool
    {
        return Right::canManageTenant() || Right::canManageTechnician();
    }

    public static function canDelete(): bool
    {
        return Right::canManageTenant() || Right::canManageTechnician();
    }

    public static function canPurge(): bool
    {
        return Right::canAdmin();
    }

    // ------------------------------------------------------------------
    // Chiffrement local des paramètres de connexion (catégorie a, même principe
    // autonome que Provider — CDC 4.4 quater) : snapshot figé à la création,
    // clé jamais stockée, recalculée à la volée.
    // ------------------------------------------------------------------

    public function prepareInputForAdd($input)
    {
        if (empty($input['storage_type'])) {
            $input['storage_type'] = 'nas';
        }

        $usersIdKeyowner = (int)($input['users_id_keyowner'] ?? 0);
        if ($usersIdKeyowner === 0) {
            $usersIdKeyowner = (int)Session::getLoginUserID();
        }
        $entitiesId = (int)($input['entities_id'] ?? 0);

        $snapshot = KeyDerivation::buildSnapshot($entitiesId, $usersIdKeyowner);
        $input    = array_merge($input, $snapshot);

        return $this->encryptConnectionParamsInInput($input, $snapshot, []);
    }

    public function prepareInputForUpdate($input)
    {
        $newEntitiesId = isset($input['entities_id']) ? (int)$input['entities_id'] : (int)$this->fields['entities_id'];
        $newKeyowner   = isset($input['users_id_keyowner']) ? (int)$input['users_id_keyowner'] : (int)$this->fields['users_id_keyowner'];

        $entityChanged   = $newEntitiesId !== (int)$this->fields['entities_id'];
        $keyownerChanged = $newKeyowner !== (int)$this->fields['users_id_keyowner'];

        // Valeurs déchiffrées AVANT tout changement de clé — servent à la fois de filet
        // de sécurité pour le re-chiffrement (entité/référent modifié) et de base de
        // fusion pour une édition partielle des paramètres de connexion (retour de
        // Luc — même principe que "laisser vide = ne pas modifier" côté Provider, mais
        // au niveau de chaque clé du JSON plutôt que du blob entier).
        $existingParams = $this->getDecryptedConnectionParams();

        if ($entityChanged || $keyownerChanged) {
            // Un des ingrédients réels de la clé change : re-dérive la clé AVANT
            // d'accepter le changement, exactement comme Provider::prepareInputForUpdate()
            // pour les identifiants API. Le blob sera ré-écrit par
            // encryptConnectionParamsInInput() ci-dessous avec la nouvelle clé, à partir
            // de $existingParams déjà déchiffrés avec l'ancienne — pas de round-trip
            // chiffré->chiffré nécessaire.
            if (!empty($this->fields['connection_params']) && empty($existingParams)) {
                // Un blob existait mais n'a pas pu être déchiffré (ancienne clé perdue/
                // invalide) : refuse le changement plutôt que de perdre silencieusement
                // les paramètres de connexion existants.
                Session::addMessageAfterRedirect(
                    __("Changement d'entité ou d'utilisateur référent refusé : le re-chiffrement des paramètres de connexion existants a échoué. Aucune modification n'a été appliquée.", 'backupgestion'),
                    false,
                    ERROR
                );
                return false;
            }

            $newSnapshot = KeyDerivation::buildSnapshot($newEntitiesId, $newKeyowner);
            $input       = array_merge($input, $newSnapshot);
        }

        $keySource = array_merge($this->fields, $input);
        return $this->encryptConnectionParamsInInput($input, $keySource, $existingParams);
    }

    /**
     * Fusionne les paramètres de connexion nouvellement saisis (un sous-champ par clé,
     * ex. connection_params_raw[host]) avec les valeurs déchiffrées existantes — un
     * champ laissé vide conserve sa valeur actuelle, comme les identifiants API de
     * Provider — puis rechiffre l'ensemble en un seul blob JSON.
     */
    private function encryptConnectionParamsInInput(array $input, array $keySource, array $existingParams): array
    {
        if (!isset($input['connection_params_raw']) || !is_array($input['connection_params_raw'])) {
            return $input;
        }

        $submitted = $input['connection_params_raw'];
        unset($input['connection_params_raw']);

        $merged = $existingParams;
        foreach ($submitted as $key => $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $merged[$key] = $value;
            }
        }

        if (empty($merged)) {
            return $input;
        }

        $key = KeyDerivation::deriveKey($keySource, self::KEY_CONTEXT);
        $input['connection_params'] = addslashes(Credential::encrypt(json_encode($merged), $key));

        return $input;
    }

    /**
     * Déchiffre et décode les paramètres de connexion (tableau clé => valeur) — jamais
     * renvoyés en clair ailleurs qu'ici, à la demande explicite de l'appelant.
     */
    public function getDecryptedConnectionParams(): array
    {
        if (empty($this->fields['connection_params'])) {
            return [];
        }
        try {
            $key  = KeyDerivation::deriveKey($this->fields, self::KEY_CONTEXT);
            $json = Credential::decrypt($this->fields['connection_params'], $key);
        } catch (\Throwable $e) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Définition des champs de connexion par backend manuel (CDC 2.1) — structure
     * prête à étendre, comme ProviderFactory::getCredentialFields() pour les providers.
     */
    public static function getConnectionFields(string $storageType): array
    {
        return match ($storageType) {
            // Principe général (retour de Luc) : par défaut, tout identifiant/secret
            // passe par l'onglet "Comptes" (liaison à des comptes Accounts existants —
            // login, clé d'accès, clé secrète, clé de chiffrement…), jamais par un champ
            // de connexion en clair/chiffré ici. ConnectionFields ne décrit donc plus que
            // "comment on s'y connecte" (paramètres non sensibles : adresse, partage,
            // endpoint, bucket…) — un futur backend qui aurait vraiment besoin d'un
            // secret propre à lui (cas spécifique, hors du modèle "compte Accounts")
            // resterait la seule exception à ce principe.
            'nas' => [
                'host'  => ['label' => __('Hôte / adresse', 'backupgestion'), 'type' => 'text', 'required' => true],
                'share' => ['label' => __('Partage', 'backupgestion'), 'type' => 'text', 'required' => false],
            ],
            'file' => [
                'path' => ['label' => __('Chemin local', 'backupgestion'), 'type' => 'text', 'required' => true],
            ],
            's3' => [
                'endpoint' => ['label' => __('Endpoint', 'backupgestion'), 'type' => 'text', 'required' => true],
                'bucket'   => ['label' => __('Bucket', 'backupgestion'), 'type' => 'text', 'required' => true],
            ],
            default => [],
        };
    }

    public static function getStorageTypeLabel(string $storageType): string
    {
        return match ($storageType) {
            'acronis' => __('Acronis (natif, découvert automatiquement)', 'backupgestion'),
            'nas'     => __('NAS', 'backupgestion'),
            'file'    => __('Fichier local', 'backupgestion'),
            's3'      => __('S3 / Wasabi', 'backupgestion'),
            default   => $storageType,
        };
    }

    public function cleanDBonPurge()
    {
        StorageAccount::deleteForStorage((int)$this->fields['id']);
    }

    // ------------------------------------------------------------------

    public static function getTypeName($nb = 0): string
    {
        return _n('Espace de stockage', 'Espaces de stockage', $nb, 'backupgestion');
    }

    public static function getTable($classname = null): string
    {
        // Doit se résoudre en "StorageSpace" via la convention GLPI de résolution
        // classe<->table (nom de classe en minuscules + "s") pour que
        // Toolbox::getItemTypeForTable() fonctionne correctement, notamment dans
        // Search::show() — voir la migration de renommage dans hook.php.
        return 'glpi_plugin_backupgestion_storagespaces';
    }

    public static function getIcon(): string
    {
        return 'ti ti-server-2';
    }

    public static function getFormURL($full = true): string
    {
        return Plugin::getWebDir('backupgestion', $full) . '/front/storagespace.form.php';
    }

    public static function getFormURLWithID($id = 0, $full = true): string
    {
        return self::getFormURL($full) . '?id=' . (int)$id;
    }

    public static function getSearchURL($full = true): string
    {
        return Plugin::getWebDir('backupgestion', $full) . '/front/storagespace.php';
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

        $manualTypeLabels = [];
        foreach (self::MANUAL_TYPES as $type) {
            $manualTypeLabels[$type] = self::getStorageTypeLabel($type);
        }

        $storageType = ($this->fields['storage_type'] ?? '') !== '' ? $this->fields['storage_type'] : 'nas';

        // Pré-remplissage des champs non sensibles avec leur valeur réelle déchiffrée,
        // comme pour les identifiants API de Provider — seuls les champs "password"
        // restent vides sur la fiche d'édition.
        $prefillValues = [];
        if ($this->fields['id'] ?? 0) {
            $decrypted = $this->getDecryptedConnectionParams();
            foreach (self::getConnectionFields($storageType) as $key => $def) {
                if (($def['type'] ?? '') !== 'password' && isset($decrypted[$key])) {
                    $prefillValues[$key] = $decrypted[$key];
                }
            }
        }

        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@backupgestion/storagespace.form.html.twig',
            [
                'item'             => $this,
                'params'           => $options,
                'manualTypeLabels' => $manualTypeLabels,
                'connectionFields' => self::getConnectionFields($storageType),
                'prefillValues'    => $prefillValues,
                'canUpdate'        => self::canUpdate(),
            ]
        );

        return true;
    }

    public function rawSearchOptions(): array
    {
        $tab = [];

        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        $tab[] = [
            'id'            => 1,
            'table'         => self::getTable(),
            'field'         => 'name',
            'name'          => __('Nom', 'backupgestion'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 2,
            'table'         => self::getTable(),
            'field'         => 'id',
            'name'          => __('ID'),
            'massiveaction' => false,
            'datatype'      => 'number',
        ];

        $tab[] = [
            'id'            => 3,
            'table'         => self::getTable(),
            'field'         => 'storage_type',
            'name'          => __('Type', 'backupgestion'),
            'datatype'      => 'specific',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 16,
            'table'         => self::getTable(),
            'field'         => 'comment',
            'name'          => __('Commentaire', 'backupgestion'),
            'datatype'      => 'text',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 19,
            'table'         => self::getTable(),
            'field'         => 'date_mod',
            'name'          => __('Dernière mise à jour'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 80,
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => __('Entité'),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'storage_type') {
            return self::getStorageTypeLabel((string)$values[$field]);
        }
        return '';
    }
}
