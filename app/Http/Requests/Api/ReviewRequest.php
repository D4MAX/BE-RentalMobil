<?php

namespace App\Http\Requests\Api;

class ReviewRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'komentar' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['rating.between' => 'Rating antara 1 sampai 5', 'komentar.max' => 'Komentar maksimal 500 karakter'];
    }
}
