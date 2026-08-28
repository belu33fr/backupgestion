<?php

/**
 * BackupGestion - Plugin GLPI 11 de visualisation des sauvegardes multi-provider (Acronis en V1)
 */

define('PLUGIN_BACKUPGESTION_VERSION', '0.9.1-dev');
define('PLUGIN_BACKUPGESTION_MIN_GLPI', '11.0.0');
define('PLUGIN_BACKUPGESTION_MAX_GLPI', '12.0.0');

use Glpi\Plugin\Hooks;
use GlpiPlugin\Backupgestion\Provider;
use GlpiPlugin\Backupgestion\ProviderAccounts;
use GlpiPlugin\Backupgestion\ProviderAccountsDefaults;
use GlpiPlugin\Backupgestion\ProviderChildren;
use GlpiPlugin\Backupgestion\Right;
use GlpiPlugin\Backupgestion\StorageSpace;

function plugin_init_backupgestion(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['backupgestion'] = true;

    if (!Session::getLoginUserID()) {
        return;
    }

    // Menu dans "Outils"
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['backupgestion'] = [
        'tools' => Provider::class,
    ];

    // Actions massives via hook (compatible namespace GLPI 11)
    $PLUGIN_HOOKS[Hooks::USE_MASSIVE_ACTION]['backupgestion'] = 1;

    // Enregistrer la classe pour que getItemForItemtype() la trouve. "dropdown_itemtypes"
    // n'est pas/plus un attribut reconnu par Plugin::registerClass() dans cette version
    // de GLPI (warning confirmé en log, y compris pour d'autres plugins) — retiré.
    Plugin::registerClass(Provider::class);

    // Espaces de stockage (jalon 3, CDC 2.1) — sous-entrée du même menu "Sauvegardes",
    // pas d'entrée de menu top-level séparée (cf. Provider::getMenuContent()).
    Plugin::registerClass(StorageSpace::class);

    // Onglet "Sauvegardes" dans les profils (matrice des 5 droits, 4.6)
    Plugin::registerClass(Right::class, ['addtabon' => 'Profile']);

    // Onglet "Valeurs par défaut" (Accounts) sur la fiche provider, séparé de la
    // fiche principale pour la clarté de l'interface (retour de Luc).
    Plugin::registerClass(ProviderAccountsDefaults::class, ['addtabon' => Provider::class]);

    // Onglet "Sous-tenants" sur la fiche provider — liste des tenants enfants
    // rattachés + découverte, séparés de la fiche principale pour l'alléger
    // (retour de Luc).
    Plugin::registerClass(ProviderChildren::class, ['addtabon' => Provider::class]);

    // Onglet "Comptes" sur la fiche provider — création de compte (formulaire
    // BackupGestion) + liste des comptes déjà liés (rendue directement par
    // Accounts, Account_Item::showForAsset), regroupées en un seul endroit
    // (retour de Luc). Remplace l'onglet natif "Comptes associés" d'Accounts :
    // Account::registerType(Provider::class) n'est volontairement plus appelé
    // ci-dessous, sinon les deux onglets coexisteraient en faisant doublon.
    Plugin::registerClass(ProviderAccounts::class, ['addtabon' => Provider::class]);

    // Initialiser les droits dans la session courante
    Right::initProfile();

    // NB : tâche CRON (détection périodique des rattachements, 4.7) et pages dashboard
    // live (plans/appareils/stats) — prochaines étapes du jalon 3, pas encore dans ce
    // squelette (StorageSpace, lui, est posé).
}

function plugin_version_backupgestion(): array
{
    return [
        'name'         => 'Gestion Sauvegarde',
        'version'      => PLUGIN_BACKUPGESTION_VERSION,
        'author'       => 'L. Berthaud, Claude (Anthropic)',
        'license'      => 'GPL v2+',
        'homepage'     => 'https://github.com/belu33fr/backupgestion',
        'bugtracker'   => 'https://github.com/belu33fr/backupgestion/issues',
        'readme'       => 'https://github.com/belu33fr/backupgestion/blob/main/docs/BackupGestion_CDC_v1.docx',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_BACKUPGESTION_MIN_GLPI,
                'max' => PLUGIN_BACKUPGESTION_MAX_GLPI,
            ],
            'php' => [
                'min'  => '8.1',
                'exts' => ['curl', 'json', 'openssl'],
            ],
        ],
    ];
}

function plugin_backupgestion_check_prerequisites(): bool
{
    if (!extension_loaded('curl')) {
        echo "Extension PHP curl requise.<br/>";
        return false;
    }
    if (!extension_loaded('openssl')) {
        echo "Extension PHP openssl requise.<br/>";
        return false;
    }
    return true;
}

function plugin_backupgestion_check_config(): bool
{
    return true;
}
