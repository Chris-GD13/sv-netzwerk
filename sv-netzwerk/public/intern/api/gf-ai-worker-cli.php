#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

putenv('GF_AI_WORKER_NEXT=1');
require __DIR__ . '/gf-ai-generate.php';
