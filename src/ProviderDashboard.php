<?php

namespace GlpiPlugin\Backupgestion;

use CommonGLPI;
use Plugin;

/**
 * Onglet "Tableau de bord" sur la fiche provider — appareils, plans de sauvegarde et
 * statistiques d'usage, interrogés en direct à l'API à chaque affichage (CDC 2.1) :
 * aucune donnée n'est mirrorée localement, pas de Search::show ni d'actions massives
 * sur ces vues (décision d'architecture "pas un plugin miroir").
 *
 * N'apparaît que pour un provider disposant de ses propres identifiants API — un
 * sous-tenant simplement découvert (sans Credential propre) n'a rien à interroger
 * directement, ses appareils remontent via le provider parent (CDC 4.4).
 */
class ProviderDashboard extends CommonGLPI
{
    public static $rightname = 'plugin_backupgestion_provider';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Provider && ($item->fields['id'] ?? 0) && Credential::existsForProvider((int)$item->fields['id'])) {
            return self::createTabEntry(__('Tableau de bord', 'backupgestion'), 0, null, 'ti ti-chart-bar');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Provider || !($item->fields['id'] ?? 0)) {
            return false;
        }

        $providerId = (int)$item->fields['id'];
        if (!Credential::existsForProvider($providerId)) {
            return false;
        }

        $devices = null;
        $plans   = null;
        $stats   = null;
        $errors  = [];

        try {
            $key         = KeyDerivation::deriveKey($item->fields);
            $credentials = Credential::getForProvider($providerId, $key);
            $acronis     = ProviderFactory::create($item->fields['provider_type'] ?: 'acronis', $credentials);

            if (!$acronis instanceof AcronisProvider) {
                throw new \RuntimeException(__('Tableau de bord non disponible pour ce type de provider.', 'backupgestion'));
            }

            // Trois appels indépendants : l'échec de l'un ne doit jamais empêcher
            // l'affichage des deux autres (retour de Luc — même principe que les
            // zones "bonus" déjà en place ailleurs sur cette fiche).
            try {
                $devices = $acronis->listDevices();
            } catch (\Throwable $e) {
                $errors['devices'] = $e->getMessage();
            }
            try {
                $plans = $acronis->listBackupPlans();
            } catch (\Throwable $e) {
                $errors['plans'] = $e->getMessage();
            }
            try {
                $stats = $acronis->listBackupStats();
            } catch (\Throwable $e) {
                $errors['stats'] = $e->getMessage();
            }
        } catch (\Throwable $e) {
            $errors['global'] = $e->getMessage();
        }

        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@backupgestion/provider-dashboard.html.twig',
            [
                'item'    => $item,
                'webdir'  => Plugin::getWebDir('backupgestion'),
                'devices' => $devices,
                'plans'   => $plans,
                'stats'   => $stats,
                'errors'  => $errors,
            ]
        );

        return true;
    }
}
