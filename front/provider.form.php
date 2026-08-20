<?php

use GlpiPlugin\Backupgestion\AccountsVault;
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

if (isset($_POST['discover_children'])) {
    // Découverte de la hiérarchie de tenants depuis la fiche (AJAX) — CDC 4.2 ter
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if (!$provider->getFromDB($id) || !$provider->can($id, UPDATE)) {
        echo json_encode(['success' => false, 'message' => __('Accès refusé.', 'backupgestion')]);
        exit;
    }
    try {
        $result = $provider->discoverChildren();
        echo json_encode(['success' => true] + $result);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;

} elseif (isset($_POST['create_accounts_admin'])) {
    // Crée le compte admin Acronis dans Accounts (catégorie b — CDC 4.4/4.4 bis) et le
    // lie à ce provider. Le mot de passe et la clé tapée ne transitent jamais au-delà de
    // cette requête (chiffrement immédiat, clé éventuellement mise en cache de session
    // uniquement — CDC 4.4 ter).
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if (!$provider->getFromDB($id) || !$provider->can($id, UPDATE)) {
        echo json_encode(['success' => false, 'message' => __('Accès refusé.', 'backupgestion')]);
        exit;
    }
    try {
        $newID = AccountsVault::createAdminAccount(
            $provider,
            (string)($_POST['accounts_login'] ?? ''),
            (string)($_POST['accounts_password'] ?? ''),
            ($_POST['accounts_key'] ?? '') !== '' ? (string)$_POST['accounts_key'] : null,
            !empty($_POST['accounts_is_admin'])
        );
        echo json_encode(['success' => true, 'accounts_id' => $newID]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;

} elseif (isset($_POST['forget_accounts_key'])) {
    header('Content-Type: application/json');
    $id     = (int)($_POST['id'] ?? 0);
    $hashId = (int)($_POST['accounts_hash_id'] ?? 0);
    if (!$provider->getFromDB($id) || !$provider->can($id, UPDATE)) {
        echo json_encode(['success' => false, 'message' => __('Accès refusé.', 'backupgestion')]);
        exit;
    }
    AccountsVault::forgetKey($hashId > 0 ? $hashId : null);
    echo json_encode(['success' => true]);
    exit;

} elseif (isset($_POST['test_connection'])) {
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
    $input = $_POST;
    unset($input['add']);
    $credentials = backupgestion_extract_credentials($input);

    $provider->check(-1, CREATE, $input);
    $newID = $provider->add($input);
    if ($newID && !empty($credentials)) {
        $key = KeyDerivation::deriveKey($provider->fields);
        Credential::saveForProvider($newID, $credentials, $key);
    }

    if ($newID && ($_SESSION['glpibackcreated'] ?? false)) {
        Html::redirect(Provider::getFormURL() . '?id=' . $newID);
    }
    Html::back();

} elseif (isset($_POST['update'])) {
    $id    = (int)$_POST['id'];
    $input = $_POST;
    $credentials = backupgestion_extract_credentials($input);

    $provider->check($id, UPDATE);
    if ($provider->update($input) && !empty($credentials)) {
        // $provider->fields reflète l'état après update() — y compris un éventuel
        // nouveau snapshot de clé si l'entité/le référent a changé (Provider::prepareInputForUpdate).
        $key = KeyDerivation::deriveKey($provider->fields);
        Credential::saveForProvider($id, $credentials, $key);
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
