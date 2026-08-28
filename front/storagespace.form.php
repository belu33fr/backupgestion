<?php

use GlpiPlugin\Backupgestion\StorageAccount;
use GlpiPlugin\Backupgestion\StorageSpace;

include('../../../inc/includes.php');

if (!StorageSpace::canView()) {
    Html::displayRightError();
}

$storage = new StorageSpace();

if (isset($_POST['link_account'])) {
    // Lie un compte Accounts EXISTANT à cet espace de stockage (onglet "Comptes" —
    // CDC 2.1, jalon 3 : un espace de stockage peut avoir plusieurs comptes :
    // identifiant, admin, clé de chiffrement…). Pas de "rôle" saisi ici : c'est le
    // "Type de compte" natif d'Accounts qui porte déjà cette information — role est
    // conservé côté schéma (compatibilité) mais toujours vide depuis cet écran
    // (retour de Luc).
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if (!$storage->getFromDB($id) || !$storage->can($id, UPDATE)) {
        echo json_encode(['success' => false, 'message' => __('Accès refusé.', 'backupgestion')]);
        exit;
    }
    $accountId = (int)($_POST['plugin_accounts_accounts_id'] ?? 0);
    if ($accountId <= 0) {
        echo json_encode(['success' => false, 'message' => __('Veuillez sélectionner un compte.', 'backupgestion')]);
        exit;
    }
    $linkId = StorageAccount::linkAccount($id, '', $accountId);
    echo json_encode(['success' => $linkId > 0, 'link_id' => $linkId]);
    exit;

} elseif (isset($_POST['unlink_account'])) {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if (!$storage->getFromDB($id) || !$storage->can($id, UPDATE)) {
        echo json_encode(['success' => false, 'message' => __('Accès refusé.', 'backupgestion')]);
        exit;
    }
    $linkId = (int)($_POST['link_id'] ?? 0);
    $link   = new StorageAccount();
    if (!$link->getFromDB($linkId) || (int)$link->fields['backupgestion_storages_id'] !== $id) {
        echo json_encode(['success' => false, 'message' => __('Lien introuvable.', 'backupgestion')]);
        exit;
    }
    $ok = StorageAccount::unlink($linkId);
    echo json_encode(['success' => $ok]);
    exit;

} elseif (isset($_POST['add'])) {
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
        StorageSpace::class
    );

    $storage->display([
        'id'          => $ID,
        'formoptions' => "data-track-changes='true'",
    ]);

    Html::footer();
}
