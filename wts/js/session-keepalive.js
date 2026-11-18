/**
 * 開いたままの画面で、保存を押した時に入力が消えないようにする（全画面共通）。
 *
 * - 画面が見えている間は数分おきに、また画面に戻ってきた時に、サーバーへ知らせてログインを保つ。
 * - 返ってきた新しいトークンを、画面のフォームに入れ直す（古いトークンのまま保存すると弾かれるため）。
 * - ログインが切れていた時は、保存を押す前に画面の上に知らせる。入力はそのまま残る。
 *   別のタブでログインし直すと、知らせは自動で消えて保存できるようになる。
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var pingUrl = script ? script.getAttribute('data-ping-url') : null;
    if (!pingUrl || !window.fetch) return;

    var INTERVAL_MS = 5 * 60 * 1000;
    var lastPing = Date.now();
    var busy = false;

    function applyToken(token) {
        if (!token) return;
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', token);
        var fields = document.querySelectorAll('input[name="csrf_token"]');
        for (var i = 0; i < fields.length; i++) fields[i].value = token;
    }

    function showExpired() {
        if (document.getElementById('wtsSessionExpired')) return;
        var bar = document.createElement('div');
        bar.id = 'wtsSessionExpired';
        bar.setAttribute('role', 'alert');
        bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:10000;background:#b71c1c;color:#fff;'
            + 'padding:12px 16px;font-size:1rem;line-height:1.5;box-shadow:0 2px 8px rgba(0,0,0,.3);';
        bar.textContent = 'ログインの時間が切れました。入力した内容はこの画面に残っています。'
            + '保存する前に、別のタブでログインし直してください（ログインし直すと、この知らせは消えます）。';
        document.body.appendChild(bar);
    }

    function hideExpired() {
        var bar = document.getElementById('wtsSessionExpired');
        if (bar) bar.parentNode.removeChild(bar);
    }

    function ping() {
        if (busy) return;
        busy = true;
        lastPing = Date.now();
        fetch(pingUrl, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.text(); })
            .then(function (text) {
                var data = null;
                try { data = JSON.parse(text); } catch (e) { /* ログイン画面が返ってきた＝ログイン切れ */ }
                if (data && data.ok) {
                    applyToken(data.csrf_token);
                    hideExpired();
                } else {
                    showExpired();
                }
            })
            .catch(function () { /* 通信できない時は何もしない（圏外で騒がない。保存時に各画面が知らせる） */ })
            .then(function () { busy = false; });
    }

    setInterval(function () {
        if (document.visibilityState === 'visible') ping();
    }, INTERVAL_MS);

    // 画面に戻ってきた時（スマホを置いて戻った・別のタブから戻った）。1分以内に確かめていれば省く
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && Date.now() - lastPing > 60 * 1000) ping();
    });
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) ping(); // 「戻る」で前の画面が出し直された時は、トークンが古いことがある
    });
})();
