#!/bin/bash
set -e

echo "==> Node.js のインストール"
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt-get install -y nodejs

echo "==> Claude Code のインストール"
npm install -g @anthropic-ai/claude-code

echo "==> data/ ディレクトリの権限設定"
chown -R www-data:www-data /var/www/html/data 2>/dev/null || true

echo "==> Apache をポート 53570 に設定"
sed -i 's/Listen 80$/Listen 53570/' /etc/apache2/ports.conf
sed -i 's/<VirtualHost \*:80>/<VirtualHost *:53570>/' /etc/apache2/sites-enabled/000-default.conf
service apache2 start

echo "==> 完了"