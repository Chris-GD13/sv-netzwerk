<?php
declare(strict_types=1);

function svnetCanAccessEnergy(array $user): bool
{
    // Account identity only: no administrator role, display name or delegated profile.
    return strtolower(trim((string)($user['email'] ?? ''))) === 'cw@sv-schuett.eu';
}

function svnetEnergyDestination(string $service): ?string
{
    return [
        'evcc' => 'http://10.77.66.1:7070/#/',
        'anker' => 'http://10.77.66.1:8123/config/integrations/integration/anker_solix',
        'keba' => 'http://192.168.178.106/',
    ][$service] ?? null;
}
