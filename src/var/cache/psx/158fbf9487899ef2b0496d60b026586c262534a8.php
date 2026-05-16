<?php

declare(strict_types=1);

use App\Components\SearchForm;
use App\Components\Shell;
use App\Docs\DocStore;
use App\Docs\Nav;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Html\H;

// PSX components are resolved at runtime via the compiled component
// manifest, so the batch compiler must not treat them as unresolved.
// @psx-runtime App\Components\Shell
// @psx-runtime App\Components\SearchForm

/**
 * Home — hero with a prominent search box plus the full doc index
 * grouped by category. Function-style, two-level: the factory sets
 * page metadata, the returned closure renders the PSX tree.
 */
return function (PageContext $ctx, DocStore $store): Closure {
    $ctx->metadata([
        'title' => 'Relayer ドキュメント',
        'description' => 'Relayer — Next.js App Router 風の PHP フルスタックフレームワークのドキュメント。',
    ]);

    // The index reflects the whole corpus, so its ETag is the
    // corpus digest — unchanged between syncs => 304.
    $ctx->cache(new Cache(
        maxAge: 120,
        public: true,
        mustRevalidate: true,
        etag: 'home-' . $store->corpusTag(),
    ));

    $groups = Nav::groups($store);

    return fn () => (
        \Polidog\UsePhp\Runtime\RenderContext::getApp()->renderPsxComponent('App\\Components\\Shell', ['store' => $store, 'active' => null, 'children' => [
H::section(className: 'text-center py-9', children: [
H::h1(className: 'text-4xl font-bold mb-2 text-slate-900 dark:text-white', children: 'Relayer ドキュメント'), 
H::p(className: 'text-slate-500 dark:text-slate-400 max-w-xl mx-auto mb-6', children: 'Next.js App Router 風の規約で、ルーティング・API・認証・キャッシュ・DB を ひとつの boot エントリにまとめた PHP フルスタックフレームワーク。'), 
\Polidog\UsePhp\Runtime\RenderContext::getApp()->renderPsxComponent('App\\Components\\SearchForm', ['large' => true]), 
H::div(className: 'flex gap-3 justify-center', children: [
H::a(href: '/docs/getting-started', className: 'inline-block px-5 py-2.5 rounded-lg font-semibold bg-indigo-600 hover:bg-indigo-700 text-white no-underline', children: 'はじめる →'), 
H::a(href: 'https://github.com/polidog/relayer', target: '_blank', rel: 'noopener noreferrer', className: 'inline-block px-5 py-2.5 rounded-lg font-semibold border border-slate-300 dark:border-slate-700 hover:border-indigo-500 text-slate-800 dark:text-slate-200 no-underline', children: 'GitHub で見る')
])
]), 

H::div(className: 'grid gap-4 mt-6 [grid-template-columns:repeat(auto-fit,minmax(260px,1fr))]', children: 
array_map(fn (array $g) => (
                    H::section(className: 'bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl px-5 py-4', children: [
H::h2(className: 'm-0 mb-2.5 text-base font-semibold text-slate-900 dark:text-white', children: $g['category']), 
H::ul(className: 'list-none m-0 p-0', children: 
array_map(fn ($d) => (
                                H::li(children: 
H::a(href: '/docs/' . $d->slug, className: 'block px-2.5 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 no-underline', children: [
H::span(className: 'block font-semibold text-slate-900 dark:text-white', children: $d->title), 
'' !== $d->description
                                            ? (H::span(className: 'block text-[13px] text-slate-500 dark:text-slate-400', children: $d->description))
                                            : null
]))




                            ), $g['docs']))
])

                ), $groups))
]])












    );
};
