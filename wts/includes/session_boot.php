<?php
/**
 * セッションをテナントごとに分ける（session_start() の直前に必ず呼ぶ）
 *
 * なぜ: 全テナント（Smiley=/Smiley/taxi/wts・Lino=/wts-tenants/lino・…）が同じオリジン tw1nkle.com に居るため、
 * cookie 名 PHPSESSID・path=/・保存先1か所のままだと、あるテナントでログインしたブラウザが別テナントを開くと
 * 同じセッションが読まれ、同じ users.id の人としてログインした状態になる（2026-09-24 に verify2 の管理者
 * セッションで Smiley のダッシュボードが開くことを実測。Lino でも同じ）。
 *
 * 対策: 配置パス（APP_BASE_PATH）から鍵を作り、①cookie 名 ②cookie path ③保存先ディレクトリ の3つを分ける。
 * 3つとも分けるのは、名前だけ分けても保存先が同じだと他テナントの有効な session id を持ち込めるため。
 *
 * 呼ぶ場所: index.php / logout.php / includes/session_check.php / templates/daily_report.php
 * .env はまだ読まれていないことがある（index.php は session_start が database.php より先）ので、ここで自分で読む。
 */

function wts_session_base_path(): string
{
    $v = getenv('APP_BASE_PATH');
    if (!$v) {
        // .env を軽く読む（database.php の loadEnv と同じ書式・APP_BASE_PATH だけ）
        $env = __DIR__ . '/../.env';
        if (is_readable($env)) {
            foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos(ltrim($line), 'APP_BASE_PATH=') === 0) {
                    $v = trim(substr(trim($line), strlen('APP_BASE_PATH=')), " \t\"'");
                    break;
                }
            }
        }
    }
    return rtrim($v ?: '/Smiley/taxi/wts', '/');
}

function wts_session_boot(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $base = wts_session_base_path();
    $key = substr(md5($base), 0, 8);

    // 保存先: テナントごとのサブディレクトリ（他アプリ・他テナントの GC と分離）
    $dir = '/home/twinklemark/twinklemark.xsrv.jp/xserver_php/session_wts/' . $key;
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        ini_set('session.save_path', $dir);
    }
    ini_set('session.gc_maxlifetime', 28800);
    ini_set('session.cookie_lifetime', 0);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', 1);
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    if ($secure) {
        ini_set('session.cookie_secure', 1);
    }
    session_name('WTSSESS_' . $key);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $base . '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
