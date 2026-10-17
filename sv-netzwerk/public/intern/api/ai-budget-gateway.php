<?php
declare(strict_types=1);

/** Phase 2 has no paid transport. No environment flag can enable one. */
function svAiGuardHandle($handle): mixed {
    $url = (string)curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === 'api.openai.com' || $host === 'api.anthropic.com'
        || $host === 'generativelanguage.googleapis.com') {
        throw new RuntimeException('PAID_AI_DISABLED: Phase 2 erlaubt ausschließlich simulierte Modellaufrufe.');
    }
    return $handle;
}
