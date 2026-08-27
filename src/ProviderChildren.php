<?php

namespace GlpiPlugin\Backupgestion;

use CommonGLPI;
use Plugin;

/**
 * Onglet "Sous-tenants" sur la fiche provider — regroupe la liste des tenants
 * enfants rattachés (découverte automatique + déplacement rapide d'entité) et le
 * bouton de découverte, séparés de la fiche principale pour l'alléger (retour de
 * Luc). Suit le même mécanisme d'onglet que ProviderAccountsDefaults/Right.
 */
class ProviderChildren extends CommonGLPI
{
    public static $rightname = 'plugin_backupgestion_provider';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Provider && ($item->fields['id'] ?? 0)) {
            $nb = countElementsInTable(Provider::getTable(), [
                'backupgestion_providers_id_parent' => $item->fields['id'],
                'is_deleted'                          => 0,
            ]);
            return self::createTabEntry(__('Sous-tenants', 'backupgestion'), $nb, null, 'ti ti-hierarchy-2');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Provider || !($item->fields['id'] ?? 0)) {
            return false;
        }

        global $DB;

        $children = [];

        // Même filtrage que l'ancien affichage sur la fiche principale : n'afficher
        // que les enfants dont l'entité est visible depuis celle du parent (retour
        // de Luc), un tenant fraîchement découvert (entities_id = 0) restant visible
        // pour permettre son rattachement via le transfert rapide.
        $visibleEntities = self::getDescendantEntityIds((int)$item->fields['entities_id']);
        if (!in_array(0, $visibleEntities, true)) {
            $visibleEntities[] = 0;
        }

        foreach ($DB->request([
            'FROM'  => Provider::getTable(),
            'WHERE' => [
                'backupgestion_providers_id_parent' => $item->fields['id'],
                'entities_id'                        => $visibleEntities,
                'is_deleted'                          => 0,
            ],
            'ORDER' => 'name ASC',
        ]) as $row) {
            $row['entity_name'] = ((int)$row['entities_id'] === 0)
                ? __('(non attribuée)', 'backupgestion')
                : \Dropdown::getDropdownName('glpi_entities', (int)$row['entities_id']);

            $row['quickmove_widget'] = \Dropdown::show('Entity', [
                'name'    => 'quickmove_entity_' . $row['id'],
                'value'   => (int)$row['entities_id'],
                'width'   => '100%',
                'rand'    => mt_rand(),
                'display' => false,
            ]);

            $children[] = $row;
        }

        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@backupgestion/provider-children.html.twig',
            [
                'item'      => $item,
                'children'  => $children,
                'webdir'    => Plugin::getWebDir('backupgestion'),
                'canUpdate' => Provider::canUpdate(),
            ]
        );

        return true;
    }

    /**
     * Entité donnée + toutes ses sous-entités — copie de Provider::getDescendantEntityIds()
     * (privée là-bas), même principe de lecture directe de glpi_entities.entities_id.
     */
    private static function getDescendantEntityIds(int $entities_id): array
    {
        global $DB;

        $result   = [$entities_id];
        $frontier = [$entities_id];
        $guard    = 0;

        while (!empty($frontier) && $guard++ < 50) {
            $next = [];
            foreach ($DB->request([
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['entities_id' => $frontier],
                'SELECT' => ['id'],
            ]) as $row) {
                $id = (int)$row['id'];
                if (!in_array($id, $result, true)) {
                    $result[] = $id;
                    $next[]   = $id;
                }
            }
            $frontier = $next;
        }

        return $result;
    }
}
