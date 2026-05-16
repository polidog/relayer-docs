---
title: API ルート
description: route.php によるメソッド別 JSON ハンドラの定義方法とレスポンス規約。
category: ルーティング
order: 3
---

# API ルート

ディレクトリに `route.php` を置くと、そのパスは JSON API になります。
ファイルは **HTTP メソッドをキーにしたハンドラのマップだけ** を return します。

```php
<?php
use App\Service\UserRepository;
use Polidog\Relayer\Http\Request;

return [
    'GET'  => fn (UserRepository $users): array => ['users' => $users->all()],
    'POST' => function (Request $req, UserRepository $users): array {
        $users->create($req->allPost());

        return ['ok' => true];
    },
];
```

## 規約

- キーは HTTP メソッド（大文字小文字は無視）。
- 値はオートワイヤされるクロージャ。ページと同じリゾルバなので
  `PageContext`・`Request`・`Identity`・コンテナサービスが型で注入されます。
- 戻り値はそのまま JSON 化されます。`null` は `204 No Content`。
- ハンドラ内で `\http_response_code()` を先に設定すればそのまま反映されます。
- 未対応メソッドは `405` と `Allow` ヘッダ。認証失敗は JSON の `401` / `403`。
- このファイルは**宣言禁止**（毎リクエスト再評価されるため、return のみ）。

## レスポンスオブジェクトを作らない

API ルートで自前の Response を組み立てないでください。データを返せば
フレームワークがエンコードします。

```php
return [
    'GET' => fn (): array => ['time' => date('c')],
];
```

動的セグメントの値はハンドラ内で `$ctx->params['id']` から取得します
（`PageContext` を引数に取る）。

## 実例

このサイトの `/api/search` は `src/Pages/api/search/route.php` で、
`Request` と `DocStore` を注入し、全文検索結果を JSON で返します。
1 つのディレクトリはページか `route.php` のどちらか一方なので、HTML 検索ページ
（`/search`）とは別ディレクトリに分けています。
