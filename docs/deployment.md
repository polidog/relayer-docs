---
title: デプロイ
description: このサイトの構成 — ローカル Markdown を CLI で Turso に同期し、Relayer が表示と検索を担当。
category: 運用
order: 13
---

# デプロイ

このドキュメントサイト自体の構成と運用手順です。Relayer のドッグフーディングであり、
**ドキュメントデータは Turso（libSQL）に置き、ローカルから CLI で更新、サーバーは
表示と全文検索だけを行う** という分離になっています。

## 全体像

```
docs/*.md  --(bin/docs sync)-->  Turso (libSQL)  <--(read)--  Relayer Web
   ローカル                         本番ストア                  表示 + 検索
```

- 接続先は環境変数で自動切替（`App\Docs\DocStoreFactory`）。
  - `TURSO_DATABASE_URL`（+ `TURSO_AUTH_TOKEN`）あり → **Turso**（本番）
  - なし → `var/docs.db` のローカル SQLite（資格情報ゼロで動く）
- PHP に libSQL 拡張は不要。Turso へは **HTTP API（`/v2/pipeline`）** に
  標準の https ストリームで接続します。
- 検索は SQLite **FTS5 + trigram** トークナイザ。日本語（分かち書き不要）と
  英語の部分一致に対応し、短いクエリは LIKE にフォールバックします。

## CLI でドキュメントを更新

```bash
bin/docs migrate            # スキーマ作成（冪等）
bin/docs status             # 差分プレビュー（新規/変更/削除）
bin/docs sync               # 変更分を upsert
bin/docs sync --prune       # ローカルから消えたページも削除
bin/docs list               # ストア内の一覧
```

各 `docs/<slug>.md` はフロントマターを持ちます。

```markdown
---
title: ページタイトル
description: 一覧と meta description に使われる説明
category: カテゴリ（サイドバーの見出し）
order: 10
---

# 本文（Markdown）
```

`slug` はファイル名（小文字・数字・ハイフン）。本文のハッシュで変更を検知し、
変わったページだけ送信します。FTS インデックスは行更新と同一トランザクションで
同期されるため、「読めるが検索できない」状態は発生しません。

## Turso のセットアップ

```bash
turso db create relayer-doc
turso db show relayer-doc --url          # libsql://... を控える
turso db tokens create relayer-doc       # 認証トークンを控える
```

`.env.local`（gitignore 済み）に設定します。

```dotenv
TURSO_DATABASE_URL=libsql://relayer-doc-xxxx.turso.io
TURSO_AUTH_TOKEN=eyJ...
```

設定後にローカルから本番へ反映：

```bash
bin/docs migrate
bin/docs sync --prune
```

## 本番サーバー（Cloud Run / nginx + php-fpm）

本番は **Google Cloud Run** にコンテナ（**nginx + php-fpm**）でデプロイします。
ステートレス（Turso は HTTP、ETag はリテラル、プロファイラは無効＝**実行時の
ファイル書き込みゼロ**）なので、Cloud Run の読み取り専用 FS と相性が良く、
ゼロスケールで低トラフィックなら実質無料です。

### PSX 事前コンパイルの要点

`APP_ENV` が dev 以外だと PSX はオンザフライ非コンパイル＝**事前コンパイル必須**。
キャッシュは 2 か所に分かれます（`Dockerfile` がビルド時に生成）。

- コンポーネント → `<root>/var/cache/psx`（マニフェストに**絶対パス**で記録）
- ページ/レイアウト → `<root>/src/var/cache/psx`（`dirname(src/Pages)`）

ポイント:

- `public/index.php` は `dirname(__DIR__)` で**正規化した絶対 projectRoot** を渡す
  （キャッシュキーがブレない）。
- ページは `<Shell>` 等を **`// @psx-runtime App\Components\Shell`** で宣言し、
  バッチコンパイラに「実行時マニフェストで解決」と伝える（無いと exit 1）。
- ビルドとランタイムで**同一の絶対パス**（`/var/www/html`）であること
  （キャッシュは `realpath` の sha1 キー）。

### デプロイ手順

```bash
# 1. Turso 認証情報を Secret に
gcloud secrets create turso-url   --replication-policy=automatic
printf '%s' "libsql://relayer-doc-xxxx.turso.io" | gcloud secrets versions add turso-url   --data-file=-
gcloud secrets create turso-token --replication-policy=automatic
printf '%s' "eyJ..."                              | gcloud secrets versions add turso-token --data-file=-

# 2. ビルド & デプロイ（ソースから。Dockerfile を使用）
gcloud run deploy relayer-doc \
  --source . \
  --region asia-northeast1 \
  --allow-unauthenticated \
  --cpu 1 --memory 512Mi \
  --min-instances 0 --max-instances 4 \
  --set-env-vars APP_ENV=production \
  --set-secrets 'TURSO_DATABASE_URL=turso-url:latest,TURSO_AUTH_TOKEN=turso-token:latest'
```

`--min-instances 0` でゼロスケール（無アクセス時は課金なし）。`$PORT` は Cloud Run が
注入し、`docker/entrypoint.sh` が nginx をその port にバインドします。

### GitHub Actions（CI/CD）

`.github/workflows/deploy.yml` が `main` への push（および手動実行）で
**アプリのデプロイのみ**を行います。**Workload Identity Federation**
（鍵レス／JSON 鍵不要）で GCP 認証し、`gcloud run deploy --source .` で
ビルド＆ロールアウト。

ドキュメント本文の同期は **CI では行いません**。`docs/*.md` はローカルから
CLI で Turso に直接書き込みます（下記）。サーバーは Turso を読むだけなので、
コードのデプロイとコンテンツ更新は独立しています。

必要な GitHub リポジトリ Secrets:

| Secret | 用途 |
|--------|------|
| `GCP_PROJECT_ID` | デプロイ先プロジェクト |
| `GCP_WORKLOAD_IDENTITY_PROVIDER` | WIF プロバイダのリソース名 |
| `GCP_SERVICE_ACCOUNT` | デプロイ用サービスアカウント |

任意の変数 `GCP_REGION`（既定 `asia-northeast1`）。GCP 側の一回限りの準備
（WIF プール/プロバイダ、ロール、API 有効化、Cloud Run が実行時に読む
Secret Manager の `turso-url`/`turso-token`）はワークフロー先頭のコメントに
手順を記載しています。

### ドキュメントの反映（ローカル CLI から）

`.env.local` に `TURSO_*` を設定し、ローカルから直接同期します。

```bash
bin/docs migrate
bin/docs sync --prune
```

> 補足: App Engine は Standard が PHP 8.5 非対応、Flex は最低 1 インスタンス常駐で
> 割高なため、本アプリでは Cloud Run を採用しています。
