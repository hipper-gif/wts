<?php
// この受け口は使っていないため閉じた（2026-11-18 全画面点検 B-30/B-31）。
// どの画面からも呼ばれておらず、控えも記録も残さずに現金管理の行を消せる状態だった。
// 配置の仕組みはファイルを消さないので、中身を空にして閉じる。
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'message' => 'この機能は使われていません。'], JSON_UNESCAPED_UNICODE);
