# relayer-doc

[Relayer](https://github.com/polidog/relayer) のドキュメントサイト。
Relayer 自身で作られた（ドッグフーディング）ドキュメントビューア + 全文検索です。

- **本文**: [Turso](https://turso.tech)（libSQL）を**唯一の正**として保持（中間ファイル無し）
- **編集**: CLI `bin/docs`（`$EDITOR`）で Turso を直接読み書き
- **表示/検索**: Relayer（PHP）が表示と SQLite FTS5（trigram）全文検索を担当
- **UI**: Tailwind（Play CDN, ビルド不要）+ ダークモード

接続先は環境変数で自動切替：`TURSO_DATABASE_URL` があれば Turso、
無ければローカル `var/docs.db`（資格情報ゼロで動作）。

## セットアップ

```bash
composer install
mise exec -- php bin/docs migrate          # スキーマ作成
mise exec -- php bin/docs new my-first-page # 記事を書く（$EDITOR）
APP_ENV=dev php -S 127.0.0.1:8000 -t public
```

PHP 8.5 以上が必要です（このリポジトリは `mise.toml` で 8.5 を固定）。
ブラウザで <http://127.0.0.1:8000> を開きます。

## CLI（記事はストアが唯一の正・ソースファイル無し）

```bash
bin/docs migrate               スキーマ作成（冪等）
bin/docs list                  記事一覧
bin/docs new  <slug>           新規作成（$EDITOR が開く）
bin/docs edit <slug>           既存を編集（$EDITOR が開く）
bin/docs show <slug>           保存内容を表示
bin/docs rm   <slug> [--force] 削除
bin/docs export <dir>          全記事を .md で書き出し（バックアップ/移行）
bin/docs import <file.md>...   .md を取り込み（移行用）
```

`new` / `edit` はフロントマター + Markdown 本文の編集バッファを `$EDITOR`
で開き、保存時に Turso へ upsert します（非対話は `--file <path>`、`-` で
stdin）。バッファ形式:

```markdown
---
title: ページタイトル
description: 一覧 / meta description に使われる説明
category: サイドバーの見出し
order: 10
---

# 本文（Markdown）
```

`slug` はコマンド引数。`docs/*.md` のような中間ソースは持たず、Turso が
一元的なソースです。バックアップ/移行は `export` / `import` を使います。

## 構成

```
bin/docs                  記事編集 CLI（PHP, $EDITOR で Turso を直接編集）
src/Docs/                 ストア層 + Markdown→Element レンダラ
  DocStore.php            FTS5 含む SQL（Pdo / Turso 共通）
  PdoConnection.php       ローカル SQLite
  TursoConnection.php     Turso HTTP API（/v2/pipeline, curl 不要）
  Markdown.php            Markdown → use-php Element ツリー
  Nav.php                 ナビ用データ（マークアップ無し）
src/Components/           共有 PSX コンポーネント（Shell / SearchForm）
src/Pages/                ルート（layout.psx / page.psx / docs/[slug] / search / api/search）
src/DocumentFactory.php   <head> 一式（Tailwind CDN + dark mode 等）。毎リクエスト生成
public/index.php          エントリ（classic mode）
worker.php                エントリ（FrankenPHP worker mode。公開ディレクトリ外）
Dockerfile                本番イメージ（FrankenPHP + アプリツリー）
static-build.Dockerfile   シングルバイナリ版イメージ（下記）
docker/embed/             シングルバイナリ版だけが使う Caddyfile / php.ini / entrypoint
```

詳細・デプロイ手順はサイト内の「[デプロイ](http://127.0.0.1:8000/docs/deployment)」を参照。

## 本番デプロイ（Fly.io / FrankenPHP）

`static-build.Dockerfile`（シングルバイナリ）を `fly.toml` でビルドして Fly.io に
デプロイします。アプリ・PHP 8.5・拡張・Caddy を内包した約104MB の実行ファイル 1 つ＋
CA 証明書だけのイメージ（192MB）。FrankenPHP（Caddy + 埋め込み PHP）が単一プロセスで
HTTP を直接処理＝nginx/php-fpm 不要。worker は使わず classic mode（php-fpm
ドロップイン）。PSX とルートのコンパイルは**起動時**（約0.1秒、`docker/embed/`）。
リージョンは `nrt`（東京）、アイドル時はマシン停止＝ゼロスケール。

`Dockerfile`（`dunglas/frankenphp:php8.5` + アプリツリー）も動く状態で残してあり、
`fly.toml` の `dockerfile =` を戻すだけで完全にロールバックできます。両者のレスポンスが
バイト単位で一致することは切り替え前に検証済み。**ビルドは PHP をソースからコンパイル
するため約45分**（16コア実測）かかる点だけ注意。

一回限りの準備（`fly` 未インストールなら `curl -L https://fly.io/install.sh | sh`）:

```bash
fly auth login
fly apps create relayer-doc          # 名前が埋まっていたら fly.toml の app= を変更
fly secrets set \
  "TURSO_DATABASE_URL=$(grep '^TURSO_DATABASE_URL=' .env.local | cut -d= -f2-)" \
  "TURSO_AUTH_TOKEN=$(grep '^TURSO_AUTH_TOKEN=' .env.local | cut -d= -f2-)" \
  --app relayer-doc
fly deploy                           # 初回はローカルから動作確認
```

Google Analytics（任意）: GA4 測定 ID を Fly secret に設定すると
**本番のみ**計測が有効になります。未設定なら `gtag` を一切出力しない
ので、ローカル/dev のトラフィックは計測されません。値は `G-XXXXXXXXXX`
形式のみ受理（不正値は無視）。

```bash
fly secrets set GA_MEASUREMENT_ID=G-XXXXXXXXXX --app relayer-doc
```

ローカル確認: `docker build -t relayer-doc . && docker run -p 8080:8080 \
 -e TURSO_DATABASE_URL=... -e TURSO_AUTH_TOKEN=... relayer-doc`

### シングルバイナリ版（`static-build.Dockerfile`）— 本番はこちら

アプリ・PHP 8.5・拡張・Caddy を 1 つの実行ファイルに詰めたビルド。ローカルで動かすには:

```bash
GH_TOKEN="$(gh auth token)" docker build \
  --secret id=github_token,env=GH_TOKEN \
  -t relayer-doc-static -f static-build.Dockerfile .

docker run --rm -p 8080:8080 --env-file .env.local relayer-doc-static      # classic mode
docker run --rm -p 8080:8080 --env-file .env.local \
  -e RELAYER_WORKER=1 relayer-doc-static                                    # worker mode
```

`GH_TOKEN` は任意（未指定でも動く）。static-php-cli が ~35 本のソースの最新版を
`api.github.com` で解決するため、未認証の 60 req/h を使い切ると途中で 403 になる。

計測値（既存イメージとの比較）:

| | `Dockerfile` | `static-build.Dockerfile` |
|---|---|---|
| イメージ | 655MB | **192MB**（バイナリ 104MB + CA 証明書のみ） |
| 起動 → 初回 200 | 1393ms | **700ms** |
| アイドル時 RSS | 88MB | **51MB** |
| ビルド時間 | 約2分 | **約45分**（PHP をソースからビルド） |
| レスポンス | — | 全エンドポイントでバイト単位一致 |

`Dockerfile` と違い PSX / ルートのコンパイルは**起動時**に走る（約0.1秒）。埋め込み
アプリの絶対パスがビルド前に確定しないため（詳細は `docker/embed/embed-compile.php`）。

**worker mode について**: 実装・検証済みだが、このアプリでは**性能上の利点は測定
できなかった**。単発レイテンシは classic と同一（ホーム 121ms / doc ページ 196ms、
いずれも Turso 往復が支配的で、PHP 側は `/robots.txt` の 3ms が示すとおり誤差）。
RSS は +8MB。切り替えは同一バイナリのまま `RELAYER_WORKER=1` と再起動だけで、
ロールバックにリビルドが要らない。Turso をローカルレプリカに寄せるなど I/O 律速で
なくなった時に再評価する。

### CI/CD（GitHub Actions）

`.github/workflows/deploy.yml` が `main` push / 手動実行で `flyctl deploy
--remote-only`（Fly のリモートビルダーで Dockerfile をビルド）を実行し、
**成功後に Cloudflare のゾーンキャッシュをパージ**します（ページは
`s-maxage` を持つため、パージしないと最大 TTL の間エッジが旧ビルドを
配信し続ける）。必要な設定（GitHub → Settings → Secrets and variables
→ Actions、名前は完全一致・前後空白なし）:

- `FLY_API_TOKEN` — **Secret**。`fly tokens create deploy --app relayer-doc` で発行
- `CLOUDFLARE_ZONE_ID` — 対象ゾーン Overview の「Zone ID」。機密ではないので
  **Secret / Variable どちらでも可**（ワークフローは両方を見る）
- `CLOUDFLARE_API_TOKEN` — **Secret のみ**（資格情報。Variable はマスクされない）。
  My Profile → API Tokens → Create Token、権限「Zone › Cache Purge ›
  Purge」、対象ゾーンにスコープ

Cloudflare の2つが未設定だとデプロイ後のパージステップが、どちらが
欠けているかを明示して失敗します。

記事の更新は CI ではなく**ローカル CLI**から Turso を直接編集:
`bin/docs edit <slug>`（サーバーは Turso を読むだけ）。詳細はサイト内
「[デプロイ](http://127.0.0.1:8000/docs/deployment)」を参照。
