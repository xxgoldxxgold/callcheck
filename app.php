<?php
session_start();

/**
 * ログアウト処理
 * ?logout=1 パラメータでログアウトを実行
 */
if (isset($_GET['logout'])) {
    // PHPセッション破棄
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), 
            '', 
            time() - 42000, 
            $params["path"], 
            $params["domain"], 
            $params["secure"], 
            $params["httponly"]
        );
    }
    session_destroy();

    // Firebase からもログアウト（フロントで実行）
    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <meta http-equiv="refresh" content="3;url=./">
        <title>ログアウト</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
        <style>
            :root { --ios-bg: #F2F2F7; }
            body {
                margin: 0;
                background: var(--ios-bg);
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans JP", sans-serif;
                display: grid;
                place-items: center;
                min-height: 100vh;
            }
            .card {
                background: #fff;
                padding: 28px 24px;
                border-radius: 14px;
                border: 1px solid #e6e8ef;
                box-shadow: 0 12px 24px rgba(16, 24, 40, .06);
                text-align: center;
            }
            h2 {
                margin: 0 0 8px;
                font-size: 20px;
            }
            p {
                margin: 0;
                color: #6C6C70;
            }
        </style>

        <script type="module">
            import { initializeApp } from "https://www.gstatic.com/firebasejs/10.12.5/firebase-app.js";
            import { getAuth, signOut } from "https://www.gstatic.com/firebasejs/10.12.5/firebase-auth.js";

            const firebaseConfig = {
                apiKey: "AIzaSyBAXIQkeLvBTMHlHgyoJDQur21FEy3J2yQ",
                authDomain: "bscqi7k64u7dbn7i6pfyy5ht4uom5d.firebaseapp.com",
                projectId: "bscqi7k64u7dbn7i6pfyy5ht4uom5d",
                storageBucket: "bscqi7k64u7dbn7i6pfyy5ht4uom5d.firebasestorage.app",
                messagingSenderId: "919638810052",
                appId: "1:919638810052:web:94546541333d026598d891"
            };
            
            const app = initializeApp(firebaseConfig);
            const auth = getAuth(app);
            
            try {
                await signOut(auth);
            } catch (e) {
                console.warn('Firebase logout error:', e);
            }
            
            document.cookie = "php_logged_out=1; Max-Age=60; path=/";
        </script>
    </head>
    <body>
        <div class="card">
            <h2><i class="fa-solid fa-door-open"></i> ログアウトしました</h2>
            <p>3秒後にトップへ戻ります…</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
  <title>営業時間確認コール</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://maps.googleapis.com">
  <link rel="preconnect" href="https://maps.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <script>
  // Maps API読み込み前に位置情報取得を開始 + localStorageキャッシュで即座に表示
  (function(){
    var cached = null;
    try { cached = JSON.parse(localStorage.getItem('_pos')); } catch(e) {}
    window.__earlyPos = cached;
    window.__earlyGeo = new Promise(function(resolve) {
      if (!navigator.geolocation) { resolve(cached); return; }
      var done = false;
      var timer = setTimeout(function() { if (!done) { done = true; resolve(cached); } }, 1500);
      navigator.geolocation.getCurrentPosition(function(p) {
        if (done) return;
        done = true; clearTimeout(timer);
        var loc = { lat: p.coords.latitude, lng: p.coords.longitude };
        try { localStorage.setItem('_pos', JSON.stringify(loc)); } catch(e) {}
        resolve(loc);
      }, function() {
        if (!done) { done = true; clearTimeout(timer); resolve(cached); }
      }, { enableHighAccuracy: false, timeout: 1500, maximumAge: 600000 });
    });
  })();
  </script>
  <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars(getenv('GOOGLE_API_KEY'), ENT_QUOTES, 'UTF-8'); ?>&libraries=places,geometry"></script>
  <style>
    :root {
      --bg: #f5f7fb;
      --card: #fff;
      --muted: #70757a;
      --border: #dadce0;
      --text: #202124;
      --primary: #1a73e8;
      --primary-h: #1765cc;
      --radius: 8px;
      --shadow: 0 1px 3px rgba(60,64,67,.3), 0 4px 8px 3px rgba(60,64,67,.15);
    }
    * { box-sizing: border-box; }
    html { height: 100%; overflow: hidden; }
    body { margin: 0; background: #fff; color: var(--text); font-family: Roboto, "Noto Sans JP", Arial, sans-serif; height: 100%; overflow: hidden; position: fixed; inset: 0; }

    main { position: fixed; inset: 0; overflow: hidden; }
    main.showing-results .bottom-sheet,
    main.showing-detail .bottom-sheet,
    main.showing-route .bottom-sheet { display: none; }

    /* ===== フルスクリーンマップ ===== */
    .map-section { position: absolute; inset: 0; }
    .map-section #bgMap { width: 100%; height: 100%; }
    .map-controls { position: absolute; top: 68px; right: 10px; z-index: 1; display: flex; flex-direction: column; gap: 8px; }
    .map-ctrl-btn { background: #fff; border: none; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.3); width: 40px; height: 40px; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0; touch-action: manipulation; -webkit-tap-highlight-color: transparent; user-select: none; -webkit-user-select: none; }
    .map-ctrl-btn:hover { background: #f5f5f5; }
    .map-ctrl-btn:active { background: #e8e8e8; }
    .map-ctrl-btn svg { width: 20px; height: 20px; fill: #666; }
    .zoom-group { display: flex; flex-direction: column; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.3); }
    .zoom-group button { background: #fff; border: none; width: 40px; height: 40px; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0; touch-action: manipulation; -webkit-tap-highlight-color: transparent; user-select: none; -webkit-user-select: none; }
    .zoom-group button:hover { background: #f5f5f5; }
    .zoom-group button:active { background: #e8e8e8; }
    .zoom-group button + button { border-top: 1px solid #e0e0e0; }
    .zoom-group svg { width: 18px; height: 18px; fill: #666; }
    .pin-label { position: absolute; transform: translate(-50%, 0); font-size: 11px; font-weight: 500; color: #1a1a1a; white-space: nowrap; pointer-events: none; text-shadow: 0 0 3px #fff, 0 0 3px #fff; max-width: 120px; overflow: hidden; text-overflow: ellipsis; }

    /* === 上部: 検索バー (Google Maps風) === */
    .search-bar-area { position: absolute; top: 10px; left: 10px; right: 10px; z-index: 2; pointer-events: none; }
    .search-bar { pointer-events: auto; }
    .favorites-area { pointer-events: auto; }
    main.showing-results .favorites-area,
    main.showing-detail .favorites-area { display: none !important; }
    .search-bar { display: flex; align-items: center; gap: 8px; background: #fff; border-radius: 28px; padding: 8px 16px; box-shadow: var(--shadow); height: 48px; }
    .search-bar i.search-icon { color: var(--muted); font-size: 16px; flex-shrink: 0; }
    .search-bar .back-btn { display: none; background: none; border: none; padding: 4px; cursor: pointer; color: var(--text); font-size: 18px; flex-shrink: 0; }
    .search-bar.has-results .search-icon { display: none; }
    .search-bar.has-results .back-btn { display: block; }
    .search-bar input { border: 0; outline: none; width: 100%; font-size: 15px; background: transparent; color: var(--text); }
    .search-bar input::placeholder { color: #9aa0a6; }
    /* === 検索結果リスト (Google Maps風ボトムシート) === */
    .search-results { display: none; position: fixed; bottom: 0; left: 0; right: 0; z-index: 10; background: #fff; border-radius: 14px 14px 0 0; box-shadow: 0 -2px 10px rgba(0,0,0,.15); overflow: hidden; flex-direction: column; transition: height 0.3s cubic-bezier(.4,0,.2,1); will-change: height; }
    .search-results.show { display: flex; }
    .search-results.sheet-peek { height: 35vh; }
    .search-results.sheet-half { height: 55vh; }
    .search-results.sheet-full { height: 85vh; }
    .results-drag { flex-shrink: 0; cursor: ns-resize; padding: 10px 0 6px; touch-action: none; user-select: none; -webkit-user-select: none; }
    .results-drag-bar { width: 36px; height: 4px; background: #dadce0; border-radius: 2px; margin: 0 auto; }
    .results-drag:active .results-drag-bar { background: #9aa0a6; }
    .results-count-row { flex-shrink: 0; padding: 4px 16px 6px; font-size: 12px; color: var(--muted); }
    .results-list { flex: 1; overflow-y: auto; overflow-x: hidden; -webkit-overflow-scrolling: touch; scrollbar-width: none; -ms-overflow-style: none; overscroll-behavior-y: contain; }
    .results-list::-webkit-scrollbar { display: none; }
    .search-results { overflow-x: hidden; overscroll-behavior: contain; }
    /* 店舗カード（Google Maps風 詳細カード一覧） */
    .store-card { border-bottom: 8px solid #f1f3f4; overflow: hidden; content-visibility: auto; contain-intrinsic-size: auto 300px; }
    .store-card:last-child { border-bottom: none; }
    /* 写真（横スクロール） */
    .store-card-photos { display: flex; gap: 4px; overflow-x: auto; -webkit-overflow-scrolling: touch; padding: 0; background: #f1f3f4; scrollbar-width: none; -ms-overflow-style: none; cursor: grab; user-select: none; }
    .store-card-photos::-webkit-scrollbar { display: none; }
    .store-card-photos img { pointer-events: none; -webkit-user-drag: none; }
    .pg-item { flex-shrink: 0; }
    .pg-item img { height: 130px; width: auto; display: block; }
    .store-card-body { padding: 12px 16px 14px; }
    .store-card-name { font-size: 16px; font-weight: 500; color: var(--text); margin-bottom: 4px; line-height: 1.3; }
    .store-card-rating { display: flex; align-items: center; gap: 2px; font-size: 12px; color: var(--muted); margin-bottom: 3px; line-height: 1.3; flex-wrap: wrap; }
    .store-card-rating .rating-value { font-weight: 500; color: var(--text); }
    .store-card-rating .rating-star { color: #fbbc04; font-size: 12px; }
    .store-card-rating .dot-sep { margin: 0 4px; color: #dadce0; }
    .store-card-meta { font-size: 13px; color: var(--muted); line-height: 1.4; margin-bottom: 2px; }
    .store-card-meta .open-badge { color: #1e8e3e; font-weight: 500; }
    .store-card-meta .closed-badge { color: #d93025; font-weight: 500; }
    .store-card-meta .dot-sep { margin: 0 4px; color: #dadce0; }
    .store-card-info { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--muted); margin-bottom: 2px; }
    .store-card-info i { width: 14px; text-align: center; font-size: 11px; color: #9aa0a6; flex-shrink: 0; }
    .store-card-info .phone-num { color: var(--primary); font-weight: 500; }
    .store-card-actions { display: flex; gap: 8px; margin-top: 10px; }
    .store-card-actions button { flex: 1; padding: 8px 0; border-radius: 20px; font-size: 13px; font-weight: 500; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; }
    .store-card-actions .sc-call-btn { background: var(--primary); color: #fff; border: none; }
    .store-card-actions .sc-route-btn { background: #fff; color: var(--primary); border: 1px solid var(--border); }

    /* === 店舗詳細パネル (Google Maps風ボトムシート) === */
    .place-detail-panel { display: none; position: fixed; bottom: 0; left: 0; right: 0; z-index: 11; background: #fff; border-radius: 14px 14px 0 0; box-shadow: 0 -2px 10px rgba(0,0,0,.15); overflow: hidden; flex-direction: column; transition: height 0.3s cubic-bezier(.4,0,.2,1); will-change: height; }
    .place-detail-panel.show { display: flex; }
    .place-detail-panel.sheet-peek { height: 30vh; }
    .place-detail-panel.sheet-half { height: 55vh; }
    .place-detail-panel.sheet-full { height: 85vh; }
    .place-detail-header { display: flex; align-items: center; gap: 8px; padding: 10px 16px 6px; flex-shrink: 0; border-bottom: 1px solid #f1f3f4; }
    .place-detail-back { background: none; border: none; cursor: pointer; font-size: 18px; color: var(--text); padding: 4px; }
    .place-detail-title { font-size: 15px; font-weight: 500; color: var(--text); flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .place-detail-body { flex: 1; overflow-y: auto; -webkit-overflow-scrolling: touch; padding: 12px 16px 20px; scrollbar-width: none; -ms-overflow-style: none; overscroll-behavior-y: contain; }
    .place-detail-body::-webkit-scrollbar { display: none; }
    .pd-name { font-size: 18px; font-weight: 500; color: var(--text); margin-bottom: 4px; }
    .pd-rating { display: flex; align-items: center; gap: 4px; margin-bottom: 6px; }
    .pd-rating-num { font-size: 14px; font-weight: 500; color: var(--text); }
    .pd-rating-count { font-size: 13px; color: var(--muted); }
    .pd-meta { font-size: 13px; color: var(--muted); margin-bottom: 4px; }
    .pd-status-open { font-size: 13px; color: #1e8e3e; font-weight: 500; }
    .pd-status-closed { font-size: 13px; color: #d93025; font-weight: 500; }
    .pd-hours { font-size: 13px; color: var(--muted); margin-top: 2px; }
    .pd-photos { display: flex; gap: 6px; overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
    .pd-photos img { height: 120px; border-radius: 8px; object-fit: cover; flex-shrink: 0; }
    .pd-info-row { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f1f3f4; font-size: 13px; }
    .pd-info-row i { color: var(--muted); font-size: 14px; margin-top: 2px; flex-shrink: 0; width: 18px; text-align: center; }
    .pd-info-row a { color: var(--primary); text-decoration: none; }
    .pd-info-row .pd-phone-num { color: var(--primary); font-weight: 500; }
    .pd-actions { display: flex; gap: 8px; margin: 14px 0; }
    .pd-actions button { flex: 1; padding: 10px 0; border-radius: 20px; font-size: 14px; font-weight: 500; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; }
    .pd-actions .pd-call-btn { background: var(--primary); color: #fff; border: none; }
    .pd-actions .pd-route-btn { background: #fff; color: var(--primary); border: 1px solid var(--border); }
    .pd-review { border-top: 1px solid #f1f3f4; padding-top: 10px; margin-top: 10px; }
    .pd-review-header { font-size: 14px; font-weight: 500; color: var(--text); margin-bottom: 8px; }
    .pd-review-item { margin-bottom: 10px; }
    .pd-review-stars { display: flex; align-items: center; gap: 4px; margin-bottom: 2px; }
    .pd-review-time { font-size: 11px; color: var(--muted); }
    .pd-review-text { font-size: 13px; color: var(--text); line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }

    /* === 下部: ボトムシート (Google Maps風ドラッグ対応) === */
    .bottom-sheet { position: absolute; bottom: 0; left: 0; right: 0; z-index: 2; background: #fff; border-radius: 16px 16px 0 0; box-shadow: 0 -2px 6px rgba(60,64,67,.15); display: flex; flex-direction: column; overflow: hidden; transition: height 0.3s cubic-bezier(.4,0,.2,1); will-change: height; }
    .bottom-sheet.sheet-peek { height: 12vh; }
    .bottom-sheet.sheet-half { height: 45vh; }
    .bottom-sheet.sheet-full { height: 90vh; }
    .sheet-drag { flex-shrink: 0; cursor: ns-resize; padding: 10px 0 6px; touch-action: none; user-select: none; -webkit-user-select: none; }
    .sheet-drag:active .sheet-handle { background: #9aa0a6; }
    .sheet-handle { width: 36px; height: 4px; background: #dadce0; border-radius: 2px; margin: 0 auto; }
    .sheet-content { flex: 1; overflow: hidden; padding: 0 16px 16px; display: flex; flex-direction: column; gap: 8px; overscroll-behavior-y: contain; scrollbar-width: none; -ms-overflow-style: none; touch-action: none; }
    .bottom-sheet.sheet-full .sheet-content { overflow-y: auto; -webkit-overflow-scrolling: touch; touch-action: pan-y; }
    .sheet-content::-webkit-scrollbar { display: none; }

    /* ボトムシート内の入力 */
    .sheet-input { display: flex; align-items: center; gap: 10px; border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; background: #fff; }
    .sheet-input i { color: var(--muted); font-size: 14px; flex-shrink: 0; }
    .sheet-input input { border: 0; outline: none; width: 100%; font-size: 14px; background: transparent; }

    /* 発信ボタン (Google Maps風) */
    .btn { padding: 12px 16px; min-height: 44px; border: 0; border-radius: 24px; background: var(--primary); color: #fff; font-weight: 500; font-size: 14px; box-shadow: 0 1px 3px rgba(60,64,67,.3); cursor: pointer; width: 100%; transition: background 0.2s, box-shadow 0.2s; }
    .btn:hover { background: var(--primary-h); box-shadow: 0 1px 3px rgba(60,64,67,.3), 0 4px 8px rgba(60,64,67,.15); }
    .btn:active { box-shadow: 0 1px 2px rgba(60,64,67,.2); }
    .btn:disabled { opacity: 0.4; cursor: not-allowed; box-shadow: none; }

    /* ステータス */
    .status { padding: 8px 12px; border-radius: 8px; background: #f1f3f4; font-size: 13px; color: var(--muted); }

    /* アクション */
    .actions { display: none; gap: 6px; flex-wrap: wrap; }
    .actions a { display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; border: 1px solid var(--border); border-radius: 20px; background: #fff; text-decoration: none; color: var(--muted); font-size: 11px; }

    /* 通話進行カード */
    .call-progress-card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 14px; margin: 0 4px 12px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .call-progress-header { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 600; color: var(--primary); margin-bottom: 10px; }
    .call-progress-close { margin-left: auto; background: none; border: none; cursor: pointer; color: var(--muted); font-size: 14px; padding: 4px; }
    .call-progress-status { font-size: 13px; color: #333; margin-bottom: 8px; }
    .call-progress-result { background: #e8f5e9; border-radius: 8px; padding: 10px 12px; margin-bottom: 8px; }

    /* 録音プレーヤー */
    .recording-player { display: none; padding: 10px; background: #f8f9fa; border: 1px solid var(--border); border-radius: 8px; }
    .recording-player.show { display: block; }
    .recording-player-header { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; font-size: 12px; font-weight: 500; }
    .recording-player-header i { color: #ea4335; }
    .recording-player audio { width: 100%; height: 36px; border-radius: 8px; }
    .recording-player-info { display: flex; align-items: center; justify-content: space-between; margin-top: 4px; font-size: 11px; color: var(--muted); }
    .recording-download { display: inline-flex; align-items: center; gap: 4px; color: var(--primary); text-decoration: none; font-size: 11px; font-weight: 500; }
    .recording-loading { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--muted); padding: 4px 0; }

    /* お気に入りチップ (Google Maps風) */
    .favorites-area { padding: 0 4px; }
    .favorites-list { display: flex; flex-wrap: nowrap; gap: 8px; overflow-x: auto; -webkit-overflow-scrolling: touch; padding: 4px 0; }
    .favorites-list::-webkit-scrollbar { display: none; }
    .fav-chip { display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; background: #fff; border: 1px solid var(--border); border-radius: 20px; font-size: 13px; font-weight: 500; cursor: pointer; transition: background 0.15s; white-space: nowrap; box-shadow: 0 1px 2px rgba(60,64,67,.1); }
    .fav-chip:hover { background: #f1f3f4; }
    .fav-chip .fav-call { color: var(--primary); }
    .fav-chip .fav-delete { color: var(--muted); font-size: 10px; margin-left: 2px; opacity: 0.4; }
    .fav-chip .fav-delete:hover { color: #ea4335; opacity: 1; }
    .fav-add-row { display: flex; gap: 6px; margin-top: 6px; }
    .fav-add-row .sheet-input { flex: 1; min-width: 0; padding: 6px 10px; }
    .fav-add-row .sheet-input input { font-size: 13px; }
    .fav-add-btn { padding: 6px 12px; border: 1px solid var(--border); border-radius: 20px; background: #fff; cursor: pointer; font-weight: 500; color: var(--primary); font-size: 13px; }

    /* テキストボタン */
    .text-btn { background: none; border: none; cursor: pointer; font-size: 12px; color: var(--primary); font-weight: 500; }
    .section-label { font-size: 12px; font-weight: 500; color: var(--muted); }

    /* ルートマップ */
    .map-container { display: none; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
    .map-header { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: #f8f9fa; border-bottom: 1px solid var(--border); }
    .map-title { font-weight: 500; font-size: 13px; }
    .map-close { background: none; border: none; color: var(--muted); cursor: pointer; font-size: 16px; padding: 4px; }
    .map-content { height: 200px; }
    .map-directions { max-height: 120px; overflow-y: auto; padding: 8px 12px; }
    .direction-step { padding: 4px 0; border-bottom: 1px solid #f1f3f4; font-size: 12px; line-height: 1.4; }
    .direction-step:last-child { border-bottom: none; }
    .direction-step .step-number { display: inline-block; width: 18px; height: 18px; background: var(--primary); color: #fff; border-radius: 50%; text-align: center; line-height: 18px; font-size: 10px; font-weight: 500; margin-right: 6px; }

    /* === マップ下のセクション（ボトムシート内に統合） === */
    .below-map { display: flex; flex-direction: column; gap: 10px; width: 100%; }

    /* カード */
    .card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: 0 1px 2px rgba(60,64,67,.1); padding: 16px; }
    .card > * + * { margin-top: 10px; }

    /* 入力 (below-map用) */
    .input { display: flex; align-items: center; gap: 10px; border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; background: #fff; }
    .input input { border: 0; outline: none; width: 100%; font-size: 14px; background: transparent; }
    .input.compact { padding: 8px 10px; }
    .input.compact input { font-size: 13px; }

    /* 折りたたみカード */
    .collapse-card { padding: 0; }
    .collapse-header { padding: 14px 16px; font-size: 14px; font-weight: 500; cursor: pointer; list-style: none; display: flex; align-items: center; gap: 8px; }
    .collapse-header::-webkit-details-marker { display: none; }
    .collapse-header::after { content: '\25B8'; margin-left: auto; color: var(--muted); font-size: 12px; transition: transform 0.2s; }
    details[open] > .collapse-header::after { transform: rotate(90deg); }
    .collapse-body { padding: 0 16px 16px; }

    /* 統計 */
    .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .stats-box { background: #f8f9fa; padding: 14px; border-radius: 8px; text-align: center; }
    .stats-label { font-size: 12px; color: var(--muted); font-weight: 500; margin-bottom: 4px; }
    .stats-value { font-size: 22px; font-weight: 500; color: var(--primary); }
    .stats-sub { font-size: 11px; color: var(--muted); margin-top: 2px; }

    /* 通話履歴 */
    .history-item-wrap { margin-bottom: 6px; }
    .history-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; background: #fff; cursor: pointer; transition: background .15s; }
    .history-item-wrap.expanded .history-item { border-radius: 8px 8px 0 0; border-bottom: none; background: #f8faff; }
    .history-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0; }
    .history-icon.open { background: #e6f4ea; color: #1e8e3e; }
    .history-icon.closed { background: #fce8e6; color: #d93025; }
    .history-icon.unknown { background: #fef7e0; color: #e37400; }
    .history-icon.failed { background: #f1f3f4; color: #9aa0a6; }
    .history-body { flex: 1; min-width: 0; }
    .history-phone { font-weight: 500; font-size: 13px; }
    .history-detail { font-size: 11px; color: var(--muted); margin-top: 1px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .history-time { font-size: 10px; color: var(--muted); white-space: nowrap; }
    .history-actions { display: flex; gap: 4px; flex-shrink: 0; }
    .history-btn { width: 28px; height: 28px; border: 1px solid var(--border); border-radius: 50%; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 11px; color: var(--muted); }
    .history-btn:hover { color: var(--primary); border-color: var(--primary); background: #e8f0fe; }
    .history-btn.fav-toggle.is-fav { color: #fbbc04; border-color: #fbbc04; }
    .history-expand { display: none; border: 1px solid var(--border); border-top: none; border-radius: 0 0 8px 8px; background: #fafbfc; padding: 12px; }
    .history-item-wrap.expanded .history-expand { display: block; }
    .history-expand-row { font-size: 13px; margin-bottom: 6px; color: #333; }
    .history-expand-row i { width: 16px; text-align: center; margin-right: 6px; color: var(--muted); }
    .history-expand-label { font-size: 11px; color: var(--muted); }
    .history-expand-summary { font-size: 12px; color: #555; background: #f0f2f5; padding: 8px 10px; border-radius: 6px; margin: 8px 0; line-height: 1.5; }
    .history-expand .recording-player { margin-top: 8px; }
    .history-expand-loading { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--muted); padding: 6px 0; }
    .history-empty { text-align: center; padding: 20px; color: var(--muted); font-size: 13px; }
    .history-more { text-align: center; margin-top: 6px; }
    .history-more button { padding: 6px 16px; border: 1px solid var(--border); border-radius: 20px; background: #fff; cursor: pointer; font-weight: 500; color: var(--muted); font-size: 12px; }

    /* === 予約パネル === */
    .reservation-panel { display: none; position: fixed; bottom: 0; left: 0; right: 0; z-index: 12; background: #fff; border-radius: 14px 14px 0 0; box-shadow: 0 -2px 10px rgba(0,0,0,.15); overflow: hidden; flex-direction: column; transition: height 0.3s cubic-bezier(.4,0,.2,1); will-change: height; }
    .reservation-panel.show { display: flex; }
    .reservation-panel.sheet-peek { height: 35vh; }
    .reservation-panel.sheet-half { height: 65vh; }
    .reservation-panel.sheet-full { height: 90vh; }
    .rsv-label { font-size: 12px; font-weight: 500; color: var(--muted); display: block; margin-bottom: 4px; }
    .rsv-input { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; background: #fff; color: var(--text); box-sizing: border-box; }
    .rsv-input:focus { border-color: var(--primary); outline: none; }
    .sc-reserve-btn { background: #e67e22 !important; color: #fff !important; border: none !important; }
    .sc-reserve-btn:hover { background: #d35400 !important; }
    .pd-reserve-btn { background: #e67e22 !important; color: #fff !important; border: none !important; }
    .pd-reserve-btn:hover { background: #d35400 !important; }
    .rsv-result-card { padding: 16px; border-radius: 12px; text-align: center; margin-top: 12px; }
    .rsv-result-card.confirmed { background: #e6f4ea; border: 1px solid #1e8e3e; }
    .rsv-result-card.rejected { background: #fce8e6; border: 1px solid #d93025; }
    .rsv-result-card.unknown { background: #fef7e0; border: 1px solid #e37400; }
    .rsv-result-card .rsv-result-icon { font-size: 32px; margin-bottom: 8px; }
    .rsv-result-card .rsv-result-title { font-size: 16px; font-weight: 500; margin-bottom: 4px; }
    .rsv-result-card .rsv-result-detail { font-size: 13px; color: var(--muted); }

    /* 予約一覧 */
    .rsv-history-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; background: #fff; margin-bottom: 6px; }
    .rsv-history-item.past { opacity: 0.5; }
    .rsv-history-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0; }
    .rsv-history-icon.confirmed { background: #e6f4ea; color: #1e8e3e; }
    .rsv-history-icon.rejected { background: #fce8e6; color: #d93025; }
    .rsv-history-icon.unknown { background: #fef7e0; color: #e37400; }
    .rsv-history-body { flex: 1; min-width: 0; }
    .rsv-history-name { font-weight: 500; font-size: 13px; }
    .rsv-history-detail { font-size: 11px; color: var(--muted); margin-top: 1px; }
    .rsv-history-play { width: 28px; height: 28px; border: 1px solid #1a73e8; border-radius: 50%; background: #e8f0fe; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 11px; color: #1a73e8; flex-shrink: 0; }
    .rsv-history-play:hover { background: #1a73e8; color: #fff; }
    .rsv-history-play.playing { background: #1a73e8; color: #fff; }
    .rsv-history-delete { width: 28px; height: 28px; border: 1px solid var(--border); border-radius: 50%; background: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 11px; color: var(--muted); flex-shrink: 0; }
    .rsv-history-delete:hover { color: #d93025; border-color: #d93025; background: #fce8e6; }

    /* サポートチャット */
    .support-tabs { display: flex; gap: 4px; margin-bottom: 10px; }
    .support-tab { flex: 1; padding: 6px 0; border: 1px solid var(--border); border-radius: 6px; background: #fff; font-size: 11px; cursor: pointer; text-align: center; color: var(--text); }
    .support-tab.active { border-color: #1a73e8; background: #e8f0fe; color: #1a73e8; font-weight: 600; }
    .support-tab[data-type="bug"].active { border-color: #d93025; background: #fce8e6; color: #d93025; }
    .support-tab[data-type="improvement"].active { border-color: #1e8e3e; background: #e6f4ea; color: #1e8e3e; }
    .support-msgs { max-height: 280px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; margin-bottom: 10px; padding: 4px 0; }
    .support-msg { max-width: 85%; padding: 8px 12px; border-radius: 12px; font-size: 13px; line-height: 1.5; word-break: break-word; }
    .support-msg.user { align-self: flex-end; background: #1a73e8; color: #fff; border-bottom-right-radius: 4px; }
    .support-msg.ai { align-self: flex-start; background: #f1f3f4; color: var(--text); border-bottom-left-radius: 4px; }
    .support-msg.welcome { align-self: center; background: none; color: var(--muted); font-size: 12px; text-align: center; padding: 12px; }
    .support-input { display: flex; gap: 6px; }
    .support-input input { flex: 1; padding: 8px 12px; border: 1px solid var(--border); border-radius: 20px; font-size: 13px; outline: none; }
    .support-input input:focus { border-color: #1a73e8; }
    .support-input button { width: 36px; height: 36px; border: none; border-radius: 50%; background: #1a73e8; color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
    .support-input button:disabled { background: #ccc; cursor: default; }
    .support-new-btn { border: none; background: none; color: #1a73e8; font-size: 11px; cursor: pointer; padding: 2px 0; }
    .support-new-btn:hover { text-decoration: underline; }

    /* 管理画面オーバーレイ */
    .admin-overlay { display: none; position: fixed; inset: 0; z-index: 9999; background: #f8f9fa; overflow-y: auto; }
    .admin-overlay.show { display: block; }
    .admin-header { position: sticky; top: 0; background: #fff; padding: 12px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; z-index: 1; }
    .admin-header h2 { font-size: 16px; font-weight: 600; margin: 0; flex: 1; }
    .admin-close { border: none; background: none; font-size: 20px; cursor: pointer; color: var(--muted); padding: 4px; }
    .admin-filter { display: flex; gap: 4px; padding: 10px 16px; flex-wrap: wrap; }
    .admin-filter-btn { padding: 4px 10px; border: 1px solid var(--border); border-radius: 12px; background: #fff; font-size: 11px; cursor: pointer; }
    .admin-filter-btn.active { background: #1a73e8; color: #fff; border-color: #1a73e8; }
    .admin-list { padding: 0 16px 16px; }
    .admin-item { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 12px; margin-bottom: 8px; cursor: pointer; }
    .admin-item:hover { border-color: #1a73e8; }
    .admin-item.resolved { opacity: 0.5; }
    .admin-item-top { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
    .admin-type-badge { padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; }
    .admin-type-badge.question { background: #e8f0fe; color: #1a73e8; }
    .admin-type-badge.bug { background: #fce8e6; color: #d93025; }
    .admin-type-badge.improvement { background: #e6f4ea; color: #1e8e3e; }
    .admin-item-user { font-size: 11px; color: var(--muted); }
    .admin-item-date { font-size: 10px; color: var(--muted); margin-left: auto; }
    .admin-item-summary { font-size: 13px; font-weight: 500; }
    .admin-item-last { font-size: 11px; color: var(--muted); margin-top: 2px; }
    .admin-detail { padding: 16px; }
    .admin-detail-back { border: none; background: none; color: #1a73e8; font-size: 13px; cursor: pointer; margin-bottom: 10px; }
    .admin-detail-msgs { display: flex; flex-direction: column; gap: 6px; }
    .admin-resolve-btn { margin-top: 12px; padding: 8px 16px; border: 1px solid #1e8e3e; border-radius: 8px; background: #e6f4ea; color: #1e8e3e; font-size: 13px; cursor: pointer; }
    .admin-resolve-btn:hover { background: #1e8e3e; color: #fff; }

    /* ログアウトリンク */
    .logout-link { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 14px 0 20px; color: var(--muted); font-size: 13px; text-decoration: none; cursor: pointer; }
    .logout-link:hover { color: #d93025; }

    /* InfoWindow */
    .gm-style-iw-ch { padding-top: 0 !important; }
    .gm-style .gm-style-iw-d { overflow: auto !important; max-height: 420px !important; }
    .gm-style .gm-style-iw-c { padding: 12px !important; }

    @media (max-width: 767px) {
      .store-suggestions { max-height: 45vh; }
      .bottom-sheet.sheet-half { height: 40vh; }
    }
  </style>
</head>
<body>
  <main>
    <!-- ===== フルスクリーンマップ ===== -->
    <div class="map-section">
      <div id="bgMap"></div>
      <div class="map-controls">
        <button id="mapMyLoc" class="map-ctrl-btn" title="現在地"><svg viewBox="0 0 24 24"><path d="M12 8c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm8.94 3A8.994 8.994 0 0 0 13 3.06V1h-2v2.06A8.994 8.994 0 0 0 3.06 11H1v2h2.06A8.994 8.994 0 0 0 11 20.94V23h2v-2.06A8.994 8.994 0 0 0 20.94 13H23v-2h-2.06zM12 19c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7z"/></svg></button>
        <div class="zoom-group">
          <button id="mapZoomIn" title="拡大"><svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg></button>
          <button id="mapZoomOut" title="縮小"><svg viewBox="0 0 24 24"><path d="M19 13H5v-2h14v2z"/></svg></button>
        </div>
      </div>

      <!-- 上部: 検索バー (Google Maps風) -->
      <div class="search-bar-area">
        <div class="search-bar" id="searchBar">
          <i class="fa-solid fa-magnifying-glass search-icon"></i>
          <button class="back-btn" id="searchBackBtn"><i class="fa-solid fa-arrow-left"></i></button>
          <input id="name" placeholder="店名か電話番号を入力して検索">
        </div>
        <!-- お気に入りチップ (検索バー下に横スクロール) -->
        <div id="favoritesSection" class="favorites-area" style="display: none; margin-top: 8px;">
          <div id="favoritesList" class="favorites-list"></div>
          <div id="favAddForm" style="display: none;" class="fav-add-row">
            <div class="sheet-input" style="flex:1;min-width:0;"><input id="favName" placeholder="店名"></div>
            <div class="sheet-input" style="flex:1;min-width:0;"><input id="favPhone" placeholder="電話番号"></div>
            <button id="favAddBtn" class="fav-add-btn"><i class="fa-solid fa-check"></i></button>
          </div>
        </div>
      </div>

      <!-- 下部: ボトムシート (Google Maps風) -->
      <div class="bottom-sheet sheet-peek" id="homeSheet">
        <div class="sheet-drag" id="homeSheetDrag">
          <div class="sheet-handle"></div>
        </div>
        <div class="sheet-content" id="homeSheetContent">

          <!-- 電話番号＋発信ボタン（非表示・内部で使用） -->
          <div style="display:none;">
            <input id="to">
            <button id="callBtn"></button>
            <div id="actions"><a id="viewLink"></a><a id="twimlLink"></a><a id="openRecordingLink"></a><a id="hoursRecordingLink"></a></div>
          </div>

          <!-- 通話進行カード -->
          <div id="callProgressCard" class="call-progress-card" style="display:none;">
            <div class="call-progress-header">
              <i class="fa-solid fa-phone-volume"></i> 営業確認中
              <button id="callProgressClose" class="call-progress-close"><i class="fa-solid fa-times"></i></button>
            </div>
            <div id="status" class="call-progress-status"></div>
            <div id="resultCard" class="call-progress-result" style="display:none;"><div id="resultText"></div></div>
            <div id="recordingPlayer" class="recording-player">
              <div class="recording-player-header"><i class="fa-solid fa-circle-play"></i> 通話録音</div>
              <div id="recordingLoading" class="recording-loading"><i class="fa-solid fa-spinner fa-spin"></i> 録音データを読み込み中…</div>
              <audio id="recordingAudio" controls preload="none"></audio>
              <div id="recordingInfo" class="recording-player-info">
                <span id="recordingDuration"></span>
                <a id="recordingDownload" class="recording-download" download><i class="fa-solid fa-download"></i> DL</a>
              </div>
            </div>
          </div>

          <!-- ルートマップ（非表示） -->
          <div id="mapContainer" class="map-container">
            <div class="map-header">
              <div class="map-title" id="mapTitle">店舗へのルート</div>
              <button class="map-close" id="mapClose"><i class="fa-solid fa-times"></i></button>
            </div>
            <div class="map-content"><div id="map" style="width:100%;height:100%;"></div></div>
            <div class="map-directions" id="mapDirections"></div>
          </div>

          <!-- === マップ下のセクション（ボトムシート内に統合） === -->
          <div class="below-map">
            <span id="usageCount" style="display:none;"></span>
            <!-- 統計は管理画面に移動 -->
            <span id="todaySuccess" style="display:none;"></span>
            <span id="todayTotal" style="display:none;"></span>
            <span id="todayRate" style="display:none;"></span>
            <span id="yesterdaySuccess" style="display:none;"></span>
            <span id="yesterdayTotal" style="display:none;"></span>
            <span id="yesterdayRate" style="display:none;"></span>
            <details class="card collapse-card" id="rsvHistoryCard">
              <summary class="collapse-header"><i class="fa-solid fa-calendar-check"></i> 予約一覧</summary>
              <div class="collapse-body">
                <div id="rsvHistoryList"></div>
              </div>
            </details>
            <details class="card collapse-card" open>
              <summary class="collapse-header"><i class="fa-solid fa-clock-rotate-left"></i> 通話履歴</summary>
              <div class="collapse-body">
                <div class="input compact" style="margin-bottom: 12px;">
                  <i class="fa-solid fa-search" style="color: var(--muted);"></i>
                  <input id="historySearch" placeholder="検索…">
                </div>
                <div id="historyList"></div>
                <div id="historyMore" class="history-more" style="display: none;">
                  <button id="historyMoreBtn"><i class="fa-solid fa-angles-down"></i> もっと見る</button>
                </div>
              </div>
            </details>
            <details class="card collapse-card" id="supportCard" style="display:none;">
              <summary class="collapse-header"><i class="fa-solid fa-headset"></i> サポート</summary>
              <div class="collapse-body">
                <div class="support-tabs">
                  <button class="support-tab active" data-type="question">質問</button>
                  <button class="support-tab" data-type="bug">バグ報告</button>
                  <button class="support-tab" data-type="improvement">改善提案</button>
                </div>
                <div id="supportMsgs" class="support-msgs">
                  <div class="support-msg welcome"><i class="fa-solid fa-robot"></i><br>質問・バグ報告・改善提案をお気軽にどうぞ</div>
                </div>
                <div class="support-input">
                  <input id="supportInput" placeholder="メッセージを入力…" />
                  <button id="supportSend"><i class="fa-solid fa-paper-plane"></i></button>
                </div>
                <button class="support-new-btn" id="supportNewBtn">+ 新しい会話</button>
              </div>
            </details>
            <a href="?logout=1" class="logout-link"><i class="fa-solid fa-arrow-right-from-bracket"></i> ログアウト</a>
          </div>
        </div>
      </div>
    </div><!-- /map-section -->

    <!-- ===== 検索結果リスト (Google Maps風) ===== -->
    <div id="storeSuggestions" class="search-results"></div>

    <!-- ===== 店舗詳細パネル (ピンタップ時) ===== -->
    <div id="placeDetailPanel" class="place-detail-panel">
      <div class="results-drag" id="placeDetailDrag"><div class="results-drag-bar"></div></div>
      <div class="place-detail-header">
        <button class="place-detail-back" id="placeDetailBack">←</button>
        <div class="place-detail-title" id="placeDetailTitle"></div>
      </div>
      <div class="place-detail-body" id="placeDetailBody"></div>
    </div>

    <!-- ===== 予約パネル ===== -->
    <div id="reservationPanel" class="reservation-panel">
      <div class="results-drag" id="reservationDrag"><div class="results-drag-bar"></div></div>
      <div class="place-detail-header">
        <button class="place-detail-back" id="reservationBack">&larr;</button>
        <div class="place-detail-title" id="reservationTitle">予約</div>
      </div>
      <div class="place-detail-body" id="reservationBody">
        <div class="pd-name" id="rsvStoreName"></div>
        <div class="pd-meta" id="rsvStorePhone" style="margin-bottom:8px;"></div>
        <form id="reservationForm" style="display:flex;flex-direction:column;gap:12px;">
          <div>
            <label class="rsv-label">日付 *</label>
            <input type="date" id="rsvDate" class="rsv-input" required>
          </div>
          <div>
            <label class="rsv-label">時間 *</label>
            <input type="time" id="rsvTime" class="rsv-input" required>
          </div>
          <div>
            <label class="rsv-label">人数 *</label>
            <input type="number" id="rsvPartySize" class="rsv-input" min="1" max="99" value="2" required>
          </div>
          <div>
            <label class="rsv-label">予約者名（ひらがな） *</label>
            <input type="text" id="rsvName" class="rsv-input" placeholder="やまだたろう" required pattern="[\u3040-\u309F\u30FC\s　]+" title="ひらがなで入力してください">
          </div>
          <div>
            <label class="rsv-label">連絡先電話番号 *</label>
            <input type="tel" id="rsvPhone" class="rsv-input" placeholder="090-1234-5678" required>
          </div>
          <div style="display:flex;align-items:center;gap:8px;padding:4px 0;">
            <input type="checkbox" id="rsvFlexible" checked style="width:18px;height:18px;flex-shrink:0;accent-color:var(--primary);cursor:pointer;">
            <label for="rsvFlexible" style="font-size:13px;color:var(--text);cursor:pointer;line-height:1.4;">指定時間が空いていない場合、前後の近い時間で予約を試みる</label>
          </div>
          <div id="rsvFlexRange" style="display:flex;flex-direction:column;gap:8px;padding:4px 0 0 26px;">
            <div style="display:flex;align-items:center;gap:8px;">
              <label style="font-size:13px;color:var(--text);white-space:nowrap;min-width:80px;">予約時間前</label>
              <select id="rsvFlexBefore" class="rsv-input" style="flex:1;padding:6px 8px;font-size:14px;">
                <option value="30">30分</option>
                <option value="60" selected>1時間</option>
                <option value="90">1時間30分</option>
                <option value="120">2時間</option>
                <option value="150">2時間30分</option>
                <option value="180">3時間</option>
              </select>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
              <label style="font-size:13px;color:var(--text);white-space:nowrap;min-width:80px;">予約時間後</label>
              <select id="rsvFlexAfter" class="rsv-input" style="flex:1;padding:6px 8px;font-size:14px;">
                <option value="30">30分</option>
                <option value="60" selected>1時間</option>
                <option value="90">1時間30分</option>
                <option value="120">2時間</option>
                <option value="150">2時間30分</option>
                <option value="180">3時間</option>
              </select>
            </div>
          </div>
          <button type="submit" id="rsvSubmitBtn" class="btn" style="background:#e67e22;">
            <i class="fa-solid fa-phone-volume"></i> 予約電話をかける
          </button>
        </form>
        <div id="rsvStatus" class="status" style="display:none;margin-top:12px;"></div>
        <div id="rsvResultCard" class="rsv-result-card" style="display:none;"></div>
        <div id="rsvRecordingPlayer" class="recording-player" style="margin-top:12px;">
          <div class="recording-player-header"><i class="fa-solid fa-circle-play"></i> 通話録音</div>
          <div id="rsvRecordingLoading" class="recording-loading"><i class="fa-solid fa-spinner fa-spin"></i> 録音データを読み込み中…</div>
          <audio id="rsvRecordingAudio" controls preload="none" style="display:none;"></audio>
          <div id="rsvRecordingInfo" class="recording-player-info" style="display:none;">
            <span id="rsvRecordingDuration"></span>
            <a id="rsvRecordingDownload" class="recording-download" download><i class="fa-solid fa-download"></i> DL</a>
          </div>
        </div>
      </div>
    </div>

    <!-- below-map is now inside .sheet-content -->
  </main>

<script>
// DOM要素の取得
const $ = (s) => document.querySelector(s);
const nameEl = $('#name');
const toEl = $('#to');
const btn = $('#callBtn');
const statusEl = $('#status');
const actionsEl = $('#actions');
const viewLink = $('#viewLink');
const twimlLink = $('#twimlLink');
const resultText = $('#resultText');
const storeSuggestions = $('#storeSuggestions');
const openRecordingLink = $('#openRecordingLink');
const hoursRecordingLink = $('#hoursRecordingLink');
const usageCount = $('#usageCount');
const mapContainer = $('#mapContainer');
const mapTitle = $('#mapTitle');
const mapClose = $('#mapClose');
const mapDirections = $('#mapDirections');
const recordingPlayer = $('#recordingPlayer');
const recordingAudio = $('#recordingAudio');
const recordingLoading = $('#recordingLoading');
const recordingInfo = $('#recordingInfo');
const recordingDuration = $('#recordingDuration');
const recordingDownload = $('#recordingDownload');

// 統計表示用の要素
const todaySuccess = $('#todaySuccess');
const todayTotal = $('#todayTotal');
const todayRate = $('#todayRate');
const yesterdaySuccess = $('#yesterdaySuccess');
const yesterdayTotal = $('#yesterdayTotal');
const yesterdayRate = $('#yesterdayRate');

// グローバル変数
let currentSid = null;
let pollTimer = null;
let searchTimeout = null;
let userLocation = null;
let map = null;
let directionsService = null;
let directionsRenderer = null;
let currentStoreInfo = null;
let recordingPollTimer = null;
let recordingPlayerShown = false;

// ボタンの有効/無効切り替え
function toActive(v) {
    btn.disabled = !v;
}

// ★ ボタンを元の状態に戻す
function resetCallBtn() {
    btn.innerHTML = '<i class="fa-solid fa-phone-volume"></i> 発信する';
    btn.style.background = '';
}

// 電話番号入力時のイベント
toEl.addEventListener('input', () => {
    toActive(!!toEl.value.trim());
});

/**
 * 利用回数を取得する関数
 */
async function getUsageCount() {
    try {
        const response = await fetch('usage_counter');
        const data = await response.json();
        usageCount.textContent = data.count;
    } catch (error) {
        console.log('利用回数取得エラー:', error);
        usageCount.textContent = '-';
    }
}

/**
 * 利用回数を増加させる関数
 */
async function incrementUsageCount() {
    try {
        const response = await fetch('usage_counter', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            }
        });
        const data = await response.json();
        usageCount.textContent = data.count;
    } catch (error) {
        console.log('利用回数更新エラー:', error);
    }
}

// 統計データを取得する関数
async function getStatsData() {
  try {
    const response = await fetch('stats');
    const data = await response.json();
    
    console.log('統計データ:', data); // デバッグ用
    
    // 今日の統計を表示
    todaySuccess.textContent = data.today.success;
    todayTotal.textContent = data.today.total;
    todayRate.textContent = data.today.success_rate;
    
    // 昨日の統計を表示
    yesterdaySuccess.textContent = data.yesterday.success;
    yesterdayTotal.textContent = data.yesterday.total;
    yesterdayRate.textContent = data.yesterday.success_rate;
  } catch (error) {
    console.log('統計データ取得エラー:', error);
    // エラー時はデフォルト値を表示
    todaySuccess.textContent = '-';
    todayTotal.textContent = '-';
    todayRate.textContent = '-';
    yesterdaySuccess.textContent = '-';
    yesterdayTotal.textContent = '-';
    yesterdayRate.textContent = '-';
  }
}

// ページ読み込み時に利用回数と統計データを取得
getUsageCount();
getStatsData();

// 背景マップ初期化
let bgMap = null;
let bgMapMarker = null;
let bgMapStoreMarkers = []; // 検索結果マーカー
const bgMapEl = document.getElementById('bgMap');
bgMapEl.style.visibility = 'hidden'; // 位置確定までマップ非表示

function initBgMap(center) {
  if (bgMap) return;

  bgMap = new google.maps.Map(bgMapEl, {
    zoom: 15,
    center: center,
    mapTypeId: google.maps.MapTypeId.ROADMAP,
    disableDefaultUI: true,
    zoomControl: false,
    gestureHandling: 'greedy',
    clickableIcons: true
  });
  bgMapEl.style.visibility = '';

  // カスタムズーム＋現在地ボタン（スロットルで連打による画面崩れ防止）
  let zoomThrottle = 0;
  const throttledZoom = (delta) => {
    const now = Date.now();
    if (now - zoomThrottle < 200) return;
    zoomThrottle = now;
    bgMap.setZoom(bgMap.getZoom() + delta);
  };
  document.getElementById('mapZoomIn').addEventListener('click', () => throttledZoom(1));
  document.getElementById('mapZoomOut').addEventListener('click', () => throttledZoom(-1));
  document.getElementById('mapMyLoc').addEventListener('click', () => {
    getUserLocation().then((loc) => {
      const pos = { lat: loc.lat, lng: loc.lng };
      bgMap.panTo(pos);
      bgMap.setZoom(15);
      if (bgMapMarker) bgMapMarker.setPosition(pos);
    }).catch(() => alert('位置情報を取得できません'));
  });

  // 現在地マーカーを表示
  if (userLocation) {
    const pos = { lat: userLocation.lat, lng: userLocation.lng };
    bgMapMarker = new google.maps.Marker({
      position: pos,
      map: bgMap,
      title: '現在地',
      icon: {
        path: google.maps.SymbolPath.CIRCLE,
        scale: 10,
        fillColor: '#007AFF',
        fillOpacity: 1,
        strokeColor: '#fff',
        strokeWeight: 3
      }
    });
  }
}

// ピン横ラベル用オーバーレイ
class PinLabel extends google.maps.OverlayView {
  constructor(pos, text, map) {
    super();
    this.pos = pos;
    this.text = text;
    this.div = null;
    this.setMap(map);
  }
  onAdd() {
    this.div = document.createElement('div');
    this.div.className = 'pin-label';
    this.div.textContent = this.text;
    this.getPanes().overlayLayer.appendChild(this.div);
  }
  draw() {
    const p = this.getProjection().fromLatLngToDivPixel(this.pos);
    if (this.div) { this.div.style.left = (p.x + 12) + 'px'; this.div.style.top = (p.y - 8) + 'px'; }
  }
  onRemove() { if (this.div) { this.div.remove(); this.div = null; } }
}

let bgMapPinLabels = [];

// ===== Google Maps風ボトムシートドラッグ =====
function setupSheetDrag(sheetEl, handleEl, options = {}) {
  const snapVh = options.snapPoints || [35, 55, 85];
  const onClose = options.onClose || null;
  const scrollList = options.scrollList || null;
  const scrollLockUnlessFull = options.scrollLockUnlessFull || false;

  // --- 状態変数 ---
  let dragging = false;       // ハンドルドラッグ中
  let sheetDragging = false;  // リスト領域からのシートドラッグ中
  let startY = 0, startH = 0;
  let rafId = 0, pendingH = 0;
  let didDrag = false;
  // フリック速度
  let velocityY = 0, lastY = 0, lastTime = 0;
  // リスト用
  let touchId = null;

  function isSheetFull() { return sheetEl.classList.contains('sheet-full'); }

  function applySnapClass(snapIdx) {
    sheetEl.classList.remove('sheet-peek', 'sheet-half', 'sheet-full');
    if (snapIdx === 0) sheetEl.classList.add('sheet-peek');
    else if (snapIdx >= snapVh.length - 1) sheetEl.classList.add('sheet-full');
    else sheetEl.classList.add('sheet-half');
    // full未満ではスクロール位置リセット
    if (scrollList && snapIdx < snapVh.length - 1) {
      scrollList.scrollTop = 0;
    }
  }

  function snapTo(currentH, flickDir) {
    const vh = window.innerHeight;
    // 最小スナップの半分以下 → close
    if (currentH < vh * snapVh[0] / 200 && onClose) { onClose(); return; }
    // 最も近いスナップを探す
    let bestIdx = 0, bestDist = Infinity;
    snapVh.forEach((sp, i) => {
      const d = Math.abs(currentH - vh * sp / 100);
      if (d < bestDist) { bestDist = d; bestIdx = i; }
    });
    // フリック: 素早いスワイプなら方向にバイアス
    if (flickDir !== 0) {
      const biased = bestIdx + flickDir;
      if (biased >= 0 && biased < snapVh.length) bestIdx = biased;
    }
    // アニメーション: 現在の高さからスナップへCSSトランジション
    sheetEl.style.height = currentH + 'px';
    sheetEl.style.transition = '';
    requestAnimationFrame(() => {
      applySnapClass(bestIdx);
      requestAnimationFrame(() => { sheetEl.style.height = ''; });
    });
  }

  function getFlickDir() {
    // velocityY: 正=下方向（縮小）、負=上方向（拡大）
    if (Math.abs(velocityY) > 0.3) return velocityY > 0 ? -1 : 1;
    return 0;
  }

  function trackVelocity(y) {
    const now = Date.now();
    const dt = now - lastTime;
    if (dt > 10) {
      velocityY = (y - lastY) / dt;
      lastY = y;
      lastTime = now;
    }
  }

  function clampH(h) {
    return Math.max(0, Math.min(window.innerHeight * 0.95, h));
  }

  // ========================================
  // ハンドルからのドラッグ（常にシート移動）
  // ========================================
  function onHandleStart(e) {
    const touch = e.touches ? e.touches[0] : e;
    startY = touch.clientY;
    startH = sheetEl.getBoundingClientRect().height;
    dragging = true;
    didDrag = false;
    velocityY = 0; lastY = startY; lastTime = Date.now();
    sheetEl.style.transition = 'none';
    document.addEventListener('touchmove', onHandleMove, { passive: true });
    document.addEventListener('touchend', onHandleEnd);
    document.addEventListener('mousemove', onHandleMove);
    document.addEventListener('mouseup', onHandleEnd);
  }

  function onHandleMove(e) {
    if (!dragging) return;
    const touch = e.touches ? e.touches[0] : e;
    const dy = startY - touch.clientY;
    if (Math.abs(dy) > 3) didDrag = true;
    trackVelocity(touch.clientY);
    pendingH = clampH(startH + dy);
    if (!rafId) {
      rafId = requestAnimationFrame(() => {
        sheetEl.style.height = pendingH + 'px';
        rafId = 0;
      });
    }
  }

  function onHandleEnd() {
    if (!dragging) return;
    dragging = false;
    if (rafId) { cancelAnimationFrame(rafId); rafId = 0; }
    document.removeEventListener('touchmove', onHandleMove);
    document.removeEventListener('touchend', onHandleEnd);
    document.removeEventListener('mousemove', onHandleMove);
    document.removeEventListener('mouseup', onHandleEnd);
    if (didDrag) {
      snapTo(sheetEl.getBoundingClientRect().height, getFlickDir());
    } else {
      sheetEl.style.transition = '';
    }
  }

  handleEl.addEventListener('touchstart', onHandleStart, { passive: true });
  handleEl.addEventListener('mousedown', onHandleStart);
  handleEl.addEventListener('click', (e) => {
    if (didDrag) { e.stopImmediatePropagation(); didDrag = false; }
  }, true);

  // ========================================
  // リスト領域のタッチ: ネストスクロール制御
  // ========================================
  if (scrollList) {
    const DRAG_THRESHOLD = 6; // px移動でドラッグ判定

    scrollList.addEventListener('touchstart', (e) => {
      if (e.touches.length !== 1) return;
      touchId = e.touches[0].identifier;
      startY = e.touches[0].clientY;
      startH = sheetEl.getBoundingClientRect().height;
      sheetDragging = false;
      didDrag = false;
      velocityY = 0; lastY = startY; lastTime = Date.now();
    }, { passive: true });

    scrollList.addEventListener('touchmove', (e) => {
      if (touchId === null) return;
      const t = Array.from(e.touches).find(tt => tt.identifier === touchId);
      if (!t) return;
      const dy = t.clientY - startY; // 正=下スワイプ, 負=上スワイプ
      trackVelocity(t.clientY);

      // 既にシートドラッグモードに入っている
      if (sheetDragging) {
        e.preventDefault();
        const newH = clampH(startH - (t.clientY - startY));
        if (!rafId) {
          rafId = requestAnimationFrame(() => {
            sheetEl.style.height = newH + 'px';
            rafId = 0;
          });
        }
        return;
      }

      // --- ドラッグモード判定 ---
      const isFull = isSheetFull();

      // (A) full未満 → 常にシートドラッグ
      if (!isFull) {
        if (Math.abs(dy) > DRAG_THRESHOLD) {
          sheetDragging = true;
          didDrag = true;
          sheetEl.style.transition = 'none';
          startH = sheetEl.getBoundingClientRect().height;
          startY = t.clientY;
          e.preventDefault();
        }
        return;
      }

      // (B) full + scrollTop===0 + 下スワイプ → シートを引き下げる
      if (isFull && scrollList.scrollTop <= 0 && dy > DRAG_THRESHOLD) {
        sheetDragging = true;
        didDrag = true;
        sheetEl.style.transition = 'none';
        startH = sheetEl.getBoundingClientRect().height;
        startY = t.clientY;
        e.preventDefault();
        return;
      }

      // (C) full → 通常のリスト内スクロール（何もしない = ブラウザ標準スクロール）

    }, { passive: false });

    const endSheetDrag = () => {
      if (sheetDragging) {
        sheetDragging = false;
        if (rafId) { cancelAnimationFrame(rafId); rafId = 0; }
        snapTo(sheetEl.getBoundingClientRect().height, getFlickDir());
      }
      touchId = null;
    };
    scrollList.addEventListener('touchend', endSheetDrag, { passive: true });
    scrollList.addEventListener('touchcancel', endSheetDrag, { passive: true });
  }
}

// 検索結果マーカーをマップに表示
function updateBgMapMarkers(stores, append = false) {
  if (!bgMap) return;
  if (!append) {
    bgMapStoreMarkers.forEach(m => m.setMap(null));
    bgMapStoreMarkers = [];
    bgMapPinLabels.forEach(l => l.setMap(null));
    bgMapPinLabels = [];
  }
  const bounds = new google.maps.LatLngBounds();
  let hasNewMarkers = false;
  let markerNum = append ? bgMapStoreMarkers.length : 0;
  stores.forEach((store, idx) => {
    if (!store.lat || !store.lng) return;
    markerNum++;
    const pos = new google.maps.LatLng(parseFloat(store.lat), parseFloat(store.lng));
    const marker = new google.maps.Marker({
      position: pos,
      map: bgMap,
      title: store.name,
      icon: { url: 'https://maps.google.com/mapfiles/ms/icons/red-dot.png' }
    });
    marker.storeData = Object.assign({}, store);
    marker.storeData._markerNum = markerNum;
    (function(m) {
      m.addListener('click', function() {
        openStoreInfoWindow(m, m.storeData);
      });
    })(marker);
    bgMapStoreMarkers.push(marker);
    // ピン横に店名ラベル
    bgMapPinLabels.push(new PinLabel(pos, store.name, bgMap));
    bounds.extend(pos);
    hasNewMarkers = true;
  });
  console.log('[MARKERS] total: ' + bgMapStoreMarkers.length);

  // ★ マーカークリック: 個別listenerではなくマップクリックで最寄りマーカーを特定
  if (!bgMap._pinClickRegistered) {
    bgMap._pinClickRegistered = true;
    bgMap.addListener('click', (e) => {
      const clickLat = e.latLng.lat();
      const clickLng = e.latLng.lng();
      let best = null, bestDist = Infinity;
      bgMapStoreMarkers.forEach(m => {
        const p = m.getPosition();
        const d = google.maps.geometry.spherical.computeDistanceBetween(e.latLng, p);
        if (d < bestDist) { bestDist = d; best = m; }
      });
      // ズームレベルに応じた距離閾値（ピクセル精度）
      const zoom = bgMap.getZoom();
      const threshold = 40000 / Math.pow(2, zoom); // メートル換算の近傍
      if (best && bestDist < Math.max(threshold, 50)) {
        console.log('[MAP CLICK] nearest #' + best.storeData._markerNum + ' ' + best.storeData.name + ' dist=' + Math.round(bestDist) + 'm');
        openStoreInfoWindow(best, best.storeData);
      }
    });
  }
  // 既存マーカー（append時）もboundsに含める
  if (append) {
    bgMapStoreMarkers.forEach(m => bounds.extend(m.getPosition()));
  }
  // 現在地もboundsに含める
  if (bgMapMarker) bounds.extend(bgMapMarker.getPosition());
  if (hasNewMarkers || (append && bgMapStoreMarkers.length > 0)) {
    bgMap.fitBounds(bounds);
    const listener = google.maps.event.addListener(bgMap, 'idle', () => {
      if (bgMap.getZoom() > 16) bgMap.setZoom(16);
      google.maps.event.removeListener(listener);
    });
  }
}

// ===== 店舗詳細パネル (ピンタップ → マップ下に表示) =====
let currentPinStore = null;
const placeDetailPanel = document.getElementById('placeDetailPanel');
const placeDetailTitle = document.getElementById('placeDetailTitle');
const placeDetailBody = document.getElementById('placeDetailBody');
const placeDetailBack = document.getElementById('placeDetailBack');

function showPlaceDetail() {
  storeSuggestions.classList.remove('show');
  placeDetailPanel.classList.add('show', 'sheet-half');
  document.querySelector('main').classList.add('showing-detail');
  searchBar.classList.add('has-results');
}

function hidePlaceDetail() {
  const wasShowing = placeDetailPanel.classList.contains('show');
  if (bgDirectionsRenderer) { bgDirectionsRenderer.setMap(null); bgDirectionsRenderer = null; }
  placeDetailPanel.classList.remove('show', 'sheet-peek', 'sheet-half', 'sheet-full');
  placeDetailPanel.style.height = '';
  document.querySelector('main').classList.remove('showing-detail', 'showing-route');
  if (wasShowing && storeSuggestions.querySelectorAll('.store-card').length > 0) {
    storeSuggestions.classList.add('show');
    if (!storeSuggestions.classList.contains('sheet-peek') && !storeSuggestions.classList.contains('sheet-half') && !storeSuggestions.classList.contains('sheet-full')) {
      storeSuggestions.classList.add('sheet-peek');
    }
    document.querySelector('main').classList.add('showing-results');
  } else if (wasShowing) {
    searchBar.classList.remove('has-results');
    document.querySelector('main').classList.remove('showing-results');
  }
}

// 戻るボタン
placeDetailBack.addEventListener('click', hidePlaceDetail);

// ドラッグでシート高さ変更（詳細パネル）
const placeDetailDrag = document.getElementById('placeDetailDrag');
if (placeDetailDrag) {
  setupSheetDrag(placeDetailPanel, placeDetailDrag, {
    snapPoints: [30, 55, 85],
    scrollList: placeDetailBody,
    onClose: () => {
      if (document.querySelector('main').classList.contains('showing-route')) clearRoute();
      else hidePlaceDetail();
    }
  });
}

let detailRequestId = 0;

function openStoreInfoWindow(marker, store) {
  currentPinStore = store;
  const reqId = ++detailRequestId; // 古いfetch結果が上書きしないよう
  const safeName = escapeHtml(store.name);
  const safePhone = escapeHtml(store.phone_number);
  placeDetailTitle.textContent = store.name;
  placeDetailBody.innerHTML = `
    <div class="pd-name">${safeName}</div>
    <div class="pd-info-row"><i class="fa-solid fa-phone"></i><span class="pd-phone-num">${safePhone}</span></div>
    <div style="color:var(--muted);font-size:13px;padding:16px 0;"><i class="fa-solid fa-spinner fa-spin"></i> 詳細を読み込み中...</div>`;
  showPlaceDetail();
  if (marker) bgMap.panTo(marker.getPosition());

  if (store.place_id) {
    fetch('place_detail?place_id=' + encodeURIComponent(store.place_id))
      .then(r => r.json())
      .then(data => {
        if (reqId !== detailRequestId) return; // 古いリクエストは無視
        if (data.success && data.detail) {
          placeDetailBody.innerHTML = buildDetailContent(data.detail, store);
        } else {
          placeDetailBody.innerHTML = buildBasicContent(store);
        }
      })
      .catch(() => {
        if (reqId !== detailRequestId) return;
        placeDetailBody.innerHTML = buildBasicContent(store);
      });
  } else {
    placeDetailBody.innerHTML = buildBasicContent(store);
  }
}

function buildBasicContent(store) {
  const s = escapeHtml;
  const phone = s(store.phone_number);
  const name = s(store.name);
  return `
    <div class="pd-name">${name}</div>
    <div class="pd-meta">${s(store.address || '')}</div>
    ${panelActionButtons(phone, name, store)}
    <div class="pd-info-row"><i class="fa-solid fa-phone"></i><span class="pd-phone-num">${phone}</span></div>`;
}

function buildDetailContent(d, store) {
  const s = escapeHtml;
  const phone = s(d.phone || store.phone_number);
  const name = s(d.name || store.name);

  // 星レビュー
  let ratingHtml = '';
  if (d.rating) {
    ratingHtml = `<div class="pd-rating">
      <span class="pd-rating-num">${d.rating}</span>
      ${buildStars(d.rating)}
      <span class="pd-rating-count">(${d.ratings_total || 0})</span>
    </div>`;
  }

  // カテゴリ・価格帯
  let metaHtml = '';
  const parts = [];
  if (d.types && d.types.length > 0) parts.push(d.types.slice(0, 2).join(' · '));
  if (d.price_level !== null && d.price_level !== undefined) parts.push('¥'.repeat(d.price_level + 1));
  if (parts.length > 0) metaHtml = `<div class="pd-meta">${s(parts.join(' · '))}</div>`;

  // 営業中/休業
  let statusHtml = '';
  if (d.open_now === true) statusHtml = `<span class="pd-status-open">営業中</span>`;
  else if (d.open_now === false) statusHtml = `<span class="pd-status-closed">営業時間外</span>`;
  else if (d.business_status) statusHtml = `<span class="pd-meta">${s(d.business_status)}</span>`;

  // 営業時間（今日）
  let todayHoursHtml = '';
  if (d.hours && d.hours.length > 0) {
    const dayIndex = new Date().getDay();
    const gDayIndex = dayIndex === 0 ? 6 : dayIndex - 1;
    if (d.hours[gDayIndex]) todayHoursHtml = `<div class="pd-hours">${s(d.hours[gDayIndex])}</div>`;
  }
  let openRow = '';
  if (statusHtml || todayHoursHtml) openRow = `<div style="margin-bottom:6px;">${statusHtml}${todayHoursHtml}</div>`;

  // 写真
  let photosHtml = '';
  if (d.photos && d.photos.length > 0) {
    const imgs = d.photos.map(p => `<img src="${s(p.url)}" loading="lazy">`).join('');
    photosHtml = `<div class="pd-photos">${imgs}</div>`;
  }

  // アクションボタン
  const actionsHtml = panelActionButtons(phone, name, store);

  // 住所
  const addrHtml = d.address
    ? `<div class="pd-info-row"><i class="fa-solid fa-location-dot"></i><span>${s(d.address)}</span></div>` : '';

  // 電話
  const phoneHtml = phone
    ? `<div class="pd-info-row"><i class="fa-solid fa-phone"></i><span class="pd-phone-num">${phone}</span></div>` : '';

  // ウェブサイト
  let webHtml = '';
  if (d.website) {
    let domain = '';
    try { domain = new URL(d.website).hostname; } catch(e) { domain = d.website; }
    webHtml = `<div class="pd-info-row"><i class="fa-solid fa-globe"></i><a href="${s(d.website)}" target="_blank">${s(domain)}</a></div>`;
  }

  // Google マップリンク
  let gmapHtml = '';
  if (d.google_url) {
    gmapHtml = `<div class="pd-info-row"><i class="fa-solid fa-map"></i><a href="${s(d.google_url)}" target="_blank">Google マップで見る</a></div>`;
  }

  // レビュー
  let reviewsHtml = '';
  if (d.reviews && d.reviews.length > 0) {
    const items = d.reviews.map(rv => `
      <div class="pd-review-item">
        <div class="pd-review-stars">${buildStars(rv.rating)}<span class="pd-review-time">${s(rv.time)}</span></div>
        <div class="pd-review-text">${s(rv.text)}</div>
      </div>`).join('');
    reviewsHtml = `<div class="pd-review"><div class="pd-review-header">クチコミ</div>${items}</div>`;
  }

  return `
    <div class="pd-name">${name}</div>
    ${ratingHtml}${metaHtml}${openRow}${photosHtml}
    ${actionsHtml}
    ${addrHtml}${phoneHtml}${webHtml}${gmapHtml}
    ${reviewsHtml}`;
}

function buildStars(rating) {
  let html = '';
  for (let i = 1; i <= 5; i++) {
    if (rating >= i) html += '<i class="fa-solid fa-star" style="color:#fbbc04;font-size:12px;"></i>';
    else if (rating >= i - 0.5) html += '<i class="fa-solid fa-star-half-stroke" style="color:#fbbc04;font-size:12px;"></i>';
    else html += '<i class="fa-regular fa-star" style="color:#dadce0;font-size:12px;"></i>';
  }
  return html;
}

function panelActionButtons(phone, name, store) {
  const noPhone = !phone;
  const disabledAttr = noPhone ? 'disabled style="opacity:0.4;pointer-events:none;"' : '';
  return `<div class="pd-actions">
    <button class="pd-call-btn" ${disabledAttr} onclick="selectStoreFromPanel('${phone.replace(/'/g, "\\'")}','${name.replace(/'/g, "\\'")}')">
      <i class="fa-solid fa-phone" style="font-size:12px;"></i> 営業確認
    </button>
    <button class="pd-reserve-btn" ${disabledAttr} onclick="openReservationFromPanel(this)" data-phone="${phone.replace(/"/g, '&quot;')}" data-name="${name.replace(/"/g, '&quot;')}" data-store='${JSON.stringify(store).replace(/'/g, "&#39;").replace(/</g, "&lt;").replace(/>/g, "&gt;")}'>
      <i class="fa-solid fa-calendar-check" style="font-size:12px;"></i> 予約
    </button>
    <button class="pd-route-btn" onclick="showRouteFromPanel(this)" data-store='${JSON.stringify(store).replace(/'/g, "&#39;").replace(/</g, "&lt;").replace(/>/g, "&gt;")}'>
      <i class="fa-solid fa-diamond-turn-right" style="font-size:12px;"></i> ルート
    </button>
  </div>`;
}

function selectStoreFromPanel(phone, name) {
  toEl.value = phone;
  nameEl.value = name;
  hidePlaceDetail();
  hideSearchResults();
  storeSuggestions.innerHTML = '';
  lastSearchQuery = '';
  toActive(true);
  btn.click();
}

function showRouteFromPanel(btn) {
  const store = JSON.parse(btn.dataset.store);
  showRouteMode(store);
}

// メインマップにルート描画
let bgDirectionsRenderer = null;

function showRouteMode(store) {
  // DirectionsRenderer初期化（初回のみ）
  if (!directionsService) directionsService = new google.maps.DirectionsService();
  if (!bgDirectionsRenderer) {
    bgDirectionsRenderer = new google.maps.DirectionsRenderer({ suppressMarkers: false });
    bgDirectionsRenderer.setMap(bgMap);
  }

  // 目的地
  const lat = parseFloat(store.lat);
  const lng = parseFloat(store.lng);
  if (!lat || !lng) { alert('店舗の座標がありません'); return; }
  const dest = new google.maps.LatLng(lat, lng);

  if (!userLocation) {
    bgMap.panTo(dest);
    bgMap.setZoom(15);
    showRoutePanelInfo(store, null);
    return;
  }

  const origin = new google.maps.LatLng(userLocation.lat, userLocation.lng);
  directionsService.route({
    origin: origin,
    destination: dest,
    travelMode: google.maps.TravelMode.DRIVING,
    unitSystem: google.maps.UnitSystem.METRIC
  }, (result, status) => {
    if (status === 'OK') {
      bgDirectionsRenderer.setDirections(result);
      showRoutePanelInfo(store, result.routes[0].legs[0]);
    } else {
      bgMap.panTo(dest);
      bgMap.setZoom(15);
      showRoutePanelInfo(store, null);
    }
  });
}

function showRoutePanelInfo(store, leg) {
  const s = escapeHtml;
  let infoHtml = '';
  if (leg) {
    infoHtml = `
      <div style="display:flex;gap:16px;padding:12px 0;border-bottom:1px solid #f1f3f4;">
        <div style="text-align:center;flex:1;">
          <div style="font-size:18px;font-weight:500;color:var(--primary);">${leg.duration.text}</div>
          <div style="font-size:12px;color:var(--muted);">車</div>
        </div>
        <div style="text-align:center;flex:1;">
          <div style="font-size:18px;font-weight:500;color:var(--text);">${leg.distance.text}</div>
          <div style="font-size:12px;color:var(--muted);">距離</div>
        </div>
      </div>`;
  } else if (!userLocation) {
    infoHtml = '<div style="padding:12px 0;color:var(--muted);font-size:13px;text-align:center;">現在地を取得できませんでした</div>';
  } else {
    infoHtml = '<div style="padding:12px 0;color:var(--muted);font-size:13px;text-align:center;">ルートを取得できませんでした</div>';
  }

  // 詳細パネルにルート情報を表示
  placeDetailTitle.textContent = store.name + ' へのルート';
  placeDetailBody.innerHTML = `
    <div class="pd-name">${s(store.name)}</div>
    <div class="pd-meta">${s(store.address || '')}</div>
    ${infoHtml}
    <div class="pd-actions" style="margin-top:10px;">
      <button class="pd-call-btn" ${s(store.phone_number) ? '' : 'disabled style="opacity:0.4;pointer-events:none;"'} onclick="selectStoreFromPanel('${s(store.phone_number).replace(/'/g, "\\'")}','${s(store.name).replace(/'/g, "\\'")}')">
        <i class="fa-solid fa-phone" style="font-size:12px;"></i> 発信
      </button>
      <button class="pd-route-btn" onclick="clearRoute()">
        <i class="fa-solid fa-xmark" style="font-size:12px;"></i> 閉じる
      </button>
    </div>`;

  storeSuggestions.classList.remove('show');
  placeDetailPanel.classList.add('show', 'sheet-peek');
  placeDetailPanel.classList.remove('sheet-half', 'sheet-full');
  placeDetailPanel.style.height = '';
  const main = document.querySelector('main');
  main.classList.remove('showing-results', 'showing-detail');
  main.classList.add('showing-route');
  searchBar.classList.add('has-results');
}

function clearRoute() {
  if (bgDirectionsRenderer) {
    bgDirectionsRenderer.setMap(null);
    bgDirectionsRenderer = null;
  }
  placeDetailPanel.classList.remove('show', 'sheet-peek', 'sheet-half', 'sheet-full');
  placeDetailPanel.style.height = '';
  const main = document.querySelector('main');
  main.classList.remove('showing-route');
  if (storeSuggestions.querySelectorAll('.store-card').length > 0) {
    storeSuggestions.classList.add('show');
    if (!storeSuggestions.classList.contains('sheet-peek') && !storeSuggestions.classList.contains('sheet-half') && !storeSuggestions.classList.contains('sheet-full')) {
      storeSuggestions.classList.add('sheet-peek');
    }
    main.classList.add('showing-results');
  } else {
    searchBar.classList.remove('has-results');
  }
}

// ページ読み込み時: <head>で開始した位置情報取得の結果を使ってマップ初期化
// localStorageキャッシュがあれば即座に正しい位置で表示（東京フラッシュ防止）
const defaultCenter = window.__earlyPos || { lat: 35.6762, lng: 139.6503 };
let bgMapInited = false;
function ensureBgMap(center) {
  if (bgMapInited) return;
  bgMapInited = true;
  initBgMap(center || defaultCenter);
}
// <head>で開始した早期位置取得の結果を待つ
window.__earlyGeo.then(pos => {
  if (pos) {
    userLocation = pos;
    if (!bgMapInited) {
      ensureBgMap(pos);
    } else if (bgMap) {
      // 既にデフォルト位置で初期化済みの場合、現在地に移動
      bgMap.setCenter(pos);
      if (bgMapMarker) bgMapMarker.setPosition(pos);
      else {
        bgMapMarker = new google.maps.Marker({
          position: pos, map: bgMap, title: '現在地',
          icon: { path: google.maps.SymbolPath.CIRCLE, scale: 10, fillColor: '#007AFF', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 3 }
        });
      }
    }
  } else {
    ensureBgMap(defaultCenter);
  }
  // キャッシュ位置で初期化済みだがまだマーカーがない場合
  if (bgMap && userLocation && !bgMapMarker) {
    bgMapMarker = new google.maps.Marker({
      position: userLocation, map: bgMap, title: '現在地',
      icon: { path: google.maps.SymbolPath.CIRCLE, scale: 10, fillColor: '#007AFF', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 3 }
    });
  }
});
// キャッシュがあれば即座に初期化（Promiseの解決を待たない）
if (window.__earlyPos) {
  userLocation = window.__earlyPos;
  ensureBgMap(window.__earlyPos);
}

// ルート表示用マップ初期化
function initMap() {
  if (map) return;
  map = new google.maps.Map(document.getElementById('map'), {
    zoom: 15,
    center: { lat: 35.6762, lng: 139.6503 },
    mapTypeId: google.maps.MapTypeId.ROADMAP
  });
  directionsService = new google.maps.DirectionsService();
  directionsRenderer = new google.maps.DirectionsRenderer();
  directionsRenderer.setMap(map);
}

// マップを閉じる
mapClose.addEventListener('click', () => {
  mapContainer.style.display = 'none';
  if (storeSuggestions.querySelectorAll('.store-card').length > 0) {
    storeSuggestions.classList.add('show');
    if (!storeSuggestions.classList.contains('sheet-peek') && !storeSuggestions.classList.contains('sheet-half') && !storeSuggestions.classList.contains('sheet-full')) {
      storeSuggestions.classList.add('sheet-peek');
    }
    searchBar.classList.add('has-results');
    document.querySelector('main').classList.add('showing-results');
  }
});

// 店舗情報をマップに表示
function showStoreOnMap(storeInfo) {
  currentStoreInfo = storeInfo;
  if (!map) initMap();

  const geocoder = new google.maps.Geocoder();
  geocoder.geocode({ address: storeInfo.address }, (results, status) => {
    if (status === 'OK' && results[0]) {
      const storeLocation = results[0].geometry.location;
      map.setCenter(storeLocation);
      new google.maps.Marker({
        position: storeLocation,
        map: map,
        title: storeInfo.name,
        icon: { url: 'https://maps.google.com/mapfiles/ms/icons/red-dot.png' }
      });
      showRouteToStore(storeLocation);
      mapTitle.textContent = `${storeInfo.name} へのルート`;
      mapContainer.style.display = 'block';
    } else {
      alert('住所の座標取得に失敗しました');
    }
  });
}

// 現在地から店舗までのルートを表示
function showRouteToStore(storeLocation) {
  if (!userLocation) {
    mapDirections.innerHTML = '<div style="text-align:center;color:var(--muted);padding:20px;"><i class="fa-solid fa-location-dot"></i><br>現在地を取得できませんでした</div>';
    return;
  }
  const request = {
    origin: new google.maps.LatLng(userLocation.lat, userLocation.lng),
    destination: storeLocation,
    travelMode: google.maps.TravelMode.DRIVING,
    unitSystem: google.maps.UnitSystem.METRIC
  };
  directionsService.route(request, (result, status) => {
    if (status === 'OK') {
      directionsRenderer.setDirections(result);
      const leg = result.routes[0].legs[0];
      let html = `<div style="margin-bottom:12px;padding:8px;background:#f8f9fa;border-radius:8px;font-size:13px;"><strong>総距離:</strong> ${leg.distance.text} | <strong>所要時間:</strong> ${leg.duration.text}</div>`;
      leg.steps.forEach((step, i) => {
        html += `<div class="direction-step"><span class="step-number">${i+1}</span><span class="step-text">${escapeHtml(step.instructions.replace(/<[^>]*>/g, ''))}</span><div class="step-distance">${escapeHtml(step.distance.text)}</div></div>`;
      });
      mapDirections.innerHTML = html;
    } else {
      mapDirections.innerHTML = '<div style="text-align:center;color:var(--muted);padding:20px;"><i class="fa-solid fa-exclamation-triangle"></i><br>ルートの取得に失敗しました</div>';
    }
  });
}

// 位置情報を取得する関数
function getUserLocation() {
  return new Promise((resolve, reject) => {
    if (userLocation) {
      resolve(userLocation);
      return;
    }
    
    if (!navigator.geolocation) {
      reject(new Error('位置情報がサポートされていません'));
      return;
    }
    
    // ★ まず高精度で試す → タイムアウトしたら低精度にフォールバック
    navigator.geolocation.getCurrentPosition(
      (position) => {
        userLocation = {
          lat: position.coords.latitude,
          lng: position.coords.longitude
        };
        try { localStorage.setItem('_pos', JSON.stringify(userLocation)); } catch(e) {}
        console.log(`[GPS] 取得成功: ${userLocation.lat}, ${userLocation.lng} (精度: ${Math.round(position.coords.accuracy)}m)`);
        resolve(userLocation);
      },
      (error) => {
        // 高精度失敗 → 低精度でリトライ
        console.warn('[GPS] 高精度失敗、低精度で再試行:', error.message);
        navigator.geolocation.getCurrentPosition(
          (position) => {
            userLocation = {
              lat: position.coords.latitude,
              lng: position.coords.longitude
            };
            try { localStorage.setItem('_pos', JSON.stringify(userLocation)); } catch(e) {}
            console.log(`[GPS] 低精度で取得: ${userLocation.lat}, ${userLocation.lng} (精度: ${Math.round(position.coords.accuracy)}m)`);
            resolve(userLocation);
          },
          (err) => reject(err),
          { enableHighAccuracy: false, timeout: 5000, maximumAge: 600000 }
        );
      },
      {
        enableHighAccuracy: true,
        timeout: 8000,
        maximumAge: 300000 // 5分間キャッシュ
      }
    );
  });
}

// ★ ページロード時に位置情報を先行取得（検索用 + 高精度位置でマップ更新）
getUserLocation().then((loc) => {
  if (bgMap) {
    const pos = { lat: loc.lat, lng: loc.lng };
    bgMap.setCenter(pos);
    if (bgMapMarker) bgMapMarker.setPosition(pos);
    else {
      bgMapMarker = new google.maps.Marker({
        position: pos, map: bgMap, title: '現在地',
        icon: { path: google.maps.SymbolPath.CIRCLE, scale: 10, fillColor: '#007AFF', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 3 }
      });
    }
  }
}).catch(() => {});

// 電話番号直接入力時のカード表示
function showPhoneDirectCard(phone) {
  // 表示用にハイフン付きフォーマット
  let displayPhone = phone;
  if (/^0\d{9,10}$/.test(phone)) {
    if (phone.length === 11) displayPhone = phone.replace(/^(\d{3})(\d{4})(\d{4})$/, '$1-$2-$3');
    else if (phone.length === 10) displayPhone = phone.replace(/^(\d{2,4})(\d{2,4})(\d{4})$/, '$1-$2-$3');
  }
  storeSuggestions.innerHTML = '';
  const drag = document.createElement('div');
  drag.className = 'results-drag';
  drag.innerHTML = '<div class="results-drag-bar"></div>';
  const rl = document.createElement('div');
  rl.className = 'results-list';
  rl.innerHTML = `
    <div class="store-card">
      <div class="store-card-body">
        <div class="store-card-name"><i class="fa-solid fa-phone" style="color:var(--primary);margin-right:6px;"></i>${escapeHtml(displayPhone)}</div>
        <div class="store-card-meta">電話番号を直接入力</div>
        <div class="store-card-actions">
          <button class="sc-call-btn" onclick="directCallPhone('${escapeAttr(phone)}')"><i class="fa-solid fa-phone-volume"></i> 営業確認</button>
          <button class="sc-reserve-btn" onclick="directReservePhone('${escapeAttr(phone)}','${escapeAttr(displayPhone)}')"><i class="fa-solid fa-calendar-check"></i> 予約</button>
        </div>
      </div>
    </div>`;
  storeSuggestions.appendChild(drag);
  storeSuggestions.appendChild(rl);
  showSearchResults();
}

function directCallPhone(phone) {
  toEl.value = phone;
  hideSearchResults();
  nameEl.value = '';
  lastSearchQuery = '';
  toActive(true);
  btn.click();
}

function directReservePhone(phone, displayPhone) {
  hideSearchResults();
  showReservationPanel(phone, displayPhone || phone, {});
}

// 店名から電話番号を検索する関数
let searchAbortController = null;
let currentNextPageToken = null;
let isLoadingMore = false;

async function searchPhoneNumber(storeName) {
  if (!storeName.trim()) return;
  
  // ★ 進行中のリクエストをキャンセル
  if (searchAbortController) {
    searchAbortController.abort();
  }
  searchAbortController = new AbortController();
  const signal = searchAbortController.signal;
  currentNextPageToken = null;
  
  // ローディング表示
  storeSuggestions.innerHTML = '';
  const dragL = document.createElement('div');
  dragL.className = 'results-drag';
  dragL.innerHTML = '<div class="results-drag-bar"></div>';
  dragL.addEventListener('click', toggleResultsExpand);
  const loadMsg = document.createElement('div');
  loadMsg.className = 'results-list';
  loadMsg.innerHTML = '<div style="text-align:center;color:var(--muted);padding:24px;font-size:14px;"><i class="fa-solid fa-spinner fa-spin"></i>&nbsp; 店舗を検索中...</div>';
  storeSuggestions.appendChild(dragL);
  storeSuggestions.appendChild(loadMsg);
  showSearchResults();
  
  try {
    // 位置情報を取得（キャッシュ済みなら即返る）
    let locationData = '';
    try {
      const location = await getUserLocation();
      locationData = `&lat=${location.lat}&lng=${location.lng}`;
      console.log('[検索] 送信座標:', location.lat, location.lng);
    } catch (error) {
      console.warn('[検索] 位置情報なし:', error.message || error);
    }
    
    // キャンセル済みならここで止める
    if (signal.aborted) return;
    
    // PHP経由でGoogle Places APIを呼び出し
    const response = await fetch('search_phone', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: `store_name=${encodeURIComponent(storeName)}${locationData}`,
      signal
    });
    
    const data = await response.json();
    
    // デバッグ: バックエンドが使った座標と距離を表示
    if (data.debug) {
      console.log('[検索] mode:', data.debug.mode, 'base:', data.debug.base_location, 'top:', data.debug.top_10_distances);
    }
    
    // キャンセル済みならUI更新しない
    if (signal.aborted) return;
    
    if (data.success && data.stores && data.stores.length > 0) {
      currentNextPageToken = data.next_page_token || null;
      showStoreSuggestions(data.stores, false);
    } else if (data.success && data.phone_number) {
      toEl.value = data.phone_number;
      toActive(true);
      statusEl.innerHTML = '<i class="fa-solid fa-check-circle"></i> 電話番号を自動補完しました: ' + escapeHtml(data.phone_number);
      hideSearchResults();
    } else {
      const rl = storeSuggestions.querySelector('.results-list');
      if (rl) rl.innerHTML = '<div style="text-align:center;color:var(--muted);padding:24px;font-size:14px;">検索結果がありません</div>';
    }
  } catch (error) {
    if (error.name === 'AbortError') return;
    console.log('電話番号検索エラー:', error);
    hideSearchResults();
  }
}

// ★ 次ページ読み込み
async function loadMoreStores() {
  if (!currentNextPageToken || isLoadingMore) return;
  isLoadingMore = true;
  
  // ローディング表示を末尾に追加
  const loader = document.createElement('div');
  loader.id = 'storeLoadingMore';
  loader.style.cssText = 'text-align:center; color: var(--muted); padding: 20px; font-size: 14px;';
  loader.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>&nbsp; もっと読み込み中...';
  const rl = document.getElementById('resultsList');
  if (rl) rl.appendChild(loader);
  
  try {
    let locationData = '';
    try {
      const location = await getUserLocation();
      locationData = `&lat=${location.lat}&lng=${location.lng}`;
    } catch(e) {}
    
    const response = await fetch('search_phone', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `store_name=${encodeURIComponent(nameEl.value.trim())}&page_token=${encodeURIComponent(currentNextPageToken)}${locationData}`
    });
    
    const data = await response.json();
    
    // ローディング削除
    const ld = document.getElementById('storeLoadingMore');
    if (ld) ld.remove();
    
    if (data.success && data.stores && data.stores.length > 0) {
      currentNextPageToken = data.next_page_token || null;
      showStoreSuggestions(data.stores, true); // append mode
    } else {
      currentNextPageToken = null;
    }
  } catch(e) {
    console.log('次ページ読込エラー:', e);
    const ld = document.getElementById('storeLoadingMore');
    if (ld) ld.remove();
    currentNextPageToken = null;
  } finally {
    isLoadingMore = false;
  }
}

// 検索結果の表示/非表示を制御
let storeItemCounter = 0;
const searchBar = document.getElementById('searchBar');
const searchBackBtn = document.getElementById('searchBackBtn');
const mapSection = document.querySelector('.map-section');

function resizeMap() {
  requestAnimationFrame(() => { if (bgMap) google.maps.event.trigger(bgMap, 'resize'); });
}

function showSearchResults() {
  storeSuggestions.classList.add('show');
  if (!storeSuggestions.classList.contains('sheet-peek') && !storeSuggestions.classList.contains('sheet-half') && !storeSuggestions.classList.contains('sheet-full')) {
    storeSuggestions.classList.add('sheet-half');
  }
  searchBar.classList.add('has-results');
  document.querySelector('main').classList.add('showing-results');
}

function hideSearchResults() {
  storeSuggestions.classList.remove('show', 'sheet-peek', 'sheet-half', 'sheet-full');
  storeSuggestions.style.height = '';
  searchBar.classList.remove('has-results');
  document.querySelector('main').classList.remove('showing-results');
  bgMapStoreMarkers.forEach(m => m.setMap(null));
  bgMapStoreMarkers = [];
  bgMapPinLabels.forEach(l => l.setMap(null));
  bgMapPinLabels = [];
  hidePlaceDetail();
}

// peek ↔ expanded 切替
function toggleResultsExpand() {
  storeSuggestions.style.height = '';
  if (storeSuggestions.classList.contains('sheet-peek')) {
    storeSuggestions.classList.remove('sheet-peek');
    storeSuggestions.classList.add('sheet-half');
  } else if (storeSuggestions.classList.contains('sheet-half')) {
    storeSuggestions.classList.remove('sheet-half');
    storeSuggestions.classList.add('sheet-full');
  } else {
    storeSuggestions.classList.remove('sheet-full');
    storeSuggestions.classList.add('sheet-peek');
  }
}

// 戻るボタン
searchBackBtn.addEventListener('click', () => {
  nameEl.value = '';
  lastSearchQuery = '';
  hideSearchResults();
  storeSuggestions.innerHTML = '';
});

// 店舗候補を表示する関数（append=trueで末尾追加）
function showStoreSuggestions(stores, append = false) {
  if (!append) {
    storeSuggestions.innerHTML = '';
    storeItemCounter = 0;
  }

  // 構造を作成: ハンドルバー + 件数行 + スクロールリスト
  if (!storeSuggestions.querySelector('.results-drag')) {
    const drag = document.createElement('div');
    drag.className = 'results-drag';
    drag.innerHTML = '<div class="results-drag-bar"></div>';

    const countRow = document.createElement('div');
    countRow.className = 'results-count-row';
    countRow.id = 'resultsCountRow';

    const list = document.createElement('div');
    list.className = 'results-list';
    list.id = 'resultsList';
    // 無限スクロール (throttled)
    let scrollRaf = 0;
    list.addEventListener('scroll', () => {
      if (scrollRaf) return;
      scrollRaf = requestAnimationFrame(() => {
        scrollRaf = 0;
        const { scrollTop, scrollHeight, clientHeight } = list;
        if (scrollHeight - scrollTop - clientHeight < 40) loadMoreStores();
      });
    }, { passive: true });

    // クリックで peek ↔ expanded 切替
    drag.addEventListener('click', toggleResultsExpand);
    // ドラッグでシート高さ変更（リストスクロール→ドラッグハンドオフ対応）
    setupSheetDrag(storeSuggestions, drag, {
      snapPoints: [35, 55, 85],
      scrollList: list,
      onClose: () => { nameEl.value = ''; lastSearchQuery = ''; hideSearchResults(); storeSuggestions.innerHTML = ''; }
    });

    storeSuggestions.appendChild(drag);
    storeSuggestions.appendChild(countRow);
    storeSuggestions.appendChild(list);
  }

  stores.forEach((store) => {
    storeItemCounter++;

    const card = document.createElement('div');
    card.className = 'store-card';

    const s = escapeHtml;
    const safeName = s(store.name);
    const safePhone = s(store.phone_number);

    // 距離
    let distText = '';
    if (store.distance && store.distance < 100000) {
      distText = store.distance < 1000
        ? `${Math.round(store.distance)}m`
        : `${(store.distance / 1000).toFixed(1)}km`;
    }

    // 写真グリッド（最大5枚表示、それ以上は「もっと見る」）
    // 写真（横スクロールで全枚表示）
    let photoHtml = '';
    const photos = store.photos || [];
    if (photos.length > 0) {
      let items = '';
      for (let pi = 0; pi < photos.length; pi++) {
        items += '<div class="pg-item"><img src="' + s(photos[pi]) + '" loading="lazy" alt=""></div>';
      }
      photoHtml = '<div class="store-card-photos">' + items + '</div>';
    }

    // 評価 + 価格帯 + カテゴリ
    let ratingHtml = '';
    const rParts = [];
    if (store.rating) {
      rParts.push(`<span class="rating-value">${store.rating}</span><i class="fa-solid fa-star rating-star"></i><span>(${(store.ratings_total || 0).toLocaleString()})</span>`);
    }
    if (store.price_level !== null && store.price_level !== undefined) {
      rParts.push('¥'.repeat(store.price_level + 1));
    }
    if (store.types && store.types.length > 0) {
      rParts.push(s(store.types.join(' · ')));
    }
    if (rParts.length > 0) {
      ratingHtml = `<div class="store-card-rating">${rParts.join('<span class="dot-sep">·</span>')}</div>`;
    }

    // 営業状態 + 距離
    let statusHtml = '';
    const sParts = [];
    if (store.open_now === true) sParts.push('<span class="open-badge">営業中</span>');
    else if (store.open_now === false) sParts.push('<span class="closed-badge">営業時間外</span>');
    else if (store.business_status === 'CLOSED_TEMPORARILY') sParts.push('<span class="closed-badge">一時休業</span>');
    else if (store.business_status === 'CLOSED_PERMANENTLY') sParts.push('<span class="closed-badge">閉業</span>');
    if (distText) sParts.push(distText);
    if (sParts.length > 0) statusHtml = `<div class="store-card-meta">${sParts.join('<span class="dot-sep">·</span>')}</div>`;

    // 住所短縮
    let addr = s(store.address || '').replace(/^日本、\s*/, '').replace(/^〒\d{3}-?\d{4}\s*/, '');

    card.innerHTML = `
      ${photoHtml}
      <div class="store-card-body">
        <div class="store-card-name">${safeName}</div>
        ${ratingHtml}
        ${statusHtml}
        ${addr ? `<div class="store-card-info"><i class="fa-solid fa-location-dot"></i><span>${addr}</span></div>` : ''}
        ${safePhone ? `<div class="store-card-info"><i class="fa-solid fa-phone"></i><span class="phone-num">${safePhone}</span></div>` : `<div class="store-card-info" style="color:#999;"><i class="fa-solid fa-phone-slash"></i><span>電話番号なし</span></div>`}
        <div class="store-card-actions">
          <button class="sc-call-btn" ${safePhone ? '' : 'disabled style="opacity:0.4;pointer-events:none;"'}><i class="fa-solid fa-phone" style="font-size:12px;"></i> 営業確認</button>
          <button class="sc-reserve-btn" ${safePhone ? '' : 'disabled style="opacity:0.4;pointer-events:none;"'}><i class="fa-solid fa-calendar-check" style="font-size:12px;"></i> 予約</button>
          <button class="sc-route-btn"><i class="fa-solid fa-diamond-turn-right" style="font-size:12px;"></i> ルート</button>
        </div>
      </div>
    `;

    // 写真ドラッグスクロール（PC用 - rAF throttled）
    const photoStrip = card.querySelector('.store-card-photos');
    if (photoStrip) {
      let isDown = false, startX, scrollL, pRaf = 0;
      photoStrip.addEventListener('mousedown', (e) => {
        isDown = true; startX = e.pageX - photoStrip.offsetLeft; scrollL = photoStrip.scrollLeft;
        photoStrip.style.cursor = 'grabbing';
      });
      photoStrip.addEventListener('mouseleave', () => { isDown = false; photoStrip.style.cursor = 'grab'; });
      photoStrip.addEventListener('mouseup', () => { isDown = false; photoStrip.style.cursor = 'grab'; });
      photoStrip.addEventListener('mousemove', (e) => {
        if (!isDown) return; e.preventDefault();
        const newScroll = scrollL - (e.pageX - photoStrip.offsetLeft - startX);
        if (!pRaf) { pRaf = requestAnimationFrame(() => { photoStrip.scrollLeft = newScroll; pRaf = 0; }); }
      });
    }

    // 発信ボタン
    card.querySelector('.sc-call-btn').addEventListener('click', (e) => {
      e.stopPropagation();
      toEl.value = store.phone_number;
      nameEl.value = store.name;
      hideSearchResults();
      storeSuggestions.innerHTML = '';
      lastSearchQuery = '';
      toActive(true);
      btn.click();
    });

    // 予約ボタン
    card.querySelector('.sc-reserve-btn').addEventListener('click', (e) => {
      e.stopPropagation();
      showReservationPanel(store.phone_number, store.name, store);
    });

    // ルートボタン
    card.querySelector('.sc-route-btn').addEventListener('click', (e) => {
      e.stopPropagation();
      showRouteMode(store);
    });

    // カード本体クリック: マップピンフォーカス + シート縮小
    const storeSnapshot = Object.assign({}, store);
    card.addEventListener('click', () => {
      const idx = bgMapStoreMarkers.findIndex(m => {
        const sd = m.storeData;
        return sd && ((sd.place_id && sd.place_id === storeSnapshot.place_id) || (sd.name === storeSnapshot.name && sd.phone_number === storeSnapshot.phone_number));
      });
      if (idx >= 0) {
        const marker = bgMapStoreMarkers[idx];
        bgMap.panTo(marker.getPosition());
        bgMap.setZoom(16);
      } else if (storeSnapshot.lat && storeSnapshot.lng) {
        bgMap.panTo({ lat: parseFloat(storeSnapshot.lat), lng: parseFloat(storeSnapshot.lng) });
        bgMap.setZoom(16);
      }
      // シートをpeekに縮小して地図を見せる
      storeSuggestions.style.height = '';
      storeSuggestions.classList.remove('sheet-half', 'sheet-full');
      storeSuggestions.classList.add('sheet-peek');
    });

    const list = document.getElementById('resultsList');
    if (list) list.appendChild(card);
  });

  // store-itemが0件なら非表示
  const itemCount = storeSuggestions.querySelectorAll('.store-card').length;
  if (itemCount === 0) {
    hideSearchResults();
    return;
  }

  showSearchResults();

  // 背景マップにマーカーを表示
  updateBgMapMarkers(stores, append);

  // 件数表示を更新
  const countRow = document.getElementById('resultsCountRow');
  if (countRow) countRow.textContent = itemCount + '件の結果';
}

// 店名入力時の自動検索
let lastSearchQuery = '';

nameEl.addEventListener('input', () => {
  const storeName = nameEl.value.trim();

  if (searchTimeout) {
    clearTimeout(searchTimeout);
  }

  // 入力が空 or 短い → リセット
  if (storeName.length < 2) {
    hideSearchResults();
    storeSuggestions.innerHTML = '';
    lastSearchQuery = '';
    return;
  }

  // 電話番号パターン検出
  const phoneClean = storeName.replace(/[\s\-\(\)　]/g, '');
  if (/^(0\d{9,10}|\+?\d{10,13})$/.test(phoneClean)) {
    lastSearchQuery = storeName;
    showPhoneDirectCard(phoneClean);
    return;
  }

  // 同じクエリの場合は再検索しない
  if (storeName === lastSearchQuery) {
    if (storeSuggestions.children.length > 0) {
      showSearchResults();
    }
    return;
  }

  searchTimeout = setTimeout(() => {
    lastSearchQuery = storeName;
    searchPhoneNumber(storeName);
  }, 300);
});

// ★ フォーカス時に既存候補があれば再表示
nameEl.addEventListener('focus', () => {
  if (storeSuggestions.children.length > 0 && nameEl.value.trim().length >= 2) {
    showSearchResults();
  }
});

// 録音再生プレーヤーを表示する関数
function showRecordingPlayer(url, duration) {
  recordingPlayer.classList.add('show');
  recordingLoading.style.display = 'flex';
  recordingAudio.style.display = 'none';
  recordingInfo.style.display = 'none';

  recordingAudio.src = url;
  recordingDownload.href = url;

  recordingAudio.addEventListener('canplay', function onCanPlay() {
    recordingLoading.style.display = 'none';
    recordingAudio.style.display = 'block';
    recordingInfo.style.display = 'flex';

    // 録音時間を表示
    if (duration) {
      const dur = parseInt(duration, 10);
      const min = Math.floor(dur / 60);
      const sec = dur % 60;
      recordingDuration.textContent = `録音時間: ${min}分${sec.toString().padStart(2, '0')}秒`;
    } else if (recordingAudio.duration && isFinite(recordingAudio.duration)) {
      const dur = Math.round(recordingAudio.duration);
      const min = Math.floor(dur / 60);
      const sec = dur % 60;
      recordingDuration.textContent = `録音時間: ${min}分${sec.toString().padStart(2, '0')}秒`;
    }

    recordingAudio.removeEventListener('canplay', onCanPlay);
  }, { once: true });

  recordingAudio.addEventListener('error', function onError() {
    recordingLoading.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> 録音データの読み込みに失敗しました';
    recordingAudio.removeEventListener('error', onError);
  }, { once: true });

  recordingAudio.load();
}

// 通話進行カードの表示/非表示
const callProgressCard = $('#callProgressCard');
const callProgressClose = $('#callProgressClose');

function showCallProgress(label) {
  const hdr = callProgressCard.querySelector('.call-progress-header');
  hdr.innerHTML = `<i class="fa-solid fa-phone-volume"></i> ${escapeHtml(label || '営業確認中')}<button id="callProgressClose" class="call-progress-close"><i class="fa-solid fa-times"></i></button>`;
  hdr.querySelector('.call-progress-close').addEventListener('click', hideCallProgress);
  callProgressCard.style.display = 'block';
  const rc = document.getElementById('resultCard');
  if (rc) rc.style.display = 'none';
  // ボトムシートを広げて見えるように
  const homeSheet = document.getElementById('homeSheet');
  if (homeSheet && homeSheet.classList.contains('sheet-peek')) {
    homeSheet.classList.remove('sheet-peek');
    homeSheet.classList.add('sheet-half');
  }
}

function hideCallProgress() {
  callProgressCard.style.display = 'none';
}

// 録音プレーヤーをリセットする関数
function resetRecordingPlayer() {
  recordingPlayer.classList.remove('show');
  recordingAudio.pause();
  recordingAudio.removeAttribute('src');
  recordingAudio.style.display = 'none';
  recordingLoading.style.display = 'flex';
  recordingLoading.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 録音データを読み込み中…';
  recordingInfo.style.display = 'none';
  recordingDuration.textContent = '';
  recordingDownload.href = '';
  recordingPlayerShown = false;
  if (recordingPollTimer) {
    clearInterval(recordingPollTimer);
    recordingPollTimer = null;
  }
}

btn.addEventListener('click', async ()=>{
  const to = toEl.value.trim();
  const name = nameEl.value.trim();
  if(!to){ return; }
  toActive(false);
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 通信中…';
  btn.style.background = '#e53e3e';
  statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Twilioに発信依頼中…';

  // 通話進行カードを表示
  showCallProgress(name || to);

  // 前回の録音プレーヤーをリセット
  resetRecordingPlayer();

  try{
    const fd = new FormData();
    fd.append('to', to);
    if(name) fd.append('name', name);
    const res = await fetch('call', { method:'POST', body: fd });
    if(!res.ok){ throw new Error('発信に失敗しました'); }
    const j = await res.json();
    if(!j.ok){ throw new Error(j.error || 'failed'); }
    
    // 利用回数を増加
    incrementUsageCount();
    
    currentSid = j.sid;
    statusEl.innerHTML = '<i class="fa-solid fa-phone-volume"></i> 発信しました（CallSid: '+ currentSid +'）。相手の応答を待っています…';
    actionsEl.style.display = 'flex';
    viewLink.href = j.view_url;
    twimlLink.href = j.twiml_url;

    if(pollTimer) clearInterval(pollTimer);
    await poll();
    pollTimer = setInterval(poll, 2000);
  }catch(e){
    statusEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> エラー: ' + escapeHtml(e.message);
    toActive(true);
    resetCallBtn();
  }
});

async function poll(){
  if(!currentSid) return;
  try{
    const res = await fetch('call?json=1&sid='+encodeURIComponent(currentSid));
    if(res.status===204){ return; } // まだ
    if(!res.ok){ throw new Error('結果取得に失敗'); }
    const j = await res.json();

    // ★ 状態メッセージを優先表示
    if (j.result_state === 'no_response') {
      statusEl.innerHTML = '<i class="fa-solid fa-microphone-slash"></i> ' + escapeHtml(j.message || '無言で電話を切られました');
    } else if (j.result_state === 'no_result') {
      statusEl.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + escapeHtml(j.message || '通話は終了しましたが回答を取得できませんでした。');
    } else if (j.result_state === 'call_failed') {
      statusEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + escapeHtml(j.message || '通話が確立できませんでした。時間をおいて再度お試しください。');
    } else if (j.message) {
      statusEl.innerHTML = '<i class="fa-regular fa-message"></i> ' + escapeHtml(j.message);
    }

    const open = j.open_answer || '';
    const hours = j.hours_answer || '';
    if (open || hours) {
      let resultHtml = '';
      if (open) resultHtml += `<div style="font-size: 16px;">${escapeHtml(open)}</div>`;
      if (hours) resultHtml += `<div style="font-size: 13px; color: var(--muted); margin-top: 4px;">営業時間: ${escapeHtml(hours)}</div>`;
      resultText.innerHTML = resultHtml;
      const rc = document.getElementById('resultCard');
      if (rc) rc.style.display = 'block';
    }
    
    // デバッグ情報
    console.log('ポーリング結果:', {
      result_state: j.result_state,
      open_answer: j.open_answer,
      hours_answer: j.hours_answer,
      hours_parsed: j.hours_parsed,
      completed: j.completed
    });

    // 録音リンクを表示
    if (j.open_recording_url) {
      openRecordingLink.href = j.open_recording_url;
      openRecordingLink.style.display = 'inline-flex';
    }
    if (j.hours_recording_url) {
      hoursRecordingLink.href = j.hours_recording_url;
      hoursRecordingLink.style.display = 'inline-flex';
    }

    // 録音再生プレーヤーを表示
    if (j.recording_url && !recordingPlayerShown) {
      recordingPlayerShown = true;
      showRecordingPlayer(j.recording_url, j.recording_duration);
      // 録音待ちポーリングを停止
      if (recordingPollTimer) {
        clearInterval(recordingPollTimer);
        recordingPollTimer = null;
      }
    }

    if(j.completed){
      if (j.result_state !== 'no_response' && !j.message) {
        statusEl.innerHTML = '<i class="fa-regular fa-circle-check"></i> 通話完了。結果を取得しました。';
      }
      // ヘッダーを完了状態に更新
      const hdr = callProgressCard.querySelector('.call-progress-header');
      if (hdr) {
        const label = nameEl.value || toEl.value || '';
        hdr.innerHTML = `<i class="fa-solid fa-circle-check" style="color:#1e8e3e;"></i> ${escapeHtml(label)} - 通話完了<button class="call-progress-close" onclick="hideCallProgress()"><i class="fa-solid fa-times"></i></button>`;
      }
      clearInterval(pollTimer);
      toActive(true);
      resetCallBtn();
      
      // 統計データを更新
      getStatsData();

      // 通話履歴を更新
      loadHistory(historySearch.value.trim());

      // 録音がまだ届いていない場合、追加ポーリングで録音を待つ
      if (!j.recording_url && !recordingPollTimer) {
        recordingPlayer.classList.add('show');
        recordingLoading.style.display = 'flex';
        recordingLoading.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 録音データの準備を待っています…';
        
        let recordingRetries = 0;
        const maxRecordingRetries = 15; // 最大30秒待つ（2秒×15回）
        
        recordingPollTimer = setInterval(async () => {
          recordingRetries++;
          try {
            const rres = await fetch('call?json=1&sid=' + encodeURIComponent(currentSid));
            if (rres.ok && rres.status !== 204) {
              const rj = await rres.json();
              if (rj.recording_url && !recordingPlayerShown) {
                recordingPlayerShown = true;
                showRecordingPlayer(rj.recording_url, rj.recording_duration);
                clearInterval(recordingPollTimer);
                recordingPollTimer = null;
              }
            }
          } catch(e) {}
          
          if (recordingRetries >= maxRecordingRetries) {
            clearInterval(recordingPollTimer);
            recordingPollTimer = null;
            recordingLoading.innerHTML = '<i class="fa-solid fa-circle-info"></i> 録音データが取得できませんでした（短い通話では録音されない場合があります）';
          }
        }, 2000);
      }
    }
  }catch(e){
    // 通信エラー時は無視して次のポーリングへ
  }
}

// ============================================================
// お気に入り店舗機能 (localStorage)
// ============================================================
const favoritesSection = $('#favoritesSection');
const favoritesList = $('#favoritesList');
const favToggleAdd = $('#favToggleAdd'); // may be null in new layout
const favAddForm = $('#favAddForm');
const favNameInput = $('#favName');
const favPhoneInput = $('#favPhone');
const favAddBtn = $('#favAddBtn');

function getFavorites() {
  try { return JSON.parse(localStorage.getItem('callcheck_favorites') || '[]'); }
  catch(e) { return []; }
}

function saveFavorites(favs) {
  localStorage.setItem('callcheck_favorites', JSON.stringify(favs));
}

function renderFavorites() {
  const favs = getFavorites();
  if (favs.length === 0) {
    favoritesSection.style.display = 'block';
    favoritesList.innerHTML = '<span style="font-size: 12px; color: var(--muted);">よく確認する店舗を登録するとワンタップで発信できます</span>';
    return;
  }
  favoritesSection.style.display = 'block';
  
  favoritesList.innerHTML = favs.map((f, i) => `
    <div class="fav-chip" data-index="${i}">
      <span class="fav-call" data-phone="${escapeAttr(f.phone)}" data-name="${escapeAttr(f.name)}">
        <i class="fa-solid fa-phone"></i> ${escapeHtml(f.name)}
      </span>
      <span class="fav-delete" data-index="${i}" title="削除"><i class="fa-solid fa-xmark"></i></span>
    </div>
  `).join('') + '<div class="fav-chip fav-add-chip" style="color:var(--primary);border-style:dashed;"><i class="fa-solid fa-plus"></i> 追加</div>';

  // 追加ボタン
  favoritesList.querySelector('.fav-add-chip')?.addEventListener('click', () => {
    const showing = favAddForm.style.display === 'flex';
    favAddForm.style.display = showing ? 'none' : 'flex';
    if (!showing) {
      if (nameEl.value.trim()) favNameInput.value = nameEl.value.trim();
      if (toEl.value.trim()) favPhoneInput.value = toEl.value.trim();
      favNameInput.focus();
    }
  });
  
  // イベント: ワンタップ発信
  favoritesList.querySelectorAll('.fav-call').forEach(el => {
    el.addEventListener('click', () => {
      nameEl.value = el.dataset.name;
      toEl.value = el.dataset.phone;
      toActive(true);
      statusEl.innerHTML = `<i class="fa-solid fa-star" style="color: #f59e0b;"></i> ${escapeHtml(el.dataset.name)} を選択しました`;
    });
  });
  
  // イベント: 削除
  favoritesList.querySelectorAll('.fav-delete').forEach(el => {
    el.addEventListener('click', (e) => {
      e.stopPropagation();
      const idx = parseInt(el.dataset.index);
      const favs = getFavorites();
      favs.splice(idx, 1);
      saveFavorites(favs);
      renderFavorites();
    });
  });
}

function escapeHtml(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

function escapeAttr(str) {
  return (str || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// お気に入り追加フォーム表示切り替え
if (favToggleAdd) {
  favToggleAdd.addEventListener('click', () => {
    const showing = favAddForm.style.display === 'flex';
    favAddForm.style.display = showing ? 'none' : 'flex';
    if (!showing) {
      if (nameEl.value.trim()) favNameInput.value = nameEl.value.trim();
      if (toEl.value.trim()) favPhoneInput.value = toEl.value.trim();
      favNameInput.focus();
    }
  });
}

// お気に入り追加
favAddBtn.addEventListener('click', () => {
  const name = favNameInput.value.trim();
  const phone = favPhoneInput.value.trim();
  if (!name || !phone) return;
  
  const favs = getFavorites();
  // 重複チェック
  if (favs.some(f => f.phone === phone)) {
    alert('この電話番号は既に登録されています');
    return;
  }
  favs.push({ name, phone });
  saveFavorites(favs);
  favNameInput.value = '';
  favPhoneInput.value = '';
  favAddForm.style.display = 'none';
  renderFavorites();
});

// 初期表示
renderFavorites();

// ============================================================
// 予約一覧機能
// ============================================================
const rsvHistoryList = document.getElementById('rsvHistoryList');

function getReservations() {
  try { return JSON.parse(localStorage.getItem('callcheck_reservations') || '[]'); }
  catch(e) { return []; }
}

function saveReservations(list) {
  localStorage.setItem('callcheck_reservations', JSON.stringify(list));
}

function addReservation(data) {
  const list = getReservations();
  // SID重複チェック
  if (data.sid && list.some(r => r.sid === data.sid)) return;
  list.unshift(data);
  // 最大50件
  if (list.length > 50) list.length = 50;
  saveReservations(list);
  renderReservations();
}

function updateReservationBySid(sid, updates) {
  if (!sid) return;
  const list = getReservations();
  const idx = list.findIndex(r => r.sid === sid);
  if (idx === -1) return;
  Object.assign(list[idx], updates);
  saveReservations(list);
  renderReservations();
}

function removeReservation(index) {
  const list = getReservations();
  list.splice(index, 1);
  saveReservations(list);
  renderReservations();
}

function renderReservations() {
  const list = getReservations();
  if (list.length === 0) {
    rsvHistoryList.innerHTML = '<div class="history-empty"><i class="fa-solid fa-calendar-xmark"></i><br>予約履歴がありません</div>';
    return;
  }
  const now = new Date();
  rsvHistoryList.innerHTML = list.map((r, i) => {
    let iconCls, iconHtml;
    if (r.status === 'confirmed') {
      iconCls = 'confirmed';
      iconHtml = '<i class="fa-solid fa-check"></i>';
    } else if (r.status === 'rejected') {
      iconCls = 'rejected';
      iconHtml = '<i class="fa-solid fa-xmark"></i>';
    } else if (r.status === 'failed') {
      iconCls = 'rejected';
      iconHtml = '<i class="fa-solid fa-phone-slash"></i>';
    } else {
      iconCls = 'unknown';
      iconHtml = '<i class="fa-solid fa-question"></i>';
    }
    // 過去判定
    let isPast = false;
    if (r.rsvDate) {
      const rsvDt = new Date(r.rsvDate + 'T' + (r.rsvTime || '23:59'));
      isPast = rsvDt < now;
    }
    const detail = (r.rsvDate ? r.rsvDate.replace(/\d{4}-/, '').replace('-', '/') : '') +
      (r.rsvTime ? ' ' + r.rsvTime : '') +
      (r.partySize ? ' / ' + r.partySize + '名' : '') +
      (r.rsvName ? ' / ' + escapeHtml(r.rsvName) : '');
    const playBtn = r.recordingUrl
      ? `<button class="rsv-history-play" data-url="${escapeAttr(r.recordingUrl)}" title="録音再生"><i class="fa-solid fa-play"></i></button>`
      : '';
    return `<div class="rsv-history-item${isPast ? ' past' : ''}">
      <div class="rsv-history-icon ${iconCls}">${iconHtml}</div>
      <div class="rsv-history-body">
        <div class="rsv-history-name">${escapeHtml(r.storeName || '')}</div>
        <div class="rsv-history-detail">${detail}</div>
      </div>
      ${playBtn}
      <button class="rsv-history-delete" data-index="${i}" title="削除"><i class="fa-solid fa-trash-can"></i></button>
    </div>`;
  }).join('');
}

// 再生・削除ボタンのイベント委譲
let rsvHistoryAudio = null;
rsvHistoryList.addEventListener('click', (e) => {
  // 再生ボタン
  const playBtn = e.target.closest('.rsv-history-play');
  if (playBtn) {
    const url = playBtn.dataset.url;
    if (!url) return;
    // 既に再生中なら停止
    if (rsvHistoryAudio && !rsvHistoryAudio.paused) {
      rsvHistoryAudio.pause();
      rsvHistoryAudio = null;
      document.querySelectorAll('.rsv-history-play.playing').forEach(b => {
        b.classList.remove('playing');
        b.innerHTML = '<i class="fa-solid fa-play"></i>';
      });
      return;
    }
    // 他の再生中ボタンをリセット
    document.querySelectorAll('.rsv-history-play.playing').forEach(b => {
      b.classList.remove('playing');
      b.innerHTML = '<i class="fa-solid fa-play"></i>';
    });
    rsvHistoryAudio = new Audio(url);
    playBtn.classList.add('playing');
    playBtn.innerHTML = '<i class="fa-solid fa-stop"></i>';
    rsvHistoryAudio.play();
    rsvHistoryAudio.addEventListener('ended', () => {
      playBtn.classList.remove('playing');
      playBtn.innerHTML = '<i class="fa-solid fa-play"></i>';
      rsvHistoryAudio = null;
    });
    return;
  }
  // 削除ボタン
  const btn = e.target.closest('.rsv-history-delete');
  if (!btn) return;
  const idx = parseInt(btn.dataset.index, 10);
  if (!isNaN(idx)) removeReservation(idx);
});

// 初期表示
renderReservations();

// 折りたたみカードのヘッダーをタップしたらシートを広げる（peek時のみ→half）
document.querySelectorAll('.below-map details.collapse-card > summary').forEach(sum => {
  sum.addEventListener('click', () => {
    const sheet = document.getElementById('homeSheet');
    if (!sheet) return;
    if (sheet.classList.contains('sheet-peek')) {
      sheet.classList.remove('sheet-peek', 'sheet-half', 'sheet-full');
      sheet.classList.add('sheet-half');
    }
  });
});

// ============================================================
// 通話履歴機能
// ============================================================
const historyList = $('#historyList');
const historySearch = $('#historySearch');
const historyMoreBtn = $('#historyMoreBtn');
const historyMore = $('#historyMore');

let historyLimit = 10;
let historySearchTimeout = null;

async function loadHistory(search = '') {
  try {
    const params = `limit=${historyLimit}&search=${encodeURIComponent(search)}`;
    const res = await fetch(`call?history=1&${params}`);
    if (!res.ok) return;
    const j = await res.json();
    
    if (!j.ok || !j.calls || j.calls.length === 0) {
      historyList.innerHTML = '<div class="history-empty"><i class="fa-solid fa-phone-slash"></i><br>通話履歴がありません</div>';
      historyMore.style.display = 'none';
      return;
    }
    
    const favs = getFavorites();
    const favPhones = new Set(favs.map(f => f.phone));
    
    historyList.innerHTML = j.calls.map(c => {
      const icon = getStatusIcon(c.open_status, c.status);
      const detail = buildDetail(c);
      const time = formatTime(c.created_at || c.updated_at);
      const phone = c.to || '';
      const isFav = favPhones.has(phone);
      const sid = c.sid || '';
      const hasRec = c.has_recording ? '1' : '';
      const dur = c.duration ? `${Math.floor(c.duration/60)}分${(c.duration%60).toString().padStart(2,'0')}秒` : '';

      return `
        <div class="history-item-wrap" data-sid="${escapeAttr(sid)}">
          <div class="history-item">
            <div class="history-icon ${icon.cls}"><i class="fa-solid ${icon.icon}"></i></div>
            <div class="history-body">
              <div class="history-phone">${escapeHtml(c.name || formatPhone(phone))}</div>
              <div class="history-detail">${escapeHtml(formatPhone(phone))}${detail ? ' ― ' + escapeHtml(detail) : ''}</div>
            </div>
            <div class="history-time">${escapeHtml(time)}</div>
            <div class="history-actions">
              <button class="history-btn" title="再発信" data-phone="${escapeAttr(phone)}" data-name="${escapeAttr(c.name || '')}"><i class="fa-solid fa-phone"></i></button>
              <button class="history-btn fav-toggle ${isFav ? 'is-fav' : ''}" title="${isFav ? 'お気に入り解除' : 'お気に入り登録'}" data-phone="${escapeAttr(phone)}" data-name="${escapeAttr(c.name || '')}"><i class="fa-solid fa-star"></i></button>
            </div>
          </div>
          <div class="history-expand">
            <div class="history-expand-loading"><i class="fa-solid fa-spinner fa-spin"></i> 読み込み中…</div>
          </div>
        </div>
      `;
    }).join('');
    
    // もっと見るボタン
    historyMore.style.display = j.calls.length >= historyLimit ? 'block' : 'none';
    
    // イベント: 履歴アイテムタップで展開
    historyList.querySelectorAll('.history-item-wrap').forEach(wrap => {
      const item = wrap.querySelector('.history-item');
      item.addEventListener('click', (e) => {
        // ボタン領域のクリックは無視
        if (e.target.closest('.history-actions')) return;
        toggleHistoryExpand(wrap);
      });
    });

    // イベント: 再発信
    historyList.querySelectorAll('.history-btn:not(.fav-toggle)').forEach(el => {
      el.addEventListener('click', (e) => {
        e.stopPropagation();
        toEl.value = el.dataset.phone;
        if (el.dataset.name) nameEl.value = el.dataset.name;
        toActive(true);
        btn.click();
      });
    });

    // イベント: お気に入り登録/解除
    historyList.querySelectorAll('.fav-toggle').forEach(el => {
      el.addEventListener('click', (e) => {
        e.stopPropagation();
        const phone = el.dataset.phone;
        const favs = getFavorites();
        const idx = favs.findIndex(f => f.phone === phone);
        if (idx >= 0) {
          favs.splice(idx, 1);
        } else {
          const name = el.dataset.name || phone;
          favs.push({ name: name.substring(0, 30), phone });
        }
        saveFavorites(favs);
        renderFavorites();
        loadHistory(historySearch.value.trim());
      });
    });
    
  } catch(e) {
    console.log('履歴取得エラー:', e);
  }
}

// 履歴アイテムの展開/閉じ
async function toggleHistoryExpand(wrap) {
  const isExpanded = wrap.classList.contains('expanded');

  // 他の展開中アイテムを閉じる
  historyList.querySelectorAll('.history-item-wrap.expanded').forEach(w => {
    if (w !== wrap) {
      w.classList.remove('expanded');
      // 再生中のオーディオを停止
      const audio = w.querySelector('audio');
      if (audio) { audio.pause(); audio.removeAttribute('src'); }
    }
  });

  if (isExpanded) {
    wrap.classList.remove('expanded');
    const audio = wrap.querySelector('audio');
    if (audio) { audio.pause(); audio.removeAttribute('src'); }
    return;
  }

  wrap.classList.add('expanded');
  const expandEl = wrap.querySelector('.history-expand');
  const sid = wrap.dataset.sid;

  if (!sid) {
    expandEl.innerHTML = '<div style="font-size:12px;color:var(--muted);padding:4px 0;">データがありません</div>';
    return;
  }

  // データが既に読み込み済みならスキップ
  if (expandEl.dataset.loaded === '1') return;

  expandEl.innerHTML = '<div class="history-expand-loading"><i class="fa-solid fa-spinner fa-spin"></i> 読み込み中…</div>';

  try {
    const res = await fetch('call?json=1&sid=' + encodeURIComponent(sid));
    if (res.status === 204 || !res.ok) {
      expandEl.innerHTML = '<div style="font-size:12px;color:var(--muted);padding:4px 0;">詳細データがありません</div>';
      expandEl.dataset.loaded = '1';
      return;
    }
    const j = await res.json();

    let html = '';

    // 営業状況
    if (j.open_answer) {
      html += `<div class="history-expand-row"><i class="fa-solid fa-store"></i> ${escapeHtml(j.open_answer)}</div>`;
    }
    // 営業時間
    if (j.hours_answer) {
      html += `<div class="history-expand-row"><i class="fa-regular fa-clock"></i> 営業時間: ${escapeHtml(j.hours_answer)}</div>`;
    }
    // 通話時間
    if (j.duration) {
      const dur = parseInt(j.duration, 10);
      const min = Math.floor(dur / 60);
      const sec = dur % 60;
      html += `<div class="history-expand-row"><i class="fa-solid fa-hourglass-half"></i> 通話時間: ${min}分${sec.toString().padStart(2,'0')}秒</div>`;
    }
    // サマリー
    if (j.summary) {
      html += `<div class="history-expand-summary">${escapeHtml(j.summary)}</div>`;
    }
    // 録音プレーヤー
    if (j.recording_url) {
      const recId = 'hist-rec-' + sid.replace(/[^a-zA-Z0-9]/g, '');
      html += `
        <div class="recording-player show" style="display:block;">
          <div class="recording-player-header"><i class="fa-solid fa-circle-play"></i> 通話録音</div>
          <audio id="${recId}" controls preload="none" src="${escapeAttr(j.recording_url)}" style="width:100%;height:36px;border-radius:8px;"></audio>
          <div class="recording-player-info">
            <span></span>
            <a href="${escapeAttr(j.recording_url)}" class="recording-download" download><i class="fa-solid fa-download"></i> DL</a>
          </div>
        </div>`;
    } else {
      html += '<div style="font-size:12px;color:var(--muted);padding:4px 0;"><i class="fa-solid fa-microphone-slash"></i> 録音データなし</div>';
    }

    expandEl.innerHTML = html;
    expandEl.dataset.loaded = '1';
  } catch(e) {
    expandEl.innerHTML = '<div style="font-size:12px;color:#d93025;padding:4px 0;"><i class="fa-solid fa-triangle-exclamation"></i> 読み込みエラー</div>';
  }
}

function formatPhone(phone) {
  if (!phone) return '';
  // +81xxxxxxxx → 0xxxxxxxx
  let p = phone.replace(/^\+81/, '0');
  // 090-1234-5678 形式に
  if (p.match(/^0[789]0\d{8}$/)) return p.replace(/^(0[789]0)(\d{4})(\d{4})$/, '$1-$2-$3');
  if (p.match(/^0\d{9}$/)) return p.replace(/^(0\d{1,3})(\d{2,4})(\d{4})$/, '$1-$2-$3');
  return p;
}

function getStatusIcon(openStatus, callStatus) {
  if (callStatus === 'failed' || callStatus === 'busy' || callStatus === 'no-answer') {
    return { cls: 'failed', icon: 'fa-phone-slash' };
  }
  switch (openStatus) {
    case 'open': return { cls: 'open', icon: 'fa-check' };
    case 'closed': return { cls: 'closed', icon: 'fa-xmark' };
    case 'no_response': return { cls: 'failed', icon: 'fa-microphone-slash' };
    default: return { cls: 'unknown', icon: 'fa-question' };
  }
}

function buildDetail(c) {
  const parts = [];
  if (c.open_status === 'open') parts.push('営業中');
  else if (c.open_status === 'closed') parts.push('休業');
  else if (c.open_status === 'no_response') parts.push('無応答');
  else if (c.status === 'failed' || c.status === 'busy' || c.status === 'no-answer') parts.push('不通');
  else parts.push('不明');
  
  if (c.hours_answer) parts.push(c.hours_answer);
  else if (c.hours_end) parts.push('〜' + c.hours_end);
  if (c.summary) parts.push(c.summary);
  return parts.join(' / ');
}

function formatTime(dateStr) {
  if (!dateStr) return '';
  try {
    const d = new Date(dateStr);
    const now = new Date();
    const diff = now - d;
    if (diff < 60000) return 'たった今';
    if (diff < 3600000) return Math.floor(diff / 60000) + '分前';
    if (diff < 86400000) return Math.floor(diff / 3600000) + '時間前';
    if (diff < 604800000) return Math.floor(diff / 86400000) + '日前';
    return (d.getMonth() + 1) + '/' + d.getDate();
  } catch(e) { return ''; }
}

// 検索
historySearch.addEventListener('input', () => {
  if (historySearchTimeout) clearTimeout(historySearchTimeout);
  historySearchTimeout = setTimeout(() => {
    historyLimit = 10;
    loadHistory(historySearch.value.trim());
  }, 300);
});

// もっと見る
historyMoreBtn.addEventListener('click', () => {
  historyLimit += 10;
  loadHistory(historySearch.value.trim());
});

// 初期ロード
loadHistory();

// ============================================================
// 予約機能
// ============================================================
const reservationPanel = document.getElementById('reservationPanel');
const reservationBack = document.getElementById('reservationBack');
const reservationTitle = document.getElementById('reservationTitle');
const reservationForm = document.getElementById('reservationForm');
const rsvStoreName = document.getElementById('rsvStoreName');
const rsvStorePhone = document.getElementById('rsvStorePhone');
const rsvDate = document.getElementById('rsvDate');
const rsvTime = document.getElementById('rsvTime');
const rsvPartySize = document.getElementById('rsvPartySize');
const rsvNameInput = document.getElementById('rsvName');
const rsvPhoneInput = document.getElementById('rsvPhone');
const rsvFlexible = document.getElementById('rsvFlexible');
const rsvFlexRange = document.getElementById('rsvFlexRange');
const rsvFlexBefore = document.getElementById('rsvFlexBefore');
const rsvFlexAfter = document.getElementById('rsvFlexAfter');
const rsvSubmitBtn = document.getElementById('rsvSubmitBtn');

// チェックボックスで前後時間の表示切り替え
rsvFlexible.addEventListener('change', () => {
  rsvFlexRange.style.display = rsvFlexible.checked ? 'flex' : 'none';
});
const rsvStatus = document.getElementById('rsvStatus');
const rsvResultCard = document.getElementById('rsvResultCard');
const rsvRecordingPlayer = document.getElementById('rsvRecordingPlayer');
const rsvRecordingAudio = document.getElementById('rsvRecordingAudio');
const rsvRecordingLoading = document.getElementById('rsvRecordingLoading');
const rsvRecordingInfo = document.getElementById('rsvRecordingInfo');
const rsvRecordingDuration = document.getElementById('rsvRecordingDuration');
const rsvRecordingDownload = document.getElementById('rsvRecordingDownload');

let currentRsvStore = null;
let rsvPollTimer = null;
let rsvSid = null;
let rsvRecordingShown = false;
let rsvRecordingPollTimer = null;
let rsvPollCount = 0;

// 日付・時間のデフォルト: 現在+1h（切り上げ）。深夜は翌日にする
const today = new Date();
const nextH = new Date(today.getTime() + 3600000);
rsvDate.value = nextH.getFullYear() + '-' + String(nextH.getMonth() + 1).padStart(2, '0') + '-' + String(nextH.getDate()).padStart(2, '0');
rsvTime.value = String(nextH.getHours()).padStart(2, '0') + ':00';

// ドラッグ
const reservationDrag = document.getElementById('reservationDrag');
const reservationBodyEl = document.getElementById('reservationBody');
if (reservationDrag) {
  setupSheetDrag(reservationPanel, reservationDrag, {
    snapPoints: [35, 65, 90],
    scrollList: reservationBodyEl,
    onClose: hideReservationPanel
  });
}

// ===== ホームボトムシートのドラッグ初期化 =====
const homeSheet = document.getElementById('homeSheet');
const homeSheetDrag = document.getElementById('homeSheetDrag');
const homeSheetContent = document.getElementById('homeSheetContent');
if (homeSheet && homeSheetDrag) {
  setupSheetDrag(homeSheet, homeSheetDrag, {
    snapPoints: [12, 45, 90],
    scrollList: homeSheetContent,
    scrollLockUnlessFull: true
  });
  // ドラッグハンドルのタップで peek→half→full→half 切替
  homeSheetDrag.addEventListener('click', () => {
    const isFull = homeSheet.classList.contains('sheet-full');
    const isHalf = homeSheet.classList.contains('sheet-half');
    const currentH = homeSheet.getBoundingClientRect().height;
    homeSheet.style.height = currentH + 'px';
    homeSheet.style.transition = '';
    requestAnimationFrame(() => {
      homeSheet.classList.remove('sheet-peek', 'sheet-half', 'sheet-full');
      if (isFull) { homeSheet.classList.add('sheet-half'); homeSheetContent.scrollTop = 0; }
      else if (isHalf) homeSheet.classList.add('sheet-full');
      else homeSheet.classList.add('sheet-half');
      requestAnimationFrame(() => { homeSheet.style.height = ''; });
    });
  });
}

function showReservationPanel(phone, name, store) {
  currentRsvStore = { phone, name, store };
  rsvStoreName.textContent = name;
  rsvStorePhone.textContent = phone;
  reservationTitle.textContent = name + ' - 予約';

  // フォームリセット
  rsvSubmitBtn.disabled = false;
  rsvSubmitBtn.innerHTML = '<i class="fa-solid fa-phone-volume"></i> 予約電話をかける';
  rsvSubmitBtn.style.background = '#e67e22';
  rsvStatus.style.display = 'none';
  rsvResultCard.style.display = 'none';
  rsvRecordingPlayer.classList.remove('show');
  reservationForm.style.display = 'flex';
  rsvRecordingShown = false;
  rsvFlexible.checked = true;
  rsvFlexRange.style.display = 'flex';
  if (rsvPollTimer) { clearInterval(rsvPollTimer); rsvPollTimer = null; }
  if (rsvRecordingPollTimer) { clearInterval(rsvRecordingPollTimer); rsvRecordingPollTimer = null; }

  // パネル表示
  storeSuggestions.classList.remove('show');
  placeDetailPanel.classList.remove('show', 'sheet-peek', 'sheet-half', 'sheet-full');
  reservationPanel.classList.add('show', 'sheet-full');
  reservationPanel.style.height = '';
  document.querySelector('main').classList.add('showing-detail');
  searchBar.classList.add('has-results');
}

function hideReservationPanel() {
  reservationPanel.classList.remove('show', 'sheet-peek', 'sheet-half', 'sheet-full');
  reservationPanel.style.height = '';
  if (rsvPollTimer) { clearInterval(rsvPollTimer); rsvPollTimer = null; }
  if (rsvRecordingPollTimer) { clearInterval(rsvRecordingPollTimer); rsvRecordingPollTimer = null; }

  // 検索結果があれば復元
  if (storeSuggestions.querySelectorAll('.store-card').length > 0) {
    storeSuggestions.classList.add('show');
    if (!storeSuggestions.classList.contains('sheet-peek') && !storeSuggestions.classList.contains('sheet-half') && !storeSuggestions.classList.contains('sheet-full')) {
      storeSuggestions.classList.add('sheet-peek');
    }
    document.querySelector('main').classList.add('showing-results');
  } else {
    searchBar.classList.remove('has-results');
    document.querySelector('main').classList.remove('showing-results', 'showing-detail');
  }
}

reservationBack.addEventListener('click', hideReservationPanel);

// 詳細パネルの予約ボタンから開く
function openReservationFromPanel(btn) {
  const phone = btn.dataset.phone;
  const name = btn.dataset.name;
  const store = JSON.parse(btn.dataset.store);
  showReservationPanel(phone, name, store);
}

// 日付を日本語に変換
function formatRsvDate(dateStr) {
  const d = new Date(dateStr + 'T00:00:00');
  const days = ['日', '月', '火', '水', '木', '金', '土'];
  return (d.getMonth() + 1) + '月' + d.getDate() + '日(' + days[d.getDay()] + ')';
}

// フォーム送信
reservationForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!currentRsvStore) return;

  const phone = currentRsvStore.phone;
  const name = currentRsvStore.name;

  // バリデーション
  if (!rsvDate.value || !rsvTime.value || !rsvPartySize.value || !rsvNameInput.value.trim() || !rsvPhoneInput.value.trim()) {
    alert('必須項目を入力してください。');
    return;
  }
  if (!/^[\u3040-\u309F\u30FC\s\u3000]+$/.test(rsvNameInput.value.trim())) {
    alert('予約者名はひらがなで入力してください。');
    rsvNameInput.focus();
    return;
  }

  rsvSubmitBtn.disabled = true;
  rsvSubmitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 発信中…';
  rsvSubmitBtn.style.background = '#e53e3e';
  rsvStatus.style.display = 'block';
  rsvStatus.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Twilioに発信依頼中…';

  try {
    const fd = new FormData();
    fd.append('to', phone);
    fd.append('name', name);
    fd.append('mode', 'reservation');
    const _tm = new URLSearchParams(location.search).get('testmode');
    if (_tm) fd.append('rsv_testmode', _tm);
    fd.append('rsv_date', formatRsvDate(rsvDate.value));
    fd.append('rsv_time', rsvTime.value);
    fd.append('rsv_party_size', rsvPartySize.value);
    fd.append('rsv_name', rsvNameInput.value.trim());
    fd.append('rsv_phone', rsvPhoneInput.value.trim());
    fd.append('rsv_flexible', rsvFlexible.checked ? '1' : '0');
    if (rsvFlexible.checked) {
      fd.append('rsv_flex_before', rsvFlexBefore.value);
      fd.append('rsv_flex_after', rsvFlexAfter.value);
    }

    const res = await fetch('call', { method: 'POST', body: fd });
    if (!res.ok) throw new Error('発信に失敗しました');
    const j = await res.json();
    if (!j.ok) throw new Error(j.error || 'failed');

    incrementUsageCount();
    rsvSid = j.sid;
    rsvStatus.innerHTML = '<i class="fa-solid fa-phone-volume"></i> 予約電話を発信しました。AIが店舗と会話中です…';

    // ポーリング開始
    if (rsvPollTimer) clearInterval(rsvPollTimer);
    rsvPollCount = 0;
    await pollReservation();
    rsvPollTimer = setInterval(pollReservation, 2000);
  } catch (e) {
    rsvStatus.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> エラー: ' + escapeHtml(e.message);
    rsvSubmitBtn.disabled = false;
    rsvSubmitBtn.innerHTML = '<i class="fa-solid fa-phone-volume"></i> 予約電話をかける';
    rsvSubmitBtn.style.background = '#e67e22';
  }
});

async function pollReservation() {
  if (!rsvSid) return;
  rsvPollCount++;
  try {
    const res = await fetch('call?json=1&sid=' + encodeURIComponent(rsvSid));
    if (res.status === 204) return;
    if (!res.ok) throw new Error('結果取得に失敗');
    const j = await res.json();

    // ステータス表示
    if (j.message) {
      rsvStatus.innerHTML = '<i class="fa-regular fa-message"></i> ' + escapeHtml(j.message);
    }

    // 録音再生
    if (j.recording_url && !rsvRecordingShown) {
      rsvRecordingShown = true;
      showRsvRecording(j.recording_url, j.recording_duration);
      // 予約履歴に録音URLを保存
      updateReservationBySid(rsvSid, { recordingUrl: j.recording_url });
    }

    // 最大120秒(60回)でタイムアウト
    if (!j.completed && rsvPollCount >= 60) {
      j.completed = true;
    }

    if (j.completed) {
      clearInterval(rsvPollTimer);
      rsvPollTimer = null;

      // 結果表示
      if (j.reservation_result) {
        showReservationResult(j.reservation_result);
      } else if (j.result_state === 'call_failed') {
        rsvStatus.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> 通話が確立できませんでした。';
        addReservation({
          sid: rsvSid || '', storeName: currentRsvStore ? currentRsvStore.name : '',
          storePhone: currentRsvStore ? currentRsvStore.phone : '',
          rsvDate: rsvDate.value, rsvTime: rsvTime.value, partySize: rsvPartySize.value,
          rsvName: rsvNameInput.value.trim(), status: 'failed',
          summary: '通話が確立できませんでした',
          recordingUrl: rsvRecordingShown && rsvRecordingAudio.src ? rsvRecordingAudio.src : '',
          createdAt: new Date().toISOString()
        });
      } else if (j.result_state === 'no_response') {
        rsvStatus.innerHTML = '<i class="fa-solid fa-microphone-slash"></i> 無応答でした。';
        addReservation({
          sid: rsvSid || '', storeName: currentRsvStore ? currentRsvStore.name : '',
          storePhone: currentRsvStore ? currentRsvStore.phone : '',
          rsvDate: rsvDate.value, rsvTime: rsvTime.value, partySize: rsvPartySize.value,
          rsvName: rsvNameInput.value.trim(), status: 'failed',
          summary: '無応答でした',
          recordingUrl: rsvRecordingShown && rsvRecordingAudio.src ? rsvRecordingAudio.src : '',
          createdAt: new Date().toISOString()
        });
      }

      // フォーム非表示・ボタンリセット
      reservationForm.style.display = 'none';
      getStatsData();
      loadHistory(historySearch.value.trim());

      // 録音待ちポーリング
      if (!j.recording_url && !rsvRecordingPollTimer) {
        rsvRecordingPlayer.classList.add('show');
        rsvRecordingLoading.style.display = 'flex';
        rsvRecordingLoading.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 録音データの準備を待っています…';
        let retries = 0;
        rsvRecordingPollTimer = setInterval(async () => {
          retries++;
          try {
            const rr = await fetch('call?json=1&sid=' + encodeURIComponent(rsvSid));
            if (rr.ok && rr.status !== 204) {
              const rj = await rr.json();
              if (rj.recording_url && !rsvRecordingShown) {
                rsvRecordingShown = true;
                showRsvRecording(rj.recording_url, rj.recording_duration);
                updateReservationBySid(rsvSid, { recordingUrl: rj.recording_url });
                clearInterval(rsvRecordingPollTimer);
                rsvRecordingPollTimer = null;
              }
            }
          } catch(e) {}
          if (retries >= 15) {
            clearInterval(rsvRecordingPollTimer);
            rsvRecordingPollTimer = null;
            rsvRecordingLoading.innerHTML = '<i class="fa-solid fa-circle-info"></i> 録音データが取得できませんでした';
          }
        }, 2000);
      }
    }
  } catch(e) {}
}

function showReservationResult(result) {
  const status = result.reservation_status || 'unknown';
  let icon, title, cls;
  if (status === 'confirmed') {
    icon = '<i class="fa-solid fa-circle-check" style="color:#1e8e3e;"></i>';
    title = '予約が確定しました！';
    cls = 'confirmed';
  } else if (status === 'rejected') {
    icon = '<i class="fa-solid fa-circle-xmark" style="color:#d93025;"></i>';
    title = '予約できませんでした';
    cls = 'rejected';
  } else {
    icon = '<i class="fa-solid fa-circle-question" style="color:#e37400;"></i>';
    title = '予約の可否を確認できませんでした';
    cls = 'unknown';
  }

  let detailHtml = '';
  if (result.confirmation) detailHtml += '<div>' + escapeHtml(result.confirmation) + '</div>';
  if (result.rejection_reason) detailHtml += '<div>理由: ' + escapeHtml(result.rejection_reason) + '</div>';
  if (result.alternative_suggestion) detailHtml += '<div>代替案: ' + escapeHtml(result.alternative_suggestion) + '</div>';
  if (result.summary) detailHtml += '<div style="margin-top:4px;font-style:italic;">' + escapeHtml(result.summary) + '</div>';

  rsvResultCard.className = 'rsv-result-card ' + cls;
  rsvResultCard.innerHTML = `
    <div class="rsv-result-icon">${icon}</div>
    <div class="rsv-result-title">${title}</div>
    <div class="rsv-result-detail">${detailHtml}</div>`;
  rsvResultCard.style.display = 'block';
  rsvStatus.style.display = 'none';

  // 予約履歴に保存（録音URLが既にある場合も含める）
  addReservation({
    sid: rsvSid || '',
    storeName: currentRsvStore ? currentRsvStore.name : '',
    storePhone: currentRsvStore ? currentRsvStore.phone : '',
    rsvDate: rsvDate.value,
    rsvTime: rsvTime.value,
    partySize: rsvPartySize.value,
    rsvName: rsvNameInput.value.trim(),
    status: status,
    confirmation: result.confirmation || '',
    summary: result.summary || '',
    recordingUrl: rsvRecordingShown && rsvRecordingAudio.src ? rsvRecordingAudio.src : '',
    createdAt: new Date().toISOString()
  });
}

function showRsvRecording(url, duration) {
  rsvRecordingPlayer.classList.add('show');
  rsvRecordingLoading.style.display = 'flex';
  rsvRecordingAudio.style.display = 'none';
  rsvRecordingInfo.style.display = 'none';
  rsvRecordingAudio.src = url;
  rsvRecordingDownload.href = url;

  rsvRecordingAudio.addEventListener('canplay', function onCanPlay() {
    rsvRecordingLoading.style.display = 'none';
    rsvRecordingAudio.style.display = 'block';
    rsvRecordingInfo.style.display = 'flex';
    if (duration) {
      const dur = parseInt(duration, 10);
      rsvRecordingDuration.textContent = '録音時間: ' + Math.floor(dur / 60) + '分' + (dur % 60).toString().padStart(2, '0') + '秒';
    }
    rsvRecordingAudio.removeEventListener('canplay', onCanPlay);
  }, { once: true });

  rsvRecordingAudio.addEventListener('error', function onError() {
    rsvRecordingLoading.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> 録音データの読み込みに失敗しました';
    rsvRecordingAudio.removeEventListener('error', onError);
  }, { once: true });

  rsvRecordingAudio.load();
}

// ============================================================
// サポートチャット
// ============================================================
{
  const supportMsgs = document.getElementById('supportMsgs');
  const supportInput = document.getElementById('supportInput');
  const supportSend = document.getElementById('supportSend');
  const supportNewBtn = document.getElementById('supportNewBtn');
  const supportTabs = document.querySelectorAll('.support-tab');
  let supportConvId = sessionStorage.getItem('supportConvId') || '';
  let supportType = 'question';
  let supportSending = false;

  // タブ切り替え
  supportTabs.forEach(tab => {
    tab.addEventListener('click', () => {
      supportTabs.forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      supportType = tab.dataset.type;
    });
  });

  // 新しい会話
  supportNewBtn.addEventListener('click', () => {
    supportConvId = '';
    sessionStorage.removeItem('supportConvId');
    supportMsgs.innerHTML = '<div class="support-msg welcome"><i class="fa-solid fa-robot"></i><br>質問・バグ報告・改善提案をお気軽にどうぞ</div>';
  });

  function appendSupportMsg(role, text) {
    // welcomeメッセージを削除
    const welcome = supportMsgs.querySelector('.welcome');
    if (welcome) welcome.remove();
    const div = document.createElement('div');
    div.className = 'support-msg ' + (role === 'user' ? 'user' : 'ai');
    div.textContent = text;
    supportMsgs.appendChild(div);
    supportMsgs.scrollTop = supportMsgs.scrollHeight;
  }

  async function sendSupportMessage() {
    const text = supportInput.value.trim();
    if (!text || supportSending) return;
    supportSending = true;
    supportSend.disabled = true;
    supportInput.value = '';
    appendSupportMsg('user', text);

    // ローディング表示
    const loading = document.createElement('div');
    loading.className = 'support-msg ai';
    loading.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    supportMsgs.appendChild(loading);
    supportMsgs.scrollTop = supportMsgs.scrollHeight;

    try {
      const uid = typeof currentUser !== 'undefined' && currentUser ? currentUser.uid : 'anonymous';
      const userName = typeof currentUser !== 'undefined' && currentUser ? (currentUser.displayName || currentUser.email || '') : '';
      const res = await fetch('support', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ uid, userName, message: text, convId: supportConvId, type: supportType })
      });
      const j = await res.json();
      loading.remove();
      if (j.ok) {
        supportConvId = j.convId;
        sessionStorage.setItem('supportConvId', supportConvId);
        appendSupportMsg('ai', j.reply);
      } else {
        appendSupportMsg('ai', 'エラーが発生しました。もう一度お試しください。');
      }
    } catch (e) {
      loading.remove();
      appendSupportMsg('ai', '通信エラーが発生しました。');
    }
    supportSending = false;
    supportSend.disabled = false;
    supportInput.focus();
  }

  supportSend.addEventListener('click', sendSupportMessage);
  supportInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendSupportMessage(); }
  });
}

// ============================================================
// 管理画面（?support_admin=1）
// ============================================================
if (new URLSearchParams(location.search).has('support_admin')) {
  const ADMIN_SECRET = 'callcheck_admin_2026';
  const overlay = document.createElement('div');
  overlay.className = 'admin-overlay show';
  overlay.innerHTML = `
    <div class="admin-header">
      <h2><i class="fa-solid fa-headset"></i> サポート管理</h2>
      <div class="admin-filter">
        <button class="admin-filter-btn active" data-f="all">すべて</button>
        <button class="admin-filter-btn" data-f="question">質問</button>
        <button class="admin-filter-btn" data-f="bug">バグ</button>
        <button class="admin-filter-btn" data-f="improvement">改善</button>
        <button class="admin-filter-btn" data-f="unresolved">未解決</button>
      </div>
      <button class="admin-close" id="adminClose">&times;</button>
    </div>
    <div class="admin-list" id="adminList"><div style="text-align:center;padding:40px;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin"></i> 読み込み中…</div></div>
    <div class="admin-detail" id="adminDetail" style="display:none;"></div>`;
  document.body.appendChild(overlay);

  let allConvs = [];
  let currentFilter = 'all';

  document.getElementById('adminClose').addEventListener('click', () => {
    overlay.remove();
    const url = new URL(location.href);
    url.searchParams.delete('support_admin');
    history.replaceState(null, '', url);
  });

  overlay.querySelector('.admin-filter').addEventListener('click', (e) => {
    const btn = e.target.closest('.admin-filter-btn');
    if (!btn) return;
    overlay.querySelectorAll('.admin-filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentFilter = btn.dataset.f;
    renderAdminList();
  });

  function renderAdminList() {
    const list = document.getElementById('adminList');
    const detail = document.getElementById('adminDetail');
    detail.style.display = 'none';
    list.style.display = 'block';
    let filtered = allConvs;
    if (currentFilter === 'unresolved') filtered = allConvs.filter(c => !c.resolved);
    else if (currentFilter !== 'all') filtered = allConvs.filter(c => c.type === currentFilter);

    if (filtered.length === 0) {
      list.innerHTML = '<div style="text-align:center;padding:40px;color:var(--muted);">該当するサポート履歴はありません</div>';
      return;
    }
    const typeLabel = { question: '質問', bug: 'バグ', improvement: '改善' };
    list.innerHTML = filtered.map(c => {
      const date = c.updatedAt ? new Date(c.updatedAt).toLocaleDateString('ja-JP', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
      return `<div class="admin-item${c.resolved ? ' resolved' : ''}" data-id="${c.convId}">
        <div class="admin-item-top">
          <span class="admin-type-badge ${c.type}">${typeLabel[c.type] || '質問'}</span>
          <span class="admin-item-user">${escapeHtml(c.userName || c.uid?.slice(0,8) || '?')}</span>
          <span class="admin-item-date">${date}</span>
          ${c.resolved ? '<span style="font-size:10px;color:#1e8e3e;">✓解決済</span>' : ''}
        </div>
        <div class="admin-item-summary">${escapeHtml(c.summary || '(要約なし)')}</div>
        <div class="admin-item-last">${escapeHtml(c.lastMessage || '')}</div>
      </div>`;
    }).join('');
  }

  document.getElementById('adminList').addEventListener('click', async (e) => {
    const item = e.target.closest('.admin-item');
    if (!item) return;
    const convId = item.dataset.id;
    const list = document.getElementById('adminList');
    const detail = document.getElementById('adminDetail');
    try {
      const res = await fetch(`support?detail=${encodeURIComponent(convId)}&secret=${ADMIN_SECRET}`);
      const j = await res.json();
      if (!j.ok) return;
      const c = j.conversation;
      const typeLabel = { question: '質問', bug: 'バグ報告', improvement: '改善提案' };
      detail.innerHTML = `
        <button class="admin-detail-back"><i class="fa-solid fa-arrow-left"></i> 一覧に戻る</button>
        <div style="margin-bottom:8px;">
          <span class="admin-type-badge ${c.type}">${typeLabel[c.type] || '質問'}</span>
          <strong>${escapeHtml(c.userName || '')}</strong>
          <span style="font-size:11px;color:var(--muted);margin-left:8px;">${c.createdAt ? new Date(c.createdAt).toLocaleString('ja-JP') : ''}</span>
        </div>
        ${c.summary ? '<div style="font-size:12px;color:var(--muted);margin-bottom:10px;">要約: ' + escapeHtml(c.summary) + '</div>' : ''}
        <div class="admin-detail-msgs">
          ${(c.messages || []).map(m => `<div class="support-msg ${m.role === 'user' ? 'user' : 'ai'}">${escapeHtml(m.text)}</div>`).join('')}
        </div>
        ${!c.resolved ? '<button class="admin-resolve-btn" data-id="' + c.convId + '"><i class="fa-solid fa-check"></i> 解決済みにする</button>' : '<div style="margin-top:12px;font-size:12px;color:#1e8e3e;">✓ 解決済み</div>'}`;
      list.style.display = 'none';
      detail.style.display = 'block';

      detail.querySelector('.admin-detail-back').addEventListener('click', () => renderAdminList());
      const resolveBtn = detail.querySelector('.admin-resolve-btn');
      if (resolveBtn) {
        resolveBtn.addEventListener('click', async () => {
          await fetch('support?resolve', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ secret: ADMIN_SECRET, convId: c.convId })
          });
          resolveBtn.outerHTML = '<div style="margin-top:12px;font-size:12px;color:#1e8e3e;">✓ 解決済み</div>';
          const idx = allConvs.findIndex(x => x.convId === c.convId);
          if (idx !== -1) allConvs[idx].resolved = true;
        });
      }
    } catch(e) {}
  });

  // 初期読み込み
  (async () => {
    try {
      const res = await fetch(`support?admin&secret=${ADMIN_SECRET}`);
      const j = await res.json();
      if (j.ok) { allConvs = j.conversations; renderAdminList(); }
    } catch(e) {
      document.getElementById('adminList').innerHTML = '<div style="text-align:center;padding:40px;color:#d93025;">読み込みに失敗しました</div>';
    }
  })();
}
</script>
</body>
</html>