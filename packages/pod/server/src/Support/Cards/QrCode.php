<?php

namespace Fleetbase\Pod\Support\Cards;

use Milon\Barcode\QRcode as Encoder;

/**
 * QR codes as compact SVG, using the QR encoder that ships with Fleetbase
 * (milon/barcode). Only the encoder class is used, so this works outside
 * Laravel too.
 */
final class QrCode
{
    /**
     * The matrix of dark (true) and light (false) modules, without a quiet zone.
     *
     * @param string $level error correction: L, M, Q or H. M survives the wear a
     *                      farmer's card gets in a pocket for a season.
     *
     * @return list<list<bool>>
     */
    public static function matrix(string $data, string $level = 'M'): array
    {
        if ($data === '') {
            throw new \InvalidArgumentException('A QR code needs data.');
        }
        if (!in_array($level, ['L', 'M', 'Q', 'H'], true)) {
            throw new \InvalidArgumentException("Unknown error correction level [{$level}].");
        }

        $array = (new Encoder($data, $level))->getBarcodeArray();
        if (empty($array['bcode'])) {
            throw new \RuntimeException('The data is too long for a QR code.');
        }

        return array_map(fn ($row) => array_map(fn ($cell) => (bool) $cell, $row), $array['bcode']);
    }

    /**
     * An SVG drawn as a single path, with the standard 4-module quiet zone,
     * sized in millimetres so it prints at a known size.
     */
    public static function svg(string $data, float $sizeMm = 24.0, string $level = 'M'): string
    {
        $matrix = self::matrix($data, $level);
        $count  = count($matrix);
        $quiet  = 4;
        $total  = $count + 2 * $quiet;

        $path = '';
        foreach ($matrix as $y => $row) {
            $x = 0;
            while ($x < $count) {
                if (!$row[$x]) {
                    $x++;
                    continue;
                }
                // Merge runs of dark modules in a row into one rectangle.
                $start = $x;
                while ($x < $count && $row[$x]) {
                    $x++;
                }
                $path .= sprintf('M%d %dh%dv1h-%dz', $start + $quiet, $y + $quiet, $x - $start, $x - $start);
            }
        }

        $size = rtrim(rtrim(number_format($sizeMm, 2, '.', ''), '0'), '.');

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$smm" height="%1$smm" viewBox="0 0 %2$d %2$d" shape-rendering="crispEdges">'
            . '<rect width="%2$d" height="%2$d" fill="#fff"/><path d="%3$s" fill="#000"/></svg>',
            $size,
            $total,
            $path
        );
    }

    public static function dataUri(string $data, float $sizeMm = 24.0, string $level = 'M'): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($data, $sizeMm, $level));
    }

    /**
     * A black-and-white PNG with the 4-module quiet zone, `$scale` pixels per
     * module. Used for PDFs: dompdf sizes PNGs reliably, but not SVGs.
     */
    public static function png(string $data, int $scale = 8, string $level = 'M'): string
    {
        if (!function_exists('imagecreate')) {
            throw new \RuntimeException('The PHP GD extension is needed to draw QR codes as PNG.');
        }
        if ($scale < 1 || $scale > 40) {
            throw new \InvalidArgumentException('The scale must be between 1 and 40 pixels per module.');
        }

        $matrix = self::matrix($data, $level);
        $quiet  = 4;
        $size   = (count($matrix) + 2 * $quiet) * $scale;

        $image = imagecreate($size, $size);
        imagecolorallocate($image, 255, 255, 255); // the first colour is the background
        $black = imagecolorallocate($image, 0, 0, 0);

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $left = ($x + $quiet) * $scale;
                    $top  = ($y + $quiet) * $scale;
                    imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    public static function pngDataUri(string $data, int $scale = 8, string $level = 'M'): string
    {
        return 'data:image/png;base64,' . base64_encode(self::png($data, $scale, $level));
    }
}
