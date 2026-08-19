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
}
