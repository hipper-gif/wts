// 描画済みの画面（HTML）に入っている JS の構文を確かめる。
// 使い方: node scripts/check_inline_js.js <HTMLを置いたフォルダ>
// 背景: PHP の構文検査は画面の中の JS を見ない。2026-11-18、確認文の中に改行が入ったまま本番に出し、
//       定期点検の画面のボタンが効かなくなった（十数分で修正）。配置の前に、描画した画面をこれに通す。
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const dir = process.argv[2];
if (!dir) { console.error('フォルダを指定してください'); process.exit(2); }

const decode = (s) => s.replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
let bad = 0, total = 0;

for (const file of fs.readdirSync(dir).filter((f) => f.endsWith('.html'))) {
    const html = fs.readFileSync(path.join(dir, file), 'utf8');
    if (html.length < 500) { console.log('空に近い画面（描画できていない）:', file, html.length + ' bytes'); bad++; continue; }

    // <script> ... </script>（外部ファイルと JSON は除く）
    const scriptRe = /<script(?![^>]*\bsrc=)([^>]*)>([\s\S]*?)<\/script>/g;
    let m, i = 0;
    while ((m = scriptRe.exec(html))) {
        i++;
        if (/json/.test(m[1])) continue;
        total++;
        try { new vm.Script(m[2]); } catch (e) { bad++; console.log('NG', file, 'script#' + i, e.message); }
    }

    // onclick などの属性に書かれた処理
    const handlerRe = /\son(?:click|submit|change|input|blur|focus)="([^"]*)"/g;
    while ((m = handlerRe.exec(html))) {
        total++;
        const code = decode(m[1]);
        try { new vm.Script('(function(event){' + code + '\n})'); } catch (e) { bad++; console.log('NG', file, 'handler', e.message, '|', code.slice(0, 80)); }
    }
}

console.log('確かめた数:', total, '/ だめ:', bad);
process.exit(bad ? 1 : 0);
