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
    @mkdir($dir, 0777, true);
}

// 既存データ読み込み
$users = [];
if (file_exists($usersFile)) {
    $users = json_decode(file_get_contents($usersFile), true) ?: [];
}

$now = date('c');
$isNew = !isset($users[$uid]);

if ($isNew) {
    // 新規ユーザー
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
    // 既存ユーザー
    $users[$uid]['last_seen'] = $now;
    $users[$uid]['login_count'] = ($users[$uid]['login_count'] ?? 0) + 1;
    if ($email && !$users[$uid]['email']) $users[$uid]['email'] = $email;
    if ($name && !$users[$uid]['name']) $users[$uid]['name'] = $name;
    if ($provider) $users[$uid]['provider'] = $provider;
}

@file_put_contents($usersFile, json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

echo json_encode(['ok' => true, 'new' => $isNew]);
