<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AuthorizeChannelRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'socket_id' => ['required', 'string', 'max:64', 'regex:/\A\d+\.\d+\z/'],
            'channel_name' => ['required', 'string', 'max:200', 'starts_with:private-'],
        ];
    }
}
