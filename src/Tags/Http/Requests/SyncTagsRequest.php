<?php

namespace Innertia\Tags\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncTagsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'tags'   => ['required', 'array'],
            'tags.*' => ['string', 'max:255'],
        ];
    }
}
