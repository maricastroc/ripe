<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

final class VersionConflict extends DomainException
{
    public function __construct(string $message, public readonly ?string $currentVersionId, public readonly ?int $draftRevision)
    {
        parent::__construct($message);
    }

    public static function staleBase(?string $currentVersionId, ?int $draftRevision): self
    {
        return new self(__('This piece changed since you started editing it.'), $currentVersionId, $draftRevision);
    }

    public static function draftMoved(?string $currentVersionId, ?int $draftRevision): self
    {
        return new self(__('Your edits changed somewhere else, in another tab or window.'), $currentVersionId, $draftRevision);
    }

    public static function draftPending(?string $currentVersionId, ?int $draftRevision): self
    {
        return new self(__('Keep or discard your edits to this piece first.'), $currentVersionId, $draftRevision);
    }
}
