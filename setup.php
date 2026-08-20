<?php

/**
 * BackupGestion - Plugin GLPI 11 de visualisation des sauvegardes multi-provider (Acronis en V1)
 */

define('PLUGIN_BACKUPGESTION_VERSION', '0.3.0-dev');
define('PLUGIN_BACKUPGESTION_MIN_GLPI', '11.0.0');
define('PLUGIN_BACKUPGESTION_MAX_GLPI', '12.0.0');

use Glpi\Plugin\Hooks;
use GlpiPlugin\Backupgestion\Provider;
use GlpiPlugin\Backupgestion\ProviderAccountsDefaults;
use GlpiPlugin\Backupgestion\Right;

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

    // Enregistrer la classe pour que getItemForItemtype() la trouve
    Plugin::registerClass(Provider::class, [
        'dropdown_itemtypes' => true,
    ]);

    // Onglet "Sauvegardes" dans les profils (matrice des 5 droits, 4.6)
    Plugin::registerClass(Right::class, ['addtabon' => 'Profile']);

    // Onglet "Valeurs par défaut" (Accounts) sur la fiche provider, séparé de la
    // fiche principale pour la clarté de l'interface (retour de Luc).
    Plugin::registerClass(ProviderAccountsDefaults::class, ['addtabon' => Provider::class]);

    // Initialiser les droits dans la session courante
    Right::initProfile();

    // Enregistre Provider comme type "associable" auprès du plugin Accounts (CDC 4.4) :
    // ajoute automatiquement l'onglet natif "Comptes associés" (liste, déchiffrement,
    // etc.) sur la fiche provider — doit être appelé avant le hook POST_INIT d'Accounts,
    // qui construit ses onglets à partir des types enregistrés à ce stade.
    if (class_exists('\GlpiPlugin\Accounts\Account')) {
        \GlpiPlugin\Accounts\Account::registerType(Provider::class);
    }

    // NB : tâche CRON (détection périodique des rattachements, 4.7) arrive au jalon 3 —
    // volontairement absente de ce squelette.
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
