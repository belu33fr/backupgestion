<?php

namespace GlpiPlugin\Backupgestion;

use CommonDBTM;

/**
 * Stockage local chiffré des identifiants API (catégorie a — CDC 3.3/4.2).
 * Chiffrement AES-256-CBC ; la clé est fournie par l'appelant (jamais lue ici),
 * calculée par KeyDerivation::deriveKey() à partir du provider concerné.
 */
class Credential extends CommonDBTM
{
    public static $rightname = 'plugin_backupgestion_provider';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_backupgestion_credentials';
    }

    /**
     * Enregistre (crée ou met à jour) un jeu d'identifiants pour un provider,
     * chiffrés avec la clé fournie (dérivée par KeyDerivation, jamais stockée).
     */
    public static function saveForProvider(int $providerId, array $credentials, string $key): void
    {
        global $DB;
        foreach ($credentials as $cred_key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $encrypted = self::encrypt((string)$value, $key);
            $now       = date('Y-m-d H:i:s');
            $existing  = $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['providers_id' => $providerId, 'cred_key' => $cred_key],
            ])->current();
            if ($existing) {
                $DB->update(
                    self::getTable(),
                    ['cred_value' => $encrypted, 'date_mod' => $now],
                    ['id' => $existing['id']]
                );
            } else {
                $DB->insert(self::getTable(), [
                    'providers_id' => $providerId,
                    'cred_key'     => $cred_key,
                    'cred_value'   => $encrypted,
                    'date_mod'     => $now,
                ]);
            }
        }
    }

    /**
     * Relit et déchiffre les identifiants d'un provider avec la clé fournie.
     */
    public static function getForProvider(int $providerId, string $key): array
    {
        global $DB;
        $result = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['providers_id' => $providerId]]) as $row) {
            $result[$row['cred_key']] = self::decrypt($row['cred_value'], $key);
        }
        return $result;
    }

    /**
     * Re-chiffre tous les identifiants existants d'un provider avec une nouvelle clé —
     * utilisé lors d'un changement d'entité ou d'utilisateur référent (CDC 4.4 quater) :
     * déchiffrement avec l'ancienne clé, ré-écriture avec la nouvelle. Retourne false
     * (sans rien modifier) si un seul déchiffrement échoue, pour ne jamais corrompre
     * silencieusement des identifiants existants.
     */
    public static function reencryptForProvider(int $providerId, string $oldKey, string $newKey): bool
    {
        global $DB;

        $rows = iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['providers_id' => $providerId],
        ]));

        if (empty($rows)) {
            return true;
        }

        $plaintexts = [];
        foreach ($rows as $row) {
            $plain = self::decrypt($row['cred_value'], $oldKey);
            if ($plain === '' && $row['cred_value'] !== '') {
                // Déchiffrement impossible avec l'ancienne clé : on n'écrit rien.
                return false;
            }
            $plaintexts[$row['id']] = $plain;
        }

        $now = date('Y-m-d H:i:s');
        foreach ($plaintexts as $id => $plain) {
            $DB->update(
                self::getTable(),
                ['cred_value' => self::encrypt($plain, $newKey), 'date_mod' => $now],
                ['id' => $id]
            );
        }

        return true;
    }

    public static function deleteForProvider(int $providerId): void
    {
        global $DB;
        $DB->delete(self::getTable(), ['providers_id' => $providerId]);
    }

    /**
     * Existence pure (sans déchiffrement) — utilisé pour distinguer un provider avec
     * ses propres identifiants API d'un provider purement découvert par héritage
     * (CDC 4.2 bis/4.4 : un enfant "indépendant" ne doit jamais être re-parenté).
     */
    public static function existsForProvider(int $providerId): bool
    {
        global $DB;
        return $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['providers_id' => $providerId],
        ])->count() > 0;
    }

    /**
     * Existence d'une clé précise (ex. "datacenter_url") pour un provider — utilisé
     * pour valider les champs requis sans redéchiffrer (retour de Luc : un champ
     * requis laissé vide, avec seulement un placeholder à l'écran, s'enregistrait
     * silencieusement vide).
     */
    public static function existsKeyForProvider(int $providerId, string $key): bool
    {
        global $DB;
        return $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['providers_id' => $providerId, 'cred_key' => $key],
        ])->count() > 0;
    }

    // ------------------------------------------------------------------
    // Chiffrement AES-256-CBC
    // ------------------------------------------------------------------

    public static function encrypt(string $value, string $key): string
    {
        if ($value === '') {
            return '';
        }
        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new \RuntimeException('Credential: échec du chiffrement.');
        }
        return base64_encode($iv . $cipher);
    }

    public static function decrypt(string $encrypted, string $key): string
    {
        if ($encrypted === '') {
            return '';
        }
        $raw = base64_decode($encrypted, true);
        if ($raw === false || strlen($raw) < 16) {
            return '';
        }
        $iv     = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain  = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $plain !== false ? $plain : '';
    }
}
