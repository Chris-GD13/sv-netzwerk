<?php
declare(strict_types=1);

/** Use the authenticated account, never a delegated expert profile or display name. */
function svnetCanManageWorkRules(array $user): bool
{
    $email = strtolower(trim((string)($user['email'] ?? '')));
    return in_array($email, ['cw@sv-schuett.eu', 'info@sv-schuett.eu'], true);
}
