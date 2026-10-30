<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
$user=requireAuth();
if ($_SERVER['REQUEST_METHOD']==='GET') {
  $pdo=db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS fahrtenbuch_data (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    data_json LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_fahrtenbuch_email (user_email)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $stmt=$pdo->prepare('SELECT data_json,updated_at FROM fahrtenbuch_data WHERE user_id=:u LIMIT 1');
  $stmt->execute([':u'=>(int)($user['id']??0)]);
  $row=$stmt->fetch(PDO::FETCH_ASSOC);
  apiJson(['ok'=>true,'data'=>$row?json_decode((string)$row['data_json'],true):null,'updated_at'=>$row['updated_at']??null]);
}
if ($_SERVER['REQUEST_METHOD']!=='POST') apiError(405,'GET oder POST erforderlich.');
$body=requestBody();
$data=$body['data']??null;
if (!is_array($data)) apiError(400,'Fahrtenbuchdaten fehlen.');
$trips=is_array($data['trips']??null)?array_values($data['trips']):[];
$odometers=is_array($data['odometers']??null)?$data['odometers']:[];
$home=trim((string)($data['home']??''));
if (count($trips)>5000) apiError(413,'Zu viele Fahrten.');
foreach($trips as $trip){if(!is_array($trip))apiError(400,'Ungültiger Fahrtenbucheintrag.');}
$payload=['trips'=>$trips,'odometers'=>$odometers,'home'=>$home];
$pdo=db();
$pdo->exec("CREATE TABLE IF NOT EXISTS fahrtenbuch_data (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  user_email VARCHAR(255) NOT NULL,
  data_json LONGTEXT NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_fahrtenbuch_email (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$stmt=$pdo->prepare('INSERT INTO fahrtenbuch_data(user_id,user_email,data_json,updated_at) VALUES(:u,:e,:d,NOW()) ON DUPLICATE KEY UPDATE user_email=VALUES(user_email),data_json=VALUES(data_json),updated_at=NOW()');
$stmt->execute([':u'=>(int)($user['id']??0),':e'=>(string)($user['email']??''),':d'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
apiJson(['ok'=>true,'updated_at'=>date('c'),'data'=>$payload]);
