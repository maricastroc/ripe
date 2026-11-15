<?php

declare(strict_types=1);

namespace App\Inference;

use App\Enums\Inference\MessageRole;

final readonly class Message
{
    public function __construct(
        public MessageRole $role,
        public string $content,
    ) {}

    public static function user(string $content): self
    {
        return new self(MessageRole::User, $content);
    }
}
