<?php

declare(strict_types=1);

namespace App;

/**
 * Copy and figure data for the landing page (`/`), per locale.
 *
 * It lives in a `.php` file rather than inline in `src/Pages/page.psx`
 * for two reasons. First, the same rule the rest of the app follows:
 * data helpers return plain arrays and never markup, so the `.psx` view
 * renders them with JSX. Second, a practical one — the landing page
 * shows a `.psx` code sample, and JSX-looking text inside a `.psx`
 * source is exactly what the PSX compiler is looking for. Keeping the
 * sample in a nowdoc in a plain `.php` file puts it out of reach of the
 * compiler entirely.
 *
 * Every figure describes THIS repository: the route map is the real
 * `src/Pages` tree, the lifecycle is the real request path (see
 * `src/Pages/middleware.php` and `App\PageCache`), and the colophon
 * numbers come from the measured single-binary build. If any of those
 * move, this file moves with them.
 */
final class Landing
{
    /**
     * The `.psx` page sample shown as FIG.01, in the hero — a whole
     * route: a dynamic segment, page metadata and a returned element
     * tree, in twelve lines. It sits above the fold on purpose: the
     * opening `<?php` and the `use Polidog\…` lines are what make it
     * unmistakable at a glance that this is a PHP framework.
     *
     * Nowdoc: no interpolation, no escaping games.
     */
    private const SAMPLE = <<<'PHP'
        <?php

        use Polidog\Relayer\Router\Component\PageContext;
        use Polidog\UsePhp\Html\H;

        return function (PageContext $ctx): Closure {
            $ctx->metadata(['title' => 'Hello']);

            return fn () => (
                <main className="p-8">
                    <h1>Hello, {$ctx->params['name']}</h1>
                </main>
            );
        };
        PHP;

    /**
     * @return array{
     *     eyebrow: string,
     *     headline: list<string>,
     *     lead: string,
     *     install: string,
     *     spec: list<array{0: string, 1: string}>,
     *     ctaPrimary: string,
     *     ctaSecondary: string,
     *     figures: array<string, string>,
     *     lifecycle: array{input: string, steps: list<array{0: string, 1: string}>, output: string},
     *     lifecycleNote: string,
     *     sampleRequest: string,
     *     sampleResponse: string,
     *     routes: list<array{0: string, 1: string}>,
     *     routesNote: string,
     *     specs: list<array{key: string, title: string, body: string, slug: string}>,
     *     specsMore: string,
     *     sample: string,
     *     sampleNote: string,
     *     colophon: list<array{0: string, 1: string}>,
     *     colophonNote: string,
     *     closing: list<string>,
     *     closingCta: string
     * }
     */
    public static function content(string $locale): array
    {
        return 'en' === $locale ? self::en() : self::ja();
    }

    /** @return array<string, mixed> */
    private static function ja(): array
    {
        return [
            'eyebrow' => 'Relayer — PHP full-stack framework',
            'headline' => ['PHP のファイルを置く。', 'それが、URL になる。'],
            'lead' => 'Relayer は Next.js の App Router に着想を得た、規約重視の PHP フルスタックフレームワークです。'
                . 'ルーティング・API・サーバーアクション・認証・キャッシュ・DB を、ひとつの boot エントリにまとめます。ビルドステップはありません。',
            'install' => 'composer require polidog/relayer',
            'spec' => [
                ['Language', 'PHP 8.5 以上'],
                ['Build', 'ビルドステップなし'],
                ['License', 'MIT'],
            ],
            'ctaPrimary' => 'はじめる',
            'ctaSecondary' => 'ドキュメント',
            'figures' => [
                'lifecycle' => 'リクエストの通り道',
                'routes' => '配置 = URL',
            ],
            'lifecycleNote' => '1 リクエストが通るのはこの 4 か所だけです。ミドルウェアで共通処理、'
                . 'ページで描画、キャッシュヘッダーを付けて、ドキュメントとして書き出す。',
            'sampleRequest' => 'GET /hello/world',
            'sampleResponse' => '200 OK',
            'lifecycle' => [
                'input' => 'GET /docs/http-cache',
                'steps' => [
                    ['src/Pages/middleware.php', 'ロケール解決と共通ヘッダー'],
                    ['src/Pages/docs/[slug]/page.psx', 'PSX を Element ツリーへ'],
                    ['$ctx->cache(…)', 'ETag と s-maxage を付与'],
                    ['HtmlDocument', 'head を組み立てて描画'],
                ],
                'output' => '200 OK — text/html',
            ],
            'routes' => [
                ['src/Pages/page.psx', '/'],
                ['src/Pages/docs/[slug]/page.psx', '/docs/http-cache'],
                ['src/Pages/api/search/route.php', '/api/search'],
                ['src/Pages/sitemap.xml/route.php', '/sitemap.xml'],
                ['src/Pages/middleware.php', '全ルートに適用'],
            ],
            'routesNote' => 'これはこのサイト自身の src/Pages です。ルーティング設定ファイルはありません。',
            'specs' => [
                ['key' => 'Routing', 'title' => 'ファイルベースルーティング', 'slug' => 'routing-pages',
                    'body' => 'ディレクトリ構造がそのまま URL。page.psx がページ、route.php が API、[slug] が動的セグメント。'],
                ['key' => 'View', 'title' => 'PSX — PHP に書く JSX', 'slug' => 'usephp',
                    'body' => '.psx ファイルに JSX をそのまま書く。テンプレート言語も Node のビルドも挟みません。'],
                ['key' => 'Actions', 'title' => 'サーバーアクション', 'slug' => 'server-actions',
                    'body' => 'フォームの送信先をサーバー側の関数に直結。JavaScript を書かずに更新できます。'],
                ['key' => 'Data', 'title' => 'データベース', 'slug' => 'database',
                    'body' => 'PDO の薄い層。DSN を環境変数で渡すだけで DI コンテナに載ります。'],
                ['key' => 'Auth', 'title' => '認証', 'slug' => 'authentication',
                    'body' => 'セッションベースのログインと CSRF をフレームワーク側で用意します。'],
                ['key' => 'Cache', 'title' => 'HTTP キャッシュ', 'slug' => 'http-cache',
                    'body' => 'ページごとに ETag と s-maxage を宣言。CDN のヒット率まで含めて設計できます。'],
            ],
            'specsMore' => 'ドキュメントを全部見る',
            'sample' => self::SAMPLE,
            'sampleNote' => 'このファイルを置けば /hello/world が動きます。登録も設定も追加しません。',
            'colophon' => [
                ['Runtime', 'FrankenPHP シングルバイナリ / PHP 8.5'],
                ['Image', '192 MB・コールドスタート 700 ms'],
                ['Content', 'Turso (libSQL) — 本文の唯一の正'],
                ['Edge', 'Fly.io nrt + Cloudflare キャッシュ'],
            ],
            'colophonNote' => 'このドキュメントサイト自体が Relayer で書かれています。ソースは公開しています。',
            'closing' => ['規約を覚えるより、', '1 ページ書くほうが早い。'],
            'closingCta' => 'はじめる',
        ];
    }

    /** @return array<string, mixed> */
    private static function en(): array
    {
        return [
            'eyebrow' => 'Relayer — PHP full-stack framework',
            'headline' => ['Drop a PHP file in.', 'It becomes a route.'],
            'lead' => 'Relayer is a convention-first PHP full-stack framework inspired by the Next.js App Router. '
                . 'Routing, APIs, server actions, authentication, caching and the database are wired into a single boot entry. No build step.',
            'install' => 'composer require polidog/relayer',
            'spec' => [
                ['Language', 'PHP 8.5+'],
                ['Build', 'No build step'],
                ['License', 'MIT'],
            ],
            'ctaPrimary' => 'Get started',
            'ctaSecondary' => 'Documentation',
            'figures' => [
                'lifecycle' => 'The path of a request',
                'routes' => 'Placement is the URL',
            ],
            'lifecycleNote' => 'A request passes through four places, and no others: shared work in the middleware, '
                . 'rendering in the page, cache headers, then the document that goes out.',
            'sampleRequest' => 'GET /hello/world',
            'sampleResponse' => '200 OK',
            'lifecycle' => [
                'input' => 'GET /docs/http-cache',
                'steps' => [
                    ['src/Pages/middleware.php', 'locale + shared headers'],
                    ['src/Pages/docs/[slug]/page.psx', 'PSX to an element tree'],
                    ['$ctx->cache(…)', 'attaches ETag and s-maxage'],
                    ['HtmlDocument', 'assembles the head, renders'],
                ],
                'output' => '200 OK — text/html',
            ],
            'routes' => [
                ['src/Pages/page.psx', '/'],
                ['src/Pages/docs/[slug]/page.psx', '/docs/http-cache'],
                ['src/Pages/api/search/route.php', '/api/search'],
                ['src/Pages/sitemap.xml/route.php', '/sitemap.xml'],
                ['src/Pages/middleware.php', 'every route'],
            ],
            'routesNote' => 'That is this site’s own src/Pages. There is no routing configuration file.',
            'specs' => [
                ['key' => 'Routing', 'title' => 'File-based routing', 'slug' => 'routing-pages',
                    'body' => 'The directory tree is the URL space. page.psx is a page, route.php is an API, [slug] is a dynamic segment.'],
                ['key' => 'View', 'title' => 'PSX — JSX, written in PHP', 'slug' => 'usephp',
                    'body' => 'Write JSX directly in a .psx file. No template language, no Node build in the middle.'],
                ['key' => 'Actions', 'title' => 'Server actions', 'slug' => 'server-actions',
                    'body' => 'Point a form straight at a server-side function and update state without writing JavaScript.'],
                ['key' => 'Data', 'title' => 'Database', 'slug' => 'database',
                    'body' => 'A thin layer over PDO. Hand it a DSN through the environment and it joins the container.'],
                ['key' => 'Auth', 'title' => 'Authentication', 'slug' => 'authentication',
                    'body' => 'Session-based login and CSRF protection come from the framework.'],
                ['key' => 'Cache', 'title' => 'HTTP caching', 'slug' => 'http-cache',
                    'body' => 'Declare an ETag and s-maxage per page, and design for the CDN hit rate as you go.'],
            ],
            'specsMore' => 'Read every page',
            'sample' => self::SAMPLE,
            'sampleNote' => 'Save that file and /hello/world responds. Nothing to register, nothing to configure.',
            'colophon' => [
                ['Runtime', 'FrankenPHP single binary / PHP 8.5'],
                ['Image', '192 MB, 700 ms cold start'],
                ['Content', 'Turso (libSQL) — the single source of truth'],
                ['Edge', 'Fly.io nrt + Cloudflare cache'],
            ],
            'colophonNote' => 'This documentation site is itself written in Relayer, and the source is public.',
            'closing' => ['Reading the conventions takes longer', 'than writing the first page.'],
            'closingCta' => 'Get started',
        ];
    }
}
