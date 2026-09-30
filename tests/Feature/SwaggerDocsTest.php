<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SwaggerDocsTest extends TestCase
{
    public function testGeneratedSpecIsServedAtTheStableUrl(): void
    {
        // The frontend builds its API types from this exact URL, it stopped resolving once during the
        // l5-swagger 9 upgrade because the docs route stopped accepting a trailing filename
        $this->assertSame(0, Artisan::call('l5-swagger:generate'));

        $response = $this->get('/generated/api-docs.json')->assertOk();

        $spec = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringStartsWith('3.', $spec['openapi']);
        $this->assertArrayHasKey('/appearances', $spec['paths']);
        $this->assertArrayHasKey('/users/signin', $spec['paths']);
    }

    public function testSwaggerUiLoadsItsAssets(): void
    {
        $this->assertSame(0, Artisan::call('l5-swagger:generate'));

        $this->get('/')->assertOk()->assertSee('/generated/api-docs.json', false);
        $this->get('/generated/api-docs.json/asset/swagger-ui.css')->assertOk();
    }
}
