<?php

namespace Fleetbase\Pod\Tests\Integration;

/**
 * pod:check-runtime: the deploy stops with a readable message when the web
 * server's workers could not start.
 */
final class CheckRuntimeTest extends IntegrationTestCase
{
    public function testPassesWithAValidKeyAndUrl(): void
    {
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32)), 'app.cipher' => 'AES-256-CBC', 'app.url' => 'https://api.example.org']);
        $this->app->forgetInstance('encrypter');

        $this->artisan('pod:check-runtime')->assertSuccessful();
    }

    public function testAMalformedKeySaysWhatToFix(): void
    {
        // "base64" without the colon and the value, as when only part was pasted.
        config(['app.key' => 'base64', 'app.cipher' => 'AES-256-CBC', 'app.url' => 'https://api.example.org']);
        $this->app->forgetInstance('encrypter');

        $this->artisan('pod:check-runtime')
            ->expectsOutputToContain('APP_KEY is not valid')
            ->assertFailed();
    }

    public function testAnEmptyAddressSaysWhatToFix(): void
    {
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32)), 'app.cipher' => 'AES-256-CBC', 'app.url' => 'https://']);
        $this->app->forgetInstance('encrypter');

        $this->artisan('pod:check-runtime')
            ->expectsOutputToContain('APP_URL is "https://"')
            ->assertFailed();
    }
}
