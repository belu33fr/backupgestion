<?php

use GlpiPlugin\Backupgestion\StorageSpace;

include('../../../inc/includes.php');

if (!StorageSpace::canView()) {
    Html::displayRightError();
}

$storage = new StorageSpace();

if (isset($_POST['add'])) {
    $storage->check(-1, CREATE, $_POST);
    $newID = $storage->add($_POST);
    if ($newID && ($_SESSION['glpibackcreated'] ?? false)) {
        Html::redirect(StorageSpace::getFormURL() . '?id=' . $newID);
    }
    Html::back();

} elseif (isset($_POST['update'])) {
    $storage->check($_POST['id'], UPDATE);
    $storage->update($_POST);
    Html::back();

} elseif (isset($_POST['delete'])) {
    $storage->check($_POST['id'], DELETE);
    $storage->delete($_POST);
    $storage->redirectToList();

} elseif (isset($_POST['restore'])) {
    $storage->check($_POST['id'], DELETE);
    $storage->restore($_POST);
    $storage->redirectToList();

} elseif (isset($_POST['purge'])) {
    $storage->check($_POST['id'], PURGE);
    $storage->delete($_POST, 1);
    $storage->redirectToList();

} else {
    $ID = (int)($_GET['id'] ?? 0);

    Html::header(
        StorageSpace::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'tools',
        \GlpiPlugin\Backupgestion\Provider::class,
        'storagespace'
    );

    $storage->display([
        'id'          => $ID,
        'formoptions' => "data-track-changes='true'",
    ]);

    Html::footer();
}
