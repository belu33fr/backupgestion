<?php

use GlpiPlugin\Backupgestion\StorageSpace;

include('../../../inc/includes.php');

if (!StorageSpace::canView()) {
    Html::displayRightError();
}

Html::header(
    StorageSpace::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'tools',
    \GlpiPlugin\Backupgestion\Provider::class,
    'storagespace'
);

Search::show(StorageSpace::class);

Html::footer();
