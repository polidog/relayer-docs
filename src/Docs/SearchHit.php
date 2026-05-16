<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * One search result. The snippet is pre-split into segments so the
 * view can wrap matched spans in <mark> without ever emitting raw
 * HTML (use-php escapes every text node — highlighting has to be
 * structural, not string-injected).
 */
final readonly class SearchHit
{
    /**
     * @param list<array{text: string, hit: bool}> $segments
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $description,
        public string $category,
        public array $segments,
    ) {}
}
