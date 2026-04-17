# 菅生マックス チーム管理システム

## プロジェクト概要
ミニバスケットボールチームの部員管理・試合メンバー表作成Webアプリ。

## 技術スタック
- PHP 8.2 + Apache
- SQLite3（data/minibasket.db）
- HTML / CSS / Vanilla JavaScript
- 認証: セッションベース・ロール別パスワード（admin / editor / viewer）

## ファイル構成
- `includes/db.php` ... DB接続・共通関数（h(), require_login(), is_admin(), is_editor(), csrf_token()）
- `includes/nav.php` ... 共通ナビゲーション
- `includes/forbidden.php` ... 403エラーページ
- `css/style.css` ... スタイル（@media printの印刷スタイル含む）
- `data/` ... SQLiteファイル・config.php格納（Gitignore済み）
- `data/config.php` ... パスワードハッシュ定義（ADMIN_PASSWORD_HASH / APP_PASSWORD_HASH / VIEWER_PASSWORD_HASH / ENROLLMENT_PASSWORD_HASH / ENROLLMENT_TOKEN / ENROLLMENT_ACTIVE）

## 主要ページ
- `index.php` ... ダッシュボード
- `members.php` / `member_add.php` / `member_edit.php` ... 部員管理
- `members_import.php` / `members_export.php` ... CSV一括操作
- `matches.php` / `match_new.php` / `match_sheet.php` ... 試合・メンバー表
- `duty.php` ... 当番管理（練習A〜J・試合1〜4、Excelエクスポート）
- `schedule.php` ... 活動予定（A4横カレンダー・JPGエクスポート、全権限）
- `nyubu.php` ... 入部届け管理（受付状態・QRコード）
- `enrollment.php` ... 入部届けフォーム（保護者向け公開ページ）
- `settings.php` ... パスワード設定・webcalソース管理・予定テキスト設定（管理者のみ）
- `setup.php` ... 初回セットアップ
- `api/ical_fetch.php` ... webcalソースからiCalイベントを取得するAPI（JSON）。サーバーサイドキャッシュ（TTL 5分）付き。`?force=1` で強制再取得

## コーディング規約
- SQLはプリペアドステートメントを必ず使用（SQLインジェクション対策）
- 出力は必ずh()関数でエスケープする
- 編集操作が必要なページはrequire_editor()、管理者専用はrequire_admin()をファイル先頭に記述する
- フォーム送信・AJAX処理にはverify_csrf()を使用する
- data/ディレクトリのパスはDATA_DIR定数を使用する（Xserver対応の自動検出ロジックあり）

## DBスキーマ概要（members テーブル主要カラム）
last_name, first_name, grade, number, school, height, gender, romaji,
reversible_bibs, blue_bibs, practice_duty, practice_duty_order,
match_duty, match_duty_order, has_sibling, enrollment_date,
parent_name, parent_relationship, phone,
emergency_name, emergency_relationship, emergency_phone, active

## その他テーブル
- `app_settings` ... キーバリュー設定（key TEXT PK, value TEXT）。`schedule_text` キーで予定ページのテキスト（HTML）を保存
- `webcal_sources` ... webcalカレンダーソース（id, category, url, color_bg, color_text, sort_order, created_at）
- `matches` に `coach`, `assistant_coach`, `title` カラムを追加済み（マイグレーション対応）

## キャッシュ
- `data/cache/ical/` ... iCalキャッシュファイル格納ディレクトリ（Gitignore済みの data/ 配下）
- キャッシュキー: 全ソースURL + month1 + month2 の MD5。TTL 5分。`?force=1` で即時無効化

## 外部ライブラリ（schedule.php）
- `js/html2canvas.min.js` / `js/jspdf.umd.min.js` をローカルに配置すれば CDN 不使用で動作
- ローカルファイル未配置時は SRI + crossorigin 付き CDN（バージョン固定）にフォールバック