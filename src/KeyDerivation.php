<?php

namespace GlpiPlugin\Backupgestion;

use Entity;
use User;

/**
 * Dérivation locale de la clé protégeant les identifiants API (catégorie a) — CDC 4.4 quater.
 *
 * Cette clé n'est JAMAIS stockée nulle part, ni localement ni dans Accounts : elle est
 * recalculée à la volée via HKDF-SHA256, à partir de trois ingrédients non secrets figés
 * sur la fiche provider au moment de sa création (snapshot) :
 *   - l'entité GLPI du provider (entity_name_snapshot)
 *   - l'utilisateur référent (keyowner_name, keyowner_email)
 *   - un sel aléatoire (key_salt)
 * L'entropie réelle vient du sel (256 bits) ; le nom/e-mail/entité ne sont pas des secrets,
 * ils garantissent simplement que deux providers ne dérivent jamais la même clé.
 *
 * Aucun appel à Accounts, aucune interaction humaine : la tâche périodique de détection
 * (CDC 4.7) peut donc déchiffrer/chiffrer les identifiants API de façon autonome.
 */
class KeyDerivation
{
    private const HASH_ALGO = 'sha256';
    private const KEY_LENGTH = 32; // AES-256

    /**
     * Prépare les 4 valeurs de snapshot à écrire sur une fiche provider à sa création
     * (ou lors d'un changement volontaire d'entité / d'utilisateur référent — CDC 4.4 quater).
     */
    public static function buildSnapshot(int $entities_id, int $users_id): array
    {
        $entityName = '';
        $entity = new Entity();
        if ($entities_id > 0 && $entity->getFromDB($entities_id)) {
            $entityName = $entity->fields['completename'] ?? $entity->fields['name'] ?? '';
        }

        $userName  = '';
        $userEmail = '';
        $user = new User();
        if ($users_id > 0 && $user->getFromDB($users_id)) {
            $userName  = $user->fields['name'] ?? '';
            // getDefaultEmail() est la méthode standard GLPI pour lire l'e-mail principal
            // d'un utilisateur (utilisée par le cœur pour les notifications).
            $userEmail = method_exists($user, 'getDefaultEmail') ? (string)$user->getDefaultEmail() : '';
        }

        return [
            'key_salt'             => bin2hex(random_bytes(32)),
            'users_id_keyowner'    => $users_id,
            'keyowner_name'        => $userName,
            'keyowner_email'       => $userEmail,
            'entity_name_snapshot' => $entityName,
        ];
    }

    /**
     * Dérive la clé AES-256 pour un provider donné à partir de son snapshot figé.
     * $providerFields doit contenir key_salt, keyowner_name, keyowner_email,
     * entity_name_snapshot et id (utilisé comme "info" HKDF pour la séparation de contexte).
     */
    public static function deriveKey(array $providerFields): string
    {
        $salt = hex2bin($providerFields['key_salt'] ?? '');
        if ($salt === false || $salt === '') {
            throw new \RuntimeException('KeyDerivation: sel de dérivation absent sur ce provider.');
        }

        $ikm = implode('|', [
            $providerFields['keyowner_name'] ?? '',
            $providerFields['keyowner_email'] ?? '',
            $providerFields['entity_name_snapshot'] ?? '',
        ]);

        $info = 'backupgestion-provider-' . (string)($providerFields['id'] ?? 0);

        return hash_hkdf(self::HASH_ALGO, $ikm, self::KEY_LENGTH, $info, $salt);
    }
}
