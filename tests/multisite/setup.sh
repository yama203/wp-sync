#!/bin/bash
# マルチサイトのテスト環境（10838 がローカル役、11838 が本番役）を整える。wp-env start のあとに毎回実行する。
cd "$(dirname "$0")"
PROJECT=$(basename "$(../../node_modules/.bin/wp-env install-path 2>/dev/null | tail -1)")
for NAME in wordpress-1 tests-wordpress-1; do
	C="$PROJECT-$NAME"
	docker cp sunrise.php "$C:/var/www/html/wp-content/sunrise.php"
	docker exec "$C" bash -c 'cat > /var/www/html/.htaccess << "EOT"
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\.php)$ $2 [L]
RewriteRule . index.php [L]
EOT
chown www-data /var/www/html/.htaccess /var/www/html/wp-content/sunrise.php'
done
echo "Done."
