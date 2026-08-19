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

    // listStorages(), listDevices(), listBackupPlans(), listBackupStats() — jalon 3
    // (live query, CDC 2.1/4.3) : volontairement absents tant que jalon 2 n'est pas
    // validé (authentification d'abord, données ensuite).
}
