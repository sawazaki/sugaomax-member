# 菅生マックス チーム管理システム

## プロジェクト概要
ミニバスケットボールチームの部員管理・試合メンバー表作成Webアプリ。

## 技術スタック
- PHP 8.2 + Apache
- SQLite3（data/minibasket.db）
- HTML / CSS / Vanilla JavaScript
- 認証: セッションベース（includes/db.phpのAPP_PASSWORD）

## ファイル構成
- `includes/db.php` ... DB接続・共通関数（h(), require_login()）
- `includes/nav.php` ... 共通ナビゲーション
- `css/style.css` ... スタイル（@media printの印刷スタイル含む）
- `data/` ... SQLiteファイル格納（Gitignore済み）

## コーディング規約
- SQLはプリペアドステートメントを必ず使用（SQLインジェクション対策）
- 出力は必ずh()関数でエスケープする
- 新しいページを追加する場合はrequire_login()をファイル先頭に記述する