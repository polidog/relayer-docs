<?php

declare(strict_types=1);

namespace App\Og;

use RuntimeException;

/**
 * Renders a 1200×630 Open Graph card as PNG bytes with GD.
 *
 * Per-page social cards (the title on a branded background) without a
 * Node/Satori build step — honoring Relayer's "no build" rule the same
 * way the site uses Tailwind via the Play CDN. Deterministic: the font
 * is vendored (`assets/fonts/ipaexg.ttf`, IPAex Gothic) so the layout
 * is identical locally and in production, independent of whatever fonts
 * the host happens to have. IPAex Gothic carries both Japanese and
 * Latin glyphs, so a mixed title like "HTTP キャッシュと ETag" renders
 * in one face.
 *
 * Output is pure bytes — the route caches it at the edge (long
 * s-maxage, content-hash ETag), so this heavy path runs rarely; see
 * src/Pages/og/[slug]/route.php.
 *
 * The dimensions are the OG / `twitter:card=summary_large_image`
 * standard (1.91:1); {@see WIDTH}/{@see HEIGHT} are mirrored into
 * `og:image:width`/`height` in public/index.php — keep them in sync.
 */
final class OgImage
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;

    private const PAD = 90;

    // Site palette — green drafting direction (`--bp-*` in
    // App\DocumentFactory). Keep these in step with that stylesheet.
    private const BG = [9, 34, 25];          // --bp-plate
    private const PANEL = [42, 92, 66];
    private const ACCENT = [60, 196, 126];
    private const TITLE = [230, 246, 236];
    private const MUTED = [158, 204, 174];
    private const FAINT = [112, 156, 130];

    /**
     * @param string $title   the card headline (doc / page title)
     * @param string $eyebrow small label above it (category, brand)
     *
     * @return string PNG bytes
     *
     * @throws RuntimeException if GD/FreeType or the font is unavailable
     */
    public static function render(string $title, string $eyebrow): string
    {
        if (!\function_exists('imagettftext')) {
            throw new RuntimeException('GD with FreeType is required for OG image rendering.');
        }

        $font = \dirname(__DIR__, 2) . '/assets/fonts/ipaexg.ttf';
        if (!\is_file($font)) {
            throw new RuntimeException("OG font missing: {$font}");
        }

        $im = \imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if (false === $im) {
            throw new RuntimeException('imagecreatetruecolor failed.');
        }

        $bg = self::color($im, self::BG);
        \imagefilledrectangle($im, 0, 0, self::WIDTH, self::HEIGHT, $bg);

        // Top accent bar + a 1px inner frame for definition on light feeds.
        \imagefilledrectangle($im, 0, 0, self::WIDTH, 10, self::color($im, self::ACCENT));
        \imagerectangle($im, 24, 24, self::WIDTH - 25, self::HEIGHT - 25, self::color($im, self::PANEL));

        self::drawBrand($im, $font);

        $eyebrow = \trim($eyebrow);
        if ('' !== $eyebrow) {
            self::text($im, 26, self::PAD, 235, self::MUTED, $font, $eyebrow);
        }

        self::drawTitle($im, $font, $title);

        // Footer.
        self::text($im, 24, self::PAD, self::HEIGHT - 70, self::FAINT, $font, 'Relayer Documentation');

        \ob_start();
        \imagepng($im);

        // No imagedestroy(): it is a deprecated no-op on PHP 8.5 (GdImage
        // is freed by refcount), and emitting that deprecation notice
        // mid-stream would corrupt the PNG / break the headers.
        return (string) \ob_get_clean();
    }

    /**
     * The drafting mark: a square frame with the relay vector inside —
     * the same glyph the site header draws as inline SVG, at 70px.
     * Square corners, no fill: the card is a drawing, not a badge.
     */
    private static function drawBrand(\GdImage $im, string $font): void
    {
        $x = self::PAD;
        $y = 110;
        $s = 70;

        $accent = self::color($im, self::ACCENT);
        \imagesetthickness($im, 3);
        \imagerectangle($im, $x, $y, $x + $s, $y + $s, $accent);
        \imageline($im, $x + 15, $y + 51, $x + 34, $y + 51, $accent);
        \imageline($im, $x + 34, $y + 51, $x + 50, $y + 21, $accent);
        \imagesetthickness($im, 1);
        \imagefilledrectangle($im, $x + 44, $y + 15, $x + 56, $y + 27, $accent);

        self::text($im, 42, $x + $s + 26, $y + 50, self::TITLE, $font, 'Relayer', true);
    }

    private static function drawTitle(\GdImage $im, string $font, string $title): void
    {
        $title = \trim($title);
        if ('' === $title) {
            $title = 'Relayer ドキュメント';
        }

        $maxW = self::WIDTH - self::PAD * 2;
        // Title band: below the eyebrow, above the footer. The wrapped
        // block is vertically centered in it, so a 1-line title doesn't
        // sit high with dead space and a 3-line one stays clear of both.
        $areaTop = 270;
        $areaBottom = self::HEIGHT - 120;
        $areaH = $areaBottom - $areaTop;

        // Largest size whose wrap fits ≤ 3 lines within the band.
        foreach ([66, 58, 52, 46] as $size) {
            $lines = self::wrap($title, $size, $font, $maxW);
            $lineH = (int) \round($size * 1.5);
            if (\count($lines) <= 3 && \count($lines) * $lineH <= $areaH) {
                break;
            }
        }

        if (\count($lines) > 3) {
            $lines = \array_slice($lines, 0, 3);
            $lines[2] = self::ellipsize($lines[2], $size, $font, $maxW);
        }

        $blockH = \count($lines) * $lineH;
        // imagettftext anchors on the baseline; ~0.8·size is the ascent.
        $y = (int) \round($areaTop + ($areaH - $blockH) / 2 + $size * 0.8);
        foreach ($lines as $line) {
            self::text($im, $size, self::PAD, $y, self::TITLE, $font, $line, true);
            $y += $lineH;
        }
    }

    /**
     * Greedy wrap for mixed CJK/Latin: CJK breaks per character, but an
     * ASCII run (a word, "DATABASE_DSN") is not split mid-word — the
     * break backtracks to the last space or script boundary.
     *
     * @return list<string>
     */
    private static function wrap(string $text, int $size, string $font, int $maxW): array
    {
        $chars = \preg_split('//u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $line = '';

        foreach ($chars as $ch) {
            if (self::width($line . $ch, $size, $font) <= $maxW) {
                $line .= $ch;

                continue;
            }

            if ('' === $line) {
                $line = $ch; // one glyph wider than the box: keep it

                continue;
            }

            $cut = \mb_strlen($line);
            if (self::isWordChar($ch) && self::isWordChar(\mb_substr($line, -1))) {
                for ($i = \mb_strlen($line) - 1; $i > 0; --$i) {
                    $c = \mb_substr($line, $i, 1);
                    $p = \mb_substr($line, $i - 1, 1);
                    if (' ' === $c || !self::isAscii($c) || (self::isAscii($c) && !self::isAscii($p))) {
                        $cut = $i;

                        break;
                    }
                }
            }

            $lines[] = \rtrim(\mb_substr($line, 0, $cut));
            $line = \ltrim(\mb_substr($line, $cut)) . $ch;
        }

        if ('' !== $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    private static function ellipsize(string $line, int $size, string $font, int $maxW): string
    {
        if (self::width($line . '…', $size, $font) <= $maxW) {
            return $line . '…';
        }
        while ('' !== $line && self::width($line . '…', $size, $font) > $maxW) {
            $line = \mb_substr($line, 0, \mb_strlen($line) - 1);
        }

        return $line . '…';
    }

    private static function isAscii(string $ch): bool
    {
        return 1 === \strlen($ch) && \ord($ch) < 128;
    }

    private static function isWordChar(string $ch): bool
    {
        return 1 === \preg_match('/[A-Za-z0-9_]/', $ch);
    }

    private static function width(string $text, int $size, string $font): int
    {
        if ('' === $text) {
            return 0;
        }
        $b = \imagettfbbox($size, 0, $font, $text);

        return $b[2] - $b[0];
    }

    /**
     * Draw text at a baseline-ish anchor. `$bold` fakes weight by
     * over-stamping with a 1px x-offset (IPAex Gothic is single-weight).
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    private static function text(
        \GdImage $im,
        int $size,
        int $x,
        int $y,
        array $rgb,
        string $font,
        string $text,
        bool $bold = false,
    ): void {
        $c = self::color($im, $rgb);
        \imagettftext($im, $size, 0, $x, $y, $c, $font, $text);
        if ($bold) {
            \imagettftext($im, $size, 0, $x + 1, $y, $c, $font, $text);
        }
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private static function color(\GdImage $im, array $rgb): int
    {
        $c = \imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);

        return false === $c ? 0 : $c;
    }
}
