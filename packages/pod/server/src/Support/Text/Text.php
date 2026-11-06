<?php

namespace Fleetbase\Pod\Support\Text;

/**
 * Small, dependency-free text helpers used by import and matching.
 */
final class Text
{
    /**
     * Lower-case, strip accents and punctuation, and collapse whitespace.
     * Used as a comparison key, never shown to people.
     */
    public static function key(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = self::asciiFold($value);
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /**
     * Collapse whitespace and trim, keeping the original characters.
     */
    public static function squish(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        // Non-breaking spaces are common in spreadsheets copied from documents.
        $value = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}"], ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * Title case a person's or place's name: "aminata  KAMARA" -> "Aminata Kamara".
     * Hyphenated and apostrophe parts are capitalised too ("Koroma-sesay" -> "Koroma-Sesay").
     */
    public static function titleName(?string $value): string
    {
        $value = mb_strtolower(self::squish($value), 'UTF-8');

        return preg_replace_callback(
            "/(^|[\\s\\-'’(])(\\p{L})/u",
            fn ($m) => $m[1] . mb_strtoupper($m[2], 'UTF-8'),
            $value
        ) ?? $value;
    }

    public static function asciiFold(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if ($normalized !== false) {
                $value = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $value;
            }
        }

        return $value;
    }

    /**
     * Jaro-Winkler similarity between 0 (different) and 1 (identical).
     */
    public static function jaroWinkler(string $a, string $b, float $prefixScale = 0.1): float
    {
        if ($a === $b) {
            return 1.0;
        }

        $lenA = strlen($a);
        $lenB = strlen($b);
        if ($lenA === 0 || $lenB === 0) {
            return 0.0;
        }

        $window   = max(0, intdiv(max($lenA, $lenB), 2) - 1);
        $matchesA = array_fill(0, $lenA, false);
        $matchesB = array_fill(0, $lenB, false);
        $matches  = 0;

        for ($i = 0; $i < $lenA; $i++) {
            $start = max(0, $i - $window);
            $end   = min($i + $window + 1, $lenB);
            for ($j = $start; $j < $end; $j++) {
                if ($matchesB[$j] || $a[$i] !== $b[$j]) {
                    continue;
                }
                $matchesA[$i] = true;
                $matchesB[$j] = true;
                $matches++;
                break;
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        $transpositions = 0;
        $k              = 0;
        for ($i = 0; $i < $lenA; $i++) {
            if (!$matchesA[$i]) {
                continue;
            }
            while (!$matchesB[$k]) {
                $k++;
            }
            if ($a[$i] !== $b[$k]) {
                $transpositions++;
            }
            $k++;
        }

        $jaro = (($matches / $lenA) + ($matches / $lenB) + (($matches - $transpositions / 2) / $matches)) / 3;

        $prefix = 0;
        for ($i = 0; $i < min(4, $lenA, $lenB); $i++) {
            if ($a[$i] !== $b[$i]) {
                break;
            }
            $prefix++;
        }

        return $jaro + $prefix * $prefixScale * (1 - $jaro);
    }

    /**
     * Name similarity that ignores word order: "Kamara Aminata" ~ "Aminata Kamara".
     */
    public static function nameSimilarity(string $a, string $b): float
    {
        $a = self::key($a);
        $b = self::key($b);

        $direct = self::jaroWinkler($a, $b);
        $sortA  = explode(' ', $a);
        $sortB  = explode(' ', $b);
        sort($sortA);
        sort($sortB);

        return max($direct, self::jaroWinkler(implode(' ', $sortA), implode(' ', $sortB)));
    }
}
