<?php
declare(strict_types=1);

$core=__DIR__.'/revenue-summary.php';
if(function_exists('opcache_invalidate'))@opcache_invalidate($core,true);
require $core;
