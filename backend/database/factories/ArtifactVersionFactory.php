<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ArtifactVersionSource;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ArtifactVersion> */
class ArtifactVersionFactory extends Factory
{
    public function definition(): array
    {
        $content = fake()->paragraphs(2, true);
        $content = is_string($content) ? $content : '';

        return [
            'artifact_id' => Artifact::factory(),
            'number' => 1,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'source' => ArtifactVersionSource::UserEdit,
            'inputs' => [],
        ];
    }
}
