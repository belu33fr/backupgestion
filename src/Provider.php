<?php

namespace GlpiPlugin\Backupgestion;

use CommonDBTM;
use Plugin;
use Session;

/**
 * Compte provider (tenant Acronis).
 *
 * Porte la hiérarchie de tenants (backupgestion_providers_id_parent), l'identité
 * réelle du tenant (acronis_tenant_id), le suivi des échecs de connexion (4.4), et
 * le snapshot de dérivation de clé locale protégeant les identifiants API stockés
 * dans Credential (catégorie a — CDC 4.4 quater) : key_salt, users_id_keyowner,
 * keyowner_name, keyowner_email, entity_name_snapshot.
 */
class Provider extends CommonDBTM
{
    public static $rightname = 'plugin_backupgestion_provider';
    public $dohistory = true;

    /**
     * Objet récursif (CDC 4.2 bis) : un provider créé dans une entité racine
     * avec "Sous-entités" activée est utilisable pour les sous-entités.
     */
    public function maybeRecursive(): bool
    {
        return true;
    }

    public function isEntityAssign(): bool
    {
        return true;
    }

    public function canRecurs(): bool
    {
        return Right::canCreateProvider();
    }

    public static function canCreate(): bool
    {
        return Right::canCreateProvider();
    }

    public static function canUpdate(): bool
    {
        return Right::canUpdateProvider();
    }

    public static function canDelete(): bool
    {
        return Right::canDeleteProvider();
    }

    public static function canPurge(): bool
    {
        return Right::canPurgeProvider();
    }

    // ------------------------------------------------------------------
    // Snapshot de dérivation de clé (CDC 4.4 quater)
    // ------------------------------------------------------------------

    public function prepareInputForAdd($input)
    {
        if (empty($input['provider_type'])) {
            $input['provider_type'] = 'acronis';
        }

        $usersIdKeyowner = (int)($input['users_id_keyowner'] ?? 0);
        if ($usersIdKeyowner === 0) {
            $usersIdKeyowner = (int)Session::getLoginUserID();
        }
        $entitiesId = (int)($input['entities_id'] ?? 0);

        $snapshot = KeyDerivation::buildSnapshot($entitiesId, $usersIdKeyowner);
        return array_merge($input, $snapshot);
    }

    public function prepareInputForUpdate($input)
    {
        $newEntitiesId = isset($input['entities_id']) ? (int)$input['entities_id'] : (int)$this->fields['entities_id'];
        $newKeyowner   = isset($input['users_id_keyowner']) ? (int)$input['users_id_keyowner'] : (int)$this->fields['users_id_keyowner'];

        $entityChanged  = $newEntitiesId !== (int)$this->fields['entities_id'];
        $keyownerChanged = $newKeyowner !== (int)$this->fields['users_id_keyowner'];

        if ($entityChanged || $keyownerChanged) {
            // Un des ingrédients réels de la clé change : re-chiffrement obligatoire
            // AVANT d'accepter le changement (CDC 4.4 quater), exactement comme le fait
            // déjà Accounts en interne lors d'un transfert d'entité.
            try {
                $oldKey = KeyDerivation::deriveKey($this->fields);
            } catch (\RuntimeException $e) {
                // Pas encore de sel (provider créé avant le jalon 2, ou snapshot absent) :
                // rien à re-chiffrer, on se contente de régénérer un snapshot propre.
                $oldKey = null;
            }

            $newSnapshot = KeyDerivation::buildSnapshot($newEntitiesId, $newKeyowner);
            $newFields   = array_merge($this->fields, $newSnapshot, ['id' => $this->fields['id']]);
            $newKey      = KeyDerivation::deriveKey($newFields);

            if ($oldKey !== null) {
                $ok = Credential::reencryptForProvider((int)$this->fields['id'], $oldKey, $newKey);
                if (!$ok) {
                    Session::addMessageAfterRedirect(
                        __("Changement d'entité ou d'utilisateur référent refusé : le re-chiffrement des identifiants API existants a échoué. Aucune modification n'a été appliquée.", 'backupgestion'),
                        false,
                        ERROR
                    );
                    return false;
                }
            }

            $input = array_merge($input, $newSnapshot);
        }

        return $input;
    }

    /**
     * Supprime les identifiants API locaux (catégorie a) lors de la purge définitive
     * uniquement — jamais lors d'une simple mise à la corbeille, pour que la
     * restauration reste possible (filet de sécurité, CDC 4.4). Ne touche JAMAIS aux
     * comptes Accounts (catégorie b), quelle que soit la circonstance (CDC 3.3/4.4).
     */
    public function cleanDBonPurge()
    {
        Credential::deleteForProvider((int)$this->fields['id']);
    }

    // ------------------------------------------------------------------
    // Hiérarchie des tenants (CDC 4.2 ter) — découverte manuelle (bouton sur la
    // fiche). La tâche périodique équivalente (CDC 4.7) arrive au jalon 3.
    // ------------------------------------------------------------------

    /**
     * Authentifie ce provider auprès d'Acronis, liste ses tenants enfants directs,
     * et crée/relie automatiquement un Provider GLPI par tenant découvert — dédupliqué
     * par acronis_tenant_id (CDC 4.2 bis), jamais par providers_id.
     *
     * @return array{tenant_id:string, found:int, created:int, updated:int, skipped:int}
     * @throws \RuntimeException si l'authentification ou l'appel API échoue.
     */
    public function discoverChildren(): array
    {
        $id = (int)$this->fields['id'];

        $key         = KeyDerivation::deriveKey($this->fields);
        $credentials = Credential::getForProvider($id, $key);
        $acronis     = ProviderFactory::create($this->fields['provider_type'] ?: 'acronis', $credentials);

        if (!$acronis instanceof AcronisProvider) {
            throw new \RuntimeException(__('La découverte de hiérarchie n\'est disponible que pour un provider Acronis.', 'backupgestion'));
        }

        $result = $acronis->discoverChildTenants();

        // Mémorise l'identité réelle de ce tenant si elle n'était pas encore connue.
        if (empty($this->fields['acronis_tenant_id'])) {
            $this->update(['id' => $id, 'acronis_tenant_id' => $result['tenant_id']]);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($result['children'] as $child) {
            $childTenantId = (string)($child['id'] ?? '');
            $childName     = (string)($child['name'] ?? $childTenantId);
            if ($childTenantId === '') {
                continue;
            }

            $existing = new self();
            $found    = $existing->getFromDBByCrit(['acronis_tenant_id' => $childTenantId]);

            if (!$found) {
                $newInput = [
                    'name'                               => $childName,
                    'entities_id'                         => $this->fields['entities_id'],
                    'is_recursive'                         => 0,
                    'provider_type'                        => 'acronis',
                    'acronis_tenant_id'                    => $childTenantId,
                    'backupgestion_providers_id_parent'    => $id,
                    'comment'                               => sprintf(__('Découvert automatiquement le %s depuis "%s".', 'backupgestion'), date('Y-m-d H:i'), $this->fields['name']),
                ];
                $newID = $existing->add($newInput);
                if ($newID) {
                    $created++;
                }
                continue;
            }

            // Provider déjà connu pour ce tenant réel : ne re-parenter que s'il n'a pas
            // ses propres identifiants API — sinon c'est un provider indépendant (CDC 4.4),
            // on ne touche jamais à son rattachement parent.
            $hasOwnCredentials = Credential::existsForProvider((int)$existing->fields['id']);
            $changes = [];
            if ($existing->fields['name'] !== $childName) {
                $changes['name'] = $childName;
            }
            if (!$hasOwnCredentials && (int)$existing->fields['backupgestion_providers_id_parent'] !== $id) {
                $changes['backupgestion_providers_id_parent'] = $id;
            }
            if (!empty($changes)) {
                $existing->update(array_merge(['id' => $existing->fields['id']], $changes));
                $updated++;
            } else {
                $skipped++;
            }
        }

        return [
            'tenant_id' => $result['tenant_id'],
            'found'     => count($result['children']),
            'created'   => $created,
            'updated'   => $updated,
            'skipped'   => $skipped,
        ];
    }

    /**
     * Entité donnée + toutes ses sous-entités (lecture directe de glpi_entities.entities_id,
     * schéma stable du cœur GLPI) — pas de dépendance à un helper GLPI dont le comportement
     * exact ne peut pas être vérifié ici (cf. AccountsVault::getAncestorEntityIds()).
     */
    private static function getDescendantEntityIds(int $entities_id): array
    {
        global $DB;

        $result   = [$entities_id];
        $frontier = [$entities_id];
        $guard    = 0;

        while (!empty($frontier) && $guard++ < 50) {
            $next = [];
            foreach ($DB->request([
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['entities_id' => $frontier],
                'SELECT' => ['id'],
            ]) as $row) {
                $id = (int)$row['id'];
                if (!in_array($id, $result, true)) {
                    $result[] = $id;
                    $next[]   = $id;
                }
            }
            $frontier = $next;
        }

        return $result;
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Provider', 'Providers', $nb, 'backupgestion');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_backupgestion_providers';
    }

    public static function getIcon(): string
    {
        return 'ti ti-cloud-lock';
    }

    public static function getFormURL($full = true): string
    {
        return Plugin::getWebDir('backupgestion', $full) . '/front/provider.form.php';
    }

    public static function getFormURLWithID($id = 0, $full = true): string
    {
        return self::getFormURL($full) . '?id=' . (int)$id;
    }

    public static function getSearchURL($full = true): string
    {
        return Plugin::getWebDir('backupgestion', $full) . '/front/provider.php';
    }

    // ------------------------------------------------------------------
    // Menu GLPI 11 — "Outils" > "Sauvegardes"
    // ------------------------------------------------------------------

    public static function getMenuName(): string
    {
        return 'Sauvegardes';
    }

    public static function getMenuContent(): array
    {
        $search = self::getSearchURL(false);
        $form   = self::getFormURL(false);

        return [
            'title'   => self::getMenuName(),
            'page'    => $search,
            'icon'    => self::getIcon(),
            'options' => [
                'provider' => [
                    'title' => self::getTypeName(2),
                    'page'  => $search,
                    'links' => [
                        'search' => $search,
                        'add'    => $form,
                    ],
                ],
            ],
        ];
    }

    public static function canView(): bool
    {
        return Session::haveRightsOr(self::$rightname, [READ])
            || Session::haveRight('plugin_backupgestion_admin', READ)
            || Session::haveRight('plugin_backupgestion_financial', READ)
            || Session::haveRight('plugin_backupgestion_tenant_admin', READ)
            || Session::haveRight('plugin_backupgestion_technician', READ);
    }

    // ------------------------------------------------------------------
    // Formulaire GLPI 11 (Twig) — champs minimaux du jalon 1
    // ------------------------------------------------------------------

    public function showForm($ID, array $options = []): bool
    {
        global $DB;

        $this->initForm($ID, $options);

        $hasCredentials  = [];
        $prefillValues   = [];
        $children        = [];
        // Champs jamais renvoyés en clair au navigateur, même sur la fiche d'édition —
        // le seul "vrai" secret parmi les identifiants (comme le mot de passe DNSManager).
        $neverPrefilled  = ['client_secret'];

        if ($this->fields['id'] ?? 0) {
            foreach ($DB->request([
                'FROM'   => Credential::getTable(),
                'WHERE'  => ['providers_id' => $this->fields['id']],
                'SELECT' => ['cred_key'],
            ]) as $row) {
                $hasCredentials[$row['cred_key']] = true;
            }

            // Pré-remplissage des champs non sensibles (ID, URL) avec leur valeur réelle
            // déchiffrée — même principe que DNSManager : seul le vrai secret (mot de
            // passe / client_secret) reste vide sur la fiche d'édition.
            try {
                $key = KeyDerivation::deriveKey($this->fields);
                foreach (Credential::getForProvider((int)$this->fields['id'], $key) as $credKey => $value) {
                    if (!in_array($credKey, $neverPrefilled, true)) {
                        $prefillValues[$credKey] = $value;
                    }
                }
            } catch (\Throwable $e) {
                // Pas de sel/clé exploitable (ex. provider créé avant le jalon 2) :
                // pas de pré-remplissage, l'utilisateur ressaisira tout — pas bloquant.
            }

            // Ne lister que les enfants dont l'entité est celle du parent ou une
            // sous-entité de celle-ci : un enfant resté dans son entité d'origine après
            // un déplacement du parent (backupgestion_providers_id_parent seul ne bouge
            // pas les entités) ne doit plus apparaître si son entité n'est plus visible
            // depuis celle du parent — confirmé par Luc.
            $visibleEntities = self::getDescendantEntityIds((int)$this->fields['entities_id']);
            foreach ($DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => [
                    'backupgestion_providers_id_parent' => $this->fields['id'],
                    'entities_id'                        => $visibleEntities,
                ],
            ]) as $row) {
                // Nom complet de l'entité (pas juste son index — retour de Luc), via le
                // helper standard GLPI (confirmé utilisé par Accounts lui-même).
                $row['entity_name'] = \Dropdown::getDropdownName('glpi_entities', (int)$row['entities_id']);
                $children[]         = $row;
            }
        }

        // Liste plate de toutes les entités (id => nom complet), pour le sélecteur de
        // déplacement rapide sur les sous-tenants (retour de Luc — éviter le transfert
        // d'entité complet juste pour rattacher un tenant enfant).
        $allEntities = [];
        foreach ($DB->request(['FROM' => 'glpi_entities', 'SELECT' => ['id', 'completename'], 'ORDER' => 'completename ASC']) as $row) {
            $allEntities[(int)$row['id']] = $row['completename'];
        }

        // Zone "bonus" — ne doit jamais empêcher l'affichage de la fiche : toute erreur
        // inattendue ici (ex. plugin Accounts présent mais incompatible) est absorbée,
        // la fiche s'affiche alors comme si Accounts était indisponible.
        $accountsAvailable = false;
        try {
            $accountsAvailable = AccountsVault::isAvailable();
        } catch (\Throwable $e) {
            $accountsAvailable = false;
        }

        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@backupgestion/provider.form.html.twig',
            [
                'item'                 => $this,
                'params'               => $options,
                'providerLabel'        => ProviderFactory::getAvailableProviders()['acronis'] ?? 'Acronis',
                'credentialFields'     => ProviderFactory::getCredentialFields('acronis'),
                'hasCredentials'       => $hasCredentials,
                'prefillValues'        => $prefillValues,
                'children'             => $children,
                'allEntities'          => $allEntities,
                'webdir'               => Plugin::getWebDir('backupgestion'),
                'accountsAvailable'    => $accountsAvailable,
                'accountsHashId'       => (int)($this->fields['accounts_hash_id'] ?? 0),
            ]
        );

        return true;
    }

    // ------------------------------------------------------------------
    // Options de recherche
    // ------------------------------------------------------------------

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

        $tab[] = [
            'id'            => 86,
            'table'         => self::getTable(),
            'field'         => 'is_recursive',
            'name'          => __('Sous-entités'),
            'datatype'      => 'bool',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 20,
            'table'         => self::getTable(),
            'field'         => 'provider_type',
            'name'          => __('Type de provider', 'backupgestion'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 21,
            'table'         => self::getTable(),
            'field'         => 'acronis_tenant_id',
            'name'          => __('Identifiant tenant Acronis', 'backupgestion'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 22,
            'table'         => self::getTable(),
            'field'         => 'name',
            'linkfield'     => 'backupgestion_providers_id_parent',
            'name'          => __('Provider parent', 'backupgestion'),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 23,
            'table'         => self::getTable(),
            'field'         => 'connection_failure_days',
            'name'          => __('Jours d\'échec de connexion consécutifs', 'backupgestion'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        return $tab;
    }
}
