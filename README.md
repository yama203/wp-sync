# WP Sync

ローカルと本番の WordPress の間で、データベースとメディア（uploads）を pull / push で同期する自作プラグイン。WP Migrate Pro の代わり。

## 使い方

1. 両方のサイトに `wp-sync.zip` を入れて有効化する。
2. 本番の「ツール → WP Sync」で接続キーをコピーする。push もするなら「相手がこのサイトへ push するのを許可する」をオンにして保存する。
3. ローカルの「ツール → WP Sync」に接続キーを貼り付けて「接続する」。
   - wp-env のローカルからは、接続キーの URL のままでつながる（本番は外のサイトなので）。
4. Pull（本番 → ローカル）か Push（ローカル → 本番）を選び、DB とメディアのどちらを送るかを選んで始める。
   - 「有効化しているプラグインの設定は送らない」（初期値オン）は、送り先で有効なプラグインをそのまま残す（WP Migrate の「active_plugins を移行しない」と同じ）。オフにすると、送り元の有効・無効がそのまま送り先に入る。

pull で DB を取り込むと、ローカルのユーザーは本番のものになるので、終わったら本番のユーザーでログインし直す。

### WP-CLI

```
wp sync key                                   # このサイトの接続キー
wp sync connect <key> [--connect-url=<url>]   # 接続先を設定
wp sync allow --pull=yes --push=no            # 相手に許可する操作
wp sync pull [--no-db] [--no-media] [--delete-media] [--no-keep-active-plugins] [--replace='前=>後'] [--yes]
wp sync push [--no-db] [--no-media] [--delete-media] [--no-keep-active-plugins] [--replace='前=>後'] [--yes]
wp sync restore [--remote] [--yes]            # 直前の取り込みの前に戻す
```

## しくみ

- 通信は `/?wps-api=1` に POST する（Xserver の国外 IP 制限などで `/wp-json/` が止められていても動くように）。本文は serialize して gzip したもの。要求と応答には、送り先の秘密のキーで HMAC-SHA256 の署名を付け、時刻が 5 分以上ずれていたら断る。
- 同期を進めるのは、操作した側のサイト（ふつうはローカル）。相手は頼まれたことに答えるだけ。
- **DB**: 送り元の接頭辞で始まるテーブルを 2MB くらいずつ読み、送り先で `wpstmp_` で始まる仮のテーブルに入れる。全部入れたら `RENAME TABLE` で一度に入れ替える。途中で止まっても今のサイトは壊れない。
  - URL（home / siteurl、スキームなしの `//host`、JSON の `\/` 形、URL エンコード形）とパス（ABSPATH、uploads）を置き換える。serialize された値は中身をたどって置き換え、長さを直す。
  - 接頭辞が違っても、`{prefix}user_roles` とユーザーメタの `{prefix}capabilities` などを直す。
  - このプラグインの設定（接続キー・接続先・許可）と home・siteurl は送り先のものを残し、プラグインが有効なままになるようにする。WordPress をフォルダに置いているサイト（siteurl が home/wp など）でも、`/wp` は `/wp/…` か値全体のときだけ置き換える（`/wp-content` に引っかからない）。
  - 入れ替える前のテーブルは `wpsbak_` で始まる名前で 1 回分だけ残す（「元に戻す」で戻せる）。送り先にしかないテーブルはそのまま残る。
- **メディア**: 両方の uploads の一覧（大きさと更新日時）を比べ、違うものだけを送る。大きなファイルは 2MB ずつに分ける。送ったファイルは更新日時を送り元に合わせるので、次は送らない。PHP などの実行できるファイルと `.htaccess` は送らない。
- 途中の状態は送る側の `uploads/wp-sync/` に置く（ウェブから読めないようにしている）。

## 更新

公開リポジトリ [yama203/wp-sync](https://github.com/yama203/wp-sync) の Release から更新する。プラグインのヘッダーに `Update URI: https://github.com/yama203/wp-sync` を書いているので、WordPress は wordpress.org に問い合わせず、`update_plugins_github.com` のフィルターで WP Sync が最新の Release（6 時間キャッシュ）を返す。管理画面の「更新」・`wp plugin update wp-sync`・Site Hub の更新で、そのまま更新できる。

リリースの流れ：

1. `wp-sync/wp-sync.php` のヘッダーの `Version` と `WPS_VERSION` を上げてコミットする（サイト間の通信の形を変えたら `WPS_API_VERSION` も上げる。両側の版が違うと同期しない）
2. `npm run release`（`v{版}` のタグを push する）
3. GitHub Actions（`.github/workflows/release.yml`）が Release を作り、`wp-sync-{版}.zip` を添付する

このフォルダの wp-env はソースを直接マウントしているので、`.wp-env.json` で `WPS_DISABLE_UPDATER` を true にして更新を止めている（更新するとソースが消えるため）。

## まだできないこと

- マルチサイト
- テーマ・プラグインのファイルの同期（GH Deployer でやる）
- 一部のテーブルだけを選んで送ること
- 止まったところからの再開（もう一度始めると最初から）

## 開発

```
npm install
npm run start   # http://localhost:10837（ローカル役）と http://localhost:11837（本番役）
npm run zip     # wp-sync.zip を作る
```

wp-env の 2 台をつなぐときは、ローカル役から `http://host.docker.internal:11837` でつなぐ（コンテナの中の localhost は自分自身なので）。

```
npx wp-env run cli wp sync connect "$(npx wp-env run tests-cli wp sync key | tail -1)" --connect-url=http://host.docker.internal:11837
```

`tests/check.php` は、テストで同期した結果を見るためのもの。
