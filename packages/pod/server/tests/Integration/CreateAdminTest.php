<?php

namespace Fleetbase\Pod\Tests\Integration;

use Fleetbase\Pod\Console\Commands\CreateAdmin;
use Fleetbase\Pod\Services\AdminAccount;

/**
 * pod:create-admin: where the password comes from and what is refused.
 * The Fleetbase account steps themselves (AdminAccount) need Fleetbase core
 * and are checked on a real install.
 */
final class CreateAdminTest extends IntegrationTestCase
{
    private object $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounts = new class extends AdminAccount {
            public array $calls = [];

            public array $existing = [];

            public function exists(string $email): bool
            {
                return in_array($email, $this->existing, true);
            }

            public function ensure(string $email, string $name, string $organisation, ?string $password): array
            {
                $this->calls[] = compact('email', 'name', 'organisation', 'password');

                return ['created' => !$this->exists($email), 'user' => 'u1', 'organisation' => $organisation];
            }
        };
        $this->app->instance(AdminAccount::class, $this->accounts);
        putenv('POD_ADMIN_PASSWORD');
        putenv('POD_ADMIN_EMAIL');
    }

    protected function tearDown(): void
    {
        putenv('POD_ADMIN_PASSWORD');
        putenv('POD_ADMIN_EMAIL');
        parent::tearDown();
    }

    public function testAsksForThePasswordTwiceWithoutShowingIt(): void
    {
        $this->artisan('pod:create-admin', ['--email' => 'admin@example.org'])
            ->expectsQuestion('Password (not shown)', 'Kenema-Rice-2027')
            ->expectsQuestion('Same password again', 'Kenema-Rice-2027')
            ->expectsOutput('Created the super administrator admin@example.org for "Field Distribution". Sign in to the console with it.')
            ->doesntExpectOutputToContain('Kenema-Rice-2027')
            ->assertSuccessful();

        $this->assertSame([['email' => 'admin@example.org', 'name' => 'System Administrator', 'organisation' => 'Field Distribution', 'password' => 'Kenema-Rice-2027']], $this->accounts->calls);
    }

    public function testRefusesMismatchedOrWeakPasswordsAndChangesNothing(): void
    {
        $this->artisan('pod:create-admin', ['--email' => 'admin@example.org'])
            ->expectsQuestion('Password (not shown)', 'Kenema-Rice-2027')
            ->expectsQuestion('Same password again', 'Kenema-Rice-2028')
            ->expectsOutput('The two passwords are different. Nothing was changed.')
            ->assertFailed();
        $this->artisan('pod:create-admin', ['--email' => 'admin@example.org'])
            ->expectsQuestion('Password (not shown)', 'password')
            ->expectsQuestion('Same password again', 'password')
            ->assertFailed();
        $this->assertSame([], $this->accounts->calls);

        $this->assertNotNull(CreateAdmin::weakness('alllowercaseletters'));
        $this->assertNotNull(CreateAdmin::weakness('Short-1'));
        $this->assertNull(CreateAdmin::weakness('Kenema-Rice-2027'));
    }

    public function testUnattendedInstallsReadTheEnvironment(): void
    {
        putenv('POD_ADMIN_EMAIL=admin@example.org');
        putenv('POD_ADMIN_PASSWORD=Kenema-Rice-2027');

        $this->artisan('pod:create-admin', ['--organisation' => 'MAF Sierra Leone', '--no-interaction' => true])->assertSuccessful();
        $this->assertSame('MAF Sierra Leone', $this->accounts->calls[0]['organisation']);
        $this->assertSame('Kenema-Rice-2027', $this->accounts->calls[0]['password']);
    }

    public function testAnExistingAccountIsMadeAdministratorWithoutTouchingItsPassword(): void
    {
        $this->accounts->existing = ['admin@example.org'];

        $this->artisan('pod:create-admin', ['--email' => 'admin@example.org'])
            ->expectsOutput('admin@example.org already existed: it is now a super administrator (password unchanged).')
            ->assertSuccessful();
        $this->assertNull($this->accounts->calls[0]['password']);
    }

    public function testNeedsAnEmailAndAPasswordSource(): void
    {
        $this->artisan('pod:create-admin', ['--email' => 'not-an-email'])->assertFailed();
        $this->artisan('pod:create-admin', ['--email' => 'admin@example.org', '--no-interaction' => true])
            ->expectsOutput('No password: run this in a terminal to be asked for it, or set POD_ADMIN_PASSWORD for this run only.')
            ->assertFailed();
        $this->assertSame([], $this->accounts->calls);
    }
}
