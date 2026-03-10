<?php
/**
 * track_user.php - ユーザー登録/ログイン追跡
 */
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Tokyo');

$usersFile = __DIR__ . '/data/users.json';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$uid = trim($input['uid'] ?? '');
$email = trim($input['email'] ?? '');
$name = trim($input['displayName'] ?? '');
$provider = trim($input['provider'] ?? '');
$createdAt = trim($input['createdAt'] ?? '');

if ($uid === '') {
    echo json_encode(['ok' => false, 'error' => 'uid required']);
    exit;
}

// dataディレクトリ作成
$dir = __DIR__ . '/data';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}

// ファイルロック付き読み書き
$fp = @fopen($usersFile, 'c+');
if (!$fp) {
    echo json_encode(['ok' => false, 'error' => 'file error']);
    exit;
}
flock($fp, LOCK_EX);

$content = stream_get_contents($fp);
$users = json_decode($content, true) ?: [];

$now = date('c');
$isNew = !isset($users[$uid]);

if ($isNew) {
    $users[$uid] = [
        'email' => $email,
        'name' => $name,
        'provider' => $provider,
        'firebase_created' => $createdAt,
        'first_seen' => $now,
        'last_seen' => $now,
        'login_count' => 1,
    ];
} else {
    $users[$uid]['last_seen'] = $now;
    $users[$uid]['login_count'] = ($users[$uid]['login_count'] ?? 0) + 1;
    if ($email && !$users[$uid]['email']) $users[$uid]['email'] = $email;
    if ($name && !$users[$uid]['name']) $users[$uid]['name'] = $name;
    if ($provider) $users[$uid]['provider'] = $provider;
}

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['ok' => true, 'new' => $isNew]);
