<?php

namespace GlpiPlugin\Backupgestion;

use CommonGLPI;
use Plugin;

/**
 * Onglet "Comptes" sur la fiche provider — regroupe en un seul endroit la
 * création d'un compte utilisateur/admin/clé de chiffrement (formulaire
 * BackupGestion, valeurs par défaut) ET la liste des comptes déjà liés
 * (rendue directement par le plugin Accounts, juste en dessous) — retour de
 * Luc : segmenter clairement les éléments liés au provider plutôt que de
 * disperser "créer" (fiche principale) et "consulter" (onglet natif Accounts)
 * sur deux onglets séparés.
 *
 * Remplace entièrement l'onglet natif "Comptes associés" d'Accounts pour
 * Provider : Account::registerType(Provider::class) n'est plus appelé dans
 * setup.php, donc Accounts n'ajoute plus son propre onglet ici — on affiche
 * sa liste nous-mêmes via Account_Item::showForAsset(), méthode publique
 * réutilisée telle quelle (aucune duplication de logique d'affichage/droits).
 */
class ProviderAccounts extends CommonGLPI
{
    public static $rightname = 'plugin_backupgestion_provider';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Provider || !($item->fields['id'] ?? 0) || !self::accountsAvailable()) {
            return '';
        }

        $nb = 0;
        if (class_exists('\GlpiPlugin\Accounts\Account_Item')) {
            try {
                $nb = \GlpiPlugin\Accounts\Account_Item::countForItem($item);
            } catch (\Throwable $e) {
                $nb = 0;
            }
        }

        return self::createTabEntry(__('Comptes', 'backupgestion'), $nb, null, 'ti ti-key');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Provider || !($item->fields['id'] ?? 0) || !self::accountsAvailable()) {
            return false;
        }

        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@backupgestion/provider-accounts.html.twig',
            [
                'item'           => $item,
                'webdir'         => Plugin::getWebDir('backupgestion'),
                'accountsHashId' => (int)($item->fields['accounts_hash_id'] ?? 0),
                'canUpdate'      => Provider::canUpdate(),
            ]
        );

        // Liste native des comptes déjà liés, affichée directement par Accounts
        // (méthode publique réutilisée à l'identique — mêmes droits, même rendu
        // que son propre onglet historique). N'empêche jamais l'affichage de
        // notre formulaire ci-dessus en cas d'erreur inattendue.
        if (class_exists('\GlpiPlugin\Accounts\Account_Item')) {
            try {
                \GlpiPlugin\Accounts\Account_Item::showForAsset($item);
            } catch (\Throwable $e) {
                // Silencieux — le formulaire de création reste utilisable.
            }
        }

        return true;
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
