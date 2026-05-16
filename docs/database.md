---
title: データベース
description: DATABASE_DSN による PDO ラッパー Database のオートワイヤとリクエストキャッシュ。
category: 機能
order: 11
---

# データベース

`DATABASE_DSN` を設定すると Db レイヤーが自動配線されます。

```dotenv
DATABASE_DSN=mysql:host=127.0.0.1;dbname=app;charset=utf8mb4
DATABASE_USER=app
DATABASE_PASSWORD=secret
DATABASE_TIMEOUT=5
DATABASE_READ_TIMEOUT=10
```

DSN はそのまま PDO に渡されます（`%placeholder%` 展開なし）。
SQLite は **絶対パス**が必要です（相対パスはプロセスの cwd 基準で解決される）。

```dotenv
DATABASE_DSN=sqlite:/srv/app/var/app.db
```

## 使い方

```php
<?php
use Polidog\Relayer\Db\Database;
use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\UsePhp\Runtime\Element;

final class UserPage extends PageComponent
{
    public function __construct(private readonly Database $db) {}

    public function render(): Element
    {
        $user = $this->db->fetchOne(
            'SELECT id, name FROM users WHERE id = :id',
            ['id' => 42],
        );

        return <h1>{$user['name']}</h1>;
    }
}
```

メソッド: `fetchAll()` / `fetchOne()` / `fetchValue()` / `perform()` /
`lastInsertId()` / `transactional()`。

`Database` エイリアスは常に `CachingDatabase`（リクエストスコープの
読み取りメモ化）に解決され、dev ではプロファイラ用に `TraceableDatabase` で
ラップされます。

## このサイトのストレージ

ドキュメント本体は `DATABASE_DSN` の Db レイヤーではなく、独自の
`DocStore` を使っています。理由は **Turso（libSQL）に HTTP API 経由で
接続**し、ローカルでは `pdo_sqlite` にフォールバックするため。
SQLite の FTS5（trigram トークナイザ）で日本語・英語の部分一致検索を
実現しています。詳細は [デプロイ](/docs/deployment)。
