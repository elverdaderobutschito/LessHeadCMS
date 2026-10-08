<?php
/*
 * LessHeadCMS - headless CMS generator (PlantUML -> SQLite -> REST API)
 * Copyright (C) 2026 Udo Butschinek
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace LessHeadCMS;

/**
 * Thumbnails of the media library with GD: at most MAX × MAX pixels, aspect ratio kept, in the format of the original
 * (jpg stays jpg, png/webp with transparency, gif with a transparent colour; animated GIFs become the first frame).
 * Stored next to the original in media/ as "<name of the original>_thumb.<extension>" (see Media).
 *
 * Never a reason to reject an upload: if GD (or the function for the format) is missing, the image is broken, it would
 * need more memory than memory_limit provides or anything else fails, create() returns '' - the UI then shows the
 * original as before. Raster images only (RASTER), no SVG, no video/audio/document.
 */
final class Thumbnail
{
    public const MAX = 200;

    /** Extension => [read function, write function] */
    private const RASTER = [
        'jpg'  => ['imagecreatefromjpeg', 'imagejpeg'],
        'jpeg' => ['imagecreatefromjpeg', 'imagejpeg'],
        'png'  => ['imagecreatefrompng', 'imagepng'],
        'gif'  => ['imagecreatefromgif', 'imagegif'],
        'webp' => ['imagecreatefromwebp', 'imagewebp'],
    ];

    /** Is GD available at all? (for /api/_media/limits; individual formats may still be missing) */
    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');
    }

    /** Is a thumbnail an option at all for this extension? */
    public static function applies(string $ext): bool
    {
        return isset(self::RASTER[strtolower($ext)]);
    }

    /** File name of the thumbnail for an original: "<name>_thumb.<extension>" in the same directory */
    public static function nameFor(string $filename): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        return substr($filename, 0, -strlen($ext) - 1) . '_thumb.' . $ext;
    }

    /**
     * Creates the thumbnail for media/<$filename>.
     *
     * @return string file name of the thumbnail, or '' = none (not needed because the original is already small enough,
     *                or not possible - then the original counts as the preview)
     */
    public static function create(string $dir, string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $source = $dir . '/' . $filename;
        if (!self::applies($ext) || !self::available() || !is_file($source)) {
            return '';
        }
        [$read, $write] = self::RASTER[$ext];
        if (!function_exists($read) || !function_exists($write)) {
            return ''; // GD without this format (e.g. built without WebP support)
        }
        $size = @getimagesize($source);
        if (!is_array($size) || $size[0] < 1 || $size[1] < 1) {
            return '';
        }
        [$w, $h] = [(int) $size[0], (int) $size[1]];
        if ($w <= self::MAX && $h <= self::MAX) {
            return ''; // already small: the original is the preview
        }
        $scale = min(self::MAX / $w, self::MAX / $h);
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        if (!self::memoryFits($w, $h, $tw, $th)) {
            return ''; // otherwise PHP would abort with "Allowed memory size exhausted" - that could no longer be caught
        }
        $target = $dir . '/' . self::nameFor($filename);
        $src = null;
        $dst = null;
        try {
            $src = @$read($source);
            if (!$src) {
                return ''; // broken or truncated image
            }
            $dst = @imagecreatetruecolor($tw, $th);
            if (!$dst) {
                return '';
            }
            $key = null; // GIF: RGB of the transparent palette colour
            if ($ext === 'gif') {
                // GIF knows only one transparent palette colour: take over the same colour as the background
                $index = imagecolortransparent($src);
                if ($index >= 0 && $index < imagecolorstotal($src)) {
                    $key = imagecolorsforindex($src, $index);
                    $t = imagecolorallocate($dst, $key['red'], $key['green'], $key['blue']);
                    imagefill($dst, 0, 0, $t);
                    imagecolortransparent($dst, $t);
                }
            } elseif ($ext === 'png' || $ext === 'webp') {
                // keep the alpha channel
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            }
            // Transparent GIF: shrink without interpolation (imagecopyresized), otherwise mixed colours from the
            // transparency colour would arise at the edges and remain as a visible fringe
            $copy = $key !== null ? 'imagecopyresized' : 'imagecopyresampled';
            if (!@$copy($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h)) {
                return '';
            }
            if ($ext === 'gif') {
                // back into a palette; the conversion discards the transparency, so set the colour again afterwards
                imagetruecolortopalette($dst, $key === null, 255);
                if ($key !== null) {
                    $t = imagecolorexact($dst, $key['red'], $key['green'], $key['blue']);
                    if ($t >= 0) {
                        imagecolortransparent($dst, $t);
                    }
                }
            }
            $args = [$dst, $target];
            if ($write === 'imagejpeg') {
                $args[] = 82;
            } elseif ($write === 'imagewebp') {
                $args[] = 80;
            } elseif ($write === 'imagepng') {
                $args[] = 6;
            }
            if (!@$write(...$args) || !is_file($target) || filesize($target) === 0) {
                @unlink($target);
                return '';
            }
            @chmod($target, 0644);
            return basename($target);
        } catch (\Throwable $e) {
            @unlink($target);
            error_log('[LessHeadCMS] Vorschaubild für ' . $filename . ' nicht erzeugt: ' . $e->getMessage());
            return '';
        } finally {
            // PHP 8 frees GdImage objects itself; imagedestroy() only for older versions (resources)
            if (PHP_VERSION_ID < 80000) {
                foreach ([$src, $dst] as $img) {
                    if (is_resource($img)) {
                        imagedestroy($img);
                    }
                }
            }
        }
    }

    /**
     * Is there enough memory for original + thumbnail? GD keeps both uncompressed (truecolor: 4 bytes per pixel, plus
     * overhead), roughly estimated with a safety margin against memory_limit.
     */
    private static function memoryFits(int $w, int $h, int $tw, int $th): bool
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return true;
        }
        $bytes = (float) $limit;
        switch (strtolower(substr($limit, -1))) {
            case 'g':
                $bytes *= 1024;
                // no break
            case 'm':
                $bytes *= 1024;
                // no break
            case 'k':
                $bytes *= 1024;
        }
        $need = ($w * $h + $tw * $th) * 5 * 1.5;
        return memory_get_usage() + $need < $bytes;
    }
}
