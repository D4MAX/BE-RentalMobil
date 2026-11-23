<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/** Galat bisnis dengan format {message, code, errors?} (Spek API bagian 1 dan 15). */
class ApiException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $kode,
        public readonly int $status = 409,
        public readonly array $tambahan = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage(), 'code' => $this->kode] + $this->tambahan,
            $this->status
        );
    }
}
