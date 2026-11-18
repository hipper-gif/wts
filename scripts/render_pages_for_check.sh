#!/bin/bash
# サーバー上で実行する（ssh ... 'bash -s' < scripts/render_pages_for_check.sh）。
# 検証用の置き場（~/stage_cc/wts）のコードで主要な画面を描画し、~/stage_cc/jschk.tgz にまとめる。
# 手元で展開して node scripts/check_inline_js.js <フォルダ> に通す（画面の中の JS の構文確認）。
cd ~/stage_cc || exit 1
export STAGE=$HOME/stage_cc/wts DB_NAME=twinklemark_wtsverify2
mkdir -p jschk; rm -f jschk/*
for pg in dashboard daily_inspection pre_duty_call departure ride_records arrival arrival_list post_duty_call \
          cash_management driver_cash_count periodic_inspection accident_management complaint_records \
          hospital_assistance_logs supervision_records user_management vehicle_management location_management \
          transport_category_management data_management master_menu help document_generator annual_report taxi_ticket_invoice; do
  php8.1 harness_1020.php $pg.php 1 > jschk/$pg.html 2>/dev/null
done
# 利用者ロールで見える形と、弾かれた後の形も確かめる
for pg in dashboard daily_inspection pre_duty_call departure ride_records arrival post_duty_call driver_cash_count; do
  php8.1 harness_1020.php $pg.php 2 > jschk/user_$pg.html 2>/dev/null
done
php8.1 harness_1020.php periodic_inspection.php 1 '{"vehicle_id":"1","inspection_date":"2026-11-18","inspector_id":"1","mileage":"52310"}' > jschk/err_periodic.html 2>/dev/null
php8.1 harness_1020.php pre_duty_call.php 2 '{"driver_id":"2","call_time":"08:05","caller_name":"","alcohol_check_value":""}' > jschk/err_pre.html 2>/dev/null
php8.1 harness_1020.php user_management.php 1 '{"action":"edit","user_id":"1","name":"x","login_id":"admin","permission_level":"User"}' > jschk/err_user.html 2>/dev/null
php8.1 harness_1020.php "post_duty_call.php?date=$(date -d yesterday +%F)&driver_id=2" 2 > jschk/post_yesterday.html 2>/dev/null
tar czf jschk.tgz jschk
ls jschk | wc -l
