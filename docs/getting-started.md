---
title: はじめに
description: Relayer のインストールと最初のページ作成までの最短手順。
category: はじめに
order: 1
---

# はじめに

**Relayer** は [`polidog/use-php`](https://github.com/polidog/use-php) の上に構築された、
意見の強い PHP フルスタックフレームワークです。Next.js App Router 風の規約で、
ファイルベースルーティング・JSON API・サーバーアクション・認証・バリデーション・
キャッシュ・DB をひとつの `boot` エントリにまとめます。

## 必要環境

- PHP **8.5** 以上
- Composer
- `polidog/use-php`、Symfony の DI / Config / YAML / Dotenv（`composer require` が自動で導入）

## インストール

```bash
composer require polidog/relayer
vendor/bin/relayer init
composer install
php -S 127.0.0.1:8000 -t public
```

`vendor/bin/relayer init` は `public/index.php`・`src/Pages/`・`config/services.yaml`・
`.env` などの雛形を生成します。

## エントリポイント

アプリ全体はこの 1 ファイルから起動します（`public/index.php`）。

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Polidog\Relayer\Relayer;

Relayer::boot(__DIR__ . '/..')->run();
```

`Relayer::boot()` は `.env` を読み込み、Symfony の DI コンテナを構築し、
`AppRouter` を返します。`->run()` を呼ぶまで `addCssPath()` などの設定を追加できます。

## 最初のページ

`src/Pages/page.psx` がトップページ（`/`）です。`.psx` は JSX 風の構文で書ける PHP で、
use-php がコンパイルします。

```php
<?php
declare(strict_types=1);

use Polidog\UsePhp\Html\H;

return fn () => (
    <section>
        <h1>It works</h1>
        <p>src/Pages/page.psx を編集してください。</p>
    </section>
);
```

ディレクトリを切ると URL セグメントになり、`[id]` ディレクトリは動的セグメントです。
詳しくは [ルーティングとページ](/docs/routing-pages) を参照してください。

## このサイトについて

このドキュメントサイト自体が Relayer 製です。本文は Markdown としてローカルで管理し、
CLI で [Turso](https://turso.tech)（libSQL）に同期、サーバー側は Relayer が表示と
全文検索を担当します。仕組みは [デプロイ](/docs/deployment) を参照してください。

## 開発と本番

`.env` の `APP_ENV=dev` は PSX のオンザフライコンパイル・プロファイラ・トレースを
有効にします。それ以外（未設定含む）は本番扱いで、デプロイ時に
`vendor/bin/usephp compile src/Pages` で事前コンパイルします。
