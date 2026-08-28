<?php

namespace GlpiPlugin\Backupgestion;

/**
 * Passerelle vers le plugin Accounts (catégorie b — CDC 3.3/4.4).
 *
 * Confirmé par lecture directe du code source d'Accounts (Account.php,
 * AccountCrypto.php, Hash.php, AesKey.php) :
 *  - Account::add()/update() ne chiffrent jamais un mot de passe en clair
 *    eux-mêmes : l'appelant doit fournir `encrypted_password` déjà chiffré
 *    via AccountCrypto::encrypt($plaintext, $fingerprint).
 *  - Le "fingerprint" (empreinte) associé à une Hash peut être disponible de
 *    deux façons : soit une clé maîtresse stockée côté serveur (AesKey, liée
 *    à la Hash, chiffrée at-rest par GLPIKey — accessible sans saisie
 *    humaine), soit une clé tapée par un humain et vérifiée contre le
 *    vérificateur stocké sur la Hash (glpi_plugin_accounts_hashes.hash,
 *    format $pbkdf2$... ou legacy double-SHA256).
 *
 * BackupGestion respecte le principe de fragmentation (3.3) : cette classe
 * ne stocke JAMAIS la clé tapée au-delà d'un cache de session PHP à durée de
 * vie limitée (4.4 ter), et ne persiste rien en base ni sur disque.
 */
class AccountsVault
{
    private const SESSION_NS = 'plugin_backupgestion_accounts_keys';

    public static function isAvailable(): bool
    {
        return class_exists('\GlpiPlugin\Accounts\Account');
    }

    // ------------------------------------------------------------------
    // Listes de référence (lecture seule, aucune donnée sensible)
    // ------------------------------------------------------------------

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
            // Résolution des entités parentes construite nous-mêmes (lecture directe de
            // glpi_entities.entities_id, schéma stable du cœur GLPI) plutôt que via un
            // helper GLPI dont la signature exacte n'était pas fiable (cause du bug
            // précédent avec Entity::getAncestorsOf) : une empreinte définie précisément
            // sur cette entité est toujours visible ; une empreinte définie sur une entité
            // parente (y compris la racine, "toutes les entités") n'est visible que si elle
            // est marquée récursive — comportement confirmé par Luc.
            $ancestors = self::getAncestorEntityIds($entities_id);
            $orCriteria = ['entities_id' => $entities_id];
            if ($DB->fieldExists($table, 'is_recursive')) {
                $orCriteria = [
                    'OR' => [
                        ['entities_id' => $entities_id],
                        ['entities_id' => $ancestors, 'is_recursive' => 1],
                    ],
                ];
            } else {
                $orCriteria = ['entities_id' => array_merge([$entities_id], $ancestors)];
            }
            $where = $orCriteria;
        }

        $rows = [];
        foreach ($DB->request(['FROM' => $table, 'WHERE' => $where, 'ORDER' => 'name ASC']) as $row) {
            $rows[(int)$row['id']] = $row['name'] !== '' ? $row['name'] : ('#' . $row['id']);
        }
        return $rows;
    }

    /**
     * Remonte la chaîne des entités parentes de $entities_id (elle-même exclue),
     * jusqu'à la racine (id 0) — lecture directe de glpi_entities, sans dépendance
     * à un helper GLPI dont le comportement exact ne peut pas être vérifié ici.
     */
    private static function getAncestorEntityIds(int $entities_id): array
    {
        global $DB;

        $ancestors = [];
        $current   = $entities_id;
        $guard     = 0;

        while ($guard++ < 50) {
            if ($current === 0) {
                if (!in_array(0, $ancestors, true)) {
                    $ancestors[] = 0;
                }
                break;
            }
            $row = $DB->request([
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['id' => $current],
                'SELECT' => ['entities_id'],
            ])->current();
            if (!$row) {
                break;
            }
            $parent = (int)$row['entities_id'];
            if ($parent === $current || in_array($parent, $ancestors, true)) {
                break;
            }
            $ancestors[] = $parent;
            $current     = $parent;
        }

        return $ancestors;
    }

    // ------------------------------------------------------------------
    // Cache de session de la clé de déchiffrement Accounts (CDC 4.4 ter)
    // Uniquement en $_SESSION, jamais en base ni sur disque ; une entrée par
    // empreinte (plugin_accounts_hashes_id), timeout glissant.
    // ------------------------------------------------------------------

    public static function rememberKey(int $hashId, string $key, int $timeoutMinutes = 15): void
    {
        if ($hashId <= 0 || $key === '') {
            return;
        }
        $_SESSION[self::SESSION_NS][$hashId] = [
            'key'     => $key,
            'expires' => time() + max(1, $timeoutMinutes) * 60,
        ];
    }

    public static function getRememberedKey(int $hashId): ?string
    {
        $entry = $_SESSION[self::SESSION_NS][$hashId] ?? null;
        if (!$entry) {
            return null;
        }
        if (time() > $entry['expires']) {
            unset($_SESSION[self::SESSION_NS][$hashId]);
            return null;
        }
        return $entry['key'];
    }

    public static function forgetKey(?int $hashId = null): void
    {
        if ($hashId === null) {
            unset($_SESSION[self::SESSION_NS]);
            return;
        }
        unset($_SESSION[self::SESSION_NS][$hashId]);
    }

    // ------------------------------------------------------------------
    // Résolution de l'empreinte (fingerprint) — jamais de secret persisté
    // au-delà du cache de session ci-dessus.
    // ------------------------------------------------------------------

    /**
     * Résout la clé de chiffrement (fingerprint) utilisable pour une Hash donnée :
     *  1) clé maîtresse stockée côté serveur (AesKey) — aucune saisie requise ;
     *  2) clé tapée transmise en paramètre, vérifiée contre le vérificateur stocké
     *     sur la Hash (les deux formats — $pbkdf2$... et legacy double-SHA256 —
     *     sont acceptés, comme AccountCrypto/crypt.js) ; mémorisée en session si
     *     valide (CDC 4.4 ter) ;
     *  3) clé précédemment mémorisée en session pour cette Hash.
     * Retourne null si aucune de ces trois sources ne fournit une clé valide.
     */
    public static function resolveFingerprint(int $hashId, ?string $typedKey = null, int $sessionTimeoutMinutes = 15): ?string
    {
        if (!self::isAvailable() || $hashId <= 0) {
            return null;
        }

        if (class_exists('\GlpiPlugin\Accounts\AesKey')) {
            $aeskey = new \GlpiPlugin\Accounts\AesKey();
            if ($aeskey->getFromDBByCrit(['plugin_accounts_hashes_id' => $hashId]) && !empty($aeskey->fields['name'])) {
                return $aeskey->getDecryptedName();
            }
        }

        if (!empty($typedKey) && class_exists('\GlpiPlugin\Accounts\Hash')) {
            $hash = new \GlpiPlugin\Accounts\Hash();
            if ($hash->getFromDB($hashId) && self::verifyTypedKey($typedKey, (string)($hash->fields['hash'] ?? ''))) {
                self::rememberKey($hashId, $typedKey, $sessionTimeoutMinutes);
                return $typedKey;
            }
            // Clé tapée mais invalide : ne jamais retomber silencieusement sur le cache.
            return null;
        }

        return self::getRememberedKey($hashId);
    }

    private static function verifyTypedKey(string $typedKey, string $storedVerifier): bool
    {
        if ($storedVerifier === '') {
            return false;
        }

        if (str_starts_with($storedVerifier, '$pbkdf2$')) {
            $parts = explode('$', ltrim($storedVerifier, '$'));
            // parts: ['pbkdf2', iterations, salt_b64, derived_hex]
            if (count($parts) < 4) {
                return false;
            }
            $iterations = (int)$parts[1];
            $salt       = base64_decode($parts[2], true);
            $expected   = $parts[3];
            if ($iterations <= 0 || $salt === false || $expected === '') {
                return false;
            }
            $derived = hash_pbkdf2('sha256', $typedKey, $salt, $iterations, 0, false);
            return hash_equals($expected, $derived);
        }

        // Format legacy : double SHA-256 du texte en clair.
        return hash_equals($storedVerifier, hash('sha256', hash('sha256', $typedKey)));
    }

    // ------------------------------------------------------------------
    // Création d'un compte utilisateur Acronis (catégorie b — CDC 4.4/4.4 bis)
    // ------------------------------------------------------------------

    /** Noms des types de compte Accounts créés à titre de suggestion à l'installation
     *  (best-effort) — l'admin doit ensuite les sélectionner explicitement dans les
     *  "Valeurs par défaut" du provider (accounts_accounttype_admin_id /
     *  accounts_accounttype_cryptkey_id) : plus de résolution par nom à l'exécution,
     *  qui dépendait du bon déroulement du provisionnement (source d'un bug — retour
     *  de Luc : un compte "Clé de cryptage" créé avec le type "utilisateur" par défaut
     *  parce que le type dédié n'avait pas encore été trouvé). */
    private const ADMIN_ACCOUNTTYPE_NAME    = 'BackupGestion — Admin compte client Acronis';
    private const CRYPTKEY_ACCOUNTTYPE_NAME = 'BackupGestion — Clé de chiffrement sauvegarde';

    /**
     * Crée un compte Accounts pour ce provider, pré-rempli depuis les "Valeurs par
     * défaut Accounts" (4.4 bis), chiffré via la vraie API Accounts (AccountCrypto),
     * puis lié au provider (Account_Item).
     *
     * @param bool $isAdmin    Si vrai, force le type de compte sur celui configuré dans
     *        "Type de compte administrateur (défaut)" (accounts_accounttype_admin_id)
     *        au lieu du type "utilisateur" (accounts_accounttype_id).
     * @param bool $isCryptkey Si vrai, force le type de compte sur celui configuré dans
     *        "Type de compte clé de cryptage (défaut)" (accounts_accounttype_cryptkey_id).
     *        Mutuellement exclusif avec $isAdmin côté interface ; si les deux sont vrais
     *        ici, $isAdmin est prioritaire.
     * @return int L'ID du compte Accounts créé.
     * @throws \RuntimeException si le plugin Accounts est absent, si aucune
     *         empreinte n'est configurée/résolue, ou si la création échoue.
     */
    public static function createAdminAccount(
        Provider $provider,
        string $login,
        string $password,
        ?string $typedKey = null,
        bool $isAdmin = false,
        bool $isCryptkey = false
    ): int {
        if (!self::isAvailable()) {
            throw new \RuntimeException(__('Le plugin Accounts n\'est pas disponible.', 'backupgestion'));
        }

        $hashId = (int)($provider->fields['accounts_hash_id'] ?? 0);
        if ($hashId <= 0) {
            throw new \RuntimeException(__('Aucune empreinte configurée dans les "Valeurs par défaut Accounts" de ce provider.', 'backupgestion'));
        }

        $timeout     = 15;
        $fingerprint = self::resolveFingerprint($hashId, $typedKey, $timeout);
        if ($fingerprint === null) {
            throw new \RuntimeException(__('Clé de chiffrement invalide ou absente — veuillez la saisir.', 'backupgestion'));
        }

        $login = trim($login);
        if ($password === '') {
            throw new \RuntimeException(__('Mot de passe requis.', 'backupgestion'));
        }
        if ($isCryptkey) {
            // Une "Clé de cryptage" (passphrase, pas de notion d'identifiant) n'a pas de
            // login — on l'ignore explicitement même si l'interface en a laissé passer un
            // par erreur, pour ne jamais l'enregistrer sur ce type de compte (retour de Luc).
            $login = '';
        } else {
            // Tout autre compte (utilisateur/admin) doit avoir un identifiant, unique
            // parmi les comptes déjà liés à ce provider (retour de Luc).
            if ($login === '') {
                throw new \RuntimeException(__('Identifiant requis.', 'backupgestion'));
            }
            if (self::loginExistsForProvider((int)$provider->fields['id'], $login)) {
                throw new \RuntimeException(__('Un compte avec cet identifiant existe déjà pour ce provider.', 'backupgestion'));
            }
        }

        $accounttypeId = (int)($provider->fields['accounts_accounttype_id'] ?? 0);
        $namePrefix    = __('[Sauvegarde] Utilisateur %s', 'backupgestion');
        if ($isAdmin) {
            $accounttypeId = (int)($provider->fields['accounts_accounttype_admin_id'] ?? 0) ?: $accounttypeId;
            $namePrefix    = __('[Sauvegarde] Admin %s', 'backupgestion');
        } elseif ($isCryptkey) {
            $accounttypeId = (int)($provider->fields['accounts_accounttype_cryptkey_id'] ?? 0) ?: $accounttypeId;
            $namePrefix    = __('[Sauvegarde] Clé de chiffrement %s', 'backupgestion');
        }

        $input = [
            'name'                             => sprintf($namePrefix, $provider->fields['name'] ?? ''),
            'login'                            => $login,
            'encrypted_password'               => addslashes(\GlpiPlugin\Accounts\AccountCrypto::encrypt($password, $fingerprint)),
            'plugin_accounts_hashes_id'        => $hashId,
            'plugin_accounts_accounttypes_id'  => $accounttypeId,
            'plugin_accounts_accountstates_id' => (int)($provider->fields['accounts_accountstates_id'] ?? 0),
            'entities_id'                      => (int)($provider->fields['entities_id'] ?? 0),
            'is_recursive'                     => (int)($provider->fields['is_recursive'] ?? 0),
            'users_id'                         => (int)($provider->fields['accounts_users_id'] ?? 0),
            'users_id_tech'                    => (int)($provider->fields['accounts_users_id_tech'] ?? 0),
            'groups_id'                        => (int)($provider->fields['accounts_groups_id'] ?? 0),
            'groups_id_tech'                   => (int)($provider->fields['accounts_groups_id_tech'] ?? 0),
            'is_helpdesk_visible'              => (int)($provider->fields['accounts_is_helpdesk_visible'] ?? 0),
            'date_creation'                    => date('Y-m-d H:i:s'),
            'comment'                          => sprintf(__('Créé automatiquement par BackupGestion pour le provider "%s" le %s.', 'backupgestion'), $provider->fields['name'] ?? '', date('Y-m-d H:i')),
        ];

        $account = new \GlpiPlugin\Accounts\Account();
        $newID   = $account->add($input);
        if (!$newID) {
            throw new \RuntimeException(__('La création du compte dans Accounts a échoué.', 'backupgestion'));
        }

        self::linkToItem((int)$newID, Provider::class, (int)$provider->fields['id']);

        return (int)$newID;
    }

    /**
     * Vérifie si un compte avec ce login existe déjà parmi ceux liés (Account_Item) à
     * ce provider — comparaison insensible à la casse, comme les identifiants usuels.
     */
    private static function loginExistsForProvider(int $providerId, string $login): bool
    {
        global $DB;

        if (!self::isAvailable() || $providerId <= 0 || $login === '') {
            return false;
        }

        $wantedLogin = strtolower($login);
        $rows = $DB->request([
            'SELECT'     => ['glpi_plugin_accounts_accounts.login'],
            'FROM'       => 'glpi_plugin_accounts_accounts_items',
            'INNER JOIN' => [
                'glpi_plugin_accounts_accounts' => [
                    'ON' => [
                        'glpi_plugin_accounts_accounts_items' => 'plugin_accounts_accounts_id',
                        'glpi_plugin_accounts_accounts'       => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_plugin_accounts_accounts_items.itemtype' => Provider::class,
                'glpi_plugin_accounts_accounts_items.items_id' => $providerId,
            ],
        ]);

        // Comparaison insensible à la casse faite en PHP plutôt qu'en SQL (LOWER()) —
        // évite toute incertitude sur la syntaxe exacte acceptée par le générateur de
        // requêtes GLPI pour une expression SQL brute en clé de WHERE.
        foreach ($rows as $row) {
            if (strtolower((string)($row['login'] ?? '')) === $wantedLogin) {
                return true;
            }
        }
        return false;
    }

    /**
     * Résumé non sensible (nom, login) d'un compte Accounts existant — utilisé pour
     * l'affichage de la liste des comptes liés à un espace de stockage (StorageAccount,
     * jalon 3), sans jamais exposer le mot de passe/la clé chiffrée. Lecture directe de
     * la table plutôt que via la classe Account : évite toute dépendance à des méthodes
     * non publiques d'Accounts pour un simple affichage.
     */
    public static function getAccountSummary(int $accountId): ?array
    {
        global $DB;

        if (!self::isAvailable() || $accountId <= 0 || !$DB->tableExists('glpi_plugin_accounts_accounts')) {
            return null;
        }

        $row = $DB->request([
            'SELECT'    => [
                'glpi_plugin_accounts_accounts.id',
                'glpi_plugin_accounts_accounts.name',
                'glpi_plugin_accounts_accounts.login',
                'glpi_plugin_accounts_accounttypes.name AS type_name',
            ],
            'FROM'      => 'glpi_plugin_accounts_accounts',
            'LEFT JOIN' => [
                'glpi_plugin_accounts_accounttypes' => [
                    'ON' => [
                        'glpi_plugin_accounts_accounts'     => 'plugin_accounts_accounttypes_id',
                        'glpi_plugin_accounts_accounttypes' => 'id',
                    ],
                ],
            ],
            'WHERE' => ['glpi_plugin_accounts_accounts.id' => $accountId],
        ])->current();

        if (!$row) {
            return null;
        }

        return [
            'id'    => (int)$row['id'],
            'name'  => (string)($row['name'] ?? ''),
            'login' => (string)($row['login'] ?? ''),
            'type'  => (string)($row['type_name'] ?? ''),
        ];
    }

    /**
     * Liste des comptes Accounts visibles pour une entité (elle-même + SOUS-entités,
     * recoupé avec les entités réellement actives de la session en cours), avec un
     * libellé enrichi "Nom — Type · lié à : X, Y" — retour de Luc (onglet Comptes de
     * StorageSpace) : le nom seul ne suffit pas à distinguer des comptes, il faut voir
     * le type de compte et les éléments déjà liés (Account_Item).
     *
     * Portée corrigée (retour de Luc — "371 comptes accessibles" invisibles) : une
     * première version filtrait sur l'entité ET SES ANCÊTRES récursifs, comme pour les
     * empreintes Accounts (AccountsVault::listHashes) — pertinent pour un réglage qui
     * "descend" depuis une entité parente, mais faux ici : les comptes créés dans une
     * SOUS-entité de l'espace de stockage n'apparaissaient jamais. Même logique que
     * Dropdown::show(..., ['entity' => $id, 'entity_sons' => true]) : entité + branche
     * descendante, filtrée par ce que la session en cours peut effectivement voir.
     *
     * @return array<int, string> id => libellé, prêt pour Dropdown::showFromArray().
     */
    public static function listAccountsForDropdown(int $entities_id): array
    {
        global $DB;

        if (!self::isAvailable() || !$DB->tableExists('glpi_plugin_accounts_accounts')) {
            return [];
        }

        $branch          = getSonsOf('glpi_entities', $entities_id);
        $visibleEntities = \Session::getMatchingActiveEntities($branch);
        if (empty($visibleEntities)) {
            return [];
        }

        $where = ['glpi_plugin_accounts_accounts.entities_id' => $visibleEntities];

        $accounts = [];
        foreach ($DB->request([
            'SELECT'    => [
                'glpi_plugin_accounts_accounts.id',
                'glpi_plugin_accounts_accounts.name',
                'glpi_plugin_accounts_accounts.login',
                'glpi_plugin_accounts_accounttypes.name AS type_name',
            ],
            'FROM'      => 'glpi_plugin_accounts_accounts',
            'LEFT JOIN' => [
                'glpi_plugin_accounts_accounttypes' => [
                    'ON' => [
                        'glpi_plugin_accounts_accounts'     => 'plugin_accounts_accounttypes_id',
                        'glpi_plugin_accounts_accounttypes' => 'id',
                    ],
                ],
            ],
            'WHERE' => $where,
            'ORDER' => 'glpi_plugin_accounts_accounts.name ASC',
        ]) as $row) {
            $accounts[(int)$row['id']] = [
                'name'  => (string)($row['name'] ?? ''),
                'type'  => (string)($row['type_name'] ?? ''),
                'links' => [],
            ];
        }

        if (empty($accounts)) {
            return [];
        }

        if ($DB->tableExists('glpi_plugin_accounts_accounts_items')) {
            foreach ($DB->request([
                'FROM'  => 'glpi_plugin_accounts_accounts_items',
                'WHERE' => ['plugin_accounts_accounts_id' => array_keys($accounts)],
            ]) as $link) {
                $accountId = (int)$link['plugin_accounts_accounts_id'];
                $itemtype  = (string)($link['itemtype'] ?? '');
                $itemsId   = (int)($link['items_id'] ?? 0);
                if (!isset($accounts[$accountId]) || $itemtype === '' || !class_exists($itemtype)) {
                    continue;
                }
                try {
                    $itemName = \Dropdown::getDropdownName($itemtype::getTable(), $itemsId);
                } catch (\Throwable $e) {
                    $itemName = '';
                }
                if ($itemName !== '' && $itemName !== '&nbsp;') {
                    $accounts[$accountId]['links'][] = $itemName;
                }
            }
        }

        $out = [];
        foreach ($accounts as $id => $account) {
            $out[$id] = self::formatAccountDropdownLabel($account);
        }
        return $out;
    }

    private static function formatAccountDropdownLabel(array $account): string
    {
        $label = $account['name'] !== '' ? $account['name'] : __('(sans nom)', 'backupgestion');

        $meta = [];
        if ($account['type'] !== '') {
            $meta[] = $account['type'];
        }
        if (!empty($account['links'])) {
            $meta[] = sprintf(__('lié à : %s', 'backupgestion'), implode(', ', $account['links']));
        }
        if (!empty($meta)) {
            $label .= ' — ' . implode(' · ', $meta);
        }

        return $label;
    }

    // ------------------------------------------------------------------
    // Association Account_Item (aucune donnée sensible — simple ligne de liaison)
    // ------------------------------------------------------------------

    public static function linkToItem(int $accountId, string $itemtype, int $itemsId): bool
    {
        if (!self::isAvailable() || !class_exists('\GlpiPlugin\Accounts\Account_Item')) {
            throw new \RuntimeException(__('Le plugin Accounts n\'est pas disponible.', 'backupgestion'));
        }

        $account = new \GlpiPlugin\Accounts\Account();
        if (!$account->getFromDB($accountId)) {
            throw new \RuntimeException(__('Compte Accounts introuvable.', 'backupgestion'));
        }

        $link  = new \GlpiPlugin\Accounts\Account_Item();
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

    public static function provisionDefaultAccountType(): void
    {
        foreach ([self::ADMIN_ACCOUNTTYPE_NAME, self::CRYPTKEY_ACCOUNTTYPE_NAME] as $name) {
            self::provisionAccountTypeByName($name);
        }
    }

    private static function provisionAccountTypeByName(string $name): void
    {
        global $DB;

        $table = 'glpi_plugin_accounts_accounttypes';
        if (!$DB->tableExists($table) || !$DB->fieldExists($table, 'name')) {
            return;
        }

        $localizedName = __($name, 'backupgestion');

        $existing = $DB->request(['FROM' => $table, 'WHERE' => ['name' => $localizedName]])->current();
        if ($existing) {
            return;
        }

        $insert = ['name' => $localizedName];
        if ($DB->fieldExists($table, 'comment')) {
            $insert['comment'] = __('Créé automatiquement par le plugin BackupGestion — CDC 4.4.', 'backupgestion');
        }
        if ($DB->fieldExists($table, 'entities_id')) {
            $insert['entities_id'] = 0;
        }
        if ($DB->fieldExists($table, 'is_recursive')) {
            $insert['is_recursive'] = 1;
        }

        $DB->insert($table, $insert);
    }
}
