# WP Sync

ローカルと本番の WordPress の間で、データベース・メディア（uploads）・テーマ・プラグインを pull / push で同期する自作プラグイン。WP Migrate Pro の代わり。

## 使い方

1. 両方のサイトに `wp-sync.zip` を入れて有効化する。
2. 本番の「ツール → WP Sync」で接続キーをコピーする。push もするなら「相手がこのサイトへ push するのを許可する」をオンにして保存する。
3. ローカルの「ツール → WP Sync」に接続キーを貼り付けて「接続する」。
   - wp-env のローカルからは、接続キーの URL のままでつながる（本番は外のサイトなので）。
4. Pull（本番 → ローカル）か Push（ローカル → 本番）を選び、DB とメディアのどちらを送るかを選んで始める。
   - テーマ・プラグインは、チェックを入れると一覧が出るので、送るものを選ぶ。プラグインは「送り先にもあって版が違うもの」に初めからチェックが入る。テーマは Git で管理していることが多いので、初めは何も選ばない
   - 「プラグインの有効・無効」は「送り先のままにする」（初期値。送り先で有効なプラグインをそのまま残す。WP Migrate の「active_plugins を移行しない」と同じ）か「送り元に合わせる」（送り元の有効・無効がそのまま送り先に入る。はじめての pull 向け）を選ぶ。前回の選び方を覚えておく。

pull で DB を取り込むと、ローカルのユーザーは本番のものになるので、終わったら本番のユーザーでログインし直す。

### マルチサイト

ネットワーク全体（すべてのサイトの DB・uploads・テーマ・プラグイン）をまとめて同期する。1 つのサイトだけを送ることはまだできない。

- WP Sync はネットワークで有効化する。画面は「ネットワーク管理 → 設定 → WP Sync」。接続キーと許可はネットワークに 1 つ（メインのサイトの options に置く）。
- 両方ともマルチサイトのときだけ同期できる（シングルサイトとマルチサイトの間はできない）。サブドメイン型かサブディレクトリ型か（`SUBDOMAIN_INSTALL`）は違っていてもよい。各サイトの場所（パスかサブドメインか）で突き合わせる（本番はサブディレクトリ型で `kinoko.example.com` を当て、ローカルはサブドメイン型で `kinoko.localhost:10856`、のような組み合わせ。0.4.1）。
- 接続 URL はメインのサイトの URL。サブサイトの URL で呼ばれたら断る。
- 送り元の各サイトは、送り先でネットワークのドメインとパスを付け替えた場所になる（`https://example.com/door/` → `http://localhost:10898/door/`、`https://door.example.com` → `http://door.localhost:10898`）。サブドメイン型のネットワークへ送るとき、パスのサイトはサブドメインにする。送り先に同じ ID・同じ場所のサイトがあれば、そのサイトの home・siteurl を残す。別のドメインを当てているサイトは送り先の同じ ID のサイトに合わせる（なければドメインはそのまま）。
- `wp_site` のドメイン（wp-config.php の `DOMAIN_CURRENT_SITE` と合わせる必要がある）と `wp_sitemeta` の siteurl は送り先のものを残す。「送り先のままにする」では、ネットワークで有効なプラグイン（`active_sitewide_plugins`）と各サイトの `active_plugins` を残す。
- 送り先にだけあるサイトのテーブル（`wp_4_posts` など）は、同じ ID でサイトを作ったときに混ざらないよう、バックアップ（`wpsbak_`）に回す。「元に戻す」で戻る。

### WP-CLI

```
wp sync key                                   # このサイトの接続キー
wp sync connect <key> [--connect-url=<url>]   # 接続先を設定
wp sync allow --pull=yes --push=no            # 相手に許可する操作
wp sync pull [--no-db] [--no-media] [--delete-media] [--themes=<名前,…|all>] [--plugins=<名前,…|all>] [--no-keep-active-plugins] [--replace='前=>後'] [--yes]
wp sync push [--no-db] [--no-media] [--delete-media] [--themes=<名前,…|all>] [--plugins=<名前,…|all>] [--no-keep-active-plugins] [--replace='前=>後'] [--yes]
wp sync restore [--remote] [--yes]            # 直前の取り込みの前に戻す
```

## しくみ

- 通信は `/?wps-api=1` に POST する（Xserver の国外 IP 制限などで `/wp-json/` が止められていても動くように）。本文は serialize して gzip したもの。要求と応答には、送り先の秘密のキーで HMAC-SHA256 の署名を付け、時刻が 5 分以上ずれていたら断る。
- 同期を進めるのは、操作した側のサイト（ふつうはローカル）。相手は頼まれたことに答えるだけ。
- **DB**: 送り元の接頭辞で始まるテーブルを 2MB くらいずつ読み、送り先で `wpstmp_` で始まる仮のテーブルに入れる。全部入れたら `RENAME TABLE` で一度に入れ替える。途中で止まっても今のサイトは壊れない。
  - URL（home / siteurl、スキームなしの `//host`、JSON の `\/` 形、URL エンコード形）とパス（ABSPATH、uploads）を置き換える。送り元の URL は `http://` と `https://` の両方を、送り先の URL（スキームまで）にそろえる（サイトの URL の設定が http なのに本文が https のとき、スキームなしの置換だけでは https が残ってローカルでつながらなかった。0.4.2）。serialize された値は中身をたどって置き換え、長さを直す。
  - 接頭辞が違っても、`{prefix}user_roles` とユーザーメタの `{prefix}capabilities` などを直す。
  - このプラグインの設定（接続キー・接続先・許可）と home・siteurl は送り先のものを残し、プラグインが有効なままになるようにする。WordPress をフォルダに置いているサイト（siteurl が home/wp など）でも、`/wp` は `/wp/…` か値全体のときだけ置き換える（`/wp-content` に引っかからない）。
  - 入れ替える前のテーブルは `wpsbak_` で始まる名前で 1 回分だけ残す（「元に戻す」で戻せる）。送り先にしかないテーブルはそのまま残る。
- **言語ファイル**: DB を送るときは `wp-content/languages`（本体・plugins・themes の翻訳ファイル）も送る。サイトの言語（WPLANG）は DB で届くが、翻訳ファイルがないと英語のままになるため（はじめての pull で日本語にならなかった。0.4.4）。送るのは送り先にないものと送り元のほうが新しいものだけで、送り先にしかないものは消さない。
- **メディア**: 両方の uploads の一覧（大きさと更新日時）を比べ、違うものだけを送る。大きなファイルは 2MB ずつに分ける。送ったファイルは更新日時を送り元に合わせるので、次は送らない。PHP などの実行できるファイルと `.htaccess` は送らない。
- **テーマ・プラグイン**: 選んだものだけ、フォルダの中のファイル（大きさと更新日時）を比べ、違うものを送る。送り先では `wp-content/wps-stage/` にいったん置き、全部届いてから一度に入れ替える（送っている途中で古いファイルと新しいファイルが混ざらない）。送り元にないファイルは消す。上書き・削除したファイルは `wp-content/wps-old/` に 1 回分だけ残す（手で戻すとき用）。`.git`・`.svn`・`node_modules` は送らず、送り先にあっても消さない。WP Sync 自身は送らない。有効・無効は変えない。
- 順番はテーマ・プラグイン → メディア → DB（pull で DB を入れ替えるとログインし直しになるので、DB を最後にする）。
- 途中の状態は送る側の `uploads/wp-sync/` に置く（ウェブから読めないようにしている）。
- 接続先につながらないとき（接続の時間切れ・接続できない・HTTP 429/502/503/504）は、3・10・20・30 秒待って 4 回までやり直す。メディアが多いと要求が何千回にもなり、サーバーが一時的に接続を受け付けないことがあるため（0.4.3）。要求が届いた後の失敗（応答待ちの時間切れ）は、何度送ってもよい操作（読み出し・ファイルの書き込みなど）だけやり直す。
- それでも止まったら、画面の「続きから再開」で止まったところから続けられる（DB のテーブルの途中で止まったときは、そのテーブルを最初から送り直す）。途中の状態は 1 日たつと消える。

## 更新

公開リポジトリ [yama203/wp-sync](https://github.com/yama203/wp-sync) の Release から更新する。プラグインのヘッダーに `Update URI: https://github.com/yama203/wp-sync` を書いているので、WordPress は wordpress.org に問い合わせず、`update_plugins_github.com` のフィルターで WP Sync が最新の Release を返す。最新の版は Release に添付した `wp-sync.json`（`releases/latest/download/wp-sync.json`）から読む。GitHub の API は認証なしだと IP ごとに 1 時間 60 回までで、共用サーバーでは同じ IP のほかの利用者と分け合うため、API は `wp-sync.json` がないときだけ使う。結果は 3 時間覚えておき、WordPress の更新情報が消されたとき（「もう一度確認する」・Site Hub の「今すぐ取得」）は一緒に消す。管理画面の「更新」・`wp plugin update wp-sync`・Site Hub の更新で、そのまま更新できる。

リリースの流れ：

1. `wp-sync/wp-sync.php` のヘッダーの `Version` と `WPS_VERSION` を上げてコミットする（サイト間の通信の形を変えたら `WPS_API_VERSION` も上げる。両側の版が違うと同期しない）
2. `npm run release`（`v{版}` のタグを push する）
3. GitHub Actions（`.github/workflows/release.yml`）が Release を作り、`wp-sync-{版}.zip` と `wp-sync.json`（版と ZIP の URL）を添付する

このフォルダの wp-env はソースを直接マウントしているので、`.wp-env.json` で `WPS_DISABLE_UPDATER` を true にして更新を止めている（更新するとソースが消えるため）。

## まだできないこと

- マルチサイトの 1 サイトだけの同期（サイト単位、シングルサイトとサブサイトの間の移行）
- mu-plugins の同期
- 一部のテーブルだけを選んで送ること

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

マルチサイトのテスト環境は `tests/multisite/`（http://localhost:10838 がローカル役、http://localhost:11838 が本番役。どちらもサブディレクトリ型）。

```
cd tests/multisite
../../node_modules/.bin/wp-env start
./setup.sh   # 起動のたびに。.htaccess と、host.docker.internal で来た要求を localhost として扱う sunrise.php を入れる
../../node_modules/.bin/wp-env run cli wp sync connect "<本番役の wp sync key>" --connect-url=http://host.docker.internal:11838
```
