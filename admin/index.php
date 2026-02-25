<?php
/**
 * admin/index.php - 管理画面
 */
$ADMIN_USER = 'riyo';
$ADMIN_PASS = '2740';

if (!isset($_SERVER['PHP_AUTH_USER']) || $_SERVER['PHP_AUTH_USER'] !== $ADMIN_USER || $_SERVER['PHP_AUTH_PW'] !== $ADMIN_PASS) {
    header('WWW-Authenticate: Basic realm="Admin"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Unauthorized';
    exit;
}

date_default_timezone_set('Asia/Tokyo');

// --- AI分析エンドポイント ---
if (isset($_GET['action']) && $_GET['action'] === 'analyze' && isset($_GET['sid'])) {
    header('Content-Type: application/json; charset=utf-8');
    $sid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['sid']);
    $logsDir = __DIR__ . '/../logs';
    $logFile = $logsDir . '/' . $sid . '.json';

    if (!file_exists($logFile)) {
        echo json_encode(['error' => 'ログファイルが見つかりません']);
        exit;
    }
    $logData = json_decode(file_get_contents($logFile), true);
    if (!$logData) {
        echo json_encode(['error' => 'ログの読み込みに失敗しました']);
        exit;
    }

    // キャッシュチェック
    if (!empty($logData['ai_analysis'])) {
        echo json_encode(['ok' => true, 'analysis' => $logData['ai_analysis'], 'cached' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // データ準備
    $conversationLog = $logData['conversation_log'] ?? [];
    $reservationParams = $logData['reservation_params'] ?? [];
    $rt = $logData['realtime_result'] ?? [];
    $reservationResult = $rt['reservation_result'] ?? ($logData['reservation_result'] ?? []);

    // 会話ログテキスト化
    $convText = '';
    if (is_array($conversationLog)) {
        foreach ($conversationLog as $entry) {
            $role = $entry['role'] ?? 'unknown';
            $text = $entry['text'] ?? '';
            $convText .= "[{$role}] {$text}\n";
        }
    }
    if (!$convText) $convText = '(会話ログなし)';

    // server.jsのプロンプト読み取り
    $currentPrompt = '';
    $serverJsPath = '/opt/callcheck-relay/server.js';
    if (file_exists($serverJsPath)) {
        $jsContent = file_get_contents($serverJsPath);
        $startPos = strpos($jsContent, 'function buildReservationInstructions(params)');
        if ($startPos !== false) {
            $nextFuncPos = strpos($jsContent, "\n/* ===", $startPos + 100);
            if ($nextFuncPos !== false) {
                $currentPrompt = substr($jsContent, $startPos, $nextFuncPos - $startPos);
            } else {
                $currentPrompt = substr($jsContent, $startPos, 5000);
            }
        }
    }

    // OpenAI API呼び出し
    $apiKey = $_SERVER['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        echo json_encode(['error' => 'OpenAI APIキーが設定されていません']);
        exit;
    }

    $jsonEnc = json_encode($reservationParams, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $resEnc = json_encode($reservationResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $analysisPrompt = <<<PROMPT
あなたはAI電話予約システムのデバッグ専門家です。
以下の失敗した予約通話を分析し、問題点と修正案を提示してください。

## 予約パラメータ
```json
{$jsonEnc}
```

## 予約結果
```json
{$resEnc}
```

## 会話ログ
```
{$convText}
```

## 現在のserver.jsプロンプト（buildReservationInstructions関数）
```javascript
{$currentPrompt}
```

上記を分析して、以下のJSON形式で回答してください:
{
  "problems": ["問題点1", "問題点2", ...],
  "root_cause": "根本原因の要約（1-2文）",
  "detailed_analysis": "詳細分析（会話の流れを追って何が問題だったか）",
  "prompt_issues": ["現在のプロンプトの問題箇所1（引用+説明）", ...],
  "full_prompt_patch": "Claude Codeに送るための修正指示。server.jsのbuildReservationInstructions関数内のどの部分をどう修正すべきか、具体的にEditツールで使える形式で記述",
  "prevention_tips": ["再発防止策1", ...]
}
PROMPT;

    $payload = [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => 'あなたはAI音声通話システムの専門家です。JSON形式で回答してください。'],
            ['role' => 'user', 'content' => $analysisPrompt],
        ],
        'response_format' => ['type' => 'json_object'],
        'temperature' => 0.3,
        'max_tokens' => 4000,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $errBody = json_decode($response, true);
        $errMsg = $errBody['error']['message'] ?? "HTTP {$httpCode}";
        echo json_encode(['error' => 'OpenAI API エラー: ' . $errMsg]);
        exit;
    }

    $result = json_decode($response, true);
    $analysisText = $result['choices'][0]['message']['content'] ?? '';
    $analysis = json_decode($analysisText, true);
    if (!$analysis) {
        echo json_encode(['error' => '分析結果のパースに失敗しました', 'raw' => $analysisText]);
        exit;
    }

    // キャッシュ保存
    $logData['ai_analysis'] = $analysis;
    file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    echo json_encode(['ok' => true, 'analysis' => $analysis, 'cached' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

// データファイルパス
$statsFile = __DIR__ . '/../stats.json';
$usageFile = __DIR__ . '/../usage_counter.txt';
$logsDir   = __DIR__ . '/../logs';
$cacheFile = __DIR__ . '/../search_cache.json';
$usersFile = __DIR__ . '/../data/users.json';

// --- データ読み込み ---
$stats = [];
if (file_exists($statsFile)) {
    $stats = json_decode(file_get_contents($statsFile), true) ?: [];
    krsort($stats);
}

$usage = [];
if (file_exists($usageFile)) {
    $usage = json_decode(file_get_contents($usageFile), true) ?: [];
    krsort($usage);
}

// 通話ログ
$calls = [];
$totalLogFiles = 0;
if (is_dir($logsDir)) {
    $files = glob($logsDir . '/*.json');
    $totalLogFiles = count($files);
    usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
    foreach ($files as $f) {
        $j = @file_get_contents($f);
        if (!$j) continue;
        $d = json_decode($j, true);
        if (!$d) continue;
        $sid = basename($f, '.json');
        $rt = $d['realtime_result'] ?? [];
        $calls[] = [
            'sid' => $sid,
            'to' => $d['to'] ?? '',
            'name' => $d['name'] ?? '',
            'mode' => $d['call_mode'] ?? ($d['mode'] ?? 'check'),
            'status' => $d['status'] ?? '',
            'open_status' => $rt['open_status'] ?? ($d['open_nlp']['result']['label'] ?? ''),
            'open_answer' => $rt['open_answer'] ?? ($d['open_answer'] ?? ''),
            'hours_answer' => $rt['hours_answer'] ?? ($d['hours_answer'] ?? ''),
            'summary' => $rt['summary'] ?? ($d['summary'] ?? ''),
            'duration' => $d['duration'] ?? null,
            'recording_url' => $d['recording_url'] ?? '',
            'created_at' => $d['created_at'] ?? '',
            'updated_at' => $d['updated_at'] ?? '',
            'rsv_result' => (function() use ($rt, $d) {
                $r = $rt['reservation_result'] ?? ($d['reservation_result'] ?? '');
                if (is_array($r)) return $r['reservation_status'] ?? 'unknown';
                return is_string($r) ? $r : '';
            })(),
            'has_analysis' => !empty($d['ai_analysis']),
            'rsv_date' => $d['rsv_date'] ?? '',
            'rsv_time' => $d['rsv_time'] ?? '',
            'rsv_party' => $d['rsv_party_size'] ?? '',
            'rsv_name' => $d['rsv_name'] ?? '',
        ];
    }
}

// ユーザーデータ
$users = [];
if (file_exists($usersFile)) {
    $users = json_decode(file_get_contents($usersFile), true) ?: [];
}
$totalUsers = count($users);

// ユーザー登録日別集計
$usersByDate = [];
$usersByProvider = [];
$usersList = [];
foreach ($users as $uid => $u) {
    // first_seen または firebase_created から日付取得
    $regDate = '';
    if (!empty($u['firebase_created'])) {
        $ts = strtotime($u['firebase_created']);
        if ($ts) $regDate = date('Y-m-d', $ts);
    }
    if (!$regDate && !empty($u['first_seen'])) {
        $ts = strtotime($u['first_seen']);
        if ($ts) $regDate = date('Y-m-d', $ts);
    }
    if ($regDate) {
        $usersByDate[$regDate] = ($usersByDate[$regDate] ?? 0) + 1;
    }

    $prov = $u['provider'] ?? 'unknown';
    $usersByProvider[$prov] = ($usersByProvider[$prov] ?? 0) + 1;

    $usersList[] = [
        'uid' => substr($uid, 0, 12) . '...',
        'email' => $u['email'] ?? '',
        'name' => $u['name'] ?? '',
        'provider' => $prov,
        'firebase_created' => $u['firebase_created'] ?? '',
        'first_seen' => $u['first_seen'] ?? '',
        'last_seen' => $u['last_seen'] ?? '',
        'login_count' => $u['login_count'] ?? 0,
    ];
}
krsort($usersByDate);
// ユーザーリストを最終ログイン順にソート
usort($usersList, function($a, $b) {
    return strcmp($b['last_seen'], $a['last_seen']);
});

// 今日の新規ユーザー
$today = date('Y-m-d');
$todayNewUsers = $usersByDate[$today] ?? 0;
// 直近7日の新規ユーザー
$week7NewUsers = 0;
for ($i = 0; $i < 7; $i++) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $week7NewUsers += ($usersByDate[$d] ?? 0);
}

// キャッシュ
$cacheCount = 0;
$cacheSize = 0;
if (file_exists($cacheFile)) {
    $cacheSize = filesize($cacheFile);
    $cacheData = json_decode(file_get_contents($cacheFile), true);
    $cacheCount = is_array($cacheData) ? count($cacheData) : 0;
}

// --- 集計 ---
$today = date('Y-m-d');
$todayStats = $stats[$today] ?? ['success' => 0, 'failed' => 0];
$todayUsage = $usage[$today] ?? 0;

$totalSuccess = 0; $totalFailed = 0;
foreach ($stats as $d => $s) {
    $totalSuccess += $s['success'];
    $totalFailed += $s['failed'];
}
$totalCalls = $totalSuccess + $totalFailed;
$overallRate = $totalCalls > 0 ? round($totalSuccess / $totalCalls * 100, 1) : 0;

// 直近7日
$week7Success = 0; $week7Failed = 0;
for ($i = 0; $i < 7; $i++) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    if (isset($stats[$d])) {
        $week7Success += $stats[$d]['success'];
        $week7Failed  += $stats[$d]['failed'];
    }
}
$week7Total = $week7Success + $week7Failed;
$week7Rate = $week7Total > 0 ? round($week7Success / $week7Total * 100, 1) : 0;

// 総利用回数
$totalUsage = array_sum($usage);

// モード別集計
$checkCount = 0; $rsvCount = 0;
foreach ($calls as $c) {
    if ($c['mode'] === 'reservation') $rsvCount++;
    else $checkCount++;
}

// ユニーク電話番号
$uniquePhones = count(array_unique(array_column($calls, 'to')));

// サポート会話
$supportDir = __DIR__ . '/../logs/support';
$supportConvs = [];
$supportStats = ['total' => 0, 'question' => 0, 'bug' => 0, 'improvement' => 0, 'unresolved' => 0];
if (is_dir($supportDir)) {
    $sFiles = glob($supportDir . '/*.json');
    $supportStats['total'] = count($sFiles);
    foreach ($sFiles as $sf) {
        $sc = @json_decode(file_get_contents($sf), true);
        if (!$sc) continue;
        $sType = $sc['type'] ?? 'question';
        $supportStats[$sType] = ($supportStats[$sType] ?? 0) + 1;
        if (empty($sc['resolved'])) $supportStats['unresolved']++;
        $lastUserMsg = '';
        foreach (array_reverse($sc['messages'] ?? []) as $sm) {
            if ($sm['role'] === 'user') { $lastUserMsg = $sm['text']; break; }
        }
        $supportConvs[] = [
            'convId'    => $sc['convId'] ?? '',
            'userName'  => $sc['userName'] ?? '',
            'uid'       => $sc['uid'] ?? '',
            'type'      => $sType,
            'summary'   => $sc['summary'] ?? '',
            'lastMsg'   => mb_strimwidth($lastUserMsg, 0, 80, '…'),
            'msgCount'  => count($sc['messages'] ?? []),
            'resolved'  => !empty($sc['resolved']),
            'createdAt' => $sc['createdAt'] ?? '',
            'updatedAt' => $sc['updatedAt'] ?? '',
            'messages'  => $sc['messages'] ?? [],
        ];
    }
    usort($supportConvs, function($a, $b) { return strcmp($b['updatedAt'], $a['updatedAt']); });
}

// サーバー情報
$phpVer = phpversion();
$serverSw = $_SERVER['SERVER_SOFTWARE'] ?? 'unknown';
$hostname = gethostname();
$diskFree = @disk_free_space(__DIR__);
$diskTotal = @disk_total_space(__DIR__);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>管理画面 - callcheck</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f1117; color: #e1e4e8; min-height: 100vh; }
.header { background: linear-gradient(135deg, #1a1f36, #252b48); padding: 20px 24px; border-bottom: 1px solid #2d3548; }
.header h1 { font-size: 20px; font-weight: 600; color: #fff; }
.header .sub { font-size: 12px; color: #8b949e; margin-top: 4px; }
.container { max-width: 1200px; margin: 0 auto; padding: 20px; }

/* サマリーカード */
.summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 24px; }
.card { background: #161b22; border: 1px solid #21262d; border-radius: 10px; padding: 16px; }
.card .label { font-size: 11px; color: #8b949e; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.card .value { font-size: 28px; font-weight: 700; color: #fff; }
.card .value.green { color: #3fb950; }
.card .value.red { color: #f85149; }
.card .value.blue { color: #58a6ff; }
.card .value.orange { color: #d29922; }
.card .sub-value { font-size: 12px; color: #8b949e; margin-top: 4px; }

/* セクション */
.section { background: #161b22; border: 1px solid #21262d; border-radius: 10px; margin-bottom: 20px; overflow: hidden; }
.section-header { padding: 14px 18px; border-bottom: 1px solid #21262d; display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
.section-header h2 { font-size: 15px; font-weight: 600; }
.section-header .badge { background: #30363d; color: #8b949e; padding: 2px 8px; border-radius: 10px; font-size: 12px; }
.section-body { padding: 0; }
.section-body.collapsed { display: none; }

/* テーブル */
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { background: #0d1117; color: #8b949e; font-weight: 500; text-align: left; padding: 10px 14px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; position: sticky; top: 0; }
td { padding: 10px 14px; border-top: 1px solid #21262d; vertical-align: top; }
tr:hover td { background: #1c2333; }
.mono { font-family: 'SF Mono', 'Cascadia Code', monospace; font-size: 12px; }
.status-open { color: #3fb950; }
.status-closed { color: #f85149; }
.status-unknown { color: #d29922; }
.status-confirmed { color: #3fb950; }
.status-rejected { color: #f85149; }
.mode-check { background: #1f3a5f; color: #58a6ff; padding: 2px 6px; border-radius: 4px; font-size: 11px; }
.mode-rsv { background: #3b2f1a; color: #d29922; padding: 2px 6px; border-radius: 4px; font-size: 11px; }

/* チャート風 */
.bar-chart { padding: 14px 18px; }
.bar-row { display: flex; align-items: center; margin-bottom: 6px; font-size: 12px; }
.bar-row .bar-label { width: 80px; color: #8b949e; flex-shrink: 0; }
.bar-row .bar-track { flex: 1; height: 20px; background: #21262d; border-radius: 4px; overflow: hidden; display: flex; }
.bar-row .bar-fill-success { background: #238636; height: 100%; }
.bar-row .bar-fill-fail { background: #da3633; height: 100%; }
.bar-row .bar-num { width: 70px; text-align: right; color: #8b949e; flex-shrink: 0; font-family: monospace; }

/* レコーディング */
.play-btn { background: #238636; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 11px; }
.play-btn:hover { background: #2ea043; }

/* サーバー情報 */
.info-grid { display: grid; grid-template-columns: 140px 1fr; gap: 4px 12px; padding: 14px 18px; font-size: 13px; }
.info-grid .info-label { color: #8b949e; }
.info-grid .info-value { color: #e1e4e8; font-family: monospace; }

/* 検索 */
.search-box { padding: 12px 18px; border-bottom: 1px solid #21262d; }
.search-box input { width: 100%; background: #0d1117; border: 1px solid #30363d; color: #e1e4e8; padding: 8px 12px; border-radius: 6px; font-size: 13px; }
.search-box input:focus { outline: none; border-color: #58a6ff; }

/* スクロール */
.table-scroll { max-height: 500px; overflow-y: auto; }
.table-scroll::-webkit-scrollbar { width: 6px; }
.table-scroll::-webkit-scrollbar-track { background: #161b22; }
.table-scroll::-webkit-scrollbar-thumb { background: #30363d; border-radius: 3px; }

/* サポート */
.sp-type { padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
.sp-type-question { background: #1f3a5f; color: #58a6ff; }
.sp-type-bug { background: #3b1a1a; color: #f85149; }
.sp-type-improvement { background: #1a3b1a; color: #3fb950; }
.sp-resolved { color: #3fb950; font-size: 11px; }
.sp-unresolved { color: #d29922; font-size: 11px; }
.sp-summary { font-size: 13px; color: #e1e4e8; }
.sp-meta { font-size: 11px; color: #8b949e; }
.sp-detail { display: none; background: #0d1117; border-radius: 6px; padding: 12px; margin-top: 8px; }
.sp-detail.open { display: block; }
.sp-bubble { max-width: 85%; padding: 8px 12px; border-radius: 10px; font-size: 13px; margin-bottom: 4px; line-height: 1.5; word-break: break-word; }
.sp-bubble-user { background: #1f6feb; color: #fff; margin-left: auto; border-bottom-right-radius: 2px; }
.sp-bubble-ai { background: #21262d; color: #e1e4e8; margin-right: auto; border-bottom-left-radius: 2px; }
.sp-msgs { display: flex; flex-direction: column; gap: 4px; }
.sp-resolve-btn { background: #238636; color: #fff; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer; font-size: 11px; margin-top: 8px; }
.sp-resolve-btn:hover { background: #2ea043; }
.sp-toggle { background: none; border: none; color: #58a6ff; cursor: pointer; font-size: 11px; padding: 2px 0; }
.sp-toggle:hover { text-decoration: underline; }
.sp-filter { display: flex; gap: 6px; padding: 12px 18px; border-bottom: 1px solid #21262d; flex-wrap: wrap; }
.sp-filter-btn { padding: 4px 10px; border: 1px solid #30363d; border-radius: 12px; background: transparent; color: #8b949e; cursor: pointer; font-size: 11px; }
.sp-filter-btn.active { background: #1f6feb; color: #fff; border-color: #1f6feb; }

/* AI分析 */
.analyze-btn { background: #8b5cf6; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 11px; white-space: nowrap; }
.analyze-btn:hover { background: #7c3aed; }
.analyze-btn.cached { background: #30363d; color: #8b949e; }
.analyze-btn.cached:hover { background: #3d444d; color: #e1e4e8; }
.analyze-btn:disabled { opacity: 0.6; cursor: wait; }
.analysis-row td { background: #0d1117 !important; padding: 0 !important; border-top: none !important; }
.analysis-content { padding: 16px 20px; }
.analysis-content h4 { color: #f0883e; font-size: 13px; margin: 12px 0 6px 0; }
.analysis-content h4:first-child { margin-top: 0; }
.analysis-content p { font-size: 13px; line-height: 1.6; color: #c9d1d9; margin: 0 0 4px 0; }
.analysis-content ul { margin: 0 0 8px 0; padding-left: 20px; }
.analysis-content li { font-size: 13px; color: #c9d1d9; line-height: 1.6; margin-bottom: 2px; }
.analysis-patch { background: #161b22; border: 1px solid #30363d; border-radius: 6px; padding: 12px; margin: 8px 0; font-family: 'SF Mono', 'Cascadia Code', monospace; font-size: 12px; color: #e1e4e8; white-space: pre-wrap; word-break: break-word; line-height: 1.5; max-height: 400px; overflow-y: auto; }
.copy-btn { background: #238636; color: #fff; border: none; padding: 6px 14px; border-radius: 4px; cursor: pointer; font-size: 12px; margin-top: 4px; }
.copy-btn:hover { background: #2ea043; }
.copy-btn.copied { background: #1f6feb; }

@media (max-width: 600px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
    .container { padding: 12px; }
    table { font-size: 11px; }
    th, td { padding: 8px 10px; }
    .card .value { font-size: 22px; }
}
</style>
</head>
<body>

<div class="header">
    <h1>callcheck 管理画面</h1>
    <div class="sub"><?= $hostname ?> | PHP <?= $phpVer ?> | <?= date('Y-m-d H:i:s') ?></div>
</div>

<div class="container">

<!-- サマリーカード -->
<div class="summary-grid">
    <div class="card">
        <div class="label">本日の通話</div>
        <div class="value blue"><?= $todayStats['success'] + $todayStats['failed'] ?></div>
        <div class="sub-value">成功 <?= $todayStats['success'] ?> / 失敗 <?= $todayStats['failed'] ?></div>
    </div>
    <div class="card">
        <div class="label">本日の成功率</div>
        <?php $todayTotal = $todayStats['success'] + $todayStats['failed']; $todayRate = $todayTotal > 0 ? round($todayStats['success'] / $todayTotal * 100, 1) : 0; ?>
        <div class="value <?= $todayRate >= 70 ? 'green' : ($todayRate >= 40 ? 'orange' : 'red') ?>"><?= $todayRate ?>%</div>
        <div class="sub-value">本日利用 <?= $todayUsage ?>回</div>
    </div>
    <div class="card">
        <div class="label">直近7日</div>
        <div class="value <?= $week7Rate >= 70 ? 'green' : ($week7Rate >= 40 ? 'orange' : 'red') ?>"><?= $week7Rate ?>%</div>
        <div class="sub-value"><?= $week7Total ?>件 (成功<?= $week7Success ?>)</div>
    </div>
    <div class="card">
        <div class="label">累計通話</div>
        <div class="value"><?= $totalCalls ?></div>
        <div class="sub-value">成功率 <?= $overallRate ?>%</div>
    </div>
    <div class="card">
        <div class="label">累計利用</div>
        <div class="value blue"><?= $totalUsage ?></div>
        <div class="sub-value"><?= count($usage) ?>日分</div>
    </div>
    <div class="card">
        <div class="label">通話ログ</div>
        <div class="value"><?= $totalLogFiles ?></div>
        <div class="sub-value">ユニーク番号 <?= $uniquePhones ?></div>
    </div>
    <div class="card">
        <div class="label">営業確認</div>
        <div class="value blue"><?= $checkCount ?></div>
        <div class="sub-value">通話ログ中</div>
    </div>
    <div class="card">
        <div class="label">予約</div>
        <div class="value orange"><?= $rsvCount ?></div>
        <div class="sub-value">通話ログ中</div>
    </div>
    <div class="card">
        <div class="label">登録ユーザー</div>
        <div class="value green"><?= $totalUsers ?></div>
        <div class="sub-value">本日+<?= $todayNewUsers ?> / 7日+<?= $week7NewUsers ?></div>
    </div>
</div>

<!-- 日別統計チャート -->
<div class="section">
    <div class="section-header" onclick="toggle('chart')">
        <h2>日別統計</h2>
        <span class="badge"><?= count($stats) ?>日分</span>
    </div>
    <div class="section-body" id="chart">
        <div class="bar-chart">
            <?php
            $maxDay = 1;
            foreach ($stats as $s) { $t = $s['success'] + $s['failed']; if ($t > $maxDay) $maxDay = $t; }
            foreach ($stats as $date => $s):
                $t = $s['success'] + $s['failed'];
                $sw = $t > 0 ? round($s['success'] / $maxDay * 100, 1) : 0;
                $fw = $t > 0 ? round($s['failed'] / $maxDay * 100, 1) : 0;
                $rate = $t > 0 ? round($s['success'] / $t * 100) : 0;
            ?>
            <div class="bar-row">
                <div class="bar-label"><?= substr($date, 5) ?></div>
                <div class="bar-track">
                    <div class="bar-fill-success" style="width:<?= $sw ?>%"></div>
                    <div class="bar-fill-fail" style="width:<?= $fw ?>%"></div>
                </div>
                <div class="bar-num"><?= $s['success'] ?>/<?= $t ?> (<?= $rate ?>%)</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ユーザー登録推移 -->
<div class="section">
    <div class="section-header" onclick="toggle('users-chart')">
        <h2>ユーザー登録推移</h2>
        <span class="badge"><?= $totalUsers ?>人</span>
    </div>
    <div class="section-body" id="users-chart">
        <?php if (empty($usersByDate)): ?>
        <div style="padding:18px;color:#8b949e;font-size:13px;">まだユーザーデータがありません。ユーザーがログインすると自動的に記録されます。</div>
        <?php else: ?>
        <div class="bar-chart">
            <?php
            $maxUserDay = max(1, max($usersByDate));
            $cumulative = 0;
            $cumulativeByDate = [];
            // 古い順に累積計算
            $datesSorted = $usersByDate;
            ksort($datesSorted);
            foreach ($datesSorted as $date => $count) {
                $cumulative += $count;
                $cumulativeByDate[$date] = $cumulative;
            }
            // 新しい順に表示
            foreach ($usersByDate as $date => $count):
                $w = round($count / $maxUserDay * 100, 1);
            ?>
            <div class="bar-row">
                <div class="bar-label"><?= substr($date, 5) ?></div>
                <div class="bar-track">
                    <div class="bar-fill-success" style="width:<?= $w ?>%; background: #8b5cf6;"></div>
                </div>
                <div class="bar-num">+<?= $count ?> (累計<?= $cumulativeByDate[$date] ?>)</div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($usersByProvider)): ?>
        <div style="padding:0 18px 14px;font-size:13px;">
            <div style="color:#8b949e;margin-bottom:6px;">プロバイダー別</div>
            <?php
            $providerLabels = [
                'google.com' => 'Google',
                'apple.com' => 'Apple',
                'password' => 'メール',
                'unknown' => '不明',
            ];
            foreach ($usersByProvider as $prov => $cnt):
                $label = $providerLabels[$prov] ?? $prov;
            ?>
            <span style="display:inline-block;background:#21262d;padding:4px 10px;border-radius:12px;margin:2px 4px 2px 0;">
                <?= htmlspecialchars($label) ?>: <?= $cnt ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ユーザー一覧 -->
<div class="section">
    <div class="section-header" onclick="toggle('users-list')">
        <h2>ユーザー一覧</h2>
        <span class="badge"><?= $totalUsers ?>人</span>
    </div>
    <div class="section-body collapsed" id="users-list">
        <?php if (empty($usersList)): ?>
        <div style="padding:18px;color:#8b949e;font-size:13px;">ユーザーデータなし</div>
        <?php else: ?>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>UID</th>
                        <th>メール</th>
                        <th>名前</th>
                        <th>認証方法</th>
                        <th>登録日</th>
                        <th>最終ログイン</th>
                        <th>回数</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $providerLabels = ['google.com'=>'Google','apple.com'=>'Apple','password'=>'メール','unknown'=>'不明'];
                foreach ($usersList as $u):
                    $regDt = '';
                    if ($u['firebase_created']) {
                        $ts = strtotime($u['firebase_created']);
                        $regDt = $ts ? date('Y/m/d', $ts) : '';
                    }
                    if (!$regDt && $u['first_seen']) {
                        $ts = strtotime($u['first_seen']);
                        $regDt = $ts ? date('Y/m/d', $ts) : '';
                    }
                    $lastDt = '';
                    if ($u['last_seen']) {
                        $ts = strtotime($u['last_seen']);
                        $lastDt = $ts ? date('m/d H:i', $ts) : '';
                    }
                    $provLabel = $providerLabels[$u['provider']] ?? $u['provider'];
                ?>
                <tr>
                    <td class="mono"><?= htmlspecialchars($u['uid']) ?></td>
                    <td><?= htmlspecialchars($u['email'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($u['name'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($provLabel) ?></td>
                    <td class="mono"><?= $regDt ?: '-' ?></td>
                    <td class="mono"><?= $lastDt ?: '-' ?></td>
                    <td class="mono"><?= $u['login_count'] ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 日別利用回数 -->
<div class="section">
    <div class="section-header" onclick="toggle('usage')">
        <h2>日別利用回数</h2>
        <span class="badge"><?= count($usage) ?>日分</span>
    </div>
    <div class="section-body collapsed" id="usage">
        <div class="bar-chart">
            <?php
            $maxUsage = max(1, max($usage ?: [1]));
            foreach ($usage as $date => $count):
                $w = round($count / $maxUsage * 100, 1);
            ?>
            <div class="bar-row">
                <div class="bar-label"><?= substr($date, 5) ?></div>
                <div class="bar-track">
                    <div class="bar-fill-success" style="width:<?= $w ?>%; background: #1f6feb;"></div>
                </div>
                <div class="bar-num"><?= $count ?>回</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- サポート -->
<div class="section">
    <div class="section-header" onclick="toggle('support')">
        <h2>カスタマーサポート</h2>
        <span class="badge"><?= $supportStats['total'] ?>件<?php if ($supportStats['unresolved'] > 0): ?> (未解決 <?= $supportStats['unresolved'] ?>)<?php endif; ?></span>
    </div>
    <?php if ($supportStats['total'] > 0): ?>
    <div class="sp-filter" id="spFilter">
        <button class="sp-filter-btn active" data-f="all">すべて (<?= $supportStats['total'] ?>)</button>
        <button class="sp-filter-btn" data-f="question">質問 (<?= $supportStats['question'] ?>)</button>
        <button class="sp-filter-btn" data-f="bug">バグ (<?= $supportStats['bug'] ?>)</button>
        <button class="sp-filter-btn" data-f="improvement">改善 (<?= $supportStats['improvement'] ?>)</button>
        <button class="sp-filter-btn" data-f="unresolved">未解決 (<?= $supportStats['unresolved'] ?>)</button>
    </div>
    <?php endif; ?>
    <div class="section-body" id="support">
        <?php if (empty($supportConvs)): ?>
            <div style="padding: 30px; text-align: center; color: #8b949e;">サポート履歴はまだありません</div>
        <?php else: ?>
            <div class="table-scroll" style="max-height: 600px;">
            <?php foreach ($supportConvs as $i => $sc): ?>
                <?php
                    $typeLabel = ['question' => '質問', 'bug' => 'バグ', 'improvement' => '改善'][$sc['type']] ?? '質問';
                    $typeCls = 'sp-type-' . $sc['type'];
                    $dateStr = $sc['updatedAt'] ? date('m/d H:i', strtotime($sc['updatedAt'])) : '';
                ?>
                <div class="sp-item" data-type="<?= htmlspecialchars($sc['type']) ?>" data-resolved="<?= $sc['resolved'] ? '1' : '0' ?>" style="padding: 12px 18px; border-bottom: 1px solid #21262d;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                        <span class="sp-type <?= $typeCls ?>"><?= $typeLabel ?></span>
                        <span class="sp-meta"><?= htmlspecialchars($sc['userName'] ?: mb_strimwidth($sc['uid'], 0, 10, '…')) ?></span>
                        <span class="sp-meta" style="margin-left:auto;"><?= $dateStr ?></span>
                        <?php if ($sc['resolved']): ?>
                            <span class="sp-resolved">✓解決済</span>
                        <?php else: ?>
                            <span class="sp-unresolved">●未解決</span>
                        <?php endif; ?>
                    </div>
                    <div class="sp-summary"><?= htmlspecialchars($sc['summary'] ?: '(要約なし)') ?></div>
                    <div class="sp-meta" style="margin-top:2px;"><?= htmlspecialchars($sc['lastMsg']) ?></div>
                    <button class="sp-toggle" onclick="toggleSpDetail(<?= $i ?>)">会話を表示 ▼</button>
                    <div class="sp-detail" id="spDetail<?= $i ?>">
                        <div class="sp-msgs">
                        <?php foreach ($sc['messages'] as $m): ?>
                            <div class="sp-bubble <?= $m['role'] === 'user' ? 'sp-bubble-user' : 'sp-bubble-ai' ?>">
                                <?= nl2br(htmlspecialchars($m['text'])) ?>
                                <div style="font-size:10px;opacity:0.6;margin-top:2px;"><?= $m['time'] ? date('H:i', strtotime($m['time'])) : '' ?></div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                        <?php if (!$sc['resolved']): ?>
                        <form onsubmit="resolveSupport(event, '<?= htmlspecialchars($sc['convId'], ENT_QUOTES) ?>', this)" style="display:inline;">
                            <button type="submit" class="sp-resolve-btn">✓ 解決済みにする</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 通話履歴 -->
<div class="section">
    <div class="section-header" onclick="toggle('calls')">
        <h2>通話履歴</h2>
        <span class="badge"><?= $totalLogFiles ?>件</span>
    </div>
    <div class="search-box">
        <input type="text" id="callSearch" placeholder="店名・電話番号で検索..." oninput="filterCalls()">
    </div>
    <div class="section-body" id="calls">
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>日時</th>
                        <th>モード</th>
                        <th>店名</th>
                        <th>電話番号</th>
                        <th>結果</th>
                        <th>詳細</th>
                        <th>分析</th>
                        <th>録音</th>
                    </tr>
                </thead>
                <tbody id="callsBody">
                <?php foreach ($calls as $c):
                    $openClass = '';
                    $openLabel = $c['open_status'];
                    if (stripos($c['open_status'], 'open') !== false || $c['open_status'] === '営業中') {
                        $openClass = 'status-open'; $openLabel = '営業中';
                    } elseif (stripos($c['open_status'], 'closed') !== false || $c['open_status'] === '休業') {
                        $openClass = 'status-closed'; $openLabel = '休業';
                    } elseif ($c['open_status']) {
                        $openClass = 'status-unknown'; $openLabel = $c['open_status'];
                    }

                    if ($c['mode'] === 'reservation') {
                        if (stripos($c['rsv_result'], 'confirmed') !== false) {
                            $openClass = 'status-confirmed'; $openLabel = '予約確定';
                        } elseif (stripos($c['rsv_result'], 'rejected') !== false || stripos($c['rsv_result'], 'failed') !== false) {
                            $openClass = 'status-rejected'; $openLabel = '予約不可';
                        } elseif ($c['rsv_result']) {
                            $openClass = 'status-unknown'; $openLabel = $c['rsv_result'];
                        }
                    }

                    $phone = $c['to'];
                    if (preg_match('/^\+81(\d+)$/', $phone, $m)) $phone = '0' . $m[1];

                    $dt = '';
                    if ($c['created_at']) {
                        $ts = strtotime($c['created_at']);
                        $dt = $ts ? date('m/d H:i', $ts) : $c['created_at'];
                    }

                    $detail = $c['summary'] ?: ($c['hours_answer'] ?: $c['open_answer']);
                    if (mb_strlen($detail) > 60) $detail = mb_substr($detail, 0, 60) . '...';
                ?>
                    <tr class="call-row" data-search="<?= htmlspecialchars(strtolower($c['name'] . ' ' . $phone . ' ' . $c['summary'])) ?>">
                        <td class="mono"><?= htmlspecialchars($dt) ?></td>
                        <td><?php if ($c['mode'] === 'reservation'): ?><span class="mode-rsv">予約</span><?php else: ?><span class="mode-check">確認</span><?php endif; ?></td>
                        <td><?= htmlspecialchars($c['name'] ?: '-') ?></td>
                        <td class="mono"><?= htmlspecialchars($phone) ?></td>
                        <td><span class="<?= $openClass ?>"><?= htmlspecialchars($openLabel ?: '-') ?></span></td>
                        <td style="max-width:250px;word-break:break-all;"><?= htmlspecialchars($detail ?: '-') ?></td>
                        <td>
                            <?php
                            $isFailedRsv = ($c['mode'] === 'reservation') &&
                                (stripos($c['rsv_result'], 'rejected') !== false ||
                                 stripos($c['rsv_result'], 'unknown') !== false ||
                                 stripos($c['rsv_result'], 'no_response') !== false ||
                                 stripos($c['rsv_result'], 'failed') !== false);
                            if ($isFailedRsv): ?>
                                <button class="analyze-btn<?= $c['has_analysis'] ? ' cached' : '' ?>" onclick="analyzeCall(this,'<?= htmlspecialchars($c['sid']) ?>')" data-sid="<?= htmlspecialchars($c['sid']) ?>">
                                    <?= $c['has_analysis'] ? '分析済' : 'AI分析' ?>
                                </button>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($c['recording_url']): ?>
                                <button class="play-btn" onclick="playRec(this,'<?= htmlspecialchars($c['sid']) ?>')">再生</button>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 検索キャッシュ -->
<div class="section">
    <div class="section-header" onclick="toggle('cache')">
        <h2>検索キャッシュ</h2>
        <span class="badge"><?= $cacheCount ?>件</span>
    </div>
    <div class="section-body collapsed" id="cache">
        <div class="info-grid">
            <div class="info-label">エントリ数</div>
            <div class="info-value"><?= $cacheCount ?></div>
            <div class="info-label">ファイルサイズ</div>
            <div class="info-value"><?= $cacheSize > 0 ? round($cacheSize / 1024, 1) . ' KB' : '0' ?></div>
            <div class="info-label">有効期限</div>
            <div class="info-value">30分</div>
        </div>
    </div>
</div>

<!-- サーバー情報 -->
<div class="section">
    <div class="section-header" onclick="toggle('server')">
        <h2>サーバー情報</h2>
    </div>
    <div class="section-body collapsed" id="server">
        <div class="info-grid">
            <div class="info-label">ホスト名</div>
            <div class="info-value"><?= htmlspecialchars($hostname) ?></div>
            <div class="info-label">PHP バージョン</div>
            <div class="info-value"><?= $phpVer ?></div>
            <div class="info-label">サーバー</div>
            <div class="info-value"><?= htmlspecialchars($serverSw) ?></div>
            <div class="info-label">ドキュメントルート</div>
            <div class="info-value"><?= htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? '') ?></div>
            <div class="info-label">ディスク空き</div>
            <div class="info-value"><?= $diskFree !== false ? round($diskFree / 1024 / 1024 / 1024, 2) . ' GB' : 'N/A' ?></div>
            <div class="info-label">ディスク合計</div>
            <div class="info-value"><?= $diskTotal !== false ? round($diskTotal / 1024 / 1024 / 1024, 2) . ' GB' : 'N/A' ?></div>
            <div class="info-label">メモリ上限</div>
            <div class="info-value"><?= ini_get('memory_limit') ?></div>
            <div class="info-label">最大実行時間</div>
            <div class="info-value"><?= ini_get('max_execution_time') ?>秒</div>
            <div class="info-label">タイムゾーン</div>
            <div class="info-value"><?= date_default_timezone_get() ?></div>
            <div class="info-label">現在時刻</div>
            <div class="info-value"><?= date('Y-m-d H:i:s T') ?></div>
        </div>
    </div>
</div>

</div>

<script>
function toggle(id) {
    const el = document.getElementById(id);
    el.classList.toggle('collapsed');
}
function filterCalls() {
    const q = document.getElementById('callSearch').value.toLowerCase();
    document.querySelectorAll('.call-row').forEach(row => {
        row.style.display = row.dataset.search.includes(q) ? '' : 'none';
    });
}
function playRec(btn, sid) {
    const base = window.location.origin;
    const url = base + '/call?recording_proxy=' + sid;
    let audio = btn.parentElement.querySelector('audio');
    if (audio) { audio.remove(); btn.textContent = '再生'; return; }
    audio = document.createElement('audio');
    audio.controls = true;
    audio.src = url;
    audio.style.cssText = 'width:150px;height:30px;margin-top:4px;display:block;';
    btn.parentElement.appendChild(audio);
    audio.play();
    btn.textContent = '閉じる';
}

// サポート: 会話詳細トグル
function toggleSpDetail(i) {
    const el = document.getElementById('spDetail' + i);
    const btn = el.previousElementSibling;
    if (el.classList.contains('open')) {
        el.classList.remove('open');
        btn.textContent = '会話を表示 ▼';
    } else {
        el.classList.add('open');
        btn.textContent = '会話を閉じる ▲';
    }
}

// サポート: フィルター
document.getElementById('spFilter')?.addEventListener('click', function(e) {
    const btn = e.target.closest('.sp-filter-btn');
    if (!btn) return;
    this.querySelectorAll('.sp-filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const f = btn.dataset.f;
    document.querySelectorAll('.sp-item').forEach(item => {
        if (f === 'all') { item.style.display = ''; return; }
        if (f === 'unresolved') { item.style.display = item.dataset.resolved === '0' ? '' : 'none'; return; }
        item.style.display = item.dataset.type === f ? '' : 'none';
    });
});

// サポート: 解決済みにする
async function resolveSupport(e, convId, form) {
    e.preventDefault();
    const item = form.closest('.sp-item');
    try {
        const res = await fetch('/support?resolve', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ secret: 'callcheck_admin_2026', convId: convId })
        });
        const j = await res.json();
        if (j.ok) {
            form.outerHTML = '<span class="sp-resolved">✓ 解決済み</span>';
            if (item) {
                item.dataset.resolved = '1';
                const badge = item.querySelector('.sp-unresolved');
                if (badge) { badge.className = 'sp-resolved'; badge.textContent = '✓解決済'; }
            }
        }
    } catch(err) { alert('エラー: ' + err.message); }
}

// AI分析
async function analyzeCall(btn, sid) {
    // 既存の展開行があればトグル
    const existingRow = document.getElementById('analysis-' + sid);
    if (existingRow) {
        existingRow.style.display = existingRow.style.display === 'none' ? '' : 'none';
        return;
    }

    btn.disabled = true;
    btn.textContent = '分析中...';

    try {
        const res = await fetch('?action=analyze&sid=' + encodeURIComponent(sid));
        const j = await res.json();

        if (j.error) {
            alert('分析エラー: ' + j.error);
            btn.disabled = false;
            btn.textContent = btn.classList.contains('cached') ? '分析済' : 'AI分析';
            return;
        }

        const a = j.analysis;
        const tr = btn.closest('tr');

        // 展開行を作成
        const newRow = document.createElement('tr');
        newRow.id = 'analysis-' + sid;
        newRow.className = 'analysis-row';
        const td = document.createElement('td');
        td.colSpan = 8;

        let html = '<div class="analysis-content">';

        // 根本原因
        html += '<h4>根本原因</h4>';
        html += '<p>' + escHtml(a.root_cause || '') + '</p>';

        // 問題点
        if (a.problems && a.problems.length) {
            html += '<h4>問題点</h4><ul>';
            a.problems.forEach(p => { html += '<li>' + escHtml(p) + '</li>'; });
            html += '</ul>';
        }

        // 詳細分析
        if (a.detailed_analysis) {
            html += '<h4>詳細分析</h4>';
            html += '<p>' + escHtml(a.detailed_analysis) + '</p>';
        }

        // プロンプトの問題箇所
        if (a.prompt_issues && a.prompt_issues.length) {
            html += '<h4>プロンプトの問題箇所</h4><ul>';
            a.prompt_issues.forEach(p => { html += '<li>' + escHtml(p) + '</li>'; });
            html += '</ul>';
        }

        // 修正指示（コピー可能）
        if (a.full_prompt_patch) {
            html += '<h4>修正指示（Claude Codeに送信用）</h4>';
            html += '<div class="analysis-patch" id="patch-' + sid + '">' + escHtml(a.full_prompt_patch) + '</div>';
            html += '<button class="copy-btn" onclick="copyPatch(\'' + sid + '\', this)">コピー</button>';
        }

        // 再発防止策
        if (a.prevention_tips && a.prevention_tips.length) {
            html += '<h4>再発防止策</h4><ul>';
            a.prevention_tips.forEach(p => { html += '<li>' + escHtml(p) + '</li>'; });
            html += '</ul>';
        }

        html += '</div>';
        td.innerHTML = html;
        newRow.appendChild(td);
        tr.after(newRow);

        btn.disabled = false;
        btn.textContent = '分析済';
        btn.classList.add('cached');
    } catch (err) {
        alert('通信エラー: ' + err.message);
        btn.disabled = false;
        btn.textContent = btn.classList.contains('cached') ? '分析済' : 'AI分析';
    }
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function copyPatch(sid, btn) {
    const el = document.getElementById('patch-' + sid);
    if (!el) return;
    navigator.clipboard.writeText(el.textContent).then(() => {
        btn.textContent = 'コピー済!';
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = 'コピー'; btn.classList.remove('copied'); }, 2000);
    }).catch(() => {
        // fallback
        const range = document.createRange();
        range.selectNodeContents(el);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        document.execCommand('copy');
        btn.textContent = 'コピー済!';
        setTimeout(() => { btn.textContent = 'コピー'; }, 2000);
    });
}
</script>
</body>
</html>
