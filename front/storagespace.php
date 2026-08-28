<?php

use GlpiPlugin\Backupgestion\StorageSpace;

include('../../../inc/includes.php');

if (!StorageSpace::canView()) {
    Html::displayRightError();
}

// 4e/5e paramètres : entrée "principale" du menu = Provider (celle enregistrée dans
// MENU_TOADD), 5e param = clé exacte de Provider::getAdditionalMenuOptions() (le nom de
// classe complet, pas un slug arbitraire) pour que le fil d'Ariane retrouve la bonne
// sous-entrée.
Html::header(
    StorageSpace::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'tools',
    \GlpiPlugin\Backupgestion\Provider::class,
    StorageSpace::class
);

Search::show(StorageSpace::class);

Html::footer();
