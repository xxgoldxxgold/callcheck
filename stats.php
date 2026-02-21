<?php
/**
 * stats.php - 営業確認統計データの管理
 * 修正版：関数の重複定義を回避し、エラーハンドリングを改善
 */

header('Content-Type: application/json; charset=utf-8');

// タイムゾーンを設定（重要！）
date_default_timezone_set('Asia/Tokyo');

// 統計データファイルのパス
$statsFile = __DIR__ . '/stats.json';

// 今日と昨日の日付を取得
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

/**
 * 統計データを取得する関数（関数名を変更して重複を回避）
 */
function stats_getData($file) {
    if (!file_exists($file)) {
        return [];
    }
    
    $content = @file_get_contents($file);
    if ($content === false) {
        error_log("stats.php: ファイル読み込みエラー: $file");
        return [];
    }
    
    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("stats.php: JSONデコードエラー: " . json_last_error_msg());
        return [];
    }
    
    return $data ?? [];
}

/**
 * 統計データを保存する関数（関数名を変更して重複を回避）
 */
function stats_saveData($file, $data) {
    // 古いデータをクリーンアップ（30日以上前のデータを削除）
    $cutoff = date('Y-m-d', strtotime('-30 days'));
    foreach (array_keys($data) as $date) {
        if ($date < $cutoff) {
            unset($data[$date]);
        }
    }
    
    // データを日付順にソート（新しい順）
    krsort($data);
    
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        error_log("stats.php: JSONエンコードエラー: " . json_last_error_msg());
        return false;
    }
    
    // LOCK_EXフラグで排他制御
    $result = @file_put_contents($file, $json, LOCK_EX);
    if ($result === false) {
        error_log("stats.php: ファイル書き込みエラー: $file");
        return false;
    }
    
    return true;
}

/**
 * 成功を記録する関数（関数名を変更して重複を回避）
 */
function stats_recordSuccess($file, $date = null) {
    if ($date === null) {
        $date = date('Y-m-d');
    }
    
    $data = stats_getData($file);
    
    if (!isset($data[$date])) {
        $data[$date] = ['success' => 0, 'failed' => 0, 'created_at' => date('c')];
    }
    
    $data[$date]['success']++;
    $data[$date]['updated_at'] = date('c');
    
    if (!stats_saveData($file, $data)) {
        error_log("stats.php: 成功記録の保存に失敗");
        return false;
    }
    
    error_log("stats.php: 成功を記録 - $date: success=" . $data[$date]['success']);
    return $data[$date];
}

/**
 * 失敗を記録する関数（関数名を変更して重複を回避）
 */
function stats_recordFailure($file, $date = null) {
    if ($date === null) {
        $date = date('Y-m-d');
    }
    
    $data = stats_getData($file);
    
    if (!isset($data[$date])) {
        $data[$date] = ['success' => 0, 'failed' => 0, 'created_at' => date('c')];
    }
    
    $data[$date]['failed']++;
    $data[$date]['updated_at'] = date('c');
    
    if (!stats_saveData($file, $data)) {
        error_log("stats.php: 失敗記録の保存に失敗");
        return false;
    }
    
    error_log("stats.php: 失敗を記録 - $date: failed=" . $data[$date]['failed']);
    return $data[$date];
}

/**
 * 指定日付の統計データを取得する関数
 */
function stats_getForDate($file, $date) {
    $data = stats_getData($file);
    $dayStats = $data[$date] ?? ['success' => 0, 'failed' => 0];
    
    $total = $dayStats['success'] + $dayStats['failed'];
    $successRate = $total > 0 ? round(($dayStats['success'] / $total) * 100, 1) : 0;
    
    return [
        'date' => $date,
        'success' => $dayStats['success'],
        'failed' => $dayStats['failed'],
        'total' => $total,
        'success_rate' => $successRate,
        'created_at' => $dayStats['created_at'] ?? null,
        'updated_at' => $dayStats['updated_at'] ?? null
    ];
}

/**
 * 期間統計を取得する関数
 */
function stats_getPeriodSummary($file, $startDate, $endDate) {
    $data = stats_getData($file);
    $totalSuccess = 0;
    $totalFailed = 0;
    $dailyStats = [];
    
    // 期間内のデータを集計
    $current = strtotime($startDate);
    $end = strtotime($endDate);
    
    while ($current <= $end) {
        $date = date('Y-m-d', $current);
        if (isset($data[$date])) {
            $totalSuccess += $data[$date]['success'];
            $totalFailed += $data[$date]['failed'];
            $dailyStats[$date] = $data[$date];
        }
        $current = strtotime('+1 day', $current);
    }
    
    $total = $totalSuccess + $totalFailed;
    $successRate = $total > 0 ? round(($totalSuccess / $total) * 100, 1) : 0;
    
    return [
        'period' => [
            'start' => $startDate,
            'end' => $endDate
        ],
        'total_success' => $totalSuccess,
        'total_failed' => $totalFailed,
        'total_calls' => $total,
        'success_rate' => $successRate,
        'daily_stats' => $dailyStats
    ];
}

// POSTリクエストの処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $date = $input['date'] ?? $today;
    
    // 日付の形式チェック
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format']);
        exit;
    }
    
    switch ($action) {
        case 'success':
            $result = stats_recordSuccess($statsFile, $date);
            if ($result === false) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to record success']);
            } else {
                echo json_encode(['ok' => true, 'stats' => $result]);
            }
            break;
            
        case 'failure':
            $result = stats_recordFailure($statsFile, $date);
            if ($result === false) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to record failure']);
            } else {
                echo json_encode(['ok' => true, 'stats' => $result]);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
    exit;
}

// GETリクエストの処理（統計データを取得）
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // クエリパラメータで期間指定可能に
    $period = $_GET['period'] ?? 'today';
    
    switch ($period) {
        case 'today':
            $todayStats = stats_getForDate($statsFile, $today);
            $yesterdayStats = stats_getForDate($statsFile, $yesterday);
            
            $response = [
                'today' => $todayStats,
                'yesterday' => $yesterdayStats,
                'dates' => [
                    'today' => $today,
                    'yesterday' => $yesterday
                ],
                'timestamp' => date('c')
            ];
            break;
            
        case 'week':
            $weekStart = date('Y-m-d', strtotime('monday this week'));
            $weekEnd = date('Y-m-d', strtotime('sunday this week'));
            $response = stats_getPeriodSummary($statsFile, $weekStart, $weekEnd);
            break;
            
        case 'month':
            $monthStart = date('Y-m-01');
            $monthEnd = date('Y-m-t');
            $response = stats_getPeriodSummary($statsFile, $monthStart, $monthEnd);
            break;
            
        case 'all':
            $data = stats_getData($statsFile);
            $totalSuccess = 0;
            $totalFailed = 0;
            
            foreach ($data as $dayStats) {
                $totalSuccess += $dayStats['success'];
                $totalFailed += $dayStats['failed'];
            }
            
            $total = $totalSuccess + $totalFailed;
            $successRate = $total > 0 ? round(($totalSuccess / $total) * 100, 1) : 0;
            
            $response = [
                'all_time' => [
                    'success' => $totalSuccess,
                    'failed' => $totalFailed,
                    'total' => $total,
                    'success_rate' => $successRate
                ],
                'daily_data' => $data,
                'timestamp' => date('c')
            ];
            break;
            
        default:
            // カスタム期間（start_dateとend_dateパラメータ）
            $startDate = $_GET['start_date'] ?? $today;
            $endDate = $_GET['end_date'] ?? $today;
            
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid date format']);
                exit;
            }
            
            $response = stats_getPeriodSummary($statsFile, $startDate, $endDate);
            break;
    }
    
    error_log('統計データ取得: period=' . $period . ', response=' . json_encode($response, JSON_UNESCAPED_UNICODE));
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// DELETEリクエストの処理（統計データのリセット - 開発用）
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // セキュリティ: 本番環境ではこの機能を無効化すべき
    if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
        if (@unlink($statsFile)) {
            echo json_encode(['ok' => true, 'message' => 'Statistics reset']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to reset statistics']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Confirmation required']);
    }
    exit;
}

// その他の場合はエラー
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>