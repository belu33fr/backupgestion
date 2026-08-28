<?php

namespace GlpiPlugin\Backupgestion;

use CommonGLPI;
use Plugin;

/**
 * Onglet "Comptes" sur la fiche espace de stockage (CDC 2.1, jalon 3) — retour
 * explicite de Luc : contrairement au provider (un seul compte "actif" géré via
 * ProviderAccounts), un espace de stockage peut nécessiter PLUSIEURS comptes
 * Accounts simultanément, chacun avec un rôle différent (identifiant de connexion,
 * compte administrateur, clé de chiffrement…).
 *
 * S'appuie sur la table de liaison dédiée StorageAccount (role + compte Accounts
 * EXISTANT), pas sur Account_Item : on relie ici des comptes déjà créés dans
 * Accounts (éventuellement partagés avec un provider), on n'en crée pas de nouveaux
 * depuis cet onglet — StorageSpace n'a pas de "Valeurs par défaut Accounts" propre
 * (pas d'empreinte de chiffrement configurée par espace de stockage), contrairement
 * à Provider.
 */
class StorageAccounts extends CommonGLPI
{
    public static $rightname = 'plugin_backupgestion_provider';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof StorageSpace || !($item->fields['id'] ?? 0) || !self::accountsAvailable()) {
            return '';
        }

        $nb = StorageAccount::countForStorage((int)$item->fields['id']);
        return self::createTabEntry(__('Comptes', 'backupgestion'), $nb, null, 'ti ti-key');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof StorageSpace || !($item->fields['id'] ?? 0) || !self::accountsAvailable()) {
            return false;
        }

        $storageId = (int)$item->fields['id'];

        $links = [];
        foreach (StorageAccount::getForStorage($storageId) as $row) {
            $summary = AccountsVault::getAccountSummary((int)$row['plugin_accounts_accounts_id']);
            $links[] = [
                'id'            => (int)$row['id'],
                'role'          => (string)$row['role'],
                'role_label'    => self::rolePresets()[$row['role']] ?? $row['role'],
                'account_id'    => (int)$row['plugin_accounts_accounts_id'],
                'account_name'  => $summary['name'] ?? ('#' . $row['plugin_accounts_accounts_id']),
                'account_login' => $summary['login'] ?? '',
                'account_found' => $summary !== null,
            ];
        }

        $accountPicker = '';
        if (class_exists('\GlpiPlugin\Accounts\Account')) {
            $accountPicker = \Dropdown::show(\GlpiPlugin\Accounts\Account::class, [
                'name'        => 'plugin_accounts_accounts_id',
                'entity'      => (int)$item->fields['entities_id'],
                'entity_sons' => true,
                'width'       => '100%',
                'rand'        => mt_rand(),
                'display'     => false,
            ]);
        }

        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@backupgestion/storagespace-accounts.html.twig',
            [
                'item'          => $item,
                'links'         => $links,
                'rolePresets'   => self::rolePresets(),
                'accountPicker' => $accountPicker,
                'webdir'        => Plugin::getWebDir('backupgestion'),
                'canUpdate'     => StorageSpace::canUpdate(),
            ]
        );

        return true;
    }

    /** Rôles suggérés — liste ouverte : "Autre" laisse saisir un libellé libre. */
    public static function rolePresets(): array
    {
        return [
            'login'    => __('Identifiant de connexion', 'backupgestion'),
            'admin'    => __('Compte administrateur', 'backupgestion'),
            'cryptkey' => __('Clé de chiffrement', 'backupgestion'),
            'other'    => __('Autre', 'backupgestion'),
        ];
    }

    private static function accountsAvailable(): bool
    {
        try {
            return AccountsVault::isAvailable();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
