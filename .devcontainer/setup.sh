#!/bin/bash
set -e

echo "==> Node.js のインストール"
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt-get install -y nodejs

echo "==> Claude Code のインストール"
npm install -g @anthropic-ai/claude-code

echo "==> data/ ディレクトリの権限設定"
chown -R www-data:www-data /var/www/html/data 2>/dev/null || true

echo "==> 完了"