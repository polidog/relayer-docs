<?php

declare(strict_types=1);

namespace App\Layouts;

use Polidog\Relayer\Router\Layout\LayoutComponent;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

/**
 * Site chrome only — header (with the dark-mode toggle) and footer,
 * written as PSX with Tailwind utility classes. The data-driven
 * sidebar/search/TOC live in the Shell component, composed by pages.
 * Dependency-free so it instantiates with `new`.
 */
final class RootLayout extends LayoutComponent
{
    public function render(): Element
    {
        return (
            H::div(className: 'min-h-screen flex flex-col bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200', children: [
H::header(className: 'sticky top-0 z-20 flex items-center justify-between gap-4 px-6 h-14 border-b border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-slate-900/80 backdrop-blur', children: [
H::a(href: '/', className: 'flex items-center gap-2.5 font-bold text-slate-900 dark:text-white no-underline', children: [
H::span(className: 'grid place-items-center w-7 h-7 rounded-md bg-indigo-600 text-white font-extrabold', children: 'R'), 
H::span(children: 'Relayer Docs')
]), 
H::nav(className: 'flex items-center gap-4 text-sm', children: [
H::a(href: '/', className: 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white no-underline', children: 'ホーム'), 
H::a(href: '/search', className: 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white no-underline', children: '検索'), 
H::a(href: 'https://github.com/polidog/relayer', target: '_blank', rel: 'noopener noreferrer', className: 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white no-underline', children: 'GitHub'), 
H::__callStatic('button', ['id' => 'theme-toggle', 'type' => 'button', 'aria-label' => 'テーマ切替', 'className' => 'ml-1 grid place-items-center w-8 h-8 rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white', 'children' => [
H::span(className: 'dark:hidden', children: '🌙'), 
H::span(className: 'hidden dark:inline', children: '☀️')
]])
])
]), 

H::div(className: 'w-full max-w-6xl mx-auto px-6 py-7 flex-1', children: $this->getChildren()), 

H::footer(className: 'text-center py-6 text-[13px] text-slate-500 border-t border-slate-200 dark:border-slate-800', children: [
H::span(children: 'Relayer Documentation'), 
H::span(className: 'opacity-70', children: ' — built with Relayer + Turso')
])
])










        );
    }
}
