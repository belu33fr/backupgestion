<?php

use GlpiPlugin\Backupgestion\AcronisProvider;
use GlpiPlugin\Backupgestion\Credential;
use GlpiPlugin\Backupgestion\DeviceLink;
use GlpiPlugin\Backupgestion\DiscoveryLog;
use GlpiPlugin\Backupgestion\KeyDerivation;
use GlpiPlugin\Backupgestion\Provider;
use GlpiPlugin\Backupgestion\ProviderFactory;

include('../../../inc/includes.php');

if (!Provider::canView()) {
    Html::displayRightError();
}

global $DB;

// Vue de synthèse (CDC 4.3 bis) : nombre de providers actifs, volume total (live),
// nombre d'appareils rattachés / en attente, dernières erreurs de la tâche de
// détection. Le volume total nécessite un appel API par provider indépendant — borné
// pour rester réactif (retour de Luc à surveiller si le nombre de providers grandit
// beaucoup ; un cache technique court pourra être ajouté si besoin, CDC 4.3).
const BACKUPGESTION_DASHBOARD_MAX_LIVE_PROVIDERS = 20;

$activeProviders = countElementsInTable(Provider::getTable(), ['is_deleted' => 0]);
$providersWithFailures = countElementsInTable(Provider::getTable(), [
    'is_deleted'               => 0,
    'connection_failure_days'  => ['>', 0],
]);

$matchedDevices = DeviceLink::countByStatus(DeviceLink::STATUS_AUTO) + DeviceLink::countByStatus(DeviceLink::STATUS_MANUAL);
$pendingDevices = DeviceLink::countByStatus(DeviceLink::STATUS_PENDING);

$totalStorageBytes = 0;
$statsErrors        = [];
$providersChecked    = 0;

foreach ($DB->request([
    'FROM'  => Provider::getTable(),
    'WHERE' => ['is_deleted' => 0],
]) as $row) {
    if ($providersChecked >= BACKUPGESTION_DASHBOARD_MAX_LIVE_PROVIDERS) {
        break;
    }
    $providerId = (int)$row['id'];
    if (!Credential::existsForProvider($providerId)) {
        continue;
    }

    $providersChecked++;

    $provider = new Provider();
    if (!$provider->getFromDB($providerId)) {
        continue;
    }

    try {
        $key         = KeyDerivation::deriveKey($provider->fields);
        $credentials = Credential::getForProvider($providerId, $key);
        $acronis     = ProviderFactory::create($provider->fields['provider_type'] ?: 'acronis', $credentials);
        if (!$acronis instanceof AcronisProvider) {
            continue;
        }
        foreach ($acronis->listBackupStats() as $stat) {
            if ($stat['name'] === 'storage' && $stat['unit'] === 'bytes' && is_numeric($stat['value'])) {
                $totalStorageBytes += (float)$stat['value'];
            }
        }
    } catch (\Throwable $e) {
        $statsErrors[] = sprintf('%s : %s', $provider->fields['name'], $e->getMessage());
    }
}

$recentLogs      = DiscoveryLog::getRecent(10);
$totalStorageGb  = round($totalStorageBytes / (1024 ** 3), 2);

Html::header(
    __('Vue de synthèse', 'backupgestion'),
    $_SERVER['PHP_SELF'],
    'tools',
    Provider::class
);

\Glpi\Application\View\TemplateRenderer::getInstance()->display(
    '@backupgestion/dashboard.html.twig',
    [
        'activeProviders'        => $activeProviders,
        'providersWithFailures'  => $providersWithFailures,
        'matchedDevices'         => $matchedDevices,
        'pendingDevices'         => $pendingDevices,
        'totalStorageGb'         => $totalStorageGb,
        'providersChecked'       => $providersChecked,
        'statsErrors'            => $statsErrors,
        'recentLogs'             => $recentLogs,
    ]
);

Html::footer();
