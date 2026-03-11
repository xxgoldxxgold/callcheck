<?php
/**
 * search_phone.php v14 - 高速化: NearbySearch+TextSearch並列実行
 */

header('Content-Type: application/json; charset=utf-8');

// CORS: 自サイトのみ許可
$allowedOrigins = ['https://denwa2.com', 'https://www.denwa2.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: https://denwa2.com');
}
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$rawQuery = trim($_POST['store_name'] ?? '');
// UTF-8でない場合に変換（Windows端末等からのリクエスト対応）
if ($rawQuery !== '' && !mb_check_encoding($rawQuery, 'UTF-8')) {
    $rawQuery = mb_convert_encoding($rawQuery, 'UTF-8', 'auto');
}
$userLat = isset($_POST['lat']) ? floatval($_POST['lat']) : 0.0;
$userLng = isset($_POST['lng']) ? floatval($_POST['lng']) : 0.0;
$pageToken = trim($_POST['page_token'] ?? '');

if ($rawQuery === '' && $pageToken === '') {
    echo json_encode(['success' => false, 'error' => '店名が指定されていません']);
    exit;
}

$API_KEY = getenv('GOOGLE_API_KEY');
if (!$API_KEY) {
    error_log('search_phone.php: GOOGLE_API_KEY is not set in environment variables.');
    echo json_encode(['success' => false, 'error' => 'サーバー設定エラー: APIキーがありません']);
    exit;
}

/**
 * ★ 次ページリクエスト（page_token指定時）
 */
if ($pageToken !== '') {
  try {
    $nextUrl = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json?' . http_build_query([
      'key'        => $API_KEY,
      'pagetoken'  => $pageToken,
    ]);
    
    // ★ next_page_tokenは発行直後だとINVALID_REQUESTになるのでリトライ
    $nextData = null;
    for ($retry = 0; $retry < 3; $retry++) {
      $chNext = curl_init($nextUrl);
      curl_setopt_array($chNext, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 2]);
      $nextData = json_decode(curl_exec($chNext), true);
      curl_close($chNext);
      if (is_array($nextData) && ($nextData['status'] ?? '') === 'OK') break;
      usleep(800000); // 0.8秒待ってリトライ
    }
    
    if (!is_array($nextData) || empty($nextData['results'])) {
      echo json_encode(['success' => true, 'stores' => [], 'next_page_token' => null]);
      exit;
    }
    
    $results = $nextData['results'];
    $newNextToken = $nextData['next_page_token'] ?? null;
    
    // 距離順ソート
    if ($userLat != 0.0 && $userLng != 0.0) {
      usort($results, function($a, $b) use ($userLat, $userLng) {
        $locA = $a['geometry']['location'] ?? null;
        $locB = $b['geometry']['location'] ?? null;
        $distA = $locA ? distance_m($userLat, $userLng, $locA['lat'], $locA['lng']) : 999999;
        $distB = $locB ? distance_m($userLat, $userLng, $locB['lat'], $locB['lng']) : 999999;
        return $distA <=> $distB;
      });
    }
    
    // Details取得（電話番号）
    $maxR = min(20, count($results));
    $mh = curl_multi_init(); $chs = []; $pInfos = [];
    for ($i = 0; $i < $maxR; $i++) {
      $pid = $results[$i]['place_id'] ?? null;
      if (!$pid) continue;
      $url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query([
        'place_id' => $pid, 'fields' => 'name,formatted_address,formatted_phone_number,international_phone_number,geometry,photos', 'language' => 'ja', 'key' => $API_KEY
      ]);
      $ch = curl_init($url);
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2]);
      curl_multi_add_handle($mh, $ch);
      $chs[$i] = $ch; $pInfos[$i] = $results[$i];
    }
    $running = null; $t0 = microtime(true);
    do { curl_multi_exec($mh, $running); if ($running > 0) curl_multi_select($mh, 0.1); if ((microtime(true) - $t0) > 8.0) break; } while ($running > 0);
    
    $stores = [];
    for ($i = 0; $i < $maxR; $i++) {
      if (!isset($chs[$i])) continue;
      $resp = curl_multi_getcontent($chs[$i]);
      $det = json_decode($resp, true);
      if (($det['status'] ?? '') === 'OK') {
        $r = $det['result'] ?? [];
        $phone = $r['formatted_phone_number'] ?? ($r['international_phone_number'] ?? '');
        $tel = '';
        if ($phone !== '') { $tel = preg_replace('/^\+81/', '0', $phone); $tel = preg_replace('/\s+|-+/', '', $tel); }
        $dist = 999999;
        $sLat = null; $sLng = null;
        if (isset($r['geometry']['location'])) {
          $sLat = floatval($r['geometry']['location']['lat']);
          $sLng = floatval($r['geometry']['location']['lng']);
          if ($userLat != 0.0) {
            $dist = distance_m($userLat, $userLng, $sLat, $sLng);
          }
        }
        $pPhotos = [];
        $pPhotoSource = !empty($r['photos']) ? $r['photos'] : (!empty($pInfos[$i]['photos']) ? $pInfos[$i]['photos'] : []);
        if (!empty($pPhotoSource)) {
          $pPhotoCount = count($pPhotoSource);
          for ($p = 0; $p < $pPhotoCount; $p++) {
            $ref = $pPhotoSource[$p]['photo_reference'] ?? '';
            if ($ref) {
              $pPhotos[] = 'photo_proxy?ref=' . urlencode($ref) . '&w=800';
            }
          }
        }
        $stores[] = [
          'name' => $r['name'] ?? '', 'address' => $r['formatted_address'] ?? '',
          'phone_number' => $tel, 'distance' => $dist, 'hit' => 0,
          'has_phone' => !empty($tel), 'lat' => $sLat, 'lng' => $sLng,
          'place_id' => $pInfos[$i]['place_id'] ?? null,
          'rating' => $pInfos[$i]['rating'] ?? null,
          'ratings_total' => $pInfos[$i]['user_ratings_total'] ?? 0,
          'open_now' => isset($pInfos[$i]['opening_hours']['open_now']) ? (bool)$pInfos[$i]['opening_hours']['open_now'] : null,
          'types' => translate_types($pInfos[$i]['types'] ?? []),
          'price_level' => $pInfos[$i]['price_level'] ?? null,
          'photos' => $pPhotos,
          'business_status' => $pInfos[$i]['business_status'] ?? null,
        ];
      }
      curl_multi_remove_handle($mh, $chs[$i]); curl_close($chs[$i]);
    }
    curl_multi_close($mh);
    
    usort($stores, function($a, $b) { return floatval($a['distance']) <=> floatval($b['distance']); });
    
    echo json_encode(['success' => true, 'stores' => $stores, 'next_page_token' => $newNextToken], JSON_UNESCAPED_UNICODE);
    exit;
    
  } catch (Throwable $e) {
    error_log('search_phone.php next_page 例外: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => '次ページの取得に失敗しました']);
    exit;
  }
}

/**
 * キャッシュ設定（5分）
 */
$CACHE_FILE = __DIR__ . '/search_cache.json';
$CACHE_EXP = 1800; // 30分（店舗情報は頻繁に変わらない）
$DEBUG_MODE = isset($_POST['debug']);

/**
 * キャッシュ取得
 */
function cache_get($key) {
    global $CACHE_FILE, $CACHE_EXP;
    if (!file_exists($CACHE_FILE)) {
        return null;
    }

    $fp = @fopen($CACHE_FILE, 'r');
    if (!$fp) return null;
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $all = json_decode($content, true);
    if (!is_array($all) || !isset($all[$key])) {
        return null;
    }

    if (time() - ($all[$key]['ts'] ?? 0) > $CACHE_EXP) {
        return null;
    }

    return $all[$key]['data'] ?? null;
}

/**
 * キャッシュ設定
 */
function cache_set($key, $data) {
    global $CACHE_FILE;

    $fp = @fopen($CACHE_FILE, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);

    $content = stream_get_contents($fp);
    $all = json_decode($content, true) ?? [];

    $all[$key] = ['ts' => time(), 'data' => $data];
    if (count($all) > 120) {
        $all = array_slice($all, -60, null, true);
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($all, JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * 文字正規化（ブランド一致判定用）- 改善版
 */
function core_name($s) {
    // 全半/スペース/ひら→カナ統一
    $s = mb_convert_kana($s, 'KVasC', 'UTF-8');
    $s = mb_strtolower($s, 'UTF-8');
    
    // 記号とスペースを除去（汎用語句の削除は最小限に）
    $s = preg_replace('/[()\[\]{}「」『』【】〈〉《》.,、。・\/\-‐–—―ー−\s　]+/u', '', $s);
    $s = preg_replace('/[^0-9a-z\x{3040}-\x{30ff}\x{4e00}-\x{9fff}]+/ui', '', $s);
    
    return $s;
}

/**
 * 料理名/ジャンル名かどうかを判定
 */
function is_food_category($query) {
    $foodCategories = [
        'お好み焼き', 'お好み焼', 'おこのみやき',
        'もんじゃ', 'もんじゃ焼き', 'もんじゃ焼',
        '鉄板焼き', '鉄板焼',
        'ラーメン', 'らーめん', '拉麺',
        '寿司', 'すし', '鮨', 'スシ',
        '焼肉', '焼き肉', 'やきにく',
        'うどん', '饂飩',
        'そば', '蕎麦', 'ソバ',
        'カレー', 'カレーライス',
        '中華', '中華料理', '中国料理',
        'イタリアン', 'イタリア料理', 'イタリア',
        'フレンチ', 'フランス料理',
        'パスタ', 'スパゲッティ',
        'ピザ', 'ピッツァ',
        'ステーキ', '鉄板ステーキ',
        'とんかつ', 'トンカツ', '豚カツ',
        'てんぷら', '天ぷら', '天婦羅', '天麩羅',
        '居酒屋', 'いざかや',
        'バー', 'バル', 'ダイニングバー',
        '焼き鳥', 'やきとり', '焼鳥',
        'ハンバーガー', 'バーガー',
        'たこ焼き', 'たこ焼', 'タコ焼き',
        '串カツ', '串かつ', '串揚げ',
        'しゃぶしゃぶ', 'すき焼き',
        '海鮮', '魚介', '海鮮料理',
        '回転寿司', '回転ずし',
        'ファミレス', 'ファミリーレストラン',
        'ビュッフェ', 'バイキング', '食べ放題',
        '定食', '定食屋',
        '弁当', '弁当屋', 'べんとう',
        'おにぎり', 'おむすび',
        'パン', 'ベーカリー', 'パン屋',
        'ケーキ', 'スイーツ', 'デザート',
        'アイスクリーム', 'ジェラート',
        '和食', '日本料理', '懐石',
        '洋食', '西洋料理'
    ];
    
    $queryLower = mb_strtolower($query, 'UTF-8');
    $queryLen = mb_strlen($queryLower);
    foreach ($foodCategories as $category) {
        $categoryLower = mb_strtolower($category, 'UTF-8');
        $catLen = mb_strlen($categoryLower);
        // 完全一致
        if ($queryLower === $categoryLower) return true;
        // クエリが料理名+1文字以内（屋/店等）なら料理名検索とみなす
        // 例: "うどん屋" OK, "丸八うどん" NG（店名）
        if ($queryLen <= $catLen + 1 && mb_strpos($queryLower, $categoryLower) !== false) {
            return true;
        }
        // 料理名がクエリの短縮形（"ラーメン" → "ラーメン屋" etc）
        if ($catLen > $queryLen && mb_strpos($categoryLower, $queryLower) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * ブランド名のマッチング判定（改善版）
 */
function is_brand_match($storeName, $brandQuery) {
    // 料理名検索の場合は、店名に料理名が含まれているかチェック
    if (is_food_category($brandQuery)) {
        $storeCore = core_name($storeName);
        $queryCore = core_name($brandQuery);
        
        // お好み焼きの特別処理
        if ($brandQuery === 'お好み焼き' || $brandQuery === 'お好み焼' || $brandQuery === 'おこのみやき') {
            // お好み焼き関連のキーワードがあるか
            if (mb_strpos($storeCore, 'お好み') !== false ||
                mb_strpos($storeCore, 'おこのみ') !== false ||
                mb_strpos($storeCore, 'もんじゃ') !== false ||
                mb_strpos($storeCore, '鉄板') !== false ||
                mb_strpos($storeName, 'お好み') !== false ||
                mb_strpos($storeName, 'もんじゃ') !== false ||
                mb_strpos($storeName, '鉄板') !== false ||
                mb_strpos($storeName, 'okonomiyaki') !== false ||
                mb_strpos($storeName, 'ぽんぽこ') !== false ||  // ぽんぽこ亭対応
                mb_strpos($storeName, 'ぽんここ') !== false ||  // ぽんここてい対応
                mb_strpos($storeName, 'ポンポコ') !== false) {  // カタカナ表記対応
                return true;
            }
        }
        
        // 蕎麦/そば の特別処理
        if ($brandQuery === '蕎麦' || $brandQuery === 'そば') {
            if (mb_strpos($storeCore, 'そば') !== false ||
                mb_strpos($storeCore, '蕎麦') !== false ||
                mb_strpos($storeCore, 'そは') !== false || // 誤変換対応
                mb_strpos($storeName, 'そば') !== false ||
                mb_strpos($storeName, '蕎麦') !== false ||
                mb_strpos($storeName, 'soba') !== false ||
                mb_strpos($storeName, 'Soba') !== false) {
                return true;
            }
        }
        
        // うどんの特別処理
        if ($brandQuery === 'うどん') {
            if (mb_strpos($storeCore, 'うどん') !== false ||
                mb_strpos($storeCore, '饂飩') !== false ||
                mb_strpos($storeName, 'うどん') !== false ||
                mb_strpos($storeName, 'udon') !== false ||
                mb_strpos($storeName, 'Udon') !== false) {
                return true;
            }
        }
        
        // より緩い判定（料理名が含まれているか）
        if (mb_strpos($storeCore, $queryCore) !== false) {
            return true;
        }
        
        // 「お好み」で「お好み焼き」を見つける
        $queryRoot = mb_substr($queryCore, 0, -1);
        if (mb_strlen($queryRoot) >= 2 && mb_strpos($storeCore, $queryRoot) !== false) {
            return true;
        }
        
        // 料理名検索でも完全にマッチしない場合はfalse
        return false;
    }
    
    // 完全一致チェック
    if (mb_strpos(core_name($storeName), core_name($brandQuery)) !== false) {
        return true;
    }
    
    // 主要ブランドの別名・略称チェック
    $brandAliases = [
        'ドトール' => ['ドトールコーヒー', 'ドトールコーヒーショップ', 'doutor'],
        'スタバ' => ['スターバックス', 'スターバックスコーヒー', 'starbucks'],
        'スターバックス' => ['スタバ', 'スターバックスコーヒー', 'starbucks'],
        'マック' => ['マクドナルド', 'mcdonalds'],
        'マクドナルド' => ['マック', 'mcdonalds'],
        'セブン' => ['セブンイレブン', '7-11', '7-eleven'],
        'ファミマ' => ['ファミリーマート', 'familymart'],
        'ローソン' => ['lawson'],
    ];
    
    $brandLower = mb_strtolower($brandQuery, 'UTF-8');
    if (isset($brandAliases[$brandLower])) {
        foreach ($brandAliases[$brandLower] as $alias) {
            if (mb_strpos(core_name($storeName), core_name($alias)) !== false) {
                return true;
            }
        }
    }
    
    return false;
}

/* ===== ブランド/場所のスマート分割 =====
   料理名の場合は分割しない */
function split_brand_location_smart($q) {
  // 料理名の場合は分割しない
  if (is_food_category($q)) {
    return [$q, ''];
  }
  
  // 全角スペース→半角、連続スペース圧縮
  $q = mb_convert_kana($q, 's', 'UTF-8');
  $q = preg_replace('/\s+/u', ' ', trim($q));

  // 1) スペースあり → 先頭=ブランド / 残り=場所
  if (mb_strpos($q, ' ') !== false) {
    $parts = explode(' ', $q, 2);
    return [trim($parts[0]), trim($parts[1])];
  }

  // 2) スペースなし → カタカナ連続部 + それ以降で分割（例: ドトール渋谷, サンマルクなんば）
  if (preg_match('/^([\p{Katakana}\x{30FC}A-Za-z0-9]+)(.+)$/u', $q, $m)) {
    $brand = trim($m[1]);
    $loc   = trim($m[2]);
    // 場所がごく短い誤検出（1文字など）の場合は分割しない
    if (mb_strlen($loc, 'UTF-8') >= 2) return [$brand, $loc];
  }

  // 分割できなければ丸ごとブランド扱い（従来動作へ）
  return [$q, ''];
}

/* ===== 距離（ハヴァサイン） ===== */
function distance_m($lat1, $lng1, $lat2, $lng2) {
  $R=6371000; $φ1=deg2rad($lat1); $φ2=deg2rad($lat2);
  $dφ=$φ2-$φ1; $dλ=deg2rad($lng2-$lng1);
  $a=sin($dφ/2)**2 + cos($φ1)*cos($φ2)*sin($dλ/2)**2;
  return 2*$R*atan2(sqrt($a), sqrt(1-$a));
}

/* ===== Google Placesタイプ → 日本語変換 ===== */
function translate_types($typesRaw) {
  static $map = [
    'restaurant' => 'レストラン', 'cafe' => 'カフェ', 'bar' => 'バー',
    'bakery' => 'ベーカリー', 'meal_takeaway' => 'テイクアウト',
    'meal_delivery' => 'デリバリー', 'shopping_mall' => 'ショッピングモール',
    'supermarket' => 'スーパー', 'convenience_store' => 'コンビニ',
    'drugstore' => 'ドラッグストア', 'hair_care' => '美容院',
    'beauty_salon' => '美容サロン', 'spa' => 'スパ', 'gym' => 'ジム',
    'hospital' => '病院', 'pharmacy' => '薬局', 'dentist' => '歯科',
    'doctor' => '医院', 'lodging' => '宿泊施設',
    'gas_station' => 'ガソリンスタンド', 'car_repair' => '自動車修理',
    'parking' => '駐車場', 'clothing_store' => '衣料品店',
    'electronics_store' => '家電量販店', 'book_store' => '書店',
    'movie_theater' => '映画館', 'night_club' => 'ナイトクラブ',
  ];
  $skip = ['point_of_interest','establishment','food','store','health','finance'];
  $labels = [];
  foreach ($typesRaw as $t) {
    if (in_array($t, $skip)) continue;
    if (isset($map[$t])) { $labels[] = $map[$t]; if (count($labels) >= 2) break; }
  }
  return $labels;
}

/**
 * ブランドからカテゴリを推定（改善版）
 */
function get_brand_category($brandQuery) {
    // 料理名の場合は restaurant を返す
    if (is_food_category($brandQuery)) {
        return 'restaurant';
    }
    
    // カテゴリマップ（日本語ブランド名も追加）
    $categoryMap = [
        // 英語
        'cafe' => 'cafe',
        'coffee' => 'cafe',
        'restaurant' => 'restaurant',
        'food' => 'meal_takeaway',
        'convenience' => 'convenience_store',
        'gas' => 'gas_station',
        'bank' => 'bank',
        'atm' => 'atm',
        'hospital' => 'hospital',
        'pharmacy' => 'pharmacy',
        'supermarket' => 'supermarket',
        'department' => 'department_store',
        
        // 日本語一般
        'カフェ' => 'cafe',
        'コーヒー' => 'cafe',
        '珈琲' => 'cafe',
        '喫茶' => 'cafe',
        'レストラン' => 'restaurant',
        '食堂' => 'restaurant',
        'フード' => 'meal_takeaway',
        'コンビニ' => 'convenience_store',
        'ガソリンスタンド' => 'gas_station',
        '銀行' => 'bank',
        '病院' => 'hospital',
        '薬局' => 'pharmacy',
        'スーパー' => 'supermarket',
        'デパート' => 'department_store',
        '百貨店' => 'department_store',
        
        // 具体的なブランド名
        'ドトール' => 'cafe',
        'ドトールコーヒー' => 'cafe',
        'スターバックス' => 'cafe',
        'スタバ' => 'cafe',
        'タリーズ' => 'cafe',
        'エクセルシオール' => 'cafe',
        'ベローチェ' => 'cafe',
        'コメダ' => 'cafe',
        'コメダ珈琲' => 'cafe',
        'サンマルク' => 'cafe',
        'サンマルクカフェ' => 'cafe',
        'ルノアール' => 'cafe',
        'プロント' => 'cafe',
        'ベックス' => 'cafe',
        'カフェドクリエ' => 'cafe',
        
        // ファストフード
        'マクドナルド' => 'restaurant',
        'マック' => 'restaurant',
        'モスバーガー' => 'restaurant',
        'モス' => 'restaurant',
        'ケンタッキー' => 'restaurant',
        'ケンタ' => 'restaurant',
        'バーガーキング' => 'restaurant',
        '吉野家' => 'restaurant',
        'すき家' => 'restaurant',
        '松屋' => 'restaurant',
        
        // コンビニ
        'セブンイレブン' => 'convenience_store',
        'セブン' => 'convenience_store',
        'ファミリーマート' => 'convenience_store',
        'ファミマ' => 'convenience_store',
        'ローソン' => 'convenience_store',
        'ミニストップ' => 'convenience_store',
        'デイリーヤマザキ' => 'convenience_store',
    ];
    
    // 小文字変換して検索（英語用）
    $brandLower = strtolower($brandQuery);
    if (isset($categoryMap[$brandLower])) {
        return $categoryMap[$brandLower];
    }
    
    // そのまま検索（日本語用）
    if (isset($categoryMap[$brandQuery])) {
        return $categoryMap[$brandQuery];
    }
    
    // 部分一致チェック（ブランド名が含まれている場合）
    foreach ($categoryMap as $key => $category) {
        if (mb_strpos($brandQuery, $key) !== false || mb_strpos($key, $brandQuery) !== false) {
            return $category;
        }
    }
    
    return null;
}

try {
  // ★ キャッシュバージョン（ソート修正時にインクリメントすること）
  $CACHE_VER = 15;
  $cacheKey = md5(json_encode([$CACHE_VER, $rawQuery, round($userLat, 3), round($userLng, 3)]));
  if (!$DEBUG_MODE) {
    $c = cache_get($cacheKey);
    if ($c !== null) { echo json_encode($c); exit; }
  }

  [$brandQuery, $locationHint] = split_brand_location_smart($rawQuery);
  $brandCore = core_name($brandQuery);

  $baseLat = null; $baseLng = null; $mode = '';

  // 1) 場所ヒントがあるなら座標に解決
  if ($locationHint !== '') {
    $locUrl = 'https://maps.googleapis.com/maps/api/place/textsearch/json?' . http_build_query([
      'query'    => $locationHint,
      'language' => 'ja',
      'region'   => 'jp',
      'key'      => $API_KEY
    ]);
    $chLoc = curl_init($locUrl);
    curl_setopt_array($chLoc, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2]);
    $locData = json_decode(curl_exec($chLoc), true);
    curl_close($chLoc);
    if (is_array($locData) && !empty($locData['results'][0]['geometry']['location'])) {
      $baseLat = floatval($locData['results'][0]['geometry']['location']['lat']);
      $baseLng = floatval($locData['results'][0]['geometry']['location']['lng']);
      $mode = 'location-override';
      error_log("基点: 場所ヒント '{$locationHint}' → {$baseLat},{$baseLng}");
    }
  }

  // 2) 基点未決ならユーザー現在地
  if ($baseLat === null && $userLat != 0.0 && $userLng != 0.0) {
    $baseLat = $userLat; $baseLng = $userLng; $mode = 'user-nearby';
  }

  // 3) 検索 - rankby=distance で本当に近い店を取得
  $allResults = [];
  $nextPageToken = null;

  $isFoodSearch = is_food_category($brandQuery);
  
  if ($baseLat !== null && $baseLng !== null) {
    
    // ★★ v14: NearbySearch + TextSearch を curl_multi で並列実行 ★★
    $nearbyParams = [
      'key'      => $API_KEY,
      'language' => 'ja',
      'location' => $baseLat . ',' . $baseLng,
      'rankby'   => 'distance',
      'keyword'  => $brandQuery,
    ];
    if ($isFoodSearch) {
      $nearbyParams['type'] = 'restaurant';
    }
    $nearbyUrl = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json?' . http_build_query($nearbyParams);

    $textSupUrl = 'https://maps.googleapis.com/maps/api/place/textsearch/json?'
      . 'key=' . urlencode($API_KEY)
      . '&language=ja&region=jp'
      . '&query=' . rawurlencode($rawQuery)
      . '&location=' . $baseLat . ',' . $baseLng
      . '&radius=5000';

    // 2つのAPIコールを並列実行
    $mhSearch = curl_multi_init();
    $chNearby = curl_init($nearbyUrl);
    curl_setopt_array($chNearby, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 2]);
    curl_multi_add_handle($mhSearch, $chNearby);
    $chText = curl_init($textSupUrl);
    curl_setopt_array($chText, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 2]);
    curl_multi_add_handle($mhSearch, $chText);

    $runningSearch = null;
    $tSearch = microtime(true);
    do {
      curl_multi_exec($mhSearch, $runningSearch);
      if ($runningSearch > 0) curl_multi_select($mhSearch, 0.05);
      if ((microtime(true) - $tSearch) > 6.0) break;
    } while ($runningSearch > 0);

    $nearbyData = json_decode(curl_multi_getcontent($chNearby), true);
    $textSupData = json_decode(curl_multi_getcontent($chText), true);
    curl_multi_remove_handle($mhSearch, $chNearby); curl_close($chNearby);
    curl_multi_remove_handle($mhSearch, $chText); curl_close($chText);
    curl_multi_close($mhSearch);
    $searchElapsed = round((microtime(true) - $tSearch) * 1000);
    error_log("並列検索完了: {$searchElapsed}ms");

    $nextPageToken = null;
    if (is_array($nearbyData) && !empty($nearbyData['results'])) {
      $allResults = array_merge($allResults, $nearbyData['results']);
      $nextPageToken = $nearbyData['next_page_token'] ?? null;
      error_log("距離順Nearby検索結果 ({$brandQuery}): " . count($nearbyData['results']) . "件");
    }

    $textSearchCount = 0;
    $textSearchStatus = $textSupData['status'] ?? 'NO_RESPONSE';
    if (is_array($textSupData) && !empty($textSupData['results'])) {
      $textSearchCount = count($textSupData['results']);
      $allResults = array_merge($allResults, $textSupData['results']);
      error_log("Text検索補完: {$textSearchCount}件追加 (status={$textSearchStatus})");
    }

  } else {
    // 位置情報なしの場合: Text Search のみ
    $searchUrl = 'https://maps.googleapis.com/maps/api/place/textsearch/json?' . http_build_query([
      'key'      => $API_KEY,
      'language' => 'ja',
      'region'   => 'jp',
      'query'    => $rawQuery,
    ]);
    $ch = curl_init($searchUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 2]);
    $searchData = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (is_array($searchData) && !empty($searchData['results'])) {
      $allResults = $searchData['results'];
    }
    $mode = 'textsearch-only';
    $textSearchCount = 0;
    $textSearchStatus = 'N/A';
  }

  // 重複を除去（place_idで）
  $uniqueResults = [];
  $seenPlaceIds = [];
  foreach ($allResults as $result) {
    $placeId = $result['place_id'] ?? null;
    if ($placeId && !in_array($placeId, $seenPlaceIds)) {
      $uniqueResults[] = $result;
      $seenPlaceIds[] = $placeId;
    }
  }
  
  $results = $uniqueResults;

  if (empty($results)) {
    echo json_encode(['success' => false, 'error' => '店舗が見つかりませんでした']);
    exit;
  }
  
  // ★ Details取得前に距離順でソート（近い店が確実にtop10に入るように）
  if ($baseLat !== null && $baseLng !== null) {
    usort($results, function($a, $b) use ($baseLat, $baseLng) {
      $locA = $a['geometry']['location'] ?? null;
      $locB = $b['geometry']['location'] ?? null;
      $distA = $locA ? distance_m($baseLat, $baseLng, $locA['lat'], $locA['lng']) : 999999;
      $distB = $locB ? distance_m($baseLat, $baseLng, $locB['lat'], $locB['lng']) : 999999;
      return $distA <=> $distB;
    });
  }
  
  // 最大取得数を制限（10件で高速化、次ページで追加取得可能）
  $maxResults = min(10, count($results));

  // 4) Places Details を並列取得（電話番号取得）
  $mh = curl_multi_init(); $chs = []; $placeInfos = [];
  for ($i=0; $i<$maxResults; $i++) {
    $placeId = $results[$i]['place_id'] ?? null;
    if (!$placeId) continue;
    $url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query([
      'place_id' => $placeId,
      'fields'   => 'name,formatted_address,formatted_phone_number,international_phone_number,photos',
      'language' => 'ja',
      'key'      => $API_KEY
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>4, CURLOPT_CONNECTTIMEOUT=>2]);
    curl_multi_add_handle($mh, $ch);
    $chs[$i]=$ch; $placeInfos[$i]=$results[$i];
  }

  $running=null; $t0=microtime(true);
  do {
    curl_multi_exec($mh, $running);
    if ($running>0) curl_multi_select($mh, 0.1);
    if ((microtime(true)-$t0)>5.0) break;
  } while ($running>0);

  $stores=[];
  for ($i=0; $i<$maxResults; $i++) {
    if (!isset($chs[$i])) continue;
    $resp = curl_multi_getcontent($chs[$i]);
    $det  = json_decode($resp, true);
    $pl   = $placeInfos[$i];

    if (($det['status'] ?? '') === 'OK') {
      $r = $det['result'] ?? [];

      // 電話番号
      $phone = $r['formatted_phone_number'] ?? ($r['international_phone_number'] ?? '');
      
      // 電話番号がなくても店舗情報を保存（改善）
      $tel = '';
      if ($phone !== '') {
        $tel = preg_replace('/^\+81/', '0', $phone);
        $tel = preg_replace('/\s+|-+/', '', $tel);
      }

      // 距離・座標（TextSearch結果のgeometryから取得、Details APIでは省略）
      $dist = 999999;
      $storeLat = null;
      $storeLng = null;
      $plGeo = $pl['geometry']['location'] ?? null;
      if ($plGeo) {
        $storeLat = floatval($plGeo['lat']);
        $storeLng = floatval($plGeo['lng']);
        if ($baseLat !== null) {
          $dist = distance_m($baseLat, $baseLng, $storeLat, $storeLng);
        }
      }

      // ブランド名ヒット（改善版のマッチング使用）
      $foundName = $r['name'] ?? ($pl['name'] ?? '');
      $hit = is_brand_match($foundName, $brandQuery) ? 1 : 0;

      // 写真URL配列（Details API結果を優先、なければ検索結果から）
      $photos = [];
      $photoSource = !empty($r['photos']) ? $r['photos'] : (!empty($pl['photos']) ? $pl['photos'] : []);
      if (!empty($photoSource)) {
        $photoCount = count($photoSource);
        for ($p = 0; $p < $photoCount; $p++) {
          $ref = $photoSource[$p]['photo_reference'] ?? '';
          if ($ref) {
            $photos[] = 'photo_proxy?ref=' . urlencode($ref) . '&w=800';
          }
        }
      }

      $stores[] = [
        'name'         => $foundName,
        'address'      => $r['formatted_address'] ?? '',
        'phone_number' => $tel,
        'distance'     => $dist,
        'hit'          => $hit,
        'has_phone'    => !empty($tel),
        'lat'          => $storeLat,
        'lng'          => $storeLng,
        'place_id'     => $placeInfos[$i]['place_id'] ?? null,
        'rating'       => $pl['rating'] ?? null,
        'ratings_total'=> $pl['user_ratings_total'] ?? 0,
        'open_now'     => isset($pl['opening_hours']['open_now']) ? (bool)$pl['opening_hours']['open_now'] : null,
        'types'        => translate_types($pl['types'] ?? []),
        'price_level'  => $pl['price_level'] ?? null,
        'photos'       => $photos,
        'business_status' => $pl['business_status'] ?? null,
      ];
    }
    curl_multi_remove_handle($mh, $chs[$i]); curl_close($chs[$i]);
  }
  curl_multi_close($mh);

  if (empty($stores)) {
    // エラーメッセージをより詳細に
    $errorMsg = '店舗が見つかりませんでした。';
    if (count($results) > 0) {
      $errorMsg .= ' (検索結果: ' . count($results) . '件あったが、詳細情報の取得に失敗)';
    }
    echo json_encode([
      'success' => false, 
      'error' => $errorMsg]);
    exit;
  }

  // 5) 並び替え：距離順（純粋に近い順）
  usort($stores, function($a,$b){
    $distA = floatval($a['distance']);
    $distB = floatval($b['distance']);
    if ($distA !== $distB) {
      return $distA <=> $distB;
    }
    return strcmp($a['name'], $b['name']);
  });

  $payload = [
    'success' => true,
    'stores' => $stores,
    'next_page_token' => $nextPageToken ?? null];
  
  if (!$DEBUG_MODE) cache_set($cacheKey, $payload);

  echo json_encode($payload, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  error_log('search_phone.php 例外: '.$e->getMessage());
  echo json_encode(['success'=>false,'error'=>'内部エラーが発生しました']);
}