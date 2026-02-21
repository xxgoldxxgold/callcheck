<?php
/**
 * usage_counter.php - 利用回数カウンター
 */

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Tokyo');

// 利用回数ファイルのパス
$counterFile = __DIR__ . '/usage_counter.txt';

// 今日の日付を取得
$today = date('Y-m-d');

/**
 * 利用回数を取得する関数
 */
function getUsageCount($file, $date) {
    if (!file_exists($file)) {
        return 0;
    }
    
    $data = json_decode(file_get_contents($file), true);
    return $data[$date] ?? 0;
}

/**
 * 利用回数を増加させる関数
 */
function incrementUsageCount($file, $date) {
    $data = [];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?? [];
    }
    
    $data[$date] = ($data[$date] ?? 0) + 1;
    
    // 90日以上前のデータを削除
    $cutoff = date('Y-m-d', strtotime('-90 days'));
    foreach (array_keys($data) as $d) {
        if ($d < $cutoff) unset($data[$d]);
    }
    
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $data[$date];
}

// GETリクエストの場合は利用回数を取得
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $count = getUsageCount($counterFile, $today);
    echo json_encode(['count' => $count, 'date' => $today]);
    exit;
}

// POSTリクエストの場合は利用回数を増加
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $count = incrementUsageCount($counterFile, $today);
    echo json_encode(['count' => $count, 'date' => $today]);
    exit;
}

// その他の場合はエラー
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>


