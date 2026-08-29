<?php

namespace GlpiPlugin\Backupgestion;

/**
 * Provider Acronis Cyber Protect Cloud (V1 — CDC 4.3).
 *
 * Authentification OAuth2 client_credentials confirmée via la documentation
 * officielle (developer.acronis.com/doc/outbound/apis/authentication) :
 *   POST {datacenter_url}/api/2/idp/token
 *   Content-Type: application/x-www-form-urlencoded
 *   Authorization: Basic base64(client_id:client_secret)
 *   Body: grant_type=client_credentials
 * Réponse 200 attendue, JSON contenant notamment access_token, expires_on, token_type.
 */
class AcronisProvider implements ProviderInterface
{
    private string $clientId;
    private string $clientSecret;
    private string $datacenterUrl;

    public function __construct(array $credentials)
    {
        $this->clientId      = $credentials['client_id'] ?? '';
        $this->clientSecret  = $credentials['client_secret'] ?? '';
        $this->datacenterUrl = rtrim($credentials['datacenter_url'] ?? '', '/');
    }

    public static function getLabel(): string
    {
        return 'Acronis Cyber Protect Cloud';
    }

    public static function getCredentialFields(): array
    {
        return [
            'client_id'      => ['label' => __('Client ID', 'backupgestion'), 'type' => 'text', 'required' => true],
            'client_secret'  => ['label' => __('Client Secret', 'backupgestion'), 'type' => 'password', 'required' => true],
            'datacenter_url' => ['label' => __('URL du datacenter', 'backupgestion'), 'type' => 'text', 'required' => true, 'placeholder' => 'https://eu-cloud.acronis.com'],
        ];
    }

    /**
     * Récupère un access token via le flux client_credentials. Ne stocke rien —
     * jetable, valable ~2h côté Acronis, à ré-obtenir à chaque appel API distinct
     * (un cache technique très court pourra être ajouté au jalon 3 si besoin, sans
     * jamais devenir un mécanisme de synchronisation — CDC 4.3).
     *
     * @throws \RuntimeException si la connexion échoue, avec un message explicite.
     */
    public function fetchAccessToken(): array
    {
        if ($this->clientId === '' || $this->clientSecret === '' || $this->datacenterUrl === '') {
            throw new \RuntimeException(__('Identifiants API incomplets (client_id, client_secret ou URL de datacenter manquant).', 'backupgestion'));
        }

        $url = $this->datacenterUrl . '/api/2/idp/token';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['grant_type' => 'client_credentials']),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException(sprintf(__('Connexion impossible à %s : %s', 'backupgestion'), $url, $error));
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException(sprintf(
                __('Authentification refusée par Acronis (HTTP %d) — vérifiez client_id, client_secret et l\'URL du datacenter.', 'backupgestion'),
                $httpCode
            ));
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException(__('Réponse Acronis inattendue : aucun access_token reçu.', 'backupgestion'));
        }

        return $data;
    }

    public function testConnection(): bool
    {
        $this->fetchAccessToken();
        return true;
    }

    // ------------------------------------------------------------------
    // Hiérarchie des tenants (CDC 4.2 ter) — confirmé via la documentation
    // officielle Account Management API (developer.acronis.com/doc/account-management) :
    //   GET {base_url}/clients/{client_id}          -> { tenant_id: "..." }
    //   GET {base_url}/tenants/{tenant_id}/children  -> { items: [ {id, name, kind, parent_id, has_children, enabled}, ... ] }
    // ------------------------------------------------------------------

    private function apiGet(string $path, string $accessToken, array $query = []): array
    {
        $url = $this->datacenterUrl . '/api/2' . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException(sprintf(__('Connexion impossible à %s : %s', 'backupgestion'), $url, $error));
        }
        if ($httpCode !== 200) {
            throw new \RuntimeException(sprintf(__('Appel API refusé (HTTP %d) sur %s.', 'backupgestion'), $httpCode, $path));
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf(__('Réponse inattendue de l\'API sur %s.', 'backupgestion'), $path));
        }
        return $data;
    }

    /**
     * ID du tenant réel auquel appartiennent les identifiants API utilisés (client_id).
     */
    public function getOwnTenantId(string $accessToken): string
    {
        $data = $this->apiGet('/clients/' . rawurlencode($this->clientId), $accessToken);
        if (empty($data['tenant_id'])) {
            throw new \RuntimeException(__('Impossible de déterminer le tenant associé à ce client API.', 'backupgestion'));
        }
        return (string)$data['tenant_id'];
    }

    /**
     * Liste les tenants enfants directs d'un tenant donné (id, name, kind, parent_id,
     * has_children, enabled). Tableau vide si le tenant n'a pas d'enfant.
     */
    public function listChildTenants(string $tenantId, string $accessToken): array
    {
        $data = $this->apiGet('/tenants/' . rawurlencode($tenantId) . '/children', $accessToken, ['include_details' => 'true']);
        return $data['items'] ?? [];
    }

    /**
     * Authentifie, détermine le tenant racine (celui du client_id utilisé), puis liste
     * ses tenants enfants directs. Point d'entrée unique pour la découverte de hiérarchie.
     */
    public function discoverChildTenants(): array
    {
        $token    = $this->fetchAccessToken();
        $tenantId = $this->getOwnTenantId($token['access_token']);
        $children = $this->listChildTenants($tenantId, $token['access_token']);

        return ['tenant_id' => $tenantId, 'children' => $children];
    }

    // ------------------------------------------------------------------
    // Dashboards live (jalon 3, CDC 2.1) — appareils, plans, statistiques. Appelées à
    // chaque affichage de page, jamais par un job de synchro (aucun mirroir local).
    // ------------------------------------------------------------------

    /**
     * GET paginé (curseur `after`/`limit`, confirmé via la documentation officielle
     * developer.acronis.com/doc/outbound/apis/pagination.html) — jusqu'à $maxPages
     * pages de $limit éléments, pour rester borné en cas de tenant très volumineux
     * (CDC 4.7 : "la tâche périodique de détection doit rester paginée").
     */
    private function apiGetPaginated(string $path, string $accessToken, array $query = [], int $limit = 200, int $maxPages = 25): array
    {
        $items  = [];
        $after  = null;
        $pages  = 0;

        do {
            $pageQuery = $query + ['limit' => $limit];
            if ($after !== null) {
                $pageQuery['after'] = $after;
            }

            $url = $this->datacenterUrl . '/api' . $path . '?' . http_build_query($pageQuery);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $errno    = curl_errno($ch);
            $error    = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0) {
                throw new \RuntimeException(sprintf(__('Connexion impossible à %s : %s', 'backupgestion'), $url, $error));
            }
            if ($httpCode !== 200) {
                throw new \RuntimeException(sprintf(__('Appel API refusé (HTTP %d) sur %s.', 'backupgestion'), $httpCode, $path));
            }

            $data = json_decode((string)$response, true);
            if (!is_array($data)) {
                throw new \RuntimeException(sprintf(__('Réponse inattendue de l\'API sur %s.', 'backupgestion'), $path));
            }

            foreach (($data['items'] ?? []) as $item) {
                $items[] = $item;
            }

            $after = $data['paging']['cursors']['after'] ?? null;
            $pages++;
        } while ($after !== null && $pages < $maxPages);

        return $items;
    }

    /**
     * Appareils (agents) visibles depuis le tenant de ce provider — Agent Management API
     * (developer.acronis.com/doc/outbound/apis/api-library/agents/managing-agents/fetch-agents.html) :
     *   GET {datacenter_url}/api/agent_manager/v2/agents
     * Inclut les agents des tenants enfants sauf barrière de visibilité (comportement
     * natif de l'API, pas un choix de BackupGestion).
     */
    public function listDevices(): array
    {
        $token = $this->fetchAccessToken();
        $raw   = $this->apiGetPaginated('/agent_manager/v2/agents', $token['access_token']);

        $devices = [];
        foreach ($raw as $item) {
            $devices[] = [
                'id'        => (string)($item['id'] ?? ''),
                'name'      => (string)($item['hostname'] ?? ($item['name'] ?? '')),
                'online'    => !empty($item['online']),
                'enabled'   => !empty($item['enabled']),
                'platform'  => (string)($item['platform']['family'] ?? ''),
                // Tenant réel propriétaire de l'appareil (peut différer du tenant du
                // provider interrogé : l'API remonte aussi les appareils des tenants
                // enfants) — indispensable pour la déduplication par identité réelle
                // (CDC 4.2 ter), jamais par providers_id local.
                'tenant_id' => (string)($item['tenant']['id'] ?? ''),
                'tenant'    => (string)($item['tenant']['name'] ?? ''),
                'registered_at' => (string)($item['registration_date'] ?? ''),
            ];
        }
        return $devices;
    }

    /**
     * Plans de sauvegarde (protection plans/policies) — Resource and Policy Management
     * API (developer.acronis.com/doc/resource-policy-management/v4/guide/plans-and-policies/fetching-plans-policies.html) :
     *   GET {datacenter_url}/api/policy_management/v4/policies
     * La réponse imbrique un "plan de protection" composite (`policy.protection.total`)
     * ET l'ensemble de ses composants sous une même clé `policy` (tableau) par élément —
     * un plan combiné sauvegarde+antivirus+patch management renvoie donc, pêle-mêle,
     * des entrées de type policy.backup.*, policy.security.patch_management,
     * policy.security.gen_ai, policy.security.data_protection_map, etc. (confirmé via
     * la documentation officielle, exemple de réponse "Fetching a list of policies").
     * Seules les entrées réellement de sauvegarde (type préfixé "policy.backup.",
     * cf. "Machine backup policy" = policy.backup.machine) sont conservées ici — retour
     * de Luc : des policies de sécurité (patch management, protection IA générative…)
     * apparaissaient à tort dans le tableau "Plans de sauvegarde".
     */
    public function listBackupPlans(): array
    {
        $token = $this->fetchAccessToken();
        $raw   = $this->apiGetPaginated('/policy_management/v4/policies', $token['access_token']);

        $plans = [];
        foreach ($raw as $entry) {
            foreach (($entry['policy'] ?? []) as $policy) {
                $type = (string)($policy['type'] ?? '');
                if (!str_starts_with($type, 'policy.backup.')) {
                    continue;
                }
                $plans[] = [
                    'id'         => (string)($policy['id'] ?? ''),
                    'name'       => (string)($policy['name'] ?? ''),
                    'type'       => $type,
                    'enabled'    => !empty($policy['enabled']),
                    'tenant_id'  => (string)($policy['tenant_id'] ?? ''),
                    'updated_at' => (string)($policy['updated_at'] ?? ''),
                ];
            }
        }
        return $plans;
    }

    /**
     * Statistiques d'usage du tenant de ce provider (volume de stockage, etc.) —
     * Account Management API (developer.acronis.com/doc/account-management/v2/guide/usage-reporting/tenants-usage.html) :
     *   GET {datacenter_url}/api/2/tenants/usages?tenants={tenant_id}
     */
    public function listBackupStats(): array
    {
        $token    = $this->fetchAccessToken();
        $tenantId = $this->getOwnTenantId($token['access_token']);

        // apiGet() préfixe déjà '/api/2' — ne pas le répéter ici (piège rencontré en relecture).
        $data = $this->apiGet('/tenants/usages', $token['access_token'], ['tenants' => $tenantId]);

        $stats = [];
        foreach (($data['items'] ?? []) as $tenantUsages) {
            foreach (($tenantUsages['usages'] ?? []) as $usage) {
                $stats[] = [
                    'name'  => (string)($usage['usage_name'] ?? ($usage['name'] ?? '')),
                    'value' => $usage['value'] ?? null,
                    'unit'  => (string)($usage['measurement_unit'] ?? ''),
                    'type'  => (string)($usage['type'] ?? ''),
                ];
            }
        }
        return $stats;
    }
}
