<?php
/**
 * place_detail.php - Google Place Details取得
 * ピンクリック時に店舗の詳細情報を返す
 */
header('Content-Type: application/json; charset=utf-8');

$API_KEY = getenv('GOOGLE_API_KEY');
if (!$API_KEY) {
    echo json_encode(['success' => false, 'error' => 'API key not configured']);
    exit;
}

$placeId = $_GET['place_id'] ?? $_POST['place_id'] ?? '';
if (!$placeId) {
    echo json_encode(['success' => false, 'error' => 'place_id required']);
    exit;
}

$fields = implode(',', [
    'name',
    'formatted_address',
    'formatted_phone_number',
    'international_phone_number',
    'geometry',
    'rating',
    'user_ratings_total',
    'opening_hours',
    'business_status',
    'types',
    'website',
    'url',
    'photos',
    'price_level',
    'reviews'
]);

$url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query([
    'place_id' => $placeId,
    'fields'   => $fields,
    'language'  => 'ja',
    'key'       => $API_KEY
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_CONNECTTIMEOUT => 3
]);
$resp = curl_exec($ch);
curl_close($ch);

$data = json_decode($resp, true);
if (($data['status'] ?? '') !== 'OK') {
    echo json_encode(['success' => false, 'error' => $data['status'] ?? 'unknown']);
    exit;
}

$r = $data['result'] ?? [];

// 写真URLを生成（最大3枚）
$photos = [];
if (!empty($r['photos'])) {
    $count = min(3, count($r['photos']));
    for ($i = 0; $i < $count; $i++) {
        $ref = $r['photos'][$i]['photo_reference'] ?? '';
        if ($ref) {
            $photos[] = [
                'url' => 'https://maps.googleapis.com/maps/api/place/photo?' . http_build_query([
                    'maxwidth' => 400,
                    'photo_reference' => $ref,
                    'key' => $API_KEY
                ]),
                'attributions' => $r['photos'][$i]['html_attributions'] ?? []
            ];
        }
    }
}

// 営業時間
$hours = [];
$openNow = null;
if (!empty($r['opening_hours'])) {
    $hours = $r['opening_hours']['weekday_text'] ?? [];
    $openNow = $r['opening_hours']['open_now'] ?? null;
}

// レビュー（最大3件）
$reviews = [];
if (!empty($r['reviews'])) {
    $count = min(3, count($r['reviews']));
    for ($i = 0; $i < $count; $i++) {
        $rev = $r['reviews'][$i];
        $reviews[] = [
            'author' => $rev['author_name'] ?? '',
            'rating' => $rev['rating'] ?? 0,
            'text' => $rev['text'] ?? '',
            'time' => $rev['relative_time_description'] ?? ''
        ];
    }
}

// ビジネスタイプ翻訳
$typeMap = [
    'restaurant' => 'レストラン', 'cafe' => 'カフェ', 'bar' => 'バー',
    'bakery' => 'ベーカリー', 'meal_takeaway' => 'テイクアウト',
    'meal_delivery' => 'デリバリー', 'store' => '店舗', 'food' => '飲食',
    'shopping_mall' => 'ショッピングモール', 'supermarket' => 'スーパー',
    'convenience_store' => 'コンビニ', 'drugstore' => 'ドラッグストア',
    'hair_care' => '美容院', 'beauty_salon' => '美容サロン',
    'spa' => 'スパ', 'gym' => 'ジム', 'hospital' => '病院',
    'pharmacy' => '薬局', 'dentist' => '歯科', 'doctor' => '医院',
    'lodging' => '宿泊施設', 'gas_station' => 'ガソリンスタンド',
    'car_repair' => '自動車修理', 'parking' => '駐車場',
];
$typesRaw = $r['types'] ?? [];
$typeLabels = [];
foreach ($typesRaw as $t) {
    if (isset($typeMap[$t])) $typeLabels[] = $typeMap[$t];
}

// 営業状況
$businessStatus = $r['business_status'] ?? '';
$statusLabel = '';
if ($businessStatus === 'OPERATIONAL') $statusLabel = '営業中';
elseif ($businessStatus === 'CLOSED_TEMPORARILY') $statusLabel = '一時休業';
elseif ($businessStatus === 'CLOSED_PERMANENTLY') $statusLabel = '閉業';

// 価格帯
$priceLevel = $r['price_level'] ?? null;

echo json_encode([
    'success' => true,
    'detail' => [
        'name' => $r['name'] ?? '',
        'address' => $r['formatted_address'] ?? '',
        'phone' => $r['formatted_phone_number'] ?? '',
        'rating' => $r['rating'] ?? null,
        'ratings_total' => $r['user_ratings_total'] ?? 0,
        'open_now' => $openNow,
        'hours' => $hours,
        'business_status' => $statusLabel,
        'types' => $typeLabels,
        'website' => $r['website'] ?? '',
        'google_url' => $r['url'] ?? '',
        'photos' => $photos,
        'price_level' => $priceLevel,
        'reviews' => $reviews,
        'lat' => $r['geometry']['location']['lat'] ?? null,
        'lng' => $r['geometry']['location']['lng'] ?? null
    ]
], JSON_UNESCAPED_UNICODE);
