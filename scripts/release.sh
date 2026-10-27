#!/bin/bash
# WP Sync の Release を作る（タグを付けて push する。ZIP と Release は GitHub Actions が作る）。
#   npm run release
# 先に wp-sync/wp-sync.php の Version と WPS_VERSION を上げてコミットしておく。
set -euo pipefail
cd "$(dirname "$0")/.."

version=$(sed -n 's/^ \* Version: *//p' wp-sync/wp-sync.php | head -1 | tr -d '\r ')
constant=$(sed -n "s/^define( 'WPS_VERSION', '\(.*\)' );/\1/p" wp-sync/wp-sync.php | head -1)
tag="v$version"

if [ "$version" != "$constant" ]; then
  echo "ヘッダーの Version（$version）と WPS_VERSION（$constant）が違います。" >&2
  exit 1
fi
if [ -n "$(git status --porcelain)" ]; then
  echo "コミットしていない変更があります。先にコミットしてください。" >&2
  exit 1
fi
if git rev-parse "$tag" >/dev/null 2>&1; then
  echo "タグ $tag はすでにあります。wp-sync/wp-sync.php の Version を上げてください。" >&2
  exit 1
fi

echo "WP Sync $version で Release を作ります（タグ $tag）。"
git tag -a "$tag" -m "WP Sync $version"
git push origin HEAD "$tag"
echo "push しました。1〜2 分で GitHub の Release に ZIP が付き、各サイトの「更新」に出ます（各サイトの確認は 3 時間ごと。「更新」画面の「もう一度確認する」や Site Hub の「今すぐ取得」ですぐ確認）。"
