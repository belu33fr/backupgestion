<?php

namespace GlpiPlugin\Backupgestion;

/**
 * Contrat commun à tous les providers de sauvegarde (CDC 4.3).
 * Un seul provider en V1 (AcronisProvider), conçu pour ne pas avoir à toucher
 * au cœur du plugin lorsqu'un autre provider sera ajouté plus tard (Veeam,
 * Synology Active Backup, Cohesity…).
 */
interface ProviderInterface
{
    /**
     * Construit une instance à partir des identifiants déjà déchiffrés
     * (jamais loggués, jamais persistés au-delà de la requête courante).
     */
    public function __construct(array $credentials);

    public static function getLabel(): string;

    /**
     * Champs de credentials attendus par ce provider — utilisé pour générer le
     * formulaire de saisie et savoir quoi chiffrer/stocker dans Credential.
     * Format : ['cred_key' => ['label' => ..., 'type' => 'text'|'password', 'required' => bool]]
     */
    public static function getCredentialFields(): array;

    /**
     * Vérifie que les identifiants permettent bien de s'authentifier auprès du
     * provider. Doit lever une \RuntimeException avec un message explicite en
     * cas d'échec (jamais retourner silencieusement false).
     */
    public function testConnection(): bool;

    /**
     * Liste les appareils (agents) visibles depuis le tenant de ce provider — appel
     * en direct, jamais mirroré localement (CDC 2.1). Chaque élément doit au minimum
     * porter une clé 'id' (identité réelle côté provider, utilisée comme provider_ref
     * pour DeviceLink) et 'name' (libellé lisible).
     *
     * @throws \RuntimeException si l'appel API échoue.
     */
    public function listDevices(): array;

    /**
     * Liste les plans de sauvegarde (backup plans) définis côté provider — appel en
     * direct, jamais mirroré localement (CDC 2.1).
     *
     * @throws \RuntimeException si l'appel API échoue.
     */
    public function listBackupPlans(): array;

    /**
     * Statistiques d'usage (volume, etc.) du tenant de ce provider — appel en
     * direct, jamais mirroré localement (CDC 2.1).
     *
     * @throws \RuntimeException si l'appel API échoue.
     */
    public function listBackupStats(): array;

    // listStorages() — espaces de stockage Acronis natifs découverts côté API —
    // volontairement absent en V1 (jalon 3) : l'endpoint exact de découverte des
    // vaults/ressources de stockage natives n'a pas pu être confirmé avec certitude
    // dans la documentation Acronis à ce stade. La création manuelle des espaces de
    // stockage (nas/file/s3) reste pleinement fonctionnelle en attendant.
}
