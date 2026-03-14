<?php
/**
 * photo_proxy.php - Google Place Photo プロキシ
 * APIキーをクライアントに露出させないため、サーバー側で写真を取得してリダイレクト
 */

// リファラーチェック（外部からの直接アクセスによるAPI濫用を防止）
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$refHost = parse_url($referer, PHP_URL_HOST) ?? '';
$allowedHosts = ['denwa2.com', 'www.denwa2.com'];
if ($referer !== '' && !in_array($refHost, $allowedHosts, true)) {
    http_response_code(403);
    exit;
}

$API_KEY = getenv('GOOGLE_API_KEY');
if (!$API_KEY) {
    http_response_code(500);
    exit;
}

$ref = $_GET['ref'] ?? '';
$maxwidth = intval($_GET['w'] ?? 800);
if ($maxwidth < 100) $maxwidth = 100;
if ($maxwidth > 1600) $maxwidth = 1600;

if (!$ref || !preg_match('/^[A-Za-z0-9_\-]+$/', $ref)) {
    http_response_code(400);
    exit;
}

// Google Place Photo APIにリクエスト（リダイレクト先URLを取得）
$url = 'https://maps.googleapis.com/maps/api/place/photo?' . http_build_query([
    'maxwidth' => $maxwidth,
    'photo_reference' => $ref,
    'key' => $API_KEY
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HEADER => true,
    CURLOPT_NOBODY => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_CONNECTTIMEOUT => 3,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
curl_close($ch);

if ($redirectUrl) {
    // リダイレクト先のドメインを検証
    $redirectHost = parse_url($redirectUrl, PHP_URL_HOST);
    $allowedDomains = ['google.com', 'googleapis.com', 'gstatic.com'];
    $domainValid = false;
    if ($redirectHost) {
        foreach ($allowedDomains as $domain) {
            if ($redirectHost === $domain || str_ends_with($redirectHost, '.' . $domain)) {
                $domainValid = true;
                break;
            }
        }
    }
    if (!$domainValid) {
        http_response_code(502);
        exit;
    }
    // キャッシュヘッダー（写真は変わらないので長めに）
    header('Cache-Control: public, max-age=86400');
    header('Location: ' . $redirectUrl);
    http_response_code(302);
    exit;
}

// リダイレクトが取得できない場合はプロキシとして画像を転送
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 3,
]);
$imageData = curl_exec($ch);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 && $imageData) {
    header('Content-Type: ' . ($contentType ?: 'image/jpeg'));
    header('Cache-Control: public, max-age=86400');
    echo $imageData;
} else {
    http_response_code(404);
}
