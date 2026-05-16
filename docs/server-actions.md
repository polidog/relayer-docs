---
title: サーバーアクション
description: CSRF 保護付きフォームハンドラ。関数スタイル／クラススタイルの登録方法。
category: 機能
order: 5
---

# サーバーアクション

サーバーアクションは、発行したトークン付きフォームが送信されたときに
実行されるクロージャです。CSRF 検証は自動で、ハンドラは `render()` の前に走ります。

## 関数スタイル

```php
<?php
use App\Service\UserRepository;
use Polidog\Relayer\Router\Component\PageContext;

return function (PageContext $ctx, UserRepository $users): Closure {
    $save = $ctx->action('save', function (array $form) use ($users, $ctx): void {
        $users->create($form['name']);
        $ctx->redirect('/users'); // 303 See Other（PRG）
    });

    return fn () => (
        <form action={$save}>
            <input name="name" />
            <button>save</button>
        </form>
    );
};
```

`$ctx->action(name, handler)` は登録し、フォームに埋め込むトークンを返します。
ファクトリは毎リクエスト再実行されるため、トークンは `(pageId, name)` だけを
エンコードすれば十分です。

## クラススタイル

```php
public function render(): Element
{
    return (
        <form method="post">
            <input type="hidden" name="_usephp_action"
                   value={$this->action([$this, 'save'])} />
            <input name="title" />
        </form>
    );
}

public function save(array $form): void
{
    // $form['title'] を処理
    header('Location: /dashboard', true, 303);
    exit;
}
```

## リダイレクト

ハンドラ内で `$ctx->redirect('/path')` を呼ぶと `RedirectException` が投げられ、
`AppRouter` が `Location` レスポンスに変換します（既定 303、POST 後の
Post/Redirect/Get に最適）。これ以降のコードは実行されません。

CSRF トークンが不正な場合は `403` を返します。
