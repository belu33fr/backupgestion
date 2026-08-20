<?php

namespace GlpiPlugin\Backupgestion;

use CommonGLPI;
use Plugin;

/**
 * Onglet "Valeurs par défaut" sur la fiche provider — regroupe les champs de
 * pré-remplissage utilisés lors de la création d'un compte Accounts (empreinte,
 * type, statut, utilisateur/groupe concerné et responsable, ticket — CDC 4.4 bis),
 * séparés de la fiche principale pour la clarté de l'interface (retour de Luc).
 * Suit exactement le même mécanisme d'onglet que Right (addtabon Profile).
 */
class ProviderAccountsDefaults extends CommonGLPI
{
    public static $rightname = 'plugin_backupgestion_provider';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Provider && ($item->fields['id'] ?? 0)) {
            return self::createTabEntry(__('Valeurs par défaut', 'backupgestion'));
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Provider) {
            return false;
        }

        $entitiesId        = (int)($item->fields['entities_id'] ?? 0);
        $accountsAvailable = false;
        $accountsHashes    = [];
        $accountsTypes     = [];
        $accountsStates    = [];
        try {
            $accountsAvailable = AccountsVault::isAvailable();
            if ($accountsAvailable) {
                $accountsHashes = AccountsVault::listHashes($entitiesId);
                $accountsTypes  = AccountsVault::listAccountTypes($entitiesId);
                $accountsStates = AccountsVault::listAccountStates($entitiesId);
            }
        } catch (\Throwable $e) {
            $accountsAvailable = false;
        }

        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@backupgestion/provider-defaults.html.twig',
            [
                'item'              => $item,
                'webdir'            => Plugin::getWebDir('backupgestion'),
                'accountsAvailable' => $accountsAvailable,
                'accountsHashes'    => $accountsHashes,
                'accountsTypes'     => $accountsTypes,
                'accountsStates'    => $accountsStates,
            ]
        );

        return true;
    }
}
