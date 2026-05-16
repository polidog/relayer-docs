---
title: 認証
description: UserProvider の実装、ログイン、ロールベースのページ保護。
category: 機能
order: 8
---

# 認証

セッションベースの認証で、`UserProvider` とパスワードハッシュは差し替え可能です。

## 1. UserProvider を実装

```php
<?php
use Polidog\Relayer\Auth\{Credentials, Identity, UserProvider};

final class PdoUserProvider implements UserProvider
{
    public function __construct(private readonly \PDO $pdo) {}

    public function findByIdentifier(string $identifier): ?Credentials
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, password_hash, roles FROM users WHERE email = ?'
        );
        $stmt->execute([\strtolower(\trim($identifier))]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (false === $row) {
            return null;
        }

        return new Credentials(
            identity: new Identity(
                id: (int) $row['id'],
                displayName: (string) $row['name'],
                roles: \json_decode((string) $row['roles'], true) ?: [],
            ),
            passwordHash: (string) $row['password_hash'],
        );
    }
}
```

## 2. プロバイダをバインド

```yaml
services:
  App\Auth\PdoUserProvider: ~
  Polidog\Relayer\Auth\UserProvider:
    alias: App\Auth\PdoUserProvider
```

`UserProvider` が登録されると `Authenticator` が自動配線されます。

## 3. ログイン

```php
<?php
use Polidog\Relayer\Auth\Authenticator;
use Polidog\Relayer\Router\Component\PageContext;

return function (PageContext $ctx, Authenticator $auth): Closure {
    $error = null;
    $login = $ctx->action('login', function (array $form) use ($auth, $ctx, &$error): void {
        $identity = $auth->attempt(
            (string) ($form['email'] ?? ''),
            (string) ($form['password'] ?? ''),
        );
        if (null === $identity) {
            $error = 'メールアドレスまたはパスワードが違います。';

            return;
        }
        $ctx->redirect('/dashboard');
    });

    return fn () => (<form action={$login}>{/* ... */}</form>);
};
```

## 4. ページを保護

**クラススタイル**は属性で：

```php
#[Auth]
final class DashboardPage extends PageComponent {}

#[Auth(roles: ['admin'])]
final class AdminPage extends PageComponent {}
```

**関数スタイル**は `PageContext` で：

```php
return function (PageContext $ctx): Closure {
    $user = $ctx->requireAuth();             // 未認証なら例外→リダイレクト/401
    // $ctx->requireAuth(['admin']) でロール必須
    return fn () => <h1>ようこそ {$user->displayName}</h1>;
};
```

条件付き表示には `$ctx->user()`（未ログインなら null）を使います。
非 null の `Identity` を引数に取るページは「認証必須」を意味します。
