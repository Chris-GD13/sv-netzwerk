<?php
declare(strict_types=1);

function svnetSupportedProfiles(): array
{
    return ['christian', 'holger', 'marc', 'jens'];
}

function svnetProfileText(string $value): string
{
    return strtr(mb_strtolower(trim($value), 'UTF-8'), [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
    ]);
}

function svnetIsBackofficeUser(array $user): bool
{
    $email = svnetProfileText((string)($user['email'] ?? ''));
    $name = svnetProfileText((string)($user['full_name'] ?? ''));
    return (string)($user['role'] ?? '') === 'administrator'
        || $email === 'ws@sv-schuett.eu'
        || str_contains($email, 'susanne')
        || str_contains($name, 'susanne');
}

function svnetUserProfile(array $user): string
{
    $explicit = svnetProfileText((string)($user['svnet_expert_profile'] ?? ''));
    if (in_array($explicit, svnetSupportedProfiles(), true)) return $explicit;

    $email = svnetProfileText((string)($user['email'] ?? ''));
    $name = svnetProfileText((string)($user['full_name'] ?? ''));
    if (str_contains($email, 'hr@') || str_contains($name, 'holger roth')) return 'holger';
    if (str_contains($email, 'ms@') || str_contains($name, 'marc schuett')) return 'marc';
    if (str_contains($email, 'jens') || str_contains($name, 'jens maurer')) return 'jens';
    if (str_contains($email, 'cw@') || str_contains($name, 'christian waechter')) return 'christian';
    return '';
}

function svnetSelectedProfile(array $user, ?string $selected = null): string
{
    if (!svnetIsBackofficeUser($user)) {
        $profile = svnetUserProfile($user);
        if ($profile === '') throw new InvalidArgumentException('Für den Benutzer ist kein unterstütztes Bearbeiterprofil hinterlegt.');
        return $profile;
    }

    $profile = svnetProfileText((string)$selected);
    if ($profile === '') return 'christian';
    if (!in_array($profile, svnetSupportedProfiles(), true)) {
        throw new InvalidArgumentException('Das gespeicherte Bearbeiterprofil ist ungültig.');
    }
    return $profile;
}

function svnetExpertIdentity(string $profile, array $backoffice): array
{
    return match ($profile) {
        'christian' => array_merge($backoffice, ['email'=>'cw@sv-netzwerk.eu', 'full_name'=>'Christian Wächter', 'svnet_expert_profile'=>'christian']),
        'holger' => array_merge($backoffice, ['email'=>'hr@sv-schuett.eu', 'full_name'=>'Holger Roth', 'svnet_expert_profile'=>'holger']),
        'marc' => array_merge($backoffice, ['email'=>'ms@sv-schuett.eu', 'full_name'=>'Marc Schütt', 'svnet_expert_profile'=>'marc']),
        'jens' => array_merge($backoffice, ['email'=>'jens@profile.sv-netzwerk', 'full_name'=>'Jens Maurer', 'svnet_expert_profile'=>'jens']),
        default => throw new InvalidArgumentException('Unbekanntes Bearbeiterprofil.'),
    };
}

function svnetCasesFolderName(string $profile): string
{
    return match ($profile) {
        'christian' => 'Schadenfälle Christian Wächter',
        'holger' => 'Schadenfälle Holger Roth',
        'marc' => 'Schadenfälle Marc Schütt',
        'jens' => 'Schadenfälle Jens Maurer',
        default => throw new InvalidArgumentException('Unbekanntes Bearbeiterprofil.'),
    };
}
