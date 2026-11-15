<?php

declare(strict_types=1);

namespace App\Agent\Text;

final class Markdown
{
    private const string GAP_PATTERN = '/\[\[\s*(?:tbd|to be defined)\s*:?\s*([^\]]*?)\s*\]\]/iu';

    public static function unfence(string $text): string
    {
        $out = trim($text);

        if (preg_match('/^```(?:markdown|md)?\s*\n([\s\S]*?)\n```$/i', $out, $matches) === 1) {
            return trim($matches[1]);
        }

        return $out;
    }

    public static function words(string $text): int
    {
        $words = preg_split('/\s+/u', trim($text), flags: PREG_SPLIT_NO_EMPTY);

        return $words === false ? 0 : count($words);
    }

    /** @return list<string> */
    public static function gaps(string $text): array
    {
        preg_match_all(self::GAP_PATTERN, $text, $matches);

        $gaps = [];

        foreach ($matches[1] as $gap) {
            $gap = trim($gap);

            if ($gap !== '' && ! in_array($gap, $gaps, true)) {
                $gaps[] = $gap;
            }
        }

        return $gaps;
    }

    public static function clip(string $text, int $characters): string
    {
        return mb_strlen($text) <= $characters ? $text : mb_substr($text, 0, $characters)."\n[...]";
    }

    public static function quote(string $text, int $characters): string
    {
        return "\"\"\"\n".mb_substr($text, 0, $characters)."\n\"\"\"";
    }

    /** @param list<string> $items */
    public static function bullets(array $items, string $empty = '- (none)'): string
    {
        return $items === [] ? $empty : implode("\n", array_map(static fn (string $item): string => "- {$item}", $items));
    }
}
