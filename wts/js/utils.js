/**
 * WTS共通ユーティリティ関数
 */

/**
 * HTMLエスケープ
 * @param {string} str エスケープする文字列
 * @returns {string} エスケープされた文字列
 */
function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
