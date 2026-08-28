<?php

namespace GlpiPlugin\Backupgestion;

/**
 * Tâche périodique de détection des rattachements (CDC 4.7) — seul traitement de
 * fond du plugin. Pour chaque provider disposant d'identifiants API propres
 * (indépendant, cf. Provider — CDC 4.4), interroge en direct la liste des
 * appareils (agents) visibles depuis son tenant et enregistre dans DeviceLink
 * ceux qui n'ont pas encore de rattachement GLPI connu, pour peupler l'écran de
 * mapping manuel (CDC 4.5, jalon 4). Aucune donnée descriptive d'appareil n'est
 * mirrorée — seul le résultat du rattachement est persisté (CDC 2.1).
 *
 * Enregistrée comme CronTask GLPI standard (mécanisme cronInfo()/cron*Action*()) —
 * voir Plugin::registerClass()/CronTask::register() dans setup.php/hook.php.
 */
class DiscoveryTask
{
    public static function cronInfo(string $name): array
    {
        switch ($name) {
            case 'detection':
                return [
                    'description' => __('Détection périodique des rattachements (appareils Acronis non encore liés à un équipement GLPI)', 'backupgestion'),
                ];
        }
        return [];
    }

    /**
     * Action CRON 'detection' — un seul provider "indépendant" à la fois (ceux qui
     * n'ont pas leurs propres identifiants API sont de simples sous-tenants
     * découverts, jamais interrogés directement ici — CDC 4.4).
     */
    public static function cronDetection(?\CronTask $task = null): int
    {
        global $DB;

        $processed = 0;
        $errors    = 0;

        foreach ($DB->request([
            'FROM'  => Provider::getTable(),
            'WHERE' => ['is_deleted' => 0],
        ]) as $row) {
            $providerId = (int)$row['id'];

            if (!Credential::existsForProvider($providerId)) {
                // Sous-tenant découvert sans identifiants propres : rien à interroger
                // directement, ses appareils remontent déjà via le provider parent
                // (ou un ancêtre) qui, lui, dispose de ses propres identifiants.
                continue;
            }

            $provider = new Provider();
            if (!$provider->getFromDB($providerId)) {
                continue;
            }

            $found   = 0;
            $matched = 0;
            $pending = 0;
            $errMsg  = '';

            try {
                $key         = KeyDerivation::deriveKey($provider->fields);
                $credentials = Credential::getForProvider($providerId, $key);
                $acronis     = ProviderFactory::create($provider->fields['provider_type'] ?: 'acronis', $credentials);

                if (!$acronis instanceof AcronisProvider) {
                    continue;
                }

                $devices = $acronis->listDevices();
                $found   = count($devices);

                foreach ($devices as $device) {
                    $tenantId = $device['tenant_id'] !== '' ? $device['tenant_id'] : (string)($provider->fields['acronis_tenant_id'] ?? '');
                    if ($tenantId === '' || $device['id'] === '') {
                        continue;
                    }

                    $existingStatus = self::getExistingStatus($tenantId, $device['id']);
                    $result         = DeviceLink::recordSeen($tenantId, $device['id']);

                    if (!$result['created'] && in_array($existingStatus, [DeviceLink::STATUS_AUTO, DeviceLink::STATUS_MANUAL], true)) {
                        $matched++;
                    } else {
                        $pending++;
                    }
                }

                if ($task) {
                    $task->addVolume($found);
                    $task->log(sprintf(
                        __('%1$s : %2$d appareil(s) trouvé(s), %3$d déjà rattaché(s), %4$d en attente.', 'backupgestion'),
                        $provider->fields['name'],
                        $found,
                        $matched,
                        $pending
                    ));
                }

                DiscoveryLog::logRun($providerId, 'ok', $found, $matched, $pending);
                $processed++;
            } catch (\Throwable $e) {
                $errMsg = $e->getMessage();
                DiscoveryLog::logRun($providerId, 'error', $found, $matched, $pending, $errMsg);
                $errors++;
                if ($task) {
                    $task->log(sprintf(__('%1$s : échec — %2$s', 'backupgestion'), $provider->fields['name'], $errMsg));
                }
            }
        }

        if ($errors > 0 && $processed === 0) {
            return -1;
        }
        return $processed > 0 ? 1 : 0;
    }

    /** Statut actuel du rattachement s'il existe déjà, sinon chaîne vide. */
    private static function getExistingStatus(string $acronisTenantId, string $providerRef): string
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => DeviceLink::getTable(),
            'WHERE' => ['acronis_tenant_id' => $acronisTenantId, 'provider_ref' => $providerRef],
        ])->current();

        return $row ? (string)$row['match_status'] : '';
    }
}
