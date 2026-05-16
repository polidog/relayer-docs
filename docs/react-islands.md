---
title: React アイランド
description: サーバーレンダリングされた HTML 内にクライアント React コンポーネントを埋め込む。
category: 機能
order: 6
---

# React アイランド

リッチな UI が必要な箇所だけ、クライアント React コンポーネントを
「アイランド」としてマウントできます（エスケープハッチ）。

```php
<?php
use Polidog\Relayer\React\Island;

return fn () => (
    <section>
        <h1>Dashboard</h1>
        {Island::mount('Chart', ['points' => $data])}
    </section>
);
```

フレームワークは次のようなプレースホルダを出力します。

```html
<div data-react-island="Chart" data-react-props='…'></div>
```

クライアント側の JS バンドル（vite / esbuild などで自分でビルド）で
コンポーネントを登録します。

```js
import Chart from './islands/Chart';

window.relayerIslands.register('Chart', (el, props) => {
    createRoot(el).render(<Chart {...props} />);
});
```

ローダーはドキュメントに 1 度だけ追加します。

```php
$document->addHeadHtml(Island::loaderScript($nonce));
$document->addHeadHtml('<script type="module" src="/islands.js"></script>');
```

- SSR はありません（アイランドはクライアントでのみ描画）。
- アイランド↔サーバー間のやり取りは、自分の `route.php` への `fetch` で行います。
- バンドルの用意は利用者の責任です。フレームワークに Node ビルドを足しません。

> このドキュメントサイトはアイランドを使わず、サーバーレンダリングのみで
> 構成しています（検索もサーバー側 `route.php` / ページで完結）。
