<?php

namespace GlpiPlugin\Backupgestion;

use CommonGLPI;
use Plugin;

/**
 * Onglet "Comptes" sur la fiche espace de stockage (CDC 2.1, jalon 3) — retour
 * explicite de Luc : contrairement au provider (un seul compte "actif" géré via
 * ProviderAccounts), un espace de stockage peut nécessiter PLUSIEURS comptes
 * Accounts simultanément (identifiant de connexion, compte administrateur, clé de
 * chiffrement…).
 *
 * S'appuie sur la table de liaison dédiée StorageAccount (compte Accounts EXISTANT) :
 * on relie ici des comptes déjà créés dans Accounts (éventuellement partagés avec un
 * provider), on n'en crée pas de nouveaux depuis cet onglet — StorageSpace n'a pas de
 * "Valeurs par défaut Accounts" propre, contrairement à Provider. Pas de "rôle" saisi
 * ici : c'est le "Type de compte" natif d'Accounts (accounttype) qui porte déjà cette
 * information — le dupliquer aurait été redondant (retour de Luc).
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

        $storageId  = (int)$item->fields['id'];
        $entitiesId = (int)$item->fields['entities_id'];

        $links = [];
        foreach (StorageAccount::getForStorage($storageId) as $row) {
            $summary = AccountsVault::getAccountSummary((int)$row['plugin_accounts_accounts_id']);
            $links[] = [
                'id'            => (int)$row['id'],
                'account_id'    => (int)$row['plugin_accounts_accounts_id'],
                'account_name'  => $summary['name'] ?? ('#' . $row['plugin_accounts_accounts_id']),
                'account_login' => $summary['login'] ?? '',
                'account_type'  => $summary['type'] ?? '',
                'account_found' => $summary !== null,
            ];
        }

        $accountPicker      = '';
        $accountInfoIcon    = '';
        $addAccountIcon     = '';
        $availableAccounts  = [];

        if (class_exists('\GlpiPlugin\Accounts\Account')) {
            $rand      = mt_rand();
            $fieldName = 'plugin_accounts_accounts_id';
            $fieldId   = \Html::cleanId('dropdown_' . $fieldName . $rand);

            // Libellés enrichis (nom + type de compte + élément déjà lié) plutôt que le
            // nom seul — retour de Luc : le nom seul ne suffit pas à distinguer des
            // comptes qui se ressemblent. Dropdown::showFromArray() n'offre pas
            // nativement l'icône "i" (contrairement à Dropdown::show(), limité lui au
            // nom brut) : on la reconstruit juste après, à l'identique du mécanisme
            // natif (Ajax::updateItemOnSelectEvent + Html::showToolTip), pour conserver
            // le même comportement que Luc a confirmé fonctionnel.
            //
            // Portée entité + ancêtres récursifs (même convention que les empreintes
            // Accounts déjà utilisée ailleurs dans le plugin) : un compte créé sur une
            // entité soeur/enfant, ou une entité parente non marquée récursive, n'est
            // délibérément pas proposé ici — s'il devrait l'être, c'est cette portée
            // qu'il faut ajuster.
            $availableAccounts = AccountsVault::listAccountsForDropdown($entitiesId);

            $accountPicker = \Dropdown::showFromArray($fieldName, $availableAccounts, [
                'value'               => 0,
                'rand'                => $rand,
                'width'               => '100%',
                'display_emptychoice' => true,
                'display'             => false,
            ]);

            global $CFG_GLPI;
            $commentId = \Html::cleanId('comment_' . $fieldName . $rand);
            $linkId    = \Html::cleanId('comment_link_' . $fieldName . $rand);

            $tooltipOptions = ['contentid' => $commentId, 'linkid' => $linkId, 'display' => false];
            if (\GlpiPlugin\Accounts\Account::canView()) {
                $tooltipOptions['link']       = \GlpiPlugin\Accounts\Account::getSearchURL();
                $tooltipOptions['link_class'] = 'btn btn-outline-secondary';
            }

            $commentParams = [
                'value'       => '__VALUE__',
                'itemtype'    => \GlpiPlugin\Accounts\Account::class,
                '_idor_token' => \Session::getNewIDORToken(\GlpiPlugin\Accounts\Account::class),
                'withlink'    => $linkId,
            ];

            $accountInfoIcon = \Ajax::updateItemOnSelectEvent(
                $fieldId,
                $commentId,
                $CFG_GLPI['root_doc'] . '/ajax/comments.php',
                $commentParams,
                false
            );
            $accountInfoIcon .= \Html::showToolTip(__('Voir le compte sélectionné', 'backupgestion'), $tooltipOptions);

            // Bouton "+" pour créer un compte à la volée sans quitter la fiche — même
            // mécanisme que celui que GLPI construit lui-même pour les CommonDropdown
            // dans Dropdown::show() (icône + modal iframe), reproduit ici à la main car
            // Account (Accounts) est un CommonDBTM classique, pas un CommonDropdown, et
            // n'en bénéficie donc pas automatiquement. L'entité de l'espace de stockage
            // est passée en paramètre pour que le formulaire s'ouvre dans la bonne
            // entité (et pas celle actuellement active dans le sélecteur GLPI) — sans
            // quoi Accounts peut réclamer une empreinte de chiffrement absente de
            // l'entité active alors qu'elle existe bien dans celle de l'espace de
            // stockage (retour de Luc, erreur "Il n'existe pas de clé de chiffrement
            // pour cette entité").
            $accountItem = new \GlpiPlugin\Accounts\Account();
            if ($accountItem::canCreate()) {
                $addDomId = 'add_storageaccount_' . mt_rand();
                $addFormURL = \GlpiPlugin\Accounts\Account::getFormURL() . '?entities_id=' . $entitiesId;
                $addAccountIcon = '<div class="btn btn-outline-secondary" title="' . __s('Ajouter un compte', 'backupgestion') . '" data-bs-toggle="modal" data-bs-target="#' . $addDomId . '">';
                $addAccountIcon .= \Ajax::createIframeModalWindow(
                    $addDomId,
                    $addFormURL,
                    ['display' => false, 'reloadonclose' => true]
                );
                $addAccountIcon .= "<span data-bs-toggle='tooltip'><i class='ti ti-plus'></i><span class='sr-only'>" . __s('Ajouter un compte', 'backupgestion') . "</span></span>";
                $addAccountIcon .= '</div>';
            }
        }

        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@backupgestion/storagespace-accounts.html.twig',
            [
                'item'               => $item,
                'links'              => $links,
                'availableAccounts'  => $availableAccounts,
                'accountPicker'      => $accountPicker,
                'accountInfoIcon'    => $accountInfoIcon,
                'addAccountIcon'  => $addAccountIcon,
                'webdir'          => Plugin::getWebDir('backupgestion'),
                'canUpdate'       => StorageSpace::canUpdate(),
            ]
        );

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
