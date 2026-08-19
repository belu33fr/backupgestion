<?php

namespace GlpiPlugin\Backupgestion;

use CommonGLPI;
use DbUtils;
use Profile;
use ProfileRight;
use Session;

/**
 * Matrice des 5 droits BackupGestion (CDC 4.6) : chaque droit est un bit
 * indépendant, combinable librement dans n'importe quel profil GLPI par
 * l'administrateur — le plugin n'impose pas de "profils" figés.
 */
class Right extends Profile
{
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0): string
    {
        return 'BackupGestion';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() === 'Profile' && $item->getField('interface') === 'central') {
            return self::createTabEntry('Sauvegardes');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item->getType() === 'Profile') {
            $ID   = $item->getID();
            $prof = new self();

            // Initialiser les droits manquants avec 0
            $profileRight = new ProfileRight();
            $dbu = new DbUtils();
            foreach (array_column(self::getAllRights(), 'field') as $right) {
                if ($dbu->countElementsInTable('glpi_profilerights', ['profiles_id' => $ID, 'name' => $right]) === 0) {
                    $profileRight->add(['profiles_id' => $ID, 'name' => $right, 'rights' => 0]);
                }
            }

            $prof->showForm($ID);
        }
        return true;
    }

    public function showForm($profiles_id = 0, $openform = true, $closeform = true)
    {
        $canedit = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE]);
        $profile = new Profile();
        $profile->getFromDB($profiles_id);

        ob_start();
        $profile->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => __('BackupGestion', 'backupgestion'),
        ]);
        $rights_matrix = ob_get_clean();

        \Glpi\Application\View\TemplateRenderer::getInstance()->display(
            '@backupgestion/profile.html.twig',
            [
                'canedit'          => $canedit,
                'profile_form_url' => $profile->getFormURL(),
                'rights_matrix'    => $rights_matrix,
                'profiles_id'      => $profiles_id,
            ]
        );

        return true;
    }

    /**
     * Les 5 droits du plugin — cf. CDC 4.6 pour la portée exacte de chacun.
     */
    public static function getAllRights(): array
    {
        // Un seul jeu de libellés, réutilisé identique sur toutes les lignes : la matrice
        // GLPI fusionne les colonnes par libellé exact — des libellés différents pour un
        // même bit (ex. "Lire" vs "Lecture") créent deux colonnes distinctes au lieu d'une.
        $labels = [
            READ   => __('Lire'),
            CREATE => __('Créer'),
            UPDATE => __('Modifier'),
            DELETE => __('Supprimer'),
            PURGE  => __('Purger'),
        ];

        return [
            [
                'itemtype' => Provider::class,
                'label'    => __('Administration', 'backupgestion'),
                'field'    => 'plugin_backupgestion_admin',
                'rights'   => $labels,
            ],
            [
                'itemtype' => Provider::class,
                'label'    => __('Provider', 'backupgestion'),
                'field'    => 'plugin_backupgestion_provider',
                // Création, modification et suppression (corbeille) d'un provider sans
                // avoir l'administration complète ; la purge définitive reste réservée à
                // "Administration" (plugin_backupgestion_admin).
                'rights'   => array_intersect_key($labels, array_flip([READ, CREATE, UPDATE, DELETE])),
            ],
            [
                'itemtype' => Provider::class,
                'label'    => __('Financier', 'backupgestion'),
                'field'    => 'plugin_backupgestion_financial',
                'rights'   => array_intersect_key($labels, array_flip([READ])),
            ],
            [
                'itemtype' => Provider::class,
                'label'    => __('Administration technique', 'backupgestion'),
                'field'    => 'plugin_backupgestion_tenant_admin',
                'rights'   => array_intersect_key($labels, array_flip([READ, UPDATE])),
            ],
            [
                'itemtype' => Provider::class,
                'label'    => __('Technicien', 'backupgestion'),
                'field'    => 'plugin_backupgestion_technician',
                'rights'   => array_intersect_key($labels, array_flip([READ, UPDATE])),
            ],
        ];
    }

    public static function addDefaultProfileRights(): void
    {
        global $DB;

        $defaults = [
            'Super-Admin' => [
                'plugin_backupgestion_admin'        => ALLSTANDARDRIGHT,
                'plugin_backupgestion_provider'      => READ | CREATE | UPDATE | DELETE,
                'plugin_backupgestion_financial'     => READ,
                'plugin_backupgestion_tenant_admin'  => READ | UPDATE,
                'plugin_backupgestion_technician'    => READ | UPDATE,
            ],
            'Admin' => [
                'plugin_backupgestion_admin'        => ALLSTANDARDRIGHT,
                'plugin_backupgestion_provider'      => READ | CREATE | UPDATE | DELETE,
                'plugin_backupgestion_financial'     => READ,
                'plugin_backupgestion_tenant_admin'  => READ | UPDATE,
                'plugin_backupgestion_technician'    => READ | UPDATE,
            ],
            'Observer' => [
                'plugin_backupgestion_admin'        => 0,
                'plugin_backupgestion_provider'      => READ,
                'plugin_backupgestion_financial'     => READ,
                'plugin_backupgestion_tenant_admin'  => READ,
                'plugin_backupgestion_technician'    => READ,
            ],
        ];

        $profileRight = new ProfileRight();
        $dbu          = new DbUtils();

        foreach ($DB->request(['FROM' => 'glpi_profiles']) as $profile) {
            $profileId = (int)$profile['id'];
            $rights    = $defaults[$profile['name']] ?? [
                'plugin_backupgestion_admin'        => 0,
                'plugin_backupgestion_provider'      => 0,
                'plugin_backupgestion_financial'     => 0,
                'plugin_backupgestion_tenant_admin'  => 0,
                'plugin_backupgestion_technician'    => 0,
            ];

            foreach ($rights as $rightName => $value) {
                if ($dbu->countElementsInTable('glpi_profilerights', ['profiles_id' => $profileId, 'name' => $rightName]) === 0) {
                    $profileRight->add(['profiles_id' => $profileId, 'name' => $rightName, 'rights' => $value]);
                } else {
                    // Ne met à jour que si le droit est encore à 0 (installation fraîche) —
                    // ne doit jamais écraser un paramétrage déjà choisi par l'administrateur.
                    $DB->update(
                        'glpi_profilerights',
                        ['rights' => $value],
                        ['profiles_id' => $profileId, 'name' => $rightName, 'rights' => 0]
                    );
                }
            }
        }
    }

    public static function removeProfileRights(): void
    {
        $rights = array_column(self::getAllRights(), 'field');
        ProfileRight::deleteProfileRights($rights);
    }

    public static function initProfile(): void
    {
        global $DB;
        foreach (self::getAllRights() as $data) {
            $dbu = new DbUtils();
            if ($dbu->countElementsInTable('glpi_profilerights', ['name' => $data['field']]) === 0) {
                ProfileRight::addProfileRights([$data['field']]);
            }
        }

        $profileId = $_SESSION['glpiactiveprofile']['id'] ?? 0;
        if ($profileId) {
            foreach ($DB->request([
                'FROM'  => 'glpi_profilerights',
                'WHERE' => ['profiles_id' => $profileId, 'name' => ['LIKE', '%plugin_backupgestion%']],
            ]) as $prof) {
                $_SESSION['glpiactiveprofile'][$prof['name']] = $prof['rights'];
            }
        }
    }

    // Helpers
    public static function canAdmin(): bool       { return Session::haveRight('plugin_backupgestion_admin', UPDATE); }
    public static function canCreateProvider(): bool { return Session::haveRightsOr('plugin_backupgestion_admin', [CREATE, UPDATE]) || Session::haveRight('plugin_backupgestion_provider', CREATE); }
    public static function canUpdateProvider(): bool { return Session::haveRight('plugin_backupgestion_admin', UPDATE) || Session::haveRight('plugin_backupgestion_provider', UPDATE); }
    public static function canDeleteProvider(): bool { return Session::haveRight('plugin_backupgestion_admin', DELETE) || Session::haveRight('plugin_backupgestion_provider', DELETE); }
    public static function canPurgeProvider(): bool  { return Session::haveRight('plugin_backupgestion_admin', PURGE); }
    public static function canManageTenant(): bool { return Session::haveRightsOr('plugin_backupgestion_admin', [UPDATE]) || Session::haveRight('plugin_backupgestion_tenant_admin', UPDATE); }
    public static function canManageTechnician(): bool { return Session::haveRightsOr('plugin_backupgestion_admin', [UPDATE]) || Session::haveRight('plugin_backupgestion_technician', UPDATE); }
    public static function canReadFinancial(): bool { return Session::haveRight('plugin_backupgestion_financial', READ); }
}
