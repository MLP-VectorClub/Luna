<?php

namespace Tests\Feature;

use Tests\TestCase;

class OpenApiDocumentTest extends TestCase
{
    public function testTheCommittedOpenApiDocumentIsCurrent(): void
    {
        $this->artisan('openapi:sync', ['--check' => true])->assertSuccessful();
    }
}
