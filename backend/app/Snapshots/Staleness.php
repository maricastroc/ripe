<?php

declare(strict_types=1);

namespace App\Snapshots;

use App\Models\ArtifactVersion;
use App\Models\Question;
use Illuminate\Support\Collection;

final class Staleness
{
    /**
     * @param  Collection<int, Question>  $questions
     * @param  array<string, int>  $currentNumbers
     */
    public static function of(ArtifactVersion $version, Collection $questions, array $currentNumbers): bool
    {
        if (! $version->source->isProducedByAgent()) {
            return false;
        }

        $seenQuestions = $version->inputs['questions'] ?? [];
        $seenArtifacts = $version->inputs['artifacts'] ?? [];

        foreach ($questions as $question) {
            $seen = is_array($seenQuestions) ? ($seenQuestions[$question->id] ?? null) : null;

            if (! is_array($seen)) {
                continue;
            }

            if (($seen['status'] ?? null) !== $question->status->value || ($seen['revision'] ?? 0) !== $question->answer_revision) {
                return true;
            }
        }

        if (! is_array($seenArtifacts)) {
            return false;
        }

        foreach ($seenArtifacts as $artifactId => $number) {
            if (isset($currentNumbers[$artifactId]) && $currentNumbers[$artifactId] !== $number) {
                return true;
            }
        }

        return false;
    }
}
