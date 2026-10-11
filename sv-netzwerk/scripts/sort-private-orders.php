<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$portal = realpath((string)($argv[1] ?? ''));
if (!$portal || !is_file($portal.'/intern/api/config.php')) throw new RuntimeException('Verified portal root required.');
require_once $portal.'/intern/api/config.php';
require_once $portal.'/intern/api/private-order-classification.php';
require_once $portal.'/intern/api/private-order-storage.php';
$marker = sys_get_temp_dir().'/svnet-private-sort-20261011-'.hash('sha256', $portal).'.json';
if (is_file($marker)) { echo "PRIVATE_SORT_COMPLETE already completed\n"; exit; }
set_time_limit(0);
$root = poItemByPath(poConfig('MS_SHAREPOINT_CHRISTIAN_PRIVATE_PATH', 'VS Schäden/Christian/Privatgutachten_NL Süd'));
$orders = [];
foreach (poChildren((string)$root['id']) as $entry) {
    if (!$entry['folder'] || poIsStandardFolder($entry) || mb_strtolower($entry['name'], 'UTF-8') === 'versicherungsaufträge') continue;
    if (poIsYearFolder($entry)) {
        foreach (poChildren($entry['id']) as $order) if ($order['folder'] && !poIsStandardFolder($order)) $orders[] = $order;
    } else $orders[] = $entry;
}
$moved = 0;
foreach ($orders as $order) {
    $children = poChildren($order['id']); $targets = [];
    foreach ($children as $entry) if (poIsStandardFolder($entry)) $targets[mb_strtolower($entry['name'], 'UTF-8')] = $entry['id'];
    foreach ($children as $entry) {
        if (poIsStandardFolder($entry)) continue;
        $category = poCategory($entry); $key = mb_strtolower($category, 'UTF-8');
        if (!isset($targets[$key])) throw new RuntimeException('Target folder missing: '.$category);
        poMoveFile($entry['id'], $targets[$key]); $moved++;
    }
    // Re-read the order; no uncategorized entries may remain at its root.
    foreach (poChildren($order['id']) as $entry) if (!poIsStandardFolder($entry)) throw new RuntimeException('Order still contains unsorted entries.');
}
$report = ['orders'=>count($orders), 'moved'=>$moved, 'verified_at'=>gmdate('c')];
file_put_contents($marker, json_encode($report, JSON_UNESCAPED_UNICODE));
echo 'PRIVATE_SORT_COMPLETE '.json_encode($report)."\n";
