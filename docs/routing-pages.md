---
title: ルーティングとページ
description: src/Pages 配下のファイル規約、ページの 2 つの書き方、動的セグメント、レイアウト。
category: ルーティング
order: 2
---

# ルーティングとページ

ルーターは `src/Pages/` をファイルシステムどおりにマッピングします
（Next.js App Router 互換の規約）。

| ファイル       | 役割                                               |
|----------------|----------------------------------------------------|
| `page.psx`     | そのルートを描画（1 ディレクトリに 1 つ）          |
| `layout.psx`   | 子ページをラップ（ルート→末端へ積み重ね）          |
| `error.psx`    | ルート直下の 404 / フォールバック                  |
| `route.php`    | JSON API エンドポイント                            |
| `[param]/`     | 動的セグメント（`$ctx->params['param']` で取得）   |

> 1 つのディレクトリは **ページ** か **`route.php`** のどちらか一方です（両立不可）。

## ページの 2 つの書き方

### 関数スタイル

```php
<?php
use App\Service\UserRepository;
use Polidog\Relayer\Router\Component\PageContext;

return function (PageContext $ctx, UserRepository $users): Closure {
    $ctx->metadata(['title' => 'Users']);

    return fn () => (
        <ul>
            {array_map(fn ($u) => <li>{$u->name}</li>, $users->all())}
        </ul>
    );
};
```

2 段（factory がレンダー用クロージャを返す）にすると、描画前に
メタデータやキャッシュポリシーを宣言できます。1 段で Element を直接返しても構いません。

### クラススタイル

```php
<?php
namespace App\Pages\Users;

use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\UsePhp\Runtime\Element;

final class UserDetailPage extends PageComponent
{
    public function __construct(private readonly UserRepository $users) {}

    public function render(): Element
    {
        $user = $this->users->find($this->getParam('id'));

        return <h1>{$user->name}</h1>;
    }
}
```

引数は **型で** オートワイヤされます。`PageContext`・`Request`・`Identity`
（null 許容なら任意、非 null なら認証必須を意味する）、そしてコンテナのサービス。
`$_GET / $_POST / $_SERVER` を直接読まず、必ず `Request` を受け取ってください。

## 動的セグメント

`src/Pages/docs/[slug]/page.psx` は `/docs/:slug` にマッチし、
値は `$ctx->params['slug']` で取得します（このサイトのドキュメント表示ページが実例）。
セグメントは 1 区切りで、スラッシュは含みません。

## レイアウト

`layout.psx` は `LayoutComponent` を継承したクラスで、`$this->getChildren()` に
子ページが入ります。ネストした各階層のレイアウトがルートから順に積み重なります。
レイアウトはコンテナ DI を介さず `new` で生成されるため、依存のないチャームに
留めるのが定石です（このサイトのヘッダ／フッタがそれ）。

## ルートの確認

```bash
vendor/bin/relayer routes
```

検出された全ページ・API エンドポイントとメソッドを一覧表示します。
