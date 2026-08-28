<?php

use GlpiPlugin\Backupgestion\Provider;

include('../../../inc/includes.php');

if (!Provider::canView()) {
    Html::displayRightError();
}

Html::header(
    Provider::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'tools',
    Provider::class
);

Search::show(Provider::class);

Html::footer();
