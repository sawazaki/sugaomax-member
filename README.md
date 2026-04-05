# 菅生マックス チーム管理システム

ミニバスケットボールチーム「菅生マックス」の部員管理・試合メンバー表作成 Web アプリです。

## 主な機能

### 部員管理
- 部員の登録・編集・削除（論理削除）
- 氏名・学年・背番号・性別・所属校・身長を管理
- リバーシブルビブス・青ビブスの所持数管理
- 性別 / 所属校 / 学年でのタブフィルター＆列ソート
- CSV インポート / エクスポート

### 試合管理
- 試合の登録・編集（日付・対戦相手・会場・大会名・種別・コーチ名 等）
- 試合一覧表示

### メンバー表作成
- 試合ごとに出場選手を選択
- ドラッグ＆ドロップで並び順を変更
- 公式フォーマット（20名・4枚複写）での印刷出力に対応

## 技術スタック

| 項目 | 内容 |
|------|------|
| サーバーサイド | PHP 8.2 + Apache |
| データベース | SQLite3（`data/minibasket.db`） |
| フロントエンド | HTML / CSS / Vanilla JavaScript |
| 認証 | セッションベース（パスワード認証） |

## ファイル構成

```
/
├── index.php            # ダッシュボード
├── login.php            # ログイン
├── logout.php           # ログアウト
├── members.php          # 部員一覧
├── member_add.php       # 部員追加
├── member_edit.php      # 部員編集
├── members_export.php   # CSV エクスポート
├── members_import.php   # CSV インポート
├── matches.php          # 試合一覧
├── match_new.php        # 試合登録・編集
├── match_sheet.php      # メンバー表作成・印刷
├── includes/
│   ├── db.php           # DB接続・共通関数（h(), require_login()）
│   └── nav.php          # 共通ナビゲーション
├── css/
│   └── style.css        # スタイル（印刷用 @media print 含む）
└── data/                # SQLite ファイル格納（.gitignore 済み）
```

## セットアップ

### 必要環境
- PHP 8.2 以上（`pdo_sqlite` 拡張有効）
- Apache（または php -S での開発サーバー）

### 起動手順

```bash
# Apache + PHP 環境で /var/www/html に配置するか、
# 開発用サーバーで起動する場合:
php -S localhost:8080 -t /var/www/html
```

データベースファイルは初回アクセス時に `data/minibasket.db` へ自動作成されます。

### ログイン

`includes/db.php` の `APP_PASSWORD` 定数に設定されたパスワードでログインします。

## セキュリティ

- SQL はすべてプリペアドステートメントを使用（SQLインジェクション対策）
- 出力は `h()` 関数（`htmlspecialchars`）でエスケープ（XSS 対策）
- 全ページで `require_login()` によるセッション認証
