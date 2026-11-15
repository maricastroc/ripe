<?php

declare(strict_types=1);

use App\Agent\Text\Markdown;

it('finds every distinct gap marker, whatever its spelling', function (): void {
    $text = "Dear [[TBD: neighbour's name]],\n[[tbd street]] [[ To be defined: date ]] [[TBD: neighbour's name]] [[TBD]]";

    expect(Markdown::gaps($text))->toBe(["neighbour's name", 'street', 'date']);
});

it('removes a markdown fence around the whole answer only', function (): void {
    expect(Markdown::unfence("```markdown\n## Title\n\nText\n```"))->toBe("## Title\n\nText")
        ->and(Markdown::unfence("Intro\n```\ncode\n```"))->toBe("Intro\n```\ncode\n```");
});

it('counts words across any whitespace', function (): void {
    expect(Markdown::words("  um  dois\n\ttrês "))->toBe(3)
        ->and(Markdown::words('   '))->toBe(0);
});

it('clips long pieces and marks the cut', function (): void {
    expect(Markdown::clip('abcdef', 4))->toBe("abcd\n[...]")
        ->and(Markdown::clip('abc', 4))->toBe('abc');
});
