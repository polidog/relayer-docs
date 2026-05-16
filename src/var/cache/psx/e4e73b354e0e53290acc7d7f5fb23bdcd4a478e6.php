<?php

declare(strict_types=1);

use App\Components\Shell;
use App\Docs\DocStore;
use App\Docs\Markdown;
use App\Docs\Nav;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Html\H;

// Component resolved at runtime via the compiled component manifest.
// @psx-runtime App\Components\Shell

/**
 * A single documentation page. The slug is the dynamic `[slug]`
 * segment; the Markdown body is rendered into an element tree and
 * styled with @tailwindcss/typography (`prose` / `dark:prose-invert`),
 * plus an on-page TOC and prev/next navigation.
 */
return function (PageContext $ctx, DocStore $store): Closure {
    $slug = $ctx->params['slug'] ?? '';
    $doc = '' === $slug ? null : $store->get($slug);

    if (null === $doc) {
        \http_response_code(404);
        $ctx->cache(new Cache(noStore: true));
        $ctx->metadata(['title' => 'ページが見つかりません — Relayer Docs']);

        return fn () => (
            \Polidog\UsePhp\Runtime\RenderContext::getApp()->renderPsxComponent('App\\Components\\Shell', ['store' => $store, 'active' => null, 'children' => 
H::section(className: 'text-center py-16', children: [
H::h1(className: 'text-6xl font-bold m-0 text-slate-900 dark:text-white', children: '404'), 
H::p(className: 'text-slate-500 dark:text-slate-400 my-4', children: 'ドキュメント「' . $slug . '」は存在しません。'), 
H::a(href: '/', className: 'inline-block px-5 py-2.5 rounded-lg font-semibold bg-indigo-600 hover:bg-indigo-700 text-white no-underline', children: 'ホームへ戻る')
])])




        );
    }

    $ctx->metadata([
        'title' => $doc->title . ' — Relayer Docs',
        'description' => $doc->description,
    ]);

    // Content-addressed cache: the ETag is the page's own content
    // hash, so a `bin/docs sync` that changes this page busts the
    // cache, while an untouched page revalidates as 304 and skips the
    // (heavy) Markdown render below entirely.
    $ctx->cache(new Cache(
        maxAge: 300,
        public: true,
        mustRevalidate: true,
        etag: 'doc-' . $doc->hash,
    ));

    $md = new Markdown();
    $body = $md->render($doc->content);
    $outline = $md->outline($doc->content);
    $pn = Nav::prevNext(Nav::flat($store), $doc->slug);

    $toc = [] === $outline ? null : (
        H::div(children: [
H::div(className: 'font-bold text-slate-400 mb-2 text-xs uppercase tracking-wide', children: 'このページの内容'), 
H::ul(className: 'list-none m-0 p-0 border-l-2 border-slate-200 dark:border-slate-800', children: 
array_map(fn (array $h) => (
                    H::li(children: 
H::a(href: '#' . $h['id'], className: 3 === $h['level']
                                ? 'block py-1 pl-6 -ml-0.5 border-l-2 border-transparent text-xs text-slate-500 hover:text-slate-900 dark:hover:text-white no-underline'
                                : 'block py-1 pl-3 -ml-0.5 border-l-2 border-transparent text-slate-500 hover:text-slate-900 dark:hover:text-white no-underline', children: $h['text']))




                ), $outline))
])

    );

    return fn () => (
        \Polidog\UsePhp\Runtime\RenderContext::getApp()->renderPsxComponent('App\\Components\\Shell', ['store' => $store, 'active' => $doc->slug, 'toc' => $toc, 'children' => 
H::article(children: [
H::div(className: 'flex gap-2 items-center text-xs text-slate-500 mb-3', children: [
H::a(href: '/', className: 'no-underline hover:text-slate-900 dark:hover:text-white', children: 'ホーム'), 
H::span(className: 'opacity-50', children: '/'), 
H::span(children: '' !== $doc->category ? $doc->category : 'ドキュメント')
]), 

H::div(className: 'prose prose-slate dark:prose-invert max-w-none prose-code:before:content-none prose-code:after:content-none prose-a:text-indigo-600 dark:prose-a:text-indigo-400 prose-pre:bg-slate-900 prose-pre:text-slate-100', children: 
$body), 

(null !== $pn['prev'] || null !== $pn['next']) ? (
                    H::div(className: 'flex gap-3.5 mt-10 pt-5 border-t border-slate-200 dark:border-slate-800', children: [
null !== $pn['prev'] ? (
                            H::a(href: '/docs/' . $pn['prev']->slug, className: 'flex-1 flex flex-col gap-1 px-4 py-3 border border-slate-200 dark:border-slate-800 rounded-lg hover:border-indigo-500 no-underline', children: [
H::span(className: 'text-xs text-slate-500', children: '← 前へ'), 
H::span(className: 'font-semibold text-slate-900 dark:text-white', children: $pn['prev']->title)
])



                        ) : (H::span(className: 'flex-1')), 
null !== $pn['next'] ? (
                            H::a(href: '/docs/' . $pn['next']->slug, className: 'flex-1 flex flex-col gap-1 px-4 py-3 border border-slate-200 dark:border-slate-800 rounded-lg hover:border-indigo-500 text-right no-underline', children: [
H::span(className: 'text-xs text-slate-500', children: '次へ →'), 
H::span(className: 'font-semibold text-slate-900 dark:text-white', children: $pn['next']->title)
])



                        ) : (H::span(className: 'flex-1'))
])
                ) : null
])])


    );
};
