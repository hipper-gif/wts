<?php
require_once 'functions.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';

// $pdo, $user_id, $user_name, $user_role は session_check.php で設定済み

$page_config = getPageConfiguration('help');

$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'help',
    $page_config['icon'],
    $page_config['title'],
    $page_config['subtitle'],
    $page_config['category'],
    [
        'breadcrumb' => [
            ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
            ['text' => $page_config['title'], 'url' => 'help.php']
        ]
    ]
);

echo $page_data['html_head'];
echo $page_data['system_header'];
echo $page_data['page_header'];
?>

<style>
    .help-section {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 1.25rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid rgba(0,0,0,0.06);
    }
    .help-section h3 {
        font-size: 1.1rem;
        font-weight: 700;
        color: #2c3e50;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #0d6efd;
    }
    .help-section h4 {
        font-size: 0.95rem;
        font-weight: 700;
        color: #444;
        margin: 1rem 0 0.5rem;
    }

    /* 業務フロー */
    .flow-container {
        background: linear-gradient(135deg, #f0f4ff 0%, #e8f0fe 100%);
        border-radius: 10px;
        padding: 1.25rem;
        margin: 1rem 0;
    }
    .flow-phase {
        font-weight: 700;
        font-size: 0.95rem;
        color: #0d6efd;
        margin: 1rem 0 0.5rem;
    }
    .flow-phase:first-child { margin-top: 0; }
    .flow-step {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 12px;
        margin: 4px 0;
        background: white;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        text-decoration: none;
        color: inherit;
        transition: all 0.2s;
    }
    .flow-step:hover {
        transform: translateX(4px);
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        color: inherit;
        text-decoration: none;
    }
    .flow-num {
        background: #0d6efd;
        color: white;
        font-weight: 700;
        font-size: 0.8rem;
        width: 26px;
        height: 26px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .flow-label { font-weight: 600; font-size: 0.9rem; }
    .flow-desc { color: #666; font-size: 0.8rem; }

    /* アコーディオン */
    .help-accordion .accordion-button {
        font-weight: 600;
        font-size: 0.95rem;
        padding: 0.85rem 1rem;
        background: #f8f9fa;
    }
    .help-accordion .accordion-button:not(.collapsed) {
        background: #e8f0fe;
        color: #0d6efd;
        box-shadow: none;
    }
    .help-accordion .accordion-body {
        font-size: 0.9rem;
        line-height: 1.8;
    }

    /* チェックリスト */
    .checklist {
        list-style: none;
        padding: 0;
        margin: 0.5rem 0;
    }
    .checklist li {
        padding: 4px 0 4px 24px;
        position: relative;
        font-size: 0.9rem;
    }
    .checklist li::before {
        content: "\f00c";
        font-family: "Font Awesome 6 Free";
        font-weight: 900;
        position: absolute;
        left: 0;
        color: #43a047;
        font-size: 0.8rem;
    }

    /* 操作テーブル */
    .op-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
        margin: 0.5rem 0;
    }
    .op-table th {
        background: #e8f0fe;
        padding: 8px 10px;
        font-weight: 600;
        border: 1px solid #dee2e6;
        white-space: nowrap;
    }
    .op-table td {
        padding: 8px 10px;
        border: 1px solid #dee2e6;
        vertical-align: top;
    }
    .op-table tr:nth-child(even) td { background: #f8f9fa; }

    /* バッジ */
    .badge-required {
        background: #dc3545;
        color: white;
        font-size: 0.7rem;
        padding: 1px 6px;
        border-radius: 3px;
        font-weight: 600;
    }

    /* ヒントボックス */
    .hint-box {
        background: #e8f5e9;
        border-left: 4px solid #43a047;
        padding: 10px 14px;
        border-radius: 0 6px 6px 0;
        margin: 0.75rem 0;
        font-size: 0.85rem;
    }
    .hint-box strong { color: #2e7d32; }

    .warn-box {
        background: #fff3e0;
        border-left: 4px solid #ef6c00;
        padding: 10px 14px;
        border-radius: 0 6px 6px 0;
        margin: 0.75rem 0;
        font-size: 0.85rem;
    }
    .warn-box strong { color: #e65100; }

    /* FAQ */
    .faq-q {
        font-weight: 700;
        color: #0d6efd;
        font-size: 0.95rem;
        margin-bottom: 4px;
    }
    .faq-a { padding-left: 1rem; margin-bottom: 1rem; font-size: 0.9rem; }

    /* ナビタブ */
    .help-nav .nav-link {
        font-size: 0.85rem;
        font-weight: 600;
        color: #666;
        padding: 0.5rem 0.8rem;
    }
    .help-nav .nav-link.active {
        color: #0d6efd;
        border-color: #0d6efd;
    }
    @media (max-width: 576px) {
        .help-nav { overflow-x: auto; flex-wrap: nowrap; white-space: nowrap; }
        .help-nav .nav-link { padding: 0.4rem 0.6rem; font-size: 0.8rem; }
    }
</style>

<main class="main-content" id="main-content" tabindex="-1">
    <div class="container-fluid py-4">

        <!-- タブナビゲーション -->
        <ul class="nav nav-tabs help-nav mb-3" id="helpTab" role="tablist">
            <li class="nav-item"><a class="nav-link active" id="flow-tab" data-bs-toggle="tab" href="#flow" role="tab">業務フロー</a></li>
            <li class="nav-item"><a class="nav-link" id="detail-tab" data-bs-toggle="tab" href="#detail" role="tab">各画面の使い方</a></li>
            <li class="nav-item"><a class="nav-link" id="faq-tab" data-bs-toggle="tab" href="#faq" role="tab">FAQ</a></li>
        </ul>

        <div class="tab-content" id="helpTabContent">

            <!-- ==================== 業務フロー ==================== -->
            <div class="tab-pane fade show active" id="flow" role="tabpanel">

                <div class="help-section">
                    <h3><i class="fas fa-route me-2"></i>1日の業務フロー</h3>
                    <p>毎日の業務は、以下の7ステップで進めます。各ステップをタップすると、そのページに移動できます。</p>

                    <div class="flow-container">
                        <div class="flow-phase"><i class="fas fa-sun me-1"></i>朝（始業）</div>
                        <a href="daily_inspection.php" class="flow-step">
                            <span class="flow-num">1</span>
                            <div><span class="flow-label">日常点検</span><br><span class="flow-desc">車両の状態を17項目チェック</span></div>
                        </a>
                        <a href="pre_duty_call.php" class="flow-step">
                            <span class="flow-num">2</span>
                            <div><span class="flow-label">乗務前点呼</span><br><span class="flow-desc">健康状態・持ち物を16項目確認</span></div>
                        </a>
                        <a href="departure.php" class="flow-step">
                            <span class="flow-num">3</span>
                            <div><span class="flow-label">出庫処理</span><br><span class="flow-desc">出発時刻・天候・メーター記録</span></div>
                        </a>

                        <div class="flow-phase"><i class="fas fa-car me-1"></i>日中（運行）</div>
                        <a href="ride_records.php" class="flow-step">
                            <span class="flow-num">4</span>
                            <div><span class="flow-label">乗車記録</span><br><span class="flow-desc">お客様ごとに乗降を記録（乗車のたびに繰り返す）</span></div>
                        </a>

                        <div class="flow-phase"><i class="fas fa-moon me-1"></i>夕方（終業）</div>
                        <a href="arrival.php" class="flow-step">
                            <span class="flow-num">5</span>
                            <div><span class="flow-label">入庫処理</span><br><span class="flow-desc">帰着時刻・メーター・経費記録</span></div>
                        </a>
                        <a href="arrival_list.php" class="flow-step">
                            <span class="flow-num">6</span>
                            <div><span class="flow-label">入庫記録一覧</span><br><span class="flow-desc">過去の入庫記録を確認・修正</span></div>
                        </a>
                        <a href="post_duty_call.php" class="flow-step">
                            <span class="flow-num">7</span>
                            <div><span class="flow-label">乗務後点呼</span><br><span class="flow-desc">業務終了の12項目確認</span></div>
                        </a>
                        <a href="cash_management.php" class="flow-step">
                            <span class="flow-num">8</span>
                            <div><span class="flow-label">売上金確認</span><br><span class="flow-desc">現金を数えて差異チェック</span></div>
                        </a>
                    </div>
                </div>

                <div class="help-section">
                    <h3><i class="fas fa-mobile-alt me-2"></i>アプリのインストール</h3>
                    <p>スマルトはホーム画面に追加して、アプリのように使えます。</p>
                    <ol>
                        <li>ブラウザでスマルトのURLにアクセス</li>
                        <li>ログイン画面上部の<strong>「インストール」</strong>ボタンをタップ</li>
                        <li>確認ダイアログで<strong>「インストール」</strong>を選択</li>
                        <li>ホーム画面にアイコンが追加されます</li>
                    </ol>
                    <div class="hint-box"><strong>ポイント:</strong> ホーム画面から起動すると、全画面で操作できます。</div>
                </div>

                <div class="help-section">
                    <h3><i class="fas fa-home me-2"></i>ダッシュボードの見方</h3>
                    <table class="op-table">
                        <thead><tr><th>エリア</th><th>内容</th></tr></thead>
                        <tbody>
                            <tr><td><strong>上部バー</strong></td><td>本日の売上・月間売上・乗車数などの実績</td></tr>
                            <tr><td><strong>アラート</strong></td><td>未完了の点検や点呼など、対応が必要な項目（赤・オレンジで表示）</td></tr>
                            <tr><td><strong>始業</strong></td><td>日常点検・乗務前点呼・出庫処理へのショートカット</td></tr>
                            <tr><td><strong>運行</strong></td><td>乗車記録・予約カレンダーへのショートカット</td></tr>
                            <tr><td><strong>終業</strong></td><td>入庫処理・乗務後点呼・売上金確認へのショートカット</td></tr>
                        </tbody>
                    </table>
                    <div class="hint-box"><strong>ポイント:</strong> アラートが表示されたら、先にそちらを対応してください。</div>
                </div>
            </div>

            <!-- ==================== 各画面の使い方 ==================== -->
            <div class="tab-pane fade" id="detail" role="tabpanel">

                <div class="accordion help-accordion" id="detailAccordion">

                    <!-- 日常点検 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-inspection">
                            <i class="fas fa-tools me-2 text-primary"></i>日常点検（17項目）
                        </button></h2>
                        <div id="sec-inspection" class="accordion-collapse collapse" data-bs-parent="#detailAccordion">
                            <div class="accordion-body">
                                <h4>操作手順</h4>
                                <ol>
                                    <li><strong>点検者</strong>（自分）と<strong>車両</strong>を選択</li>
                                    <li>各項目の結果を選択：
                                        <span class="badge bg-success">可</span> 問題なし /
                                        <span class="badge bg-danger">否</span> 不良あり /
                                        <span class="badge bg-warning text-dark">省略</span> 該当なし
                                    </li>
                                    <li><strong>「登録する」</strong>をタップ</li>
                                </ol>

                                <h4>点検項目</h4>
                                <table class="op-table">
                                    <thead><tr><th>区分</th><th>項目</th></tr></thead>
                                    <tbody>
                                        <tr><td rowspan="6"><strong>車内</strong></td><td>フットブレーキの踏み代・効き</td></tr>
                                        <tr><td>パーキングブレーキの引き代</td></tr>
                                        <tr><td>エンジンのかかり具合・異音</td></tr>
                                        <tr><td>エンジンの低速・加速</td></tr>
                                        <tr><td>ワイパーのふき取り能力</td></tr>
                                        <tr><td>ウインドウォッシャー液の噴射状態</td></tr>
                                        <tr><td rowspan="6"><strong>エンジン</strong></td><td>ブレーキ液量</td></tr>
                                        <tr><td>冷却水量</td></tr>
                                        <tr><td>エンジンオイル量</td></tr>
                                        <tr><td>バッテリー液量</td></tr>
                                        <tr><td>ウインドウォッシャー液量</td></tr>
                                        <tr><td>ファンベルトの張り・損傷</td></tr>
                                        <tr><td rowspan="5"><strong>灯火・タイヤ</strong></td><td>灯火類の点灯・点滅</td></tr>
                                        <tr><td>レンズの損傷・汚れ</td></tr>
                                        <tr><td>タイヤの空気圧</td></tr>
                                        <tr><td>タイヤの亀裂・損傷</td></tr>
                                        <tr><td>タイヤ溝の深さ</td></tr>
                                    </tbody>
                                </table>

                                <div class="hint-box"><strong>時短テクニック:</strong> 全項目に問題がなければ、<strong>「全て可」</strong>ボタンで一括入力できます。</div>
                                <div class="hint-box"><strong>自動保存:</strong> 入力途中で中断しても、30秒ごとに自動で下書き保存されます。</div>
                            </div>
                        </div>
                    </div>

                    <!-- 乗務前点呼 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-preduty">
                            <i class="fas fa-clipboard-check me-2 text-primary"></i>乗務前点呼（16項目）
                        </button></h2>
                        <div id="sec-preduty" class="accordion-collapse collapse" data-bs-parent="#detailAccordion">
                            <div class="accordion-body">
                                <h4>操作手順</h4>
                                <ol>
                                    <li><strong>運転者</strong>（自分）と<strong>点呼執行者</strong>（立会人）を選択</li>
                                    <li>16項目のチェックボックスを確認してチェック</li>
                                    <li><strong>アルコール値</strong>を入力（通常 0.000）</li>
                                    <li><strong>「登録する」</strong>をタップ</li>
                                </ol>

                                <h4>確認項目</h4>
                                <ul class="checklist">
                                    <li>健康状態</li><li>服装</li><li>履物</li><li>運行前点検</li>
                                    <li>免許証</li><li>車検証</li><li>保険証</li><li>応急工具</li>
                                    <li>地図</li><li>タクシーカード</li><li>非常信号用具</li><li>釣銭</li>
                                    <li>乗務員証</li><li>運行記録用用紙</li><li>領収書</li><li>停止表示機</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- 出庫処理 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-departure">
                            <i class="fas fa-play-circle me-2 text-primary"></i>出庫処理
                        </button></h2>
                        <div id="sec-departure" class="accordion-collapse collapse" data-bs-parent="#detailAccordion">
                            <div class="accordion-body">
                                <table class="op-table">
                                    <thead><tr><th>項目</th><th>入力内容</th><th>備考</th></tr></thead>
                                    <tbody>
                                        <tr><td>運転者</td><td>自分を選択</td><td></td></tr>
                                        <tr><td>車両</td><td>使用する車両を選択</td><td></td></tr>
                                        <tr><td>出庫日</td><td>本日の日付</td><td>自動入力</td></tr>
                                        <tr><td>出庫時刻</td><td>出発する時刻</td><td>現在時刻が自動入力</td></tr>
                                        <tr><td>天候</td><td>晴 / 曇 / 雨 / 雪 / 霧</td><td>いずれか選択</td></tr>
                                        <tr><td>出庫メーター</td><td>オドメーター値（km）</td><td>目視で確認して入力</td></tr>
                                    </tbody>
                                </table>
                                <div class="hint-box"><strong>ポイント:</strong> 車両を選択すると、前回の入庫メーター値が表示されます。</div>
                            </div>
                        </div>
                    </div>

                    <!-- 乗車記録 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-ride">
                            <i class="fas fa-route me-2 text-primary"></i>乗車記録
                        </button></h2>
                        <div id="sec-ride" class="accordion-collapse collapse" data-bs-parent="#detailAccordion">
                            <div class="accordion-body">
                                <p><strong>「乗車記録を登録する」</strong>ボタン（スマホは右下の<strong>「+」</strong>）をタップして入力します。</p>

                                <h4>入力項目</h4>
                                <table class="op-table">
                                    <thead><tr><th>項目</th><th>入力内容</th><th></th></tr></thead>
                                    <tbody>
                                        <tr><td>乗車時刻</td><td>お客様が乗車した時刻</td><td><span class="badge-required">必須</span></td></tr>
                                        <tr><td>人数</td><td>お客様の人数（1〜20人）</td><td><span class="badge-required">必須</span></td></tr>
                                        <tr><td>乗車地</td><td>乗せた場所（候補から選択可）</td><td><span class="badge-required">必須</span></td></tr>
                                        <tr><td>降車地</td><td>降ろした場所（候補から選択可）</td><td><span class="badge-required">必須</span></td></tr>
                                        <tr><td>運賃</td><td>メーター運賃（円）</td><td><span class="badge-required">必須</span></td></tr>
                                        <tr><td>追加料金</td><td>迎車料・待機料など</td><td></td></tr>
                                        <tr><td>利用券額</td><td>福祉利用券の金額</td><td></td></tr>
                                        <tr><td>障害者割引</td><td>適用する場合チェック</td><td></td></tr>
                                        <tr><td>輸送分類</td><td>通院 / 外出等 / 入院 / 退院 / 転院 / 施設入所 / その他</td><td><span class="badge-required">必須</span></td></tr>
                                        <tr><td>支払方法</td><td>現金 / カード / その他</td><td><span class="badge-required">必須</span></td></tr>
                                    </tbody>
                                </table>

                                <p>運転者・車両・日付・時刻は自動入力されます。</p>

                                <h4>復路（帰りの記録）の作成</h4>
                                <p>往復送迎の場合、帰りの記録を簡単に作れます。</p>
                                <ol>
                                    <li>往路の記録の<strong>「復路作成」</strong>ボタンをタップ</li>
                                    <li>乗車地と降車地が<strong>自動で入れ替わり</strong>、運賃等も引き継がれます</li>
                                    <li>内容を確認して保存</li>
                                </ol>
                                <div class="hint-box"><strong>ポイント:</strong> 乗車地・降車地は、文字を入力するとよく使う場所の候補が自動表示されます。</div>
                            </div>
                        </div>
                    </div>

                    <!-- 入庫処理 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-arrival">
                            <i class="fas fa-stop-circle me-2 text-primary"></i>入庫処理
                        </button></h2>
                        <div id="sec-arrival" class="accordion-collapse collapse" data-bs-parent="#detailAccordion">
                            <div class="accordion-body">
                                <ol>
                                    <li><strong>未入庫一覧</strong>から自分の車両をタップ（自動入力されます）</li>
                                    <li><strong>入庫メーター</strong>を入力（走行距離は自動計算）</li>
                                    <li>経費がある場合は燃料代・高速代などを入力</li>
                                    <li>休憩した場合は休憩場所・時間を入力</li>
                                    <li><strong>「登録する」</strong>をタップ</li>
                                </ol>
                                <div class="hint-box"><strong>ポイント:</strong> 走行距離が500kmを超える場合は確認メッセージが表示されます。</div>
                            </div>
                        </div>
                    </div>

                    <!-- 入庫記録一覧 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-arrival-list">
                            <i class="fas fa-list-alt me-2 text-primary"></i>入庫記録一覧
                        </button></h2>
                        <div id="sec-arrival-list" class="accordion-collapse collapse" data-bs-parent="#detailAccordion">
                            <div class="accordion-body">
                                <p>過去の入庫記録を確認・修正するための画面です。</p>

                                <h4>検索・絞り込み</h4>
                                <ul>
                                    <li><strong>日付</strong>を選択して検索（初期値は当日）</li>
                                    <li><strong>車両</strong>で絞り込み可能</li>
                                    <li>一般ドライバーは自分の記録のみ表示されます</li>
                                </ul>

                                <h4>表示内容</h4>
                                <p>カード形式で入庫時刻・メーター・走行距離・経費・備考が確認できます。画面右側には<strong>日次集計</strong>（入庫回数・総走行距離・総費用）が表示されます。</p>

                                <h4>記録の修正</h4>
                                <ol>
                                    <li>修正したいレコードの<strong>「修正」</strong>ボタンをタップ</li>
                                    <li>修正画面で内容を変更</li>
                                    <li><strong>修正理由</strong>を入力（必須）</li>
                                    <li><strong>「保存」</strong>をタップ</li>
                                </ol>

                                <div class="hint-box"><strong>ポイント:</strong> 修正された記録には黄色の<strong>「修正済み」</strong>バッジが表示されます。</div>
                            </div>
                        </div>
                    </div>

                    <!-- 乗務後点呼 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-postduty">
                            <i class="fas fa-check-circle me-2 text-primary"></i>乗務後点呼（12項目）
                        </button></h2>
                        <div id="sec-postduty" class="accordion-collapse collapse" data-bs-parent="#detailAccordion">
                            <div class="accordion-body">
                                <h4>操作手順</h4>
                                <ol>
                                    <li><strong>運転者</strong>（自分）と<strong>点呼執行者</strong>（立会人）を選択</li>
                                    <li>12項目のチェックボックスを確認してチェック</li>
                                    <li><strong>アルコール値</strong>を入力（通常 0.000）</li>
                                    <li><strong>「登録する」</strong>をタップ</li>
                                </ol>

                                <h4>確認項目</h4>
                                <ul class="checklist">
                                    <li>乗務記録の記載は完了しているか</li>
                                    <li>車両に異常・損傷はないか</li>
                                    <li>健康状態に異常はないか</li>
                                    <li>疲労・睡眠不足はないか</li>
                                    <li>酒気・薬物の影響はないか</li>
                                    <li>事故・違反の発生はないか</li>
                                    <li>業務用品は適切に返却されているか</li>
                                    <li>業務報告は完了しているか</li>
                                    <li>車内の忘れ物はないか</li>
                                    <li>事故・違反の最終確認</li>
                                    <li>予定路線での運行は適切だったか</li>
                                    <li>乗客に関する特記事項はないか</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- 売上金確認 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-cash">
                            <i class="fas fa-calculator me-2 text-primary"></i>売上金確認・現金カウント
                        </button></h2>
                        <div id="sec-cash" class="accordion-collapse collapse" data-bs-parent="#detailAccordion">
                            <div class="accordion-body">
                                <h4>操作手順</h4>
                                <ol>
                                    <li>本日の売上サマリーを確認</li>
                                    <li>手元の現金を金種ごとに数えて入力（+/- ボタンまたは数値入力）</li>
                                    <li>差異が表示されます</li>
                                    <li>差異がある場合はメモ欄に理由を記入</li>
                                    <li><strong>「現金カウント保存」</strong>をタップ</li>
                                </ol>

                                <h4>つり銭基本在庫（合計 18,000円）</h4>
                                <table class="op-table">
                                    <thead><tr><th>金種</th><th>基本枚数</th><th>金額</th></tr></thead>
                                    <tbody>
                                        <tr><td>5千円札</td><td>1枚</td><td>5,000円</td></tr>
                                        <tr><td>千円札</td><td>10枚</td><td>10,000円</td></tr>
                                        <tr><td>500円玉</td><td>3枚</td><td>1,500円</td></tr>
                                        <tr><td>100円玉</td><td>11枚</td><td>1,100円</td></tr>
                                        <tr><td>50円玉</td><td>5枚</td><td>250円</td></tr>
                                        <tr><td>10円玉</td><td>15枚</td><td>150円</td></tr>
                                    </tbody>
                                </table>

                                <h4>差異の見方</h4>
                                <table class="op-table">
                                    <tbody>
                                        <tr><td><span class="badge bg-primary">0</span></td><td>ぴったり一致</td></tr>
                                        <tr><td><span class="badge bg-success">+</span></td><td>現金が多い</td></tr>
                                        <tr><td><span class="badge bg-danger">-</span></td><td>現金が足りない</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div><!-- /accordion -->
            </div>

            <!-- ==================== FAQ ==================== -->
            <div class="tab-pane fade" id="faq" role="tabpanel">

                <div class="help-section">
                    <h3><i class="fas fa-question-circle me-2"></i>よくある質問</h3>

                    <div class="faq-q">Q. 入力を間違えた場合は？</div>
                    <div class="faq-a">
                        <p><strong>当日の記録</strong>は、各画面の<strong>「編集」</strong>ボタンから修正できます。</p>
                        <p><strong>過去の記録</strong>は、管理者に連絡してください。管理者のみが修正理由を入力して編集できます。</p>
                    </div>

                    <div class="faq-q">Q. 途中で画面を閉じてしまった場合は？</div>
                    <div class="faq-a">
                        <p>日常点検は30秒ごとに自動で下書き保存されます。再度開くと<strong>「下書きデータがあります」</strong>と表示されるので、<strong>「復元」</strong>をタップしてください。</p>
                    </div>

                    <div class="faq-q">Q. ネットがつながらない場所では？</div>
                    <div class="faq-a">
                        <p>スマルトはオフラインでも一部の機能が使えます。ネットワークに接続された際にデータが自動で同期されます。</p>
                    </div>

                    <div class="faq-q">Q. セッションが切れてログイン画面に戻った場合は？</div>
                    <div class="faq-a">
                        <p>一定時間操作しないと自動ログアウトされます。再度ログインしてください。入力中のデータは下書き保存されている場合があります。</p>
                    </div>

                    <div class="faq-q">Q. アプリの動作がおかしい場合は？</div>
                    <div class="faq-a">
                        <ol>
                            <li>アプリを閉じて再度開く</li>
                            <li>それでも直らない場合はブラウザのキャッシュをクリアする</li>
                            <li>改善しない場合は管理者に連絡してください</li>
                        </ol>
                    </div>

                    <div class="faq-q">Q. パスワードを忘れた場合は？</div>
                    <div class="faq-a">
                        <p>管理者に連絡してパスワードをリセットしてもらってください。</p>
                    </div>
                </div>

                <div class="help-section">
                    <h3><i class="fas fa-headset me-2"></i>お問い合わせ</h3>
                    <p>上記で解決しない場合は、管理者に連絡してください。</p>
                </div>
            </div>

        </div><!-- /tab-content -->
    </div>
</main>

<script>
// URLハッシュでタブを切り替え
document.addEventListener('DOMContentLoaded', function() {
    var hash = window.location.hash;
    if (hash) {
        var tab = document.querySelector('[href="' + hash + '"]');
        if (tab) new bootstrap.Tab(tab).show();
    }
});
</script>

<?= $page_data['html_footer'] ?>
