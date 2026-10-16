<?php
declare(strict_types=1);

/** Reject the observed contact-form campaign without blocking our sender address. */
function sv_form_campaign_blocked(array $post, array $server): bool
{
    $ip = (string)($server['REMOTE_ADDR'] ?? '');
    $name = $post['Name'] ?? '';
    $name = is_scalar($name) ? (string)$name : '';
    $name = strtolower(preg_replace('/\s+/u', '', $name) ?? '');
    return $ip === '80.94.95.202' || $name === 'robertvom';
}

/** A small locked per-address limit, shared by all public form handlers. */
function sv_form_rate_allowed(string $ip, ?string $directory = null, ?int $now = null): bool
{
    $now = $now ?? time();
    $directory = $directory ?? sys_get_temp_dir() . '/svnet-public-form-limits';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        error_log('SV form rate-limit storage unavailable');
        return true;
    }
    $file = $directory . '/' . hash('sha256', $ip) . '.json';
    $handle = @fopen($file, 'c+');
    if (!$handle) {
        error_log('SV form rate-limit storage unavailable');
        return true;
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return true;
    }
    try {
        $times = json_decode(stream_get_contents($handle, 8192) ?: '[]', true);
        $times = is_array($times) ? $times : [];
        $times = array_values(array_filter($times, static fn($t) => is_int($t) && $t > $now - 900 && $t <= $now));
        $allowed = count($times) < 8;
        if ($allowed) {
            $times[] = $now;
        }
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($times));
        fflush($handle);
        return $allowed;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
