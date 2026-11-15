<?php

declare(strict_types=1);

namespace App\Agent\Results;

use App\Enums\ReviewVerdict;

final readonly class Review
{
    /**
     * @param  list<string>  $strengths
     * @param  list<ReviewIssue>  $issues
     * @param  list<string>  $missing
     * @param  list<string>  $nextActions
     */
    public function __construct(
        public ReviewVerdict $verdict,
        public string $summary,
        public array $strengths,
        public array $issues,
        public array $missing,
        public array $nextActions,
    ) {}

    public function toMarkdown(): string
    {
        $parts = ["**Verdict:** {$this->verdict->label()}.", $this->summary];

        if ($this->strengths !== []) {
            $parts[] = "## What already works\n".$this->bullets($this->strengths);
        }

        if ($this->issues !== []) {
            $parts[] = "## What to improve\n".implode("\n", array_map(
                static fn (ReviewIssue $issue): string => "- **{$issue->where}:** {$issue->problem}".($issue->suggestion === '' ? '' : " → {$issue->suggestion}"),
                $this->issues,
            ));
        }

        if ($this->missing !== []) {
            $parts[] = "## What still depends on you\n".$this->bullets($this->missing);
        }

        if ($this->nextActions !== []) {
            $parts[] = "## Next steps when you come back\n".implode("\n", array_map(static fn (string $action): string => "- [ ] {$action}", $this->nextActions));
        }

        return implode("\n\n", $parts);
    }

    /** @param list<string> $items */
    private function bullets(array $items): string
    {
        return implode("\n", array_map(static fn (string $item): string => "- {$item}", $items));
    }
}
