# relayer-doc

[Relayer](https://github.com/polidog/relayer) のドキュメントサイト。
Relayer 自身で作られた（ドッグフーディング）ドキュメントビューア + 全文検索です。

- **本文**: `docs/*.md`（フロントマター付き Markdown）をローカルで管理
- **更新**: CLI `bin/docs` で [Turso](https://turso.tech)（libSQL）へ同期
- **表示/検索**: Relayer（PHP）が表示と SQLite FTS5（trigram）全文検索を担当
- **UI**: Tailwind（Play CDN, ビルド不要）+ ダークモード

接続先は環境変数で自動切替：`TURSO_DATABASE_URL` があれば Turso、
無ければローカル `var/docs.db`（資格情報ゼロで動作）。

## セットアップ

```bash
composer install
mise exec -- php bin/docs migrate   # スキーマ作成
mise exec -- php bin/docs sync      # docs/*.md を取り込み
APP_ENV=dev php -S 127.0.0.1:8000 -t public
```

PHP 8.5 以上が必要です（このリポジトリは `mise.toml` で 8.5 を固定）。
ブラウザで <http://127.0.0.1:8000> を開きます。

## CLI

```bash
bin/docs migrate            スキーマ作成（冪等）
bin/docs status             同期前の差分プレビュー
bin/docs sync [--prune]     変更分を upsert（--prune で削除も反映）
bin/docs list               ストア内の一覧
```

## ドキュメントの書き方

`docs/<slug>.md`（slug は小文字・数字・ハイフン）:

```markdown
---
title: ページタイトル
description: 一覧 / meta description に使われる説明
category: サイドバーの見出し
order: 10
---

# 本文（Markdown）
```

編集 → `bin/docs sync` で反映。本文ハッシュで変更検知し、変わった
ページだけ送信します。

## 構成

```
docs/                     ソースの Markdown（13 本）
bin/docs                  同期 CLI（PHP）
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

## 本番デプロイ（Cloud Run / nginx + php-fpm）

`Dockerfile`（`php:8.5-fpm` + nginx）で Cloud Run にデプロイします。ビルド時に
PSX を 2 か所へ事前コンパイル（コンポーネント→`var/cache/psx`、ページ→
`src/var/cache/psx`）、実行時はファイル書き込みゼロ＝読み取り専用 FS でも動作。

```bash
gcloud run deploy relayer-doc --source . --region asia-northeast1 \
  --allow-unauthenticated --min-instances 0 \
  --set-env-vars APP_ENV=production \
  --set-secrets 'TURSO_DATABASE_URL=turso-url:latest,TURSO_AUTH_TOKEN=turso-token:latest'
```

ローカル確認: `docker build -t relayer-doc . && docker run -p 8080:8080 \
 -e PORT=8080 -e TURSO_DATABASE_URL=... -e TURSO_AUTH_TOKEN=... relayer-doc`

### CI/CD（GitHub Actions）

`.github/workflows/deploy.yml` が `main` push / 手動実行で
**アプリのデプロイのみ**（WIF キーレス認証 → `gcloud run deploy --source .`）
を実行します。必要な GitHub Secrets: `GCP_PROJECT_ID` /
`GCP_WORKLOAD_IDENTITY_PROVIDER` / `GCP_SERVICE_ACCOUNT`（任意変数
`GCP_REGION`）。GCP 側の一回限りの準備はワークフロー先頭コメント参照。

ドキュメント更新は CI ではなく**ローカル CLI**から: `bin/docs sync --prune`
（サーバーは Turso を読むだけ）。詳細はサイト内
「[デプロイ](http://127.0.0.1:8000/docs/deployment)」を参照。
