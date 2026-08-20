<?php

use GlpiPlugin\Backupgestion\Credential;
use GlpiPlugin\Backupgestion\KeyDerivation;
use GlpiPlugin\Backupgestion\Provider;
use GlpiPlugin\Backupgestion\ProviderFactory;

include('../../../inc/includes.php');

if (!Provider::canView()) {
    Html::displayRightError();
}

$provider = new Provider();

/**
 * Extrait les champs cred_* du POST (identifiants API, catégorie a) — jamais
 * envoyés à Provider::add()/update(), toujours traités séparément via Credential.
 */
function backupgestion_extract_credentials(array &$input): array
{
    $credentials = [];
    foreach (array_keys($input) as $key) {
        if (str_starts_with($key, 'cred_')) {
            $val = trim((string)$input[$key]);
            if ($val !== '') {
                $credentials[substr($key, 5)] = $val;
            }
            unset($input[$key]);
        }
    }
    return $credentials;
}

if (isset($_POST['test_connection'])) {
    // Test de connexion depuis la fiche (AJAX)
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if (!$provider->getFromDB($id) || !$provider->can($id, READ)) {
        echo json_encode(['success' => false, 'message' => __('Accès refusé.', 'backupgestion')]);
        exit;
    }
    try {
        $key         = KeyDerivation::deriveKey($provider->fields);
        $credentials = Credential::getForProvider($id, $key);
        $acronis     = ProviderFactory::create($provider->fields['provider_type'] ?: 'acronis', $credentials);
        $acronis->testConnection();
        echo json_encode(['success' => true]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;

} elseif (isset($_POST['add'])) {
    $input       = $_POST;
    unset($input['add']);
    $credentials = backupgestion_extract_credentials($input);
    error_log('BackupGestion add: cred_keys_found=' . implode(',', array_keys($credentials)));

    $newID = $provider->add($input);
    error_log('BackupGestion add: newID=' . var_export($newID, true));
    if ($newID && !empty($credentials)) {
        try {
            $key = KeyDerivation::deriveKey($provider->fields);
            Credential::saveForProvider($newID, $credentials, $key);
            error_log('BackupGestion add: credentials saved for provider ' . $newID);
        } catch (\Throwable $e) {
            error_log('BackupGestion add: FAILED to save credentials - ' . $e->getMessage());
        }
    } elseif (empty($credentials)) {
        error_log('BackupGestion add: no cred_* fields received in $_POST — form/field name mismatch?');
    }

    if ($newID && ($_SESSION['glpibackcreated'] ?? false)) {
        Html::redirect(Provider::getFormURL() . '?id=' . $newID);
    }
    Html::back();

} elseif (isset($_POST['update'])) {
    $id          = (int)$_POST['id'];
    $input       = $_POST;
    $credentials = backupgestion_extract_credentials($input);
    error_log('BackupGestion update: id=' . $id . ' cred_keys_found=' . implode(',', array_keys($credentials)));

    $updated = $provider->update($input);
    error_log('BackupGestion update: update()=' . var_export($updated, true));
    if ($updated && !empty($credentials)) {
        // $provider->fields reflète l'état après update() — y compris un éventuel
        // nouveau snapshot de clé si l'entité/le référent a changé (Provider::prepareInputForUpdate).
        try {
            $key = KeyDerivation::deriveKey($provider->fields);
            Credential::saveForProvider($id, $credentials, $key);
            error_log('BackupGestion update: credentials saved for provider ' . $id);
        } catch (\Throwable $e) {
            error_log('BackupGestion update: FAILED to save credentials - ' . $e->getMessage());
        }
    } elseif (empty($credentials)) {
        error_log('BackupGestion update: no cred_* fields received in $_POST — form/field name mismatch?');
    }
    Html::back();

} elseif (isset($_POST['delete'])) {
    $provider->check($_POST['id'], DELETE);
    $provider->delete($_POST);
    $provider->redirectToList();

} elseif (isset($_POST['restore'])) {
    $provider->check($_POST['id'], DELETE);
    $provider->restore($_POST);
    $provider->redirectToList();

} elseif (isset($_POST['purge'])) {
    $provider->check($_POST['id'], PURGE);
    $provider->delete($_POST, 1);
    $provider->redirectToList();

} else {
    $ID = (int)($_GET['id'] ?? 0);

    Html::header(
        Provider::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'tools',
        Provider::class,
        'provider'
    );

    // GLPI 11 : display() gère création et édition
    $provider->display([
        'id'          => $ID,
        'formoptions' => "data-track-changes='true'",
    ]);

    Html::footer();
}
