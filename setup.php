<?php

/**
 * BackupGestion - Plugin GLPI 11 de visualisation des sauvegardes multi-provider (Acronis en V1)
 */

define('PLUGIN_BACKUPGESTION_VERSION', '0.2.0-dev');
define('PLUGIN_BACKUPGESTION_MIN_GLPI', '11.0.0');
define('PLUGIN_BACKUPGESTION_MAX_GLPI', '12.0.0');

use Glpi\Plugin\Hooks;
use GlpiPlugin\Backupgestion\Provider;
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

    // Initialiser les droits dans la session courante
    Right::initProfile();

    // NB : tâche CRON (détection périodique des rattachements, 4.7) et page de
    // configuration ("Valeurs par défaut Accounts", 4.4 bis) arrivent aux jalons 2/3 —
    // volontairement absents de ce squelette (jalon 1).
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
