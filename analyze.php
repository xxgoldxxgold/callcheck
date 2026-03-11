<?php
/**
 * analyze.php — 通話後自動分析スクリプト
 *
 * ?run=1&sid=XXX  → call.phpから呼ばれ、会話ログをgpt-4o-miniで分析（localhost限定）
 * ?review=1       → 最近の問題一覧を返す（Basic Auth: riyo/2740）
 */

header('Content-Type: application/json; charset=utf-8');

$logsDir = __DIR__ . '/logs';
$analysisDir = $logsDir . '/analysis';

// ============================================================
// Mode 1: 分析実行（call.phpからのfire-and-forget）
// ============================================================
if (isset($_GET['run']) && $_GET['run'] === '1' && isset($_GET['sid'])) {

    // localhostのみ許可 + シークレットキー検証
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remoteIp, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    $analyzeSecret = $_SERVER['CALLBACK_SECRET'] ?? getenv('CALLBACK_SECRET') ?: '';
    $providedSecret = $_GET['secret'] ?? '';
    if ($analyzeSecret !== '' && !hash_equals($analyzeSecret, $providedSecret)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }

    // 即座に202を返し、バックグラウンドで処理続行
    http_response_code(202);
    echo json_encode(['ok' => true, 'status' => 'accepted']);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // --- ここからバックグラウンド処理 ---
    $callSid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['sid']);
    $logFile = $logsDir . '/' . $callSid . '.json';

    if (!file_exists($logFile)) {
        error_log("analyze.php: log file not found: $callSid");
        exit;
    }

    $logData = json_decode(file_get_contents($logFile), true);
    if (!$logData) {
        error_log("analyze.php: failed to parse log: $callSid");
        exit;
    }

    // スキップ条件
    $conversationLog = $logData['conversation_log'] ?? [];
    if (!is_array($conversationLog) || count($conversationLog) < 2) {
        exit; // 会話が短すぎる
    }

    $rt = $logData['realtime_result'] ?? [];
    $openStatus = $rt['open_status'] ?? ($logData['open_status'] ?? '');
    if (in_array($openStatus, ['failed', 'busy', 'no-answer', 'no_response'], true)) {
        exit; // 接続できなかった通話はスキップ
    }

    // 既に分析済みならスキップ
    if (!is_dir($analysisDir)) {
        @mkdir($analysisDir, 0755, true);
    }
    $analysisFile = $analysisDir . '/' . $callSid . '.json';
    if (file_exists($analysisFile)) {
        exit;
    }

    // 会話ログテキスト化
    $convText = '';
    foreach ($conversationLog as $entry) {
        $role = $entry['role'] ?? 'unknown';
        $text = $entry['text'] ?? '';
        $convText .= "[{$role}] {$text}\n";
    }
    if (!$convText) {
        exit;
    }

    // call_mode判定
    $callMode = $logData['call_mode'] ?? 'check';
    $reservationParams = $logData['reservation_params'] ?? [];
    $reservationResult = $rt['reservation_result'] ?? ($logData['reservation_result'] ?? []);

    // 分析プロンプト構築
    if ($callMode === 'reservation') {
        $paramsJson = json_encode($reservationParams, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $resultJson = json_encode($reservationResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $analysisPrompt = <<<PROMPT
あなたはAI電話予約システムの品質管理者です。
以下の予約通話を分析し、問題がないかチェックしてください。

## 予約パラメータ
```json
{$paramsJson}
```

## 予約結果
```json
{$resultJson}
```

## 会話ログ
```
{$convText}
```

以下の観点でチェックしてください:
1. 手順通り進んだか（挨拶→日時人数→名前→電話番号→確認）
2. 1ステップ1情報のルールを守ったか
3. 「もう一回言って」等の聞き返しに適切に応じたか
4. 名前・電話番号・日時を正確に伝えたか
5. 代替時間の提案があった場合、適切に判断したか
6. 不自然な沈黙・繰り返し・ループはないか
7. 不適切な発言はないか

JSON形式で回答してください:
{
  "has_issues": true/false,
  "severity": "none" | "low" | "medium" | "high",
  "summary": "問題の概要（1-2文。問題なしなら空文字）",
  "issues": [
    {
      "type": "issue_type",
      "description": "具体的な問題の説明",
      "conversation_context": "該当部分の会話引用",
      "suggested_fix": "server.jsプロンプトへの修正提案"
    }
  ]
}

severity判定基準:
- none: 問題なし
- low: 軽微（少し不自然だが実害なし）
- medium: 要注意（誤解や不要な繰り返しがある）
- high: 重大（情報の誤伝達、ループ、不適切発言）
PROMPT;
    } else {
        // check mode
        $summaryText = $rt['summary'] ?? ($logData['summary'] ?? '');
        $analysisPrompt = <<<PROMPT
あなたはAI電話営業確認システムの品質管理者です。
以下の営業確認通話を分析し、問題がないかチェックしてください。

## 通話結果
- 営業状態: {$openStatus}
- 要約: {$summaryText}

## 会話ログ
```
{$convText}
```

以下の観点でチェックしてください:
1. 営業中かどうかを正しく聞けたか
2. 相手の返答を正しく理解したか（open/closedの判定は正確か）
3. 営業時間を聞き取れたか
4. 不自然な沈黙・繰り返し・ループはないか
5. 不適切な発言はないか
6. 相手が困惑・不快になるような対応はなかったか

JSON形式で回答してください:
{
  "has_issues": true/false,
  "severity": "none" | "low" | "medium" | "high",
  "summary": "問題の概要（1-2文。問題なしなら空文字）",
  "issues": [
    {
      "type": "issue_type",
      "description": "具体的な問題の説明",
      "conversation_context": "該当部分の会話引用",
      "suggested_fix": "server.jsプロンプトへの修正提案"
    }
  ]
}

severity判定基準:
- none: 問題なし
- low: 軽微（少し不自然だが実害なし）
- medium: 要注意（誤解や不要な繰り返しがある）
- high: 重大（情報の誤伝達、ループ、不適切発言）
PROMPT;
    }

    // OpenAI API呼び出し
    $apiKey = $_SERVER['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        error_log("analyze.php: no OpenAI API key");
        exit;
    }

    $payload = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => 'あなたはAI音声通話システムの品質管理者です。JSON形式で回答してください。'],
            ['role' => 'user', 'content' => $analysisPrompt],
        ],
        'response_format' => ['type' => 'json_object'],
        'temperature' => 0.2,
        'max_completion_tokens' => 2000,
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
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("analyze.php: OpenAI API error HTTP $httpCode for $callSid: $curlErr");
        exit;
    }

    $result = json_decode($response, true);
    $analysisText = $result['choices'][0]['message']['content'] ?? '';
    $analysis = json_decode($analysisText, true);
    if (!$analysis) {
        error_log("analyze.php: failed to parse analysis for $callSid");
        exit;
    }

    // severity medium以上のみ保存
    $severity = $analysis['severity'] ?? 'none';
    if (!in_array($severity, ['medium', 'high'], true)) {
        exit; // 問題なし or low → 保存しない
    }

    $analysisResult = [
        'call_sid' => $callSid,
        'call_mode' => $callMode,
        'analyzed_at' => date('c'),
        'severity' => $severity,
        'has_issues' => $analysis['has_issues'] ?? true,
        'summary' => $analysis['summary'] ?? '',
        'issues' => $analysis['issues'] ?? [],
    ];

    file_put_contents($analysisFile, json_encode($analysisResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    error_log("analyze.php: saved analysis for $callSid (severity: $severity)");
    exit;
}

// ============================================================
// Mode 2: レビュー（Claude Code用）
// ============================================================
if (isset($_GET['review']) && $_GET['review'] === '1') {

    // Basic Auth
    $authUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $authPass = $_SERVER['PHP_AUTH_PW'] ?? '';
    if ($authUser !== 'riyo' || $authPass !== 'aTiZKDTLwrIop0Lc9JlwStn4') {
        header('WWW-Authenticate: Basic realm="Analysis Review"');
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }

    if (!is_dir($analysisDir)) {
        echo json_encode(['ok' => true, 'issues' => [], 'total' => 0, 'period' => 'all']);
        exit;
    }

    // フィルタパラメータ
    $since = $_GET['since'] ?? null; // e.g. 2026-02-20
    $severityFilter = $_GET['severity'] ?? null; // e.g. high
    $modeFilter = $_GET['mode'] ?? null; // e.g. reservation
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    if ($limit < 1) $limit = 50;

    // 分析ファイル一覧（新しい順）
    $files = glob($analysisDir . '/*.json');
    if (!$files) {
        echo json_encode(['ok' => true, 'issues' => [], 'total' => 0, 'period' => 'all']);
        exit;
    }

    // 更新日時で降順ソート
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    $issues = [];
    $sinceTs = $since ? strtotime($since . ' 00:00:00') : null;
    // デフォルトは7日間
    if (!$sinceTs) {
        $sinceTs = strtotime('-7 days');
    }

    foreach ($files as $f) {
        if (filemtime($f) < $sinceTs) {
            continue;
        }

        $data = json_decode(file_get_contents($f), true);
        if (!$data) continue;

        if ($severityFilter && ($data['severity'] ?? '') !== $severityFilter) continue;
        if ($modeFilter && ($data['call_mode'] ?? '') !== $modeFilter) continue;

        $issues[] = $data;
        if (count($issues) >= $limit) break;
    }

    $period = $since ? "since_{$since}" : 'last_7_days';
    echo json_encode([
        'ok' => true,
        'issues' => $issues,
        'total' => count($issues),
        'period' => $period,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ============================================================
// デフォルト: 使い方
// ============================================================
http_response_code(400);
echo json_encode(['error' => 'invalid request', 'usage' => '?run=1&sid=XXX or ?review=1']);
