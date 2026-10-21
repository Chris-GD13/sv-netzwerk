<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/intern/api/kuss-core.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $work, string $message): void { try { $work(); } catch (InvalidArgumentException|RuntimeException) { return; } throw new RuntimeException($message); }
$payload = rtrim(strtr(base64_encode(json_encode(['exp' => time() + 3600])), '+/', '-_'), '=');
$token = 'header.' . $payload . '.signature';
$encrypted = kussEncrypt($token, 'fixture-secret', 2);
check(!str_contains($encrypted, $token), 'Token must not be stored as plaintext');
check(kussDecrypt($encrypted, 'fixture-secret', 2) === $token, 'Owner can decrypt');
rejects(fn() => kussDecrypt($encrypted, 'fixture-secret', 3), 'Another user must not decrypt');
rejects(fn() => kussDecrypt($encrypted, 'other-secret', 2), 'Different key must fail');
rejects(fn() => kussEncrypt($token, '', 2), 'No fallback encryption key');
rejects(fn() => kussTokenClaims($token . "\r\nInjected: test"), 'Reject header injection');
rejects(fn() => kussTokenClaims('header.eyJleHAiOjF9.signature'), 'Reject expired token');
rejects(fn() => kussJobsQuery(['page' => 0]), 'Reject invalid pagination');
rejects(fn() => kussJobsQuery(['search' => str_repeat('x', 241)]), 'Bound search');
check(kussJobsQuery(['page' => 2, 'search' => '  26-123  ', 'url' => 'https://other.invalid']) === ['page[number]' => 2, 'filter[search]' => '26-123'], 'Only supported query fields');
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE kuss_user_connections (user_id INTEGER PRIMARY KEY, token_ciphertext TEXT, expires_at TEXT, verified_at TEXT)');
$pdo->prepare('INSERT INTO kuss_user_connections VALUES (?,?,?,?)')->execute([2, $encrypted, '2027-01-01', '2026-10-21']);
check(kussConnection($pdo, 2) !== null, 'Own connection exists');
check(kussConnection($pdo, 3) === null, 'No connection inheritance across users');
$result = kussPublicJobs(['data' => [['id' => '7', 'type' => 'jobs', 'attributes' => ['case' => '26-123', 'state' => 'accepted', 'secret' => 'excluded'], 'relationships' => ['private' => 'excluded']]], 'meta' => ['page' => ['current-page' => 2, 'last-page' => 3, 'total' => 40]]]);
check(count($result['jobs']) === 1 && $result['jobs'][0]['case'] === '26-123', 'Job mapping');
check(!str_contains(json_encode($result), 'excluded'), 'Do not return unneeded upstream data');
check($result['page'] === ['current' => 2, 'last' => 3, 'total' => 40], 'Pagination metadata');
echo "KUSS API tests passed\n";
