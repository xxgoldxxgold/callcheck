<?php
/**
 * usage_counter.php - 利用回数カウンター
 */

header('Content-Type: application/json; charset=utf-8');

// CORS: 自サイトのみ許可
$allowedOrigins = ['https://denwa2.com', 'https://www.denwa2.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

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

    $fp = @fopen($file, 'r');
    if (!$fp) return 0;
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $data = json_decode($content, true);
    return $data[$date] ?? 0;
}

/**
 * 利用回数を増加させる関数
 */
function incrementUsageCount($file, $date) {
    $fp = fopen($file, 'c+');
    if (!$fp) return 0;
    flock($fp, LOCK_EX);
    
    $content = stream_get_contents($fp);
    $data = json_decode($content, true) ?? [];
    
    $data[$date] = ($data[$date] ?? 0) + 1;
    
    // 90日以上前のデータを削除
    $cutoff = date('Y-m-d', strtotime('-90 days'));
    foreach (array_keys($data) as $d) {
        if ($d < $cutoff) unset($data[$d]);
    }
    
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
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


