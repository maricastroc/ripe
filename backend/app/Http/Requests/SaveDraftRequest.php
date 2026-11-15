<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Agent\Limits;
use Illuminate\Foundation\Http\FormRequest;

final class SaveDraftRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'content' => ['present', 'nullable', 'string', 'max:'.Limits::MAX_PIECE_CHARACTERS],
            'baseVersionId' => ['required', 'uuid'],
            'draftRevision' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function content(): string
    {
        $content = $this->input('content');

        return is_string($content) ? $content : '';
    }

    public function draftRevision(): ?int
    {
        return $this->filled('draftRevision') ? $this->integer('draftRevision') : null;
    }
}
