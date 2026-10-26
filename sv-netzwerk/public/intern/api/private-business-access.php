<?php
declare(strict_types=1);

// Explicit account grants by Christian, 17 and 26 October 2026. Roles and display names
// deliberately grant no access. Further accounts require an explicit new grant.
function pbCanCreate(array $user): bool
{
    return in_array(strtolower(trim((string)($user['email'] ?? ''))), [
        'cw@sv-schuett.eu', 'cw@sv-netzwerk.eu',
        'ws@sv-schuett.eu', 'ks@sv-schuett.eu',
        'ms@sv-schuett.eu',
    ], true);
}

function pbPrivateProfile(array $user): string
{
    // Marc creates offers in his own inventory, never in Christian's shared office inventory.
    if (strtolower(trim((string)($user['email'] ?? ''))) === 'ms@sv-schuett.eu') return 'marc';
    return pbCanCreate($user) ? 'christian' : svnetUserProfile($user);
}
