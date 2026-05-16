---
title: サービスと DI
description: services.yaml と AppConfigurator によるサービス登録、オートワイヤ、ファクトリ。
category: 機能
order: 7
---

# サービスと DI

Relayer は Symfony の DI コンテナを使い、**オートワイヤ + public** が既定です。
登録方法は 2 通りあり、併用できます。

## config/services.yaml

```yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: true

  App\Service\PdoUserRepository: ~

  App\Service\UserRepository:
    alias: App\Service\PdoUserRepository
```

`.yaml` / `.yml` / `.php` 形式に対応。引数なしで登録した定義には自動で
オートワイヤと public が付与されます（PSR-11 の `get($id)` で取得可能）。

## AppConfigurator

```php
<?php
namespace App;

use Polidog\Relayer\AppConfigurator as BaseConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AppConfigurator extends BaseConfigurator
{
    public function configure(ContainerBuilder $container): void
    {
        $container->register(PdoUserRepository::class);
        $container->setAlias(UserRepository::class, PdoUserRepository::class)
            ->setPublic(true);
    }
}
```

`Relayer::boot(__DIR__ . '/..', new App\AppConfigurator(__DIR__ . '/..'))->run();`
のように渡します。`services.yaml` の後に走るので上書きも可能。
プロジェクトルートは `$this->projectRoot`、`%app.project_root%` パラメータも使えます。

## ファクトリ（実行時に実装を選ぶ）

このサイトの `DocStore` は、環境変数で接続先を切り替えるためファクトリで生成しています。

```yaml
services:
  App\Docs\DocStore:
    factory: ['App\Docs\DocStoreFactory', 'create']
    arguments:
      - '%app.project_root%'
    autowire: false
```

`DocStoreFactory::create()` は `TURSO_DATABASE_URL` があれば Turso、なければ
ローカル SQLite を返します。ページや `route.php` は引数に `DocStore` を
型宣言するだけで注入されます。

ページ／API ハンドラの引数は **型でオートワイヤ**されます。
`PageContext`・`Request`・`Identity`、そして登録済みサービス。
