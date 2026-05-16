---
title: HTTP キャッシュと ETag
description: '#[Cache] 属性 / $ctx->cache() による Cache-Control・ETag・304 応答。'
category: 機能
order: 10
---

# HTTP キャッシュと ETag

ページ単位で HTTP キャッシュポリシーを宣言できます。フレームワークが
`Cache-Control` / `ETag` を出力し、条件付きリクエストに `304 Not Modified`
を返します。

## クラススタイル（属性）

```php
use Polidog\Relayer\Http\Cache;

#[Cache(
    maxAge: 3600,
    public: true,
    vary: ['Accept-Language'],
    etag: 'home-v1',
)]
final class HomePage extends PageComponent {}
```

## 関数スタイル

```php
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Router\Component\PageContext;

return function (PageContext $ctx): Closure {
    $ctx->cache(new Cache(maxAge: 60, public: true, etagKey: 'feed'));

    return fn () => (/* ... */);
};
```

ファクトリは「軽い準備」、返したレンダークロージャに「重い処理」を置くのが規約です。
キャッシュヒット時はレンダークロージャ本体の実行を省けます。

リクエストの `If-None-Match` を評価し、クライアントが新鮮なコピーを持っていれば
`304 Not Modified` を返してボディを送りません。

> ドキュメント本文は CLI 同期時にしか変わらないため、`etag` を使った
> 長めの `maxAge` と相性が良い領域です。
