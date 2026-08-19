<?php

namespace GlpiPlugin\Backupgestion;

/**
 * Fabrique de providers (CDC 4.3). Un seul type enregistré en V1 (Acronis),
 * structure prête pour en ajouter d'autres sans toucher au cœur du plugin.
 */
class ProviderFactory
{
    private const PROVIDERS = [
        'acronis' => AcronisProvider::class,
    ];

    public static function getAvailableProviders(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $type => $class) {
            $out[$type] = $class::getLabel();
        }
        return $out;
    }

    public static function getCredentialFields(string $type): array
    {
        if (!isset(self::PROVIDERS[$type])) {
            return [];
        }
        return self::PROVIDERS[$type]::getCredentialFields();
    }

    public static function create(string $type, array $credentials): ProviderInterface
    {
        if (!isset(self::PROVIDERS[$type])) {
            throw new \RuntimeException(sprintf('ProviderFactory: type de provider inconnu "%s".', $type));
        }
        $class = self::PROVIDERS[$type];
        return new $class($credentials);
    }
}
