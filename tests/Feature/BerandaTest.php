<?php

namespace Tests\Feature;

use Tests\TestCase;

/** GET / : JSON singkat, bukan halaman HTML bawaan. */
class BerandaTest extends TestCase
{
    public function test_beranda_menjawab_json_singkat(): void
    {
        $this->get('/')->assertOk()->assertJsonPath('api', '/api/v1')->assertJsonStructure(['nama', 'api']);
    }
}
