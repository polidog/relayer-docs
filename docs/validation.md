---
title: バリデーション
description: Zod 風スキーマバリデータ。型・制約・フィールド別エラー。
category: 機能
order: 9
---

# バリデーション

Zod に着想を得たスキーマバリデータで、フィールド別エラーを返します。

```php
<?php
use Polidog\Relayer\Validation\Validator;

$schema = Validator::object([
    'email' => Validator::string()->trim()->email(),
    'name'  => Validator::string()->trim()->min(1, '名前は必須です。'),
    'age'   => Validator::int()->min(0)->optional(),
]);

$result = $schema->safeParse($_POST);

if ($result->success) {
    $data = $result->data;          // 検証済み・型付きデータ
} else {
    $errors = $result->errors;      // ['email' => '...'] 形式
}
```

## 利用できる型

`string()` / `int()` / `float()` / `bool()` / `enum()` / `object()` / `array()`、
および `email()` / `url()` などの制約。

`string()->trim()->min(1)` のようにメソッドチェーンで制約を積み、
`optional()` で任意フィールドにします。各エラーメッセージは制約メソッドの
第 2 引数で上書きできます。

## サーバーアクションと組み合わせる

```php
$save = $ctx->action('save', function (array $form) use ($ctx) {
    $r = Validator::object([
        'title' => Validator::string()->trim()->min(1, 'タイトルは必須'),
    ])->safeParse($form);

    if (!$r->success) {
        // $r->errors を画面に表示
        return;
    }

    // $r->data を保存して PRG
    $ctx->redirect('/done');
});
```

`safeParse()` は例外を投げず `ParseResult` を返すので、フォームの
再表示とエラー表示が素直に書けます。
