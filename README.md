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
public/index.php          エントリ（Tailwind CDN + dark mode を注入）
```

詳細・デプロイ手順はサイト内の「[デプロイ](http://127.0.0.1:8000/docs/deployment)」を参照。

## 本番デプロイ（Fly.io / FrankenPHP）

`Dockerfile`（`dunglas/frankenphp:php8.5`）を `fly.toml` でそのままビルドして
Fly.io にデプロイします。FrankenPHP（Caddy + 埋め込み PHP）が単一プロセスで
HTTP を直接処理＝nginx/php-fpm 不要。worker は使わず classic mode（php-fpm
ドロップイン）。ビルド時に PSX を 2 か所へ事前コンパイル（コンポーネント→
`var/cache/psx`、ページ→`src/var/cache/psx`）、実行時はファイル書き込みゼロ。
リージョンは `nrt`（東京）、アイドル時はマシン停止＝ゼロスケール。

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

ローカル確認: `docker build -t relayer-doc . && docker run -p 8080:8080 \
 -e TURSO_DATABASE_URL=... -e TURSO_AUTH_TOKEN=... relayer-doc`

### CI/CD（GitHub Actions）

`.github/workflows/deploy.yml` が `main` push / 手動実行で `flyctl deploy
--remote-only`（Fly のリモートビルダーで Dockerfile をビルド）を実行し、
**成功後に Cloudflare のゾーンキャッシュをパージ**します（ページは
`s-maxage` を持つため、パージしないと最大 TTL の間エッジが旧ビルドを
配信し続ける）。必要な GitHub Secret は3つ:

- `FLY_API_TOKEN` — `fly tokens create deploy --app relayer-doc` で発行
- `CLOUDFLARE_ZONE_ID` — Cloudflare ダッシュボードの対象ゾーン Overview の「Zone ID」
- `CLOUDFLARE_API_TOKEN` — My Profile → API Tokens → Create Token、
  権限「Zone › Cache Purge › Purge」、対象ゾーンにスコープ

いずれも GitHub → Settings → Secrets and variables → Actions に登録。
Cloudflare の2つが未設定だとデプロイ後のパージステップで失敗します。

記事の更新は CI ではなく**ローカル CLI**から Turso を直接編集:
`bin/docs edit <slug>`（サーバーは Turso を読むだけ）。詳細はサイト内
「[デプロイ](http://127.0.0.1:8000/docs/deployment)」を参照。
