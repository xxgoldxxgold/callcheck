<?php
/**
 * support.php - カスタマーサポートAPI
 * POST: メッセージ送信 → AI応答
 * GET ?history&uid=: ユーザーの会話一覧
 * GET ?admin&secret=: 管理者用全会話一覧
 * GET ?detail=convId&secret=: 会話詳細
 * POST ?resolve&secret=: 会話を解決済みにする
 */

function envv($k, $d = '') {
    return $_ENV[$k] ?? $_SERVER[$k] ?? getenv($k) ?: $d;
}

function support_dir() {
    $d = __DIR__ . '/logs/support';
    if (!is_dir($d)) @mkdir($d, 0777, true);
    return $d;
}

function json_out($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitize_id($id) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
}

function load_conv($convId) {
    $file = support_dir() . '/' . sanitize_id($convId) . '.json';
    if (!file_exists($file)) return null;
    return json_decode(file_get_contents($file), true);
}

function save_conv($conv) {
    $file = support_dir() . '/' . sanitize_id($conv['convId']) . '.json';
    file_put_contents($file, json_encode($conv, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

$ADMIN_SECRET = envv('SUPPORT_ADMIN_SECRET', 'callcheck_admin_2026');

// ────────────────────────────────────────
// POST: メッセージ送信
// ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['resolve'])) {
    $input = json_decode(file_get_contents('php://input'), true);
    $uid      = trim($input['uid'] ?? '');
    $userName = trim($input['userName'] ?? '');
    $message  = trim($input['message'] ?? '');
    $convId   = trim($input['convId'] ?? '');
    $type     = $input['type'] ?? 'question';

    if (!$uid || !$message) {
        json_out(['ok' => false, 'error' => 'uid and message required']);
    }
    if (!in_array($type, ['question', 'bug', 'improvement'])) $type = 'question';

    // 会話の読み込み or 新規作成
    $conv = null;
    if ($convId) $conv = load_conv($convId);
    if (!$conv) {
        $convId = $uid . '_' . time() . '_' . bin2hex(random_bytes(4));
        $conv = [
            'convId'    => $convId,
            'uid'       => $uid,
            'userName'  => $userName,
            'type'      => $type,
            'messages'  => [],
            'summary'   => '',
            'createdAt' => date('c'),
            'updatedAt' => date('c'),
            'resolved'  => false,
        ];
    }

    $conv['messages'][] = ['role' => 'user', 'text' => $message, 'time' => date('c')];
    $conv['type'] = $type;
    $conv['updatedAt'] = date('c');
    if ($userName) $conv['userName'] = $userName;

    // OpenAI Chat Completion
    $apiKey = envv('OPENAI_API_KEY');
    $aiResponse = 'ご連絡ありがとうございます。担当者が確認いたします。';

    if ($apiKey) {
        $systemPrompt = <<<'PROMPT'
あなたは「電話確認くん」のカスタマーサポートAIです。

サービス概要:
- AIが店舗に自動電話をかけて営業中かどうか・営業時間を確認するWebアプリ
- 電話予約機能もあり（日時・人数・名前・電話番号を指定してAIが電話予約）
- Google Maps風の地図UIで店舗を検索して電話確認・予約ができる

主な機能:
1. 店名・料理名で店舗検索（現在地から距離順）
2. ワンタップで自動電話→AIが店舗と会話して営業状況を確認
3. 電話予約機能
4. 通話録音の再生
5. 通話履歴・予約履歴
6. Google/Apple/メールログイン対応

よくある質問:
- 料金: 現在無料
- 対応エリア: 日本国内の店舗
- 精度: AIによる音声会話のため100%ではないが、録音で確認可能
- 対応時間: 24時間（ただし店舗の営業時間外は繋がらない）

対応ルール:
- 質問には簡潔かつ丁寧に日本語で回答（2-3文程度）
- バグ報告には感謝し、詳細を確認。改善に活かすと伝える
- 改善提案には感謝し、検討すると伝える
- 技術的な内部情報（API構成、サーバー情報等）は開示しない
- 分からないことは正直に「確認します」と伝える
PROMPT;

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        // 直近10メッセージのみ送信
        $recent = array_slice($conv['messages'], -10);
        foreach ($recent as $m) {
            $messages[] = [
                'role'    => $m['role'] === 'user' ? 'user' : 'assistant',
                'content' => $m['text'],
            ];
        }

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS     => json_encode([
                'model'       => 'gpt-5-mini',
                'messages'    => $messages,
                'max_tokens'  => 300,
                'temperature' => 0.7,
            ]),
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($res, true);
        if (!empty($data['choices'][0]['message']['content'])) {
            $aiResponse = $data['choices'][0]['message']['content'];
        }
    }

    $conv['messages'][] = ['role' => 'ai', 'text' => $aiResponse, 'time' => date('c')];

    // 要約生成（毎回更新）
    if ($apiKey) {
        $allText = implode("\n", array_map(
            fn($m) => ($m['role'] === 'user' ? 'ユーザー' : 'AI') . ': ' . $m['text'],
            $conv['messages']
        ));
        $typeLabel = ['question' => '質問', 'bug' => 'バグ報告', 'improvement' => '改善提案'][$type] ?? '質問';
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS     => json_encode([
                'model'       => 'gpt-5-mini',
                'messages'    => [
                    ['role' => 'system', 'content' => "以下のサポート会話（種別: {$typeLabel}）を20文字以内で要約してください。要点だけ。"],
                    ['role' => 'user', 'content' => $allText],
                ],
                'max_tokens'  => 40,
                'temperature' => 0.2,
            ]),
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        $sd = json_decode($res, true);
        if (!empty($sd['choices'][0]['message']['content'])) {
            $conv['summary'] = $sd['choices'][0]['message']['content'];
        }
    }

    save_conv($conv);
    json_out(['ok' => true, 'convId' => $convId, 'reply' => $aiResponse]);
}

// ────────────────────────────────────────
// POST ?resolve: 解決済みにする
// ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['resolve'])) {
    $input = json_decode(file_get_contents('php://input'), true);
    $secret = $input['secret'] ?? '';
    $convId = $input['convId'] ?? '';
    if ($secret !== $ADMIN_SECRET) json_out(['ok' => false, 'error' => 'unauthorized']);
    $conv = load_conv($convId);
    if (!$conv) json_out(['ok' => false, 'error' => 'not found']);
    $conv['resolved'] = true;
    $conv['updatedAt'] = date('c');
    save_conv($conv);
    json_out(['ok' => true]);
}

// ────────────────────────────────────────
// GET ?admin: 管理者用一覧
// ────────────────────────────────────────
if (isset($_GET['admin'])) {
    $secret = $_GET['secret'] ?? '';
    if ($secret !== $ADMIN_SECRET) json_out(['ok' => false, 'error' => 'unauthorized']);

    $dir = support_dir();
    $files = glob($dir . '/*.json');
    $convs = [];
    foreach ($files as $f) {
        $c = json_decode(file_get_contents($f), true);
        if (!$c) continue;
        $lastUserMsg = '';
        foreach (array_reverse($c['messages'] ?? []) as $m) {
            if ($m['role'] === 'user') { $lastUserMsg = $m['text']; break; }
        }
        $convs[] = [
            'convId'       => $c['convId'],
            'uid'          => $c['uid'],
            'userName'     => $c['userName'] ?? '',
            'type'         => $c['type'] ?? 'question',
            'summary'      => $c['summary'] ?? '',
            'lastMessage'  => mb_strimwidth($lastUserMsg, 0, 60, '…'),
            'messageCount' => count($c['messages'] ?? []),
            'createdAt'    => $c['createdAt'] ?? '',
            'updatedAt'    => $c['updatedAt'] ?? '',
            'resolved'     => $c['resolved'] ?? false,
        ];
    }
    usort($convs, fn($a, $b) => strcmp($b['updatedAt'], $a['updatedAt']));
    json_out(['ok' => true, 'conversations' => $convs]);
}

// ────────────────────────────────────────
// GET ?detail=convId: 会話詳細
// ────────────────────────────────────────
if (isset($_GET['detail'])) {
    $secret = $_GET['secret'] ?? '';
    if ($secret !== $ADMIN_SECRET) json_out(['ok' => false, 'error' => 'unauthorized']);
    $conv = load_conv($_GET['detail']);
    if (!$conv) json_out(['ok' => false, 'error' => 'not found']);
    json_out(['ok' => true, 'conversation' => $conv]);
}

// ────────────────────────────────────────
// GET ?history&uid=: ユーザーの会話一覧
// ────────────────────────────────────────
if (isset($_GET['history'])) {
    $uid = sanitize_id($_GET['uid'] ?? '');
    if (!$uid) json_out(['ok' => false, 'error' => 'uid required']);
    $files = glob(support_dir() . '/' . $uid . '_*.json');
    $convs = [];
    foreach ($files as $f) {
        $c = json_decode(file_get_contents($f), true);
        if ($c) $convs[] = [
            'convId'   => $c['convId'],
            'type'     => $c['type'],
            'summary'  => $c['summary'],
            'updatedAt'=> $c['updatedAt'],
        ];
    }
    usort($convs, fn($a, $b) => strcmp($b['updatedAt'], $a['updatedAt']));
    json_out(['ok' => true, 'conversations' => array_slice($convs, 0, 20)]);
}

http_response_code(400);
json_out(['ok' => false, 'error' => 'invalid request']);
