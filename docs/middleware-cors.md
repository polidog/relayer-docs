---
title: ミドルウェアと CORS
description: src/Pages/middleware.php による全ルート共通処理と、組み込み CORS の使い方。
category: ルーティング
order: 4
---

# ミドルウェアと CORS

`src/Pages/middleware.php`（任意）は、全ルートのディスパッチを 1 つの
クロージャでラップします。

```php
<?php
use Polidog\Relayer\Http\Request;

return function (Request $request, Closure $next): void {
    if (null === $request->header('x-api-key')) {
        \http_response_code(401);
        echo '{"error":"missing api key"}';

        return; // $next() を呼ばなければショートサーキット
    }

    $next($request);
};
```

- クロージャは 1 つだけ。チェーンランナーはなく、必要なら手で合成します。
- `route.php` 同様、宣言禁止（return のみ、毎リクエスト評価）。
- `$next($request)` を呼ばなければ後段を実行せず終了（401 / 429 など）。

## CORS

CORS は手書きせず、組み込みミドルウェアを使います。

```php
<?php
use Polidog\Relayer\Http\Cors;

return Cors::middleware([
    'origins' => ['https://app.example.com'],
    // 'methods', 'headers', 'credentials', 'maxAge' は任意
]);
```

すべてのオリジンを許可する場合は `['origins' => ['*']]`。
プリフライト（`OPTIONS`）への応答も `Cors::middleware()` が処理します。
