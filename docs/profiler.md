---
title: プロファイラ
description: 開発時のリクエストトレース。/_profiler とトレーサブルデコレータ。
category: 運用
order: 12
---

# プロファイラ

`APP_ENV=dev` のとき、リクエストのライフサイクルがコンテナ束縛の
プロファイラに記録されます。

- `AppRouter` は `TraceableAppRouter` に差し替わり、ディスパッチの
  各イベントが記録されます。
- `EtagStore` / `SessionStorage` / `Database` / `Authenticator` は
  トレーサブルデコレータに差し替わり、`cache.*` / `session.*` /
  クエリ / 認証イベントがタイムラインに並びます。
- 記録は `var/cache/profiler` に保存されます。

本番（`APP_ENV` が dev 以外）では `Profiler` は `NullProfiler` に解決され、
ユーザーコードが `Profiler` 依存を取ってもコストはありません。
`Traceable*` クラスはオートロードすらされません。

## 除外パス

ノイズになるパスは環境変数で除外できます。

```dotenv
PROFILER_EXCLUDED_PATHS=/_profiler,/assets,/favicon.ico
```

カンマ区切りで、前方一致のプレフィックスとして扱われます。

## ルートの一覧

プロファイラとは別に、検出済みルートはコマンドで確認できます。

```bash
vendor/bin/relayer routes
```

