<?php
/**
 * call.php - ChatGPT(API)で"営業中/休業/不明"を判定する二段階フロー
 * + Realtime API コールバック受信対応
 */

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

/**
 * 環境変数ユーティリティ
 */
function envv($k, $d = '') {
    $v = getenv($k);
    return ($v !== false && $v !== '') ? $v : $d;
}

function env_first(array $names, $default = '') {
    foreach ($names as $n) {
        $v = getenv($n);
        if ($v !== false && $v !== '') {
            return $v;
        }
    }
    return $default;
}

function env_pick(array $names) {
    foreach ($names as $n) {
        $v = getenv($n);
        if ($v !== false && $v !== '') {
            return [$v, $n];
        }
    }
    return [null, null];
}

/**
 * ベースURL取得
 */
function base_url() {
    $env = rtrim(envv('PUBLIC_BASE_URL'), '/');
    if ($env) {
        return $env;
    }
    
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    
    return $scheme . '://' . $host . $dir;
}

/**
 * JSONレスポンス
 */
function json_res($a) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * ログディレクトリ管理
 */
function logs_dir() {
    $d = __DIR__ . '/logs';
    if (!is_dir($d)) {
        @mkdir($d, 0750, true);
    }
    return $d;
}

function log_path($sid) {
    return logs_dir() . '/' . preg_replace('/[^a-zA-Z0-9]/', '_', $sid) . '.json';
}

/**
 * データストレージ関数
 */
function store_get($sid) {
    $p = log_path($sid);
    if (!file_exists($p)) {
        return null;
    }
    $j = @file_get_contents($p);
    if (!$j) return null;
    $decoded = json_decode($j, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        error_log("store_get: JSON decode error for SID=$sid: " . json_last_error_msg());
        return null;
    }
    return $decoded ?: null;
}

/** flock付きアトミック読み書き */
function store_atomic($sid, $callback) {
    $p = log_path($sid);
    $fp = @fopen($p, 'c+');
    if (!$fp) {
        error_log("store_atomic: cannot open $p");
        return;
    }
    flock($fp, LOCK_EX);
    $content = stream_get_contents($fp);
    $all = $content ? (json_decode($content, true) ?: []) : [];
    $all = $callback($all);
    $all['updated_at'] = date('c');
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function store_put($sid, $k, $v) {
    store_atomic($sid, function($all) use ($k, $v) {
        $all[$k] = $v;
        return $all;
    });
}

function store_merge($sid, $arr) {
    store_atomic($sid, function($all) use ($arr) {
        foreach ($arr as $k => $v) {
            $all[$k] = $v;
        }
        return $all;
    });
}

function store_append($sid, $k, $v) {
    store_atomic($sid, function($all) use ($k, $v) {
        if (!isset($all[$k]) || !is_array($all[$k])) {
            $all[$k] = [];
        }
        $all[$k][] = $v;
        return $all;
    });
}

/**
 * 統計記録ユーティリティ
 */
function record_stats($action, $date = null) {
    if ($date === null) {
        $date = date('Y-m-d');
    }
    
    $statsFile = __DIR__ . '/stats.json';
    
    if ($action === 'success') {
        recordSuccess($statsFile, $date);
    } elseif ($action === 'failed') {
        recordFailure($statsFile, $date);
    }
}

function getStatsData($file) {
    if (!file_exists($file)) {
        return [];
    }
    
    $data = json_decode(file_get_contents($file), true);
    return $data ?? [];
}

function saveStatsData($file, $data) {
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function recordSuccess($file, $date) {
    $data = getStatsData($file);
    
    if (!isset($data[$date])) {
        $data[$date] = ['success' => 0, 'failed' => 0];
    }
    
    $data[$date]['success']++;
    saveStatsData($file, $data);
    
    return $data[$date];
}

function recordFailure($file, $date) {
    $data = getStatsData($file);
    
    if (!isset($data[$date])) {
        $data[$date] = ['success' => 0, 'failed' => 0];
    }
    
    $data[$date]['failed']++;
    saveStatsData($file, $data);
    
    return $data[$date];
}

/**
 * 時刻パース関数
 */
function mm_to_hhmm($mm) {
    $h = floor($mm / 60);
    $m = $mm % 60;
    return sprintf('%02d:%02d', $h % 24, $m);
}

function parse_hours_text($text) {
    $t = mb_convert_kana($text, 'rknas', 'UTF-8');
    $t = preg_replace('/\s+/u', ' ', $t);
    $t = preg_replace('/[~〜～ー–∼\-]+/u', '〜', $t);
    $t = preg_replace('/\s*(時|分|まで|から|〜|：|:)\s*/u', '$1', $t);

    $t = preg_replace_callback('/(午前|午後)\s*([0-2]?\d)\s*時/u', function($m){
        $h = (int)$m[2];
        if ($m[1] === '午後' && $h < 12) $h += 12;
        if ($m[1] === '午前' && $h === 12) $h = 0;
        return sprintf('%02d時', $h);
    }, $t);

    error_log("parse_hours_text デバッグ: 入力='$text', 正規化後='$t'");

    $ranges = [];

    if (preg_match('/(?P<sh>[01]?\d|2[0-4])(?:[:：時](?P<sm>半|[0-5]?\d)?(?:分)?)?〜(?P<eh>[01]?\d|2[0-4])(?:[:：時](?P<em>半|[0-5]?\d)?(?:分)?)?/u', $t, $m)) {
        $sh = (int)$m['sh'];
        $sm = ($m['sm'] === '半') ? 30 : (isset($m['sm']) && $m['sm'] !== '' ? (int)$m['sm'] : 0);
        $eh = (int)$m['eh'];
        $em = ($m['em'] === '半') ? 30 : (isset($m['em']) && $m['em'] !== '' ? (int)$m['em'] : 0);

        $ranges[] = ['start' => sprintf('%02d:%02d', $sh % 24, $sm), 'end' => sprintf('%02d:%02d', $eh % 24, $em), 'note' => null];
        return ['label' => null, 'confidence' => 0, 'reasons' => [], 'hours' => $ranges];
    }

    if (preg_match('/(?P<eh>[01]?\d|2[0-4])(?:[:：時](?P<em>半|[0-5]?\d)?(?:分)?)?(?:まで|迄|頃まで)/u', $t, $m)) {
        $eh = (int)$m['eh'];
        $em = ($m['em'] === '半') ? 30 : (isset($m['em']) && $m['em'] !== '' ? (int)$m['em'] : 0);

        $ranges[] = ['start' => null, 'end' => sprintf('%02d:%02d', $eh % 24, $em), 'note' => 'until'];
        return ['label' => null, 'confidence' => 0, 'reasons' => [], 'hours' => $ranges];
    }

    if (preg_match('/(?P<sh>[01]?\d|2[0-4])(?:[:：時](?P<sm>半|[0-5]?\d)?(?:分)?)?から/u', $t, $m)) {
        $sh = (int)$m['sh'];
        $sm = ($m['sm'] === '半') ? 30 : (isset($m['sm']) && $m['sm'] !== '' ? (int)$m['sm'] : 0);

        $ranges[] = ['start' => sprintf('%02d:%02d', $sh % 24, $sm), 'end' => null, 'note' => 'from'];
        return ['label' => null, 'confidence' => 0, 'reasons' => [], 'hours' => $ranges];
    }

    return ['label' => null, 'confidence' => 0, 'reasons' => ['no_hours'], 'hours' => []];
}

/**
 * 診断機能
 */
if (isset($_GET['diag'])) {
    // Basic Auth必須
    $authUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $authPass = $_SERVER['PHP_AUTH_PW'] ?? '';
    if ($authUser !== 'riyo' || $authPass !== (getenv('ADMIN_PASSWORD') ?: 'aTiZKDTLwrIop0Lc9JlwStn4')) {
        header('WWW-Authenticate: Basic realm="Diag"');
        http_response_code(401);
        echo 'Unauthorized';
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    $FROM_CAND = ['TWILIO_FROM', 'TWILIO_FROM_NUMBER', 'TWILIO_NUMBER', 'TWILIO_CALL_FROM', 'TWILIO_CALLER', 'CALL_FROM'];
    $OPENAI_CAND = ['OPENAI_API_KEY', 'CHATGPT_API_KEY', 'OPENAI_KEY', 'OPENAI_TOKEN'];
    $from_presence = [];
    foreach ($FROM_CAND as $k) {
        $from_presence[$k] = (bool)getenv($k);
    }
    $openai_presence = [];
    foreach ($OPENAI_CAND as $k) {
        $openai_presence[$k] = (bool)getenv($k);
    }
    [, $from_src] = env_pick($FROM_CAND);
    [, $openai_src] = env_pick($OPENAI_CAND);
    echo json_encode([
        'PUBLIC_BASE_URL' => (bool)getenv('PUBLIC_BASE_URL'),
        'TWILIO_ACCOUNT_SID_present' => (bool)getenv('TWILIO_ACCOUNT_SID'),
        'TWILIO_AUTH_TOKEN_present' => (bool)getenv('TWILIO_AUTH_TOKEN'),
        'TWILIO_FROM_candidates' => $from_presence,
        'TWILIO_FROM_effective_var' => $from_src,
        'OPENAI_key_candidates' => $openai_presence,
        'OPENAI_key_effective_var' => $openai_src,
        'OPENAI_MODEL' => getenv('OPENAI_MODEL') ?: 'gpt-5-mini',
        'php_curl' => function_exists('curl_version'),
        'mode' => 'realtime',
        'WS_PORT' => getenv('WS_PORT') ?: '8080',
        'WS_PUBLIC_URL' => getenv('WS_PUBLIC_URL') ?: '(auto-detect)',
        'CALLBACK_SECRET_present' => (bool)getenv('CALLBACK_SECRET'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * ============================================================
 * Realtime API コールバック受信
 * VPSのserver.jsからPOSTで結果を受け取る
 * ============================================================
 */
if (isset($_GET['rt_cb'])) {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        http_response_code(400);
        json_res(['ok' => false, 'error' => 'invalid json']);
    }
    
    // 秘密キー検証
    $expected_secret = envv('CALLBACK_SECRET', '');
    $received_secret = $data['secret'] ?? '';
    if ($expected_secret && $received_secret !== $expected_secret) {
        http_response_code(403);
        json_res(['ok' => false, 'error' => 'invalid secret']);
    }
    
    $callSid = $data['call_sid'] ?? '';
    $result = $data['result'] ?? [];
    $conversationLog = $data['conversation_log'] ?? [];
    
    if (!$callSid) {
        http_response_code(400);
        json_res(['ok' => false, 'error' => 'call_sid required']);
    }
    
    // 結果を一括保存（アトミック操作で競合防止）
    $openStatus = $result['open_status'] ?? 'unknown';
    // 予約モードではrt_cb到着時にstatus=completedにしない（通話はまだ続いている）
    // Twilioのstatus callbackが来るまで待つ
    $existingData = store_get($callSid);
    $callMode = $existingData['call_mode'] ?? 'check';
    $isReservation = ($callMode === 'reservation');
    $statusValue = $isReservation ? 'result_received' : 'completed';
    // ただしTwilioのstatus callbackが先に来ていた場合はcompletedのまま
    if ($isReservation && ($existingData['status'] ?? '') === 'completed') {
        $statusValue = 'completed';
    }
    $mergeData = [
        'realtime_mode' => true,
        'realtime_result' => $result,
        'open_nlp' => [
            'error' => null,
            'model' => 'realtime-api',
            'raw' => $result,
            'result' => [
                'label' => $openStatus,
                'confidence' => 0.9,
                'reasons' => ['realtime_api'],
                'hours' => []
            ]
        ],
        'status' => $statusValue,
    ];

    if (!empty($conversationLog)) {
        $mergeData['conversation_log'] = $conversationLog;
    }
    if (!empty($result['open_answer'])) {
        $mergeData['open_answer'] = $result['open_answer'];
    }
    if (!empty($result['hours_answer'])) {
        $mergeData['hours_answer'] = $result['hours_answer'];
        $mergeData['hours_parsed'] = parse_hours_text($result['hours_answer']);
    }
    if (!empty($result['hours_end'])) {
        $mergeData['hours_end'] = $result['hours_end'];
    }
    if (!empty($result['hours_start'])) {
        $mergeData['hours_start'] = $result['hours_start'];
    }
    if (!empty($result['summary'])) {
        $mergeData['summary'] = $result['summary'];
    }
    if (!empty($result['reservation_status'])) {
        $mergeData['reservation_result'] = [
            'reservation_status' => $result['reservation_status'],
            'confirmation' => $result['confirmation'] ?? '',
            'rejection_reason' => $result['rejection_reason'] ?? '',
            'alternative_suggestion' => $result['alternative_suggestion'] ?? '',
            'summary' => $result['summary'] ?? ''
        ];
    }

    // ★ 統計記録も同一アトミック操作内で実行（二重カウント防止）
    $statsRecorded = false;
    store_atomic($callSid, function($all) use ($mergeData, $openStatus, &$statsRecorded) {
        foreach ($mergeData as $k => $v) {
            $all[$k] = $v;
        }
        if (!isset($all['stats_recorded'])) {
            $all['stats_recorded'] = true;
            $statsRecorded = true;
        }
        return $all;
    });
    if ($statsRecorded) {
        if ($openStatus === 'no_response' || $openStatus === 'unknown') {
            record_stats('failed');
        } else {
            record_stats('success');
        }
    }
    
    error_log("Realtime callback: SID=$callSid, status=$openStatus, summary=" . ($result['summary'] ?? ''));
    
    json_res(['ok' => true, 'saved' => $callSid]);
}

/**
 * フォールバック用（API失敗時）
 */
function fallback_open_status($text) {
    $t = mb_convert_kana(mb_strtolower($text, 'UTF-8'), 'rkas', 'UTF-8');

    foreach (['うーん','いや','いいえ','いえ','無理','できません','休業','お休み','定休','店休','営業時間外','閉店','準備中','貸切'] as $w) {
        if (mb_strpos($t, $w) !== false) {
            return ['label' => 'closed', 'confidence' => 0.6, 'reasons' => ['kw:' . $w], 'hours' => []];
        }
    }
    foreach (['はい','ええ','うん','営業中','やってます','やっています','営業しており','開いてます','開いております','オープン','open','大丈夫です','大丈夫'] as $w) {
        if (mb_strpos($t, $w) !== false) {
            return ['label' => 'open', 'confidence' => 0.5, 'reasons' => ['kw:' . $w], 'hours' => []];
        }
    }
    return ['label' => 'unknown', 'confidence' => 0.3, 'reasons' => ['fallback'], 'hours' => []];
}

/**
 * 先読みYES/NOヒューリスティック（日本語）
 */
function smart_yesno_label($text) {
    $t = trim(mb_convert_kana(mb_strtolower($text, 'UTF-8'), 'rkas', 'UTF-8'));

    if (preg_match('/^(もしもし|もしもーし|はいもしもし|お電話ありがとう|お電話あり|はいお電話|あいお待たせ|はいお待たせ)/u', $t)) {
        return 'open';
    }

    if (preg_match('/^(はい|ええ|うん)/u', $t)) {
        return 'open';
    }

    if (preg_match('/^(いいえ|いえ|うーん|いや|無理)/u', $t)) {
        return 'closed';
    }

    $pos = ['営業中', 'やってます', 'やっています', '営業しており', '開いてます', '開いております', 'オープン', 'open', '大丈夫です', '大丈夫'];
    foreach ($pos as $p) {
        if (mb_strpos($t, $p) !== false) return 'open';
    }

    $neg = ['やってません', 'やっていません', '営業していません', '営業しておりません', '本日休業', '休業', '定休', '店休', '営業時間外', '閉店', '準備中', '貸切', '本日は無理', '対応できません'];
    foreach ($neg as $n) {
        if (mb_strpos($t, $n) !== false) return 'closed';
    }

    return null;
}

/**
 * ChatGPT(API) 判定
 */
function gpt_open_status($text) {
    $apiKey = env_first(['OPENAI_API_KEY', 'CHATGPT_API_KEY', 'OPENAI_KEY', 'OPENAI_TOKEN']);
    if (!$apiKey) {
        return ['error' => 'no_api_key', 'result' => fallback_open_status($text)];
    }
    
    $model = envv('OPENAI_MODEL', 'gpt-5-mini');
    $now = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
    $now_s = $now->format('Y-m-d H:i');
    
    $sys = "You are a bilingual (JA/EN) assistant that decides whether a Japanese shop is OPEN NOW from a clerk's reply (transcribed text). Return ONLY JSON: {\"label\":\"open|closed|unknown\",\"confidence\":number,\"reasons\":string[],\"hours\":[{\"start\":\"HH:MM\",\"end\":\"HH:MM\",\"note\":string}]}. Use Asia/Tokyo={$now_s}. If ambiguous -> unknown.";
    $usr = "Clerk's reply (Japanese): <<<{$text}>>>\nCurrent local time: {$now_s} (Asia/Tokyo)\nTask: Decide if the shop is open now. Extract hours if present.";
    $payload = json_encode(['model' => $model, 'temperature' => 0.2, 'response_format' => ['type' => 'json_object'], 'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $usr]]], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return ['error' => 'curl', 'detail' => $err, 'http' => $code, 'result' => fallback_open_status($text)];
    }
    
    $j = json_decode($resp, true);
    if (!$j || $code >= 400) {
        return ['error' => 'api', 'detail' => $j, 'http' => $code, 'result' => fallback_open_status($text)];
    }
    
    $content = $j['choices'][0]['message']['content'] ?? '';
    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        return ['error' => 'parse', 'raw' => $content, 'result' => fallback_open_status($text)];
    }
    
    $label = in_array($parsed['label'] ?? '', ['open', 'closed', 'unknown'], true) ? $parsed['label'] : 'unknown';
    $conf = isset($parsed['confidence']) ? max(0, min(1, (float)$parsed['confidence'])) : 0.5;
    $reasons = (isset($parsed['reasons']) && is_array($parsed['reasons'])) ? $parsed['reasons'] : [];
    $hours = [];
    if (!empty($parsed['hours']) && is_array($parsed['hours'])) {
        foreach ($parsed['hours'] as $h) {
            $hours[] = ['start' => $h['start'] ?? null, 'end' => $h['end'] ?? null, 'note' => $h['note'] ?? null];
        }
    }
    
    return ['error' => null, 'model' => $model, 'raw' => $parsed, 'result' => ['label' => $label, 'confidence' => $conf, 'reasons' => $reasons, 'hours' => $hours]];
}

/**
 * JSONポーリング
 */
if (isset($_GET['json'])) {
    $sid = $_GET['sid'] ?? '';
    if (!$sid) {
        http_response_code(400);
        json_res(['ok' => false, 'error' => 'sid required']);
    }
    
    $data = store_get($sid);
    if (!$data) {
        http_response_code(204);
        exit;
    }

    // uid照合: 自分の通話データのみアクセス可能（uid無しもブロック）
    $pollUid = preg_replace('/[^a-zA-Z0-9\-]/', '', $_COOKIE['uid'] ?? '');
    $dataUid = $data['uid'] ?? '';
    if ($dataUid !== '' && $pollUid !== $dataUid) {
        http_response_code(403);
        json_res(['ok' => false, 'error' => 'access denied']);
    }

    $nlp = $data['open_nlp'] ?? null;
    $label = $nlp['result']['label'] ?? null;
    $callMode = $data['call_mode'] ?? 'check';
    $rsvResult = $data['reservation_result'] ?? null;

    // 予約モード: rt_cb到着(=realtime_result存在)まではcompletedにしない
    // Twilioステータスcb(status=completed)だけではポーリングを止めない
    if ($callMode === 'reservation') {
        $hasResult = !empty($data['reservation_result']) || !empty($data['realtime_result']);
        $twilioDone = in_array($data['status'] ?? '', ['completed', 'failed', 'busy', 'no-answer'], true);
        // 予約モード: 結果があっても通話が実際に終了するまで完了にしない
        $completed = ($hasResult && $twilioDone)
                    || (($data['status'] ?? '') === 'failed')
                    || (($data['status'] ?? '') === 'busy')
                    || (($data['status'] ?? '') === 'no-answer')
                    || !empty($data['no_response'])
                    || !empty($data['no_result']);
    } else {
        $completed = !empty($data['reservation_result'])
                    || !empty($data['realtime_result'])
                    || !empty($data['hours_answer'])
                    || !empty($data['hours_no_response'])
                    || (in_array($label, ['closed', 'unknown'], true))
                    || !empty($data['no_response'])
                    || !empty($data['no_result'])
                    || (($data['status'] ?? '') === 'completed')
                    || (($data['status'] ?? '') === 'failed')
                    || (($data['status'] ?? '') === 'busy')
                    || (($data['status'] ?? '') === 'no-answer');
    }

    // 統計記録（初回のみ）
    if ($completed && !isset($data['stats_recorded'])) {
        // 通話失敗系
        if (($data['status'] ?? '') === 'failed' || ($data['status'] ?? '') === 'busy' || ($data['status'] ?? '') === 'no-answer') {
            record_stats('failed');
        // 旧Gather: 無応答・結果なし
        } elseif (!empty($data['no_response']) || !empty($data['no_result'])) {
            record_stats('failed');
        // Realtime API: no_response / unknown
        } elseif (!empty($data['realtime_result'])) {
            $rt_status = $data['realtime_result']['open_status'] ?? 'unknown';
            if ($rt_status === 'no_response' || $rt_status === 'unknown') {
                record_stats('failed');
            } else {
                record_stats('success');
            }
        } else {
            record_stats('success');
        }
        store_put($sid, 'stats_recorded', true);
    }

    // 予約モードの結果判定
    $resultHandled = false;
    if ($callMode === 'reservation' && $rsvResult) {
        $resultHandled = true;
        $rsvStatus = $rsvResult['reservation_status'] ?? 'unknown';
        if ($rsvStatus === 'confirmed') {
            $result_state = 'reservation_confirmed';
            $message = '予約が確定しました！';
            if (!empty($rsvResult['confirmation'])) $message .= ' ' . $rsvResult['confirmation'];
        } elseif ($rsvStatus === 'rejected') {
            $result_state = 'reservation_rejected';
            $message = '予約できませんでした。';
            if (!empty($rsvResult['rejection_reason'])) $message .= ' 理由: ' . $rsvResult['rejection_reason'];
            if (!empty($rsvResult['alternative_suggestion'])) $message .= ' 代替案: ' . $rsvResult['alternative_suggestion'];
        } elseif ($rsvStatus === 'no_response') {
            $result_state = 'no_response';
            $message = '無応答のため予約確認できませんでした。';
        } else {
            $result_state = 'reservation_unknown';
            $message = '予約の可否を確認できませんでした。';
        }
        if (!empty($rsvResult['summary'])) $message .= ' / ' . $rsvResult['summary'];
    }

    // 結果状態の判定（営業確認モード）
    if (!$resultHandled && !empty($data['no_response'])) {
        $result_state = 'no_response';
        $message = '🎤🚫 無言で電話を切られました';
    } elseif (!$resultHandled && !empty($data['no_result'])) {
        $result_state = 'no_result';
        $message = '通話は終了しましたが回答を取得できませんでした。';
    } elseif (!$resultHandled && (($data['status'] ?? '') === 'failed' || ($data['status'] ?? '') === 'busy' || ($data['status'] ?? '') === 'no-answer')) {
        $result_state = 'call_failed';
        $message = '通話が確立できませんでした。時間をおいて再度お試しください。';
    } elseif (!$resultHandled && !empty($data['realtime_result'])) {
        $rt = $data['realtime_result'];
        $rt_status = $rt['open_status'] ?? 'unknown';
        if ($rt_status === 'open') {
            $result_state = !empty($data['hours_answer']) ? 'hours' : 'open';
            $message = !empty($data['hours_answer'])
                ? '営業中です。営業時間: ' . ($data['hours_answer'] ?? '')
                : '営業中です。';
        } elseif ($rt_status === 'closed') {
            $result_state = 'closed';
            $message = '休業/営業時間外（Realtime API判定）';
        } elseif ($rt_status === 'no_response') {
            $result_state = 'no_response';
            $message = '🎤🚫 無言で電話を切られました';
        } else {
            $result_state = 'unknown';
            $message = '回答は取得しましたが、営業中か不明です。';
        }
        if (!empty($rt['summary'])) {
            $message .= ' / ' . $rt['summary'];
        }
    } elseif (!$resultHandled && !empty($data['hours_answer'])) {
        $result_state = 'hours';
        $message = '営業時間の返答を保存しました。';
    } elseif (!$resultHandled && $nlp) {
        if ($label === 'open') {
            $result_state = 'open';
            $message = '営業中です。営業時間を確認中…';
        } elseif ($label === 'closed') {
            $result_state = 'closed';
            $message = '休業/営業時間外（ChatGPT+ヒューリスティック）';
        } else {
            $result_state = 'unknown';
            $message = '回答は取得しましたが、営業中か不明です。';
        }
    } elseif (!$resultHandled) {
        $result_state = 'pending';
        $message = '通話中または待機中です。';
    }

    $open_recording_url = $data['open_recording'] ?? null;
    $hours_recording_url = $data['hours_recording'] ?? null;
    $recording_url = $data['recording_url'] ?? null;
    $recording_duration = $data['recording_duration'] ?? null;

    // 録音再生用のプロキシURLを生成
    $recording_play_url = null;
    if ($recording_url) {
        $recording_play_url = base_url() . '/call?play_recording=1&sid=' . urlencode($sid);
    }

    json_res([
        'ok' => true,
        'open_answer' => $data['open_answer'] ?? null,
        'open_nlp' => $nlp,
        'hours_answer' => $data['hours_answer'] ?? null,
        'hours_parsed' => $data['hours_parsed'] ?? null,
        'raw' => $data['raw'] ?? [],
        'status' => $data['status'] ?? null,
        'result_state' => $result_state,
        'awaiting_hours' => ($label === 'open' && empty($data['hours_answer'])),
        'message' => $message,
        'completed' => $completed,
        'updated_at' => $data['updated_at'] ?? null,
        'open_recording_url' => $open_recording_url,
        'hours_recording_url' => $hours_recording_url,
        'recording_url' => $recording_play_url,
        'recording_duration' => $recording_duration,
        'realtime_mode' => !empty($data['realtime_mode']),
        'realtime_result' => $data['realtime_result'] ?? null,
        'call_mode' => $callMode,
        'reservation_result' => $rsvResult,
        'summary' => $data['summary'] ?? null,
        'conversation_log' => $data['conversation_log'] ?? null,
        'view_url' => 'https://console.twilio.com/us1/monitor/logs/call-logs/' . $sid,
        'twiml_url' => base_url() . '/call?start=1'
    ]);
}

/**
 * TwiML: Realtime API版 - Media Streamで接続
 */
if (isset($_GET['start'])) {
    header('Content-Type: text/xml; charset=utf-8');
    
    $ws_url = envv('WS_PUBLIC_URL', '');
    if (!$ws_url) {
        $base = base_url();
        $ws_url = str_replace(['https://', 'http://'], ['wss://', 'ws://'], $base);
        $ws_port = envv('WS_PORT', '8080');
        $parsed = parse_url($ws_url);
        $ws_url = ($parsed['scheme'] ?? 'wss') . '://' . ($parsed['host'] ?? 'localhost') . ':' . $ws_port . '/media-stream';
    }
    
    $callSid = $_POST['CallSid'] ?? ($_GET['CallSid'] ?? 'unknown');
    $mode = $_GET['mode'] ?? 'check';

    echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<Response>
  <Connect>
    <Stream url="<?php echo htmlspecialchars($ws_url, ENT_QUOTES, 'UTF-8'); ?>">
      <Parameter name="CallSid" value="<?php echo htmlspecialchars($callSid, ENT_QUOTES, 'UTF-8'); ?>"/>
      <Parameter name="mode" value="<?php echo htmlspecialchars($mode, ENT_QUOTES, 'UTF-8'); ?>"/>
      <Parameter name="lang" value="<?php echo htmlspecialchars($_GET['lang'] ?? 'ja', ENT_QUOTES, 'UTF-8'); ?>"/>
      <Parameter name="callback_url" value="<?php echo htmlspecialchars(base_url() . '/call?rt_cb=1', ENT_QUOTES, 'UTF-8'); ?>"/>
<?php
    if ($mode === 'reservation') {
        foreach (['rsv_date', 'rsv_time', 'rsv_party_size', 'rsv_name', 'rsv_last_name', 'rsv_first_name', 'rsv_phone', 'rsv_flexible', 'rsv_flex_before', 'rsv_flex_after'] as $key) {
            $val = $_GET[$key] ?? '';
            if ($val !== '') {
                echo '      <Parameter name="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"/>' . "\n";
            }
        }
    }
?>
    </Stream>
  </Connect>
</Response>
<?php exit; }

/**
 * 【レガシー】TwiML: Step1 Gather版（フォールバック用）
 */
if (isset($_GET['start_gather'])) {
    header('Content-Type: text/xml; charset=utf-8');
    $action = base_url() . '/call?g=1';
    echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<Response>
  <Gather input="speech" language="ja-JP" method="POST" speechTimeout="1" timeout="5" actionOnEmptyResult="true" action="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>">
    <Say language="ja-JP" voice="Google.ja-JP-Neural2-B"><prosody rate="150%">えーっと、あの、今からお伺いしたいんですけど、営業されていますか？</prosody></Say>
  </Gather>
</Response>
<?php exit; }

/**
 * Gather: 返答 → ChatGPT解析（YES/NO補強）
 */
if (isset($_GET['g'])) {
    header('Content-Type: text/xml; charset=utf-8');
    $sid = $_POST['CallSid'] ?? '';
    $text = trim($_POST['SpeechResult'] ?? '');
    $conf = $_POST['Confidence'] ?? '';
    
    if ($sid) {
        store_append($sid, 'raw', "[open]($conf) " . $text);
        store_put($sid, 'open_answer', $text);
    }

    $label = smart_yesno_label($text);

    $gpt = null;
    if ($label === null) {
        $gpt = gpt_open_status($text);
        $label = $gpt['result']['label'] ?? 'unknown';
        
        if ($label === 'unknown') {
            $fb = fallback_open_status($text);
            if ($fb['label'] !== 'unknown') {
                $label = $fb['label'];
            }
        }
    }
    
    if ($sid) {
        if ($label !== null) {
            $nlp_data = [
                'error'  => null,
                'model'  => 'heuristic_or_gpt',
                'raw'    => ['label' => $label, 'confidence' => 0.8, 'reasons' => ['final'], 'hours' => []],
                'result' => ['label' => $label, 'confidence' => 0.8, 'reasons' => ['final'], 'hours' => []],
            ];
            if (!empty($gpt) && isset($gpt['result'])) {
                $nlp_data['gpt_shadow'] = $gpt;
            }
        } else {
            $nlp_data = $gpt ?: [
                'error'  => 'gpt_failed',
                'result' => ['label' => 'unknown', 'confidence' => 0.0, 'reasons' => ['gpt_failed'], 'hours' => []],
            ];
        }
        store_put($sid, 'open_nlp', $nlp_data);
    }

    if ($text === '' || $label === 'closed' || $label === 'unknown') {
        if ($text === '') {
            store_put($sid, 'no_response', true);
        }
        echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<Response>
  <Say language="ja-JP" voice="Google.ja-JP-Neural2-B"><prosody rate="150%">わかりました、ありがとうございました、またの機会にお伺い致します。</prosody></Say>
  <Hangup/>
</Response>
<?php exit; }

    $action = base_url() . '/call?h=1';
    echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<Response>
  <Gather input="speech" language="ja-JP" method="POST" speechTimeout="1" timeout="5" actionOnEmptyResult="true" action="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>">
    <Say language="ja-JP" voice="Google.ja-JP-Neural2-B"><prosody rate="150%">なんじまで営業されてますか？</prosody></Say>
  </Gather>
</Response>
<?php exit; }

/**
 * Gather: 営業時間の返答（記録して終了）
 */
if (isset($_GET['h'])) {
    header('Content-Type: text/xml; charset=utf-8');
    $sid = $_POST['CallSid'] ?? '';
    $text = trim($_POST['SpeechResult'] ?? '');
    $conf = $_POST['Confidence'] ?? '';
    
    if ($sid) {
        store_append($sid, 'raw', "[hours]($conf) " . $text);
        
        if ($text === '') {
            store_put($sid, 'hours_no_response', true);
        } else {
            store_put($sid, 'hours_answer', $text);
            $parsed = parse_hours_text($text);
            store_put($sid, 'hours_parsed', $parsed);
        }
    }
    
    echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<Response>
  <Say language="ja-JP" voice="Google.ja-JP-Neural2-B"><prosody rate="150%">ありがとうございます、回答を記録しました、失礼いたします。</prosody></Say>
  <Hangup/>
</Response>
<?php exit; }

/**
 * ステータスコールバック
 */
if (isset($_GET['status'])) {
    $sid = $_POST['CallSid'] ?? '';
    $stat = $_POST['CallStatus'] ?? '';
    $dur = $_POST['CallDuration'] ?? null;
    
    if ($sid) {
        store_merge($sid, ['status' => $stat, 'duration' => $dur]);
        store_append($sid, 'raw', '[status] ' . $stat . ' dur=' . ($dur ?? ''));
        
        if ($stat === 'failed' || $stat === 'busy' || $stat === 'no-answer') {
            $data = store_get($sid);
            if ($data && !isset($data['stats_recorded'])) {
                record_stats('failed');
                store_put($sid, 'stats_recorded', true);
            }
        }
    }
    
    http_response_code(204);
    exit;
}

/**
 * 通話履歴API
 */
if (isset($_GET['history'])) {
    $limit = min(100, max(1, intval($_GET['limit'] ?? 50)));
    $search = trim($_GET['search'] ?? '');
    $uid = preg_replace('/[^a-zA-Z0-9\-]/', '', $_COOKIE['uid'] ?? '');

    // uid未設定なら空配列を返す（他ユーザーの履歴混入防止）
    if ($uid === '') {
        json_res(['ok' => true, 'calls' => [], 'total' => 0]);
        exit;
    }

    $dir = logs_dir();
    $files = glob($dir . '/*.json');
    if (!$files) {
        json_res(['ok' => true, 'calls' => [], 'total' => 0]);
        exit;
    }
    
    // ファイルを更新日時の新しい順にソート
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    $calls = [];
    foreach ($files as $f) {
        $j = @file_get_contents($f);
        if (!$j) continue;
        $d = json_decode($j, true);
        if (!$d) continue;

        // uidフィルタ: 自分の通話のみ表示（常に適用）
        if (($d['uid'] ?? '') !== $uid) continue;

        // 検索フィルタ
        if ($search !== '') {
            // 電話番号の正規化（検索文字列を+81形式にも変換してマッチ）
            $searchNormalized = preg_replace('/[\s\-\(\)]/', '', $search);
            $searchPlus81 = '';
            if (preg_match('/^0/', $searchNormalized)) {
                $searchPlus81 = '+81' . substr($searchNormalized, 1);
            }
            // +81で始まる検索の場合、0始まりにも変換
            $search0 = '';
            if (preg_match('/^\+81/', $searchNormalized)) {
                $search0 = '0' . substr($searchNormalized, 3);
            }
            
            $to = $d['to'] ?? '';
            $rt = $d['realtime_result'] ?? [];
            $haystack = $to . ' ' 
                . ($d['name'] ?? '') . ' ' 
                . ($d['open_answer'] ?? '') . ' ' 
                . ($d['hours_answer'] ?? '') . ' ' 
                . ($d['summary'] ?? '') . ' '
                . ($rt['open_answer'] ?? '') . ' '
                . ($rt['hours_answer'] ?? '') . ' '
                . ($rt['summary'] ?? '');
            
            $found = mb_stripos($haystack, $search) !== false;
            if (!$found && $searchPlus81) $found = mb_stripos($haystack, $searchPlus81) !== false;
            if (!$found && $search0) $found = mb_stripos($haystack, $search0) !== false;
            if (!$found && $searchNormalized !== $search) $found = mb_stripos($haystack, $searchNormalized) !== false;
            if (!$found) continue;
        }
        
        $sid = basename($f, '.json');
        $rt = $d['realtime_result'] ?? null;
        $openStatus = $rt['open_status'] ?? ($d['open_nlp']['result']['label'] ?? null);
        
        $calls[] = [
            'sid' => $sid,
            'to' => $d['to'] ?? '',
            'name' => $d['name'] ?? '',
            'status' => $d['status'] ?? '',
            'open_status' => $openStatus,
            'open_answer' => $d['open_answer'] ?? null,
            'hours_answer' => $d['hours_answer'] ?? null,
            'hours_end' => $rt['hours_end'] ?? ($d['hours_end'] ?? null),
            'summary' => $d['summary'] ?? ($rt['summary'] ?? null),
            'duration' => $d['duration'] ?? null,
            'created_at' => $d['created_at'] ?? null,
            'updated_at' => $d['updated_at'] ?? null,
            'has_recording' => !empty($d['recording_url']),
        ];
        
        if (count($calls) >= $limit) break;
    }
    
    json_res(['ok' => true, 'calls' => $calls, 'total' => count($calls)]);
    exit;
}

/**
 * 電話番号の国番号から言語コードを判定
 */
function detect_lang_from_phone($phone) {
    // 3桁 → 2桁 → 1桁の順でマッチ（長い方が優先）
    static $map3 = [
        // 東アジア・東南アジア
        '852'=>'yue','853'=>'yue','855'=>'km','856'=>'lo',
        '880'=>'bn','886'=>'zh',
        // 中東
        '961'=>'ar','962'=>'ar','963'=>'ar','964'=>'ar','965'=>'ar',
        '966'=>'ar','968'=>'ar','970'=>'ar','971'=>'ar','972'=>'he',
        '973'=>'ar','974'=>'ar',
        // 中央・南アジア
        '976'=>'mn','977'=>'ne','992'=>'ru','993'=>'ru','994'=>'az',
        '995'=>'ka','996'=>'ru','998'=>'uz',
        // 北アフリカ
        '212'=>'ar','213'=>'ar','216'=>'ar','218'=>'ar',
        // サブサハラアフリカ
        '234'=>'en','254'=>'sw','255'=>'sw',
        // ヨーロッパ
        '351'=>'pt','352'=>'fr','353'=>'en','354'=>'is','355'=>'sq',
        '356'=>'en','358'=>'fi','359'=>'bg',
        '370'=>'lt','371'=>'lv','372'=>'et','373'=>'ro','374'=>'hy',
        '375'=>'ru','380'=>'uk','381'=>'sr','385'=>'hr','386'=>'sl',
        '420'=>'cs','421'=>'sk',
    ];
    static $map2 = [
        '20'=>'ar','27'=>'en','30'=>'el','31'=>'nl','32'=>'nl','33'=>'fr',
        '34'=>'es','36'=>'hu','39'=>'it','40'=>'ro','41'=>'de','43'=>'de',
        '44'=>'en','45'=>'da','46'=>'sv','47'=>'no','48'=>'pl','49'=>'de',
        '51'=>'es','52'=>'es','53'=>'es','54'=>'es','55'=>'pt','56'=>'es',
        '57'=>'es','58'=>'es','60'=>'ms','61'=>'en','62'=>'id','63'=>'tl',
        '64'=>'en','65'=>'en','66'=>'th','81'=>'ja','82'=>'ko','84'=>'vi',
        '86'=>'zh','90'=>'tr','91'=>'hi','92'=>'ur','93'=>'fa','94'=>'si',
        '95'=>'my','98'=>'fa',
    ];
    static $map1 = [
        '1'=>'en','7'=>'ru',
    ];
    $digits = ltrim($phone, '+');
    $c3 = substr($digits, 0, 3);
    if (isset($map3[$c3])) return $map3[$c3];
    $c2 = substr($digits, 0, 2);
    if (isset($map2[$c2])) return $map2[$c2];
    $c1 = substr($digits, 0, 1);
    if (isset($map1[$c1])) return $map1[$c1];
    return 'en'; // デフォルト英語
}

/**
 * 発信API
 */
function handle_dial() {
    $to = preg_replace('/\D+/', '', $_POST['to'] ?? '');
    if (!$to) {
        json_res(['ok' => false, 'error' => 'to required']);
    }
    
    if (strpos($to, '0') === 0) {
        $to = '+81' . substr($to, 1);
    }

    // 言語判定: rsv_lang指定があれば優先、なければ電話番号から自動判定
    $lang = (!empty($_POST['rsv_lang']) && ($_POST['mode'] ?? '') === 'reservation')
        ? preg_replace('/[^a-z]/', '', $_POST['rsv_lang'])
        : detect_lang_from_phone($to);

    $sid = env_first(['TWILIO_ACCOUNT_SID', 'ACCOUNT_SID']);
    $token = env_first(['TWILIO_AUTH_TOKEN', 'AUTH_TOKEN']);
    $from = env_first(['TWILIO_FROM', 'TWILIO_FROM_NUMBER', 'TWILIO_NUMBER', 'TWILIO_CALL_FROM', 'TWILIO_CALLER', 'CALL_FROM']);
    
    if (!$sid || !$token || !$from) {
        json_res(['ok' => false, 'error' => 'env_missing', 'detail' => [
            'TWILIO_ACCOUNT_SID' => (bool)$sid,
            'TWILIO_AUTH_TOKEN' => (bool)$token,
            'TWILIO_FROM_any' => (bool)$from,
        ]]);
    }

    $mode = $_POST['mode'] ?? 'check';

    // 予約テストモード
    $testmode = $_POST['rsv_testmode'] ?? '';
    if ($mode === 'reservation' && $testmode === '1') {
        $to = '+819075368115';
    } elseif ($mode === 'reservation' && $testmode === '2') {
        $to = '+818091663233';
    }

    $urlStart = base_url() . '/call?start=1&mode=' . urlencode($mode) . '&lang=' . urlencode($lang);

    // 予約パラメータをTwiML URLに追加
    $rsvParams = [];
    if ($mode === 'reservation') {
        foreach (['rsv_date', 'rsv_time', 'rsv_party_size', 'rsv_name', 'rsv_last_name', 'rsv_first_name', 'rsv_phone', 'rsv_flexible', 'rsv_flex_before', 'rsv_flex_after'] as $key) {
            $val = $_POST[$key] ?? '';
            if ($val !== '') {
                $rsvParams[$key] = $val;
                $urlStart .= '&' . $key . '=' . urlencode($val);
            }
        }
    }

    $statusCb = base_url() . '/call?status=1';

    $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . $sid . '/Calls.json');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $sid . ':' . $token);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $post = http_build_query([
        'To' => $to,
        'From' => $from,
        'Url' => $urlStart,
        'Method' => 'POST',
        'Record' => 'true',
        'RecordingStatusCallback' => base_url() . '/call?recording=1',
        'RecordingStatusCallbackMethod' => 'POST',
        'StatusCallback' => $statusCb,
        'StatusCallbackMethod' => 'POST',
        'StatusCallbackEvent' => ['initiated', 'ringing', 'answered', 'completed'],
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        error_log("Twilio curl error: $err");
        json_res(['ok' => false, 'error' => 'call_failed']);
    }

    $j = json_decode($resp, true);
    if ($code >= 400 || !$j || empty($j['sid'])) {
        error_log("Twilio API error: HTTP $code, response: $resp");
        json_res(['ok' => false, 'error' => 'call_failed']);
    }

    $callSid = $j['sid'];
    $name = $_POST['name'] ?? '';
    $callUid = preg_replace('/[^a-zA-Z0-9\-]/', '', $_COOKIE['uid'] ?? '');
    if ($callUid === '') {
        json_res(['ok' => false, 'error' => 'uid required']);
    }
    store_merge($callSid, ['created_at' => date('c'), 'status' => 'initiated', 'to' => $to, 'name' => $name, 'realtime_mode' => true, 'call_mode' => $mode, 'reservation_params' => $rsvParams, 'uid' => $callUid]);
    store_append($callSid, 'raw', '[dial] to=' . $to . ' name=' . $name);
    
    json_res([
        'ok' => true, 
        'sid' => $callSid,
        'view_url' => 'https://console.twilio.com/us1/monitor/logs/call-logs/' . $callSid,
        'twiml_url' => $urlStart
    ]);
}

/**
 * 録音再生プロキシ
 * Twilio録音はBasic認証が必要なため、サーバー側でプロキシする
 */
if (isset($_GET['play_recording'])) {
    $sid = $_GET['sid'] ?? '';
    if (!$sid) {
        http_response_code(400);
        echo 'sid required';
        exit;
    }
    
    $data = store_get($sid);

    // uid照合: 自分の録音のみアクセス可能（uid無しもブロック）
    $recUid = preg_replace('/[^a-zA-Z0-9\-]/', '', $_COOKIE['uid'] ?? '');
    $recDataUid = $data['uid'] ?? '';
    if ($recDataUid !== '' && $recUid !== $recDataUid) {
        http_response_code(403);
        echo 'access denied';
        exit;
    }

    $recordingUrl = $data['recording_url'] ?? '';

    if (!$recordingUrl) {
        http_response_code(404);
        echo 'recording not found';
        exit;
    }
    
    // Twilio認証情報
    $twilioSid = env_first(['TWILIO_ACCOUNT_SID', 'ACCOUNT_SID']);
    $twilioToken = env_first(['TWILIO_AUTH_TOKEN', 'AUTH_TOKEN']);
    
    if (!$twilioSid || !$twilioToken) {
        http_response_code(500);
        echo 'twilio credentials missing';
        exit;
    }
    
    // .mp3形式で取得
    $mp3Url = rtrim($recordingUrl, '/') . '.mp3';
    
    $ch = curl_init($mp3Url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $twilioSid . ':' . $twilioToken);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    $audioData = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    
    if ($err || $code >= 400 || !$audioData) {
        http_response_code(502);
        echo 'failed to fetch recording: ' . ($err ?: "HTTP $code");
        exit;
    }
    
    // 音声データをそのまま返す
    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . strlen($audioData));
    header('Content-Disposition: inline; filename="recording_' . preg_replace('/[^a-zA-Z0-9]/', '_', $sid) . '.mp3"');
    header('Cache-Control: private, max-age=3600');
    echo $audioData;
    exit;
}

/**
 * 録音コールバック
 */
if (isset($_GET['recording'])) {
    $sid = $_POST['CallSid'] ?? '';
    $recordingSid = $_POST['RecordingSid'] ?? '';
    $recordingUrl = $_POST['RecordingUrl'] ?? '';
    $recordingDuration = $_POST['RecordingDuration'] ?? '';
    
    if ($sid && $recordingUrl) {
        store_put($sid, 'recording_url', $recordingUrl);
        store_put($sid, 'recording_duration', $recordingDuration);
        store_append($sid, 'raw', "[recording] sid=$recordingSid duration=$recordingDuration url=$recordingUrl");
    }
    
    http_response_code(204);
    exit;
}

if (isset($_GET['dial'])) {
    handle_dial();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !isset($_GET['json']) && !isset($_GET['start']) && !isset($_GET['g'])
    && !isset($_GET['h']) && !isset($_GET['status']) && !isset($_GET['diag'])
    && !isset($_GET['recording']) && !isset($_GET['rt_cb']) && !isset($_GET['play_recording'])
    && !isset($_GET['start'])) {
    handle_dial();
    exit;
}

http_response_code(404);
echo "call.php ready";
?>
