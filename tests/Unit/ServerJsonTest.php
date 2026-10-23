<?php

namespace Tests\Unit;

use App\Mcp\Servers\VectorClubServer;
use Laravel\Mcp\Server\Attributes\Version;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * server.json is what the MCP Registry lists. The registry validates it when it is published, which is too late to find out
 * about these: the workflow that publishes it checks the tag against it, and these run with the rest of the suite.
 */
class ServerJsonTest extends TestCase
{
    private function server(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../server.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_name_is_in_the_namespace_github_login_proves(): void
    {
        $name = $this->server()['name'];

        $this->assertMatchesRegularExpression('#^[a-zA-Z0-9.-]+/[a-zA-Z0-9._-]+$#', $name);
        $this->assertStringStartsWith('io.github.MLP-VectorClub/', $name);
    }

    public function test_it_is_within_the_limits_of_the_schema(): void
    {
        $server = $this->server();

        $this->assertNotEmpty($server['description']);
        $this->assertLessThanOrEqual(100, mb_strlen($server['description']));
        $this->assertLessThanOrEqual(100, mb_strlen($server['title']));
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $server['version']);
    }

    public function test_it_is_the_version_the_server_reports(): void
    {
        $attribute = (new ReflectionClass(VectorClubServer::class))->getAttributes(Version::class)[0]->newInstance();

        $this->assertSame($this->server()['version'], $attribute->value);
    }

    public function test_it_lists_the_endpoint_and_asks_for_the_key_as_a_secret_bearer_token(): void
    {
        $remotes = $this->server()['remotes'];

        $this->assertCount(1, $remotes);
        $this->assertSame('streamable-http', $remotes[0]['type']);
        $this->assertSame('https://api.mlpvector.club/mcp', $remotes[0]['url']);

        $header = $remotes[0]['headers'][0];
        $this->assertSame('Authorization', $header['name']);
        $this->assertSame('Bearer {api_key}', $header['value']);
        $this->assertTrue($header['isRequired']);
        $this->assertSame(['api_key'], array_keys($header['variables']));
        $this->assertTrue($header['variables']['api_key']['isSecret']);
    }
}
