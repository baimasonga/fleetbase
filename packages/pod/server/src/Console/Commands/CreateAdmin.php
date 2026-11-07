<?php

namespace Fleetbase\Pod\Console\Commands;

use Fleetbase\Pod\Services\AdminAccount;
use Illuminate\Console\Command;

/**
 * Creates (or repairs) the installation's super administrator.
 *
 * The password is never taken on the command line, where it would stay in
 * the shell history and the process list. It is asked for with hidden input,
 * or read from POD_ADMIN_PASSWORD for an unattended install (set it only for
 * that run, then remove it).
 */
class CreateAdmin extends Command
{
    public const MIN_PASSWORD = 12;

    protected $signature = 'pod:create-admin
        {--email= : Sign-in email (default: POD_ADMIN_EMAIL)}
        {--name=System Administrator : Display name}
        {--organisation= : Organisation for a new account (default: POD_ORGANISATION, else "Field Distribution")}
        {--reset-password : For an existing account, set a new password}';

    protected $description = 'Create or repair the super administrator account (password asked for, never on the command line)';

    public function handle(AdminAccount $accounts): int
    {
        $email = trim((string) ($this->option('email') ?: env('POD_ADMIN_EMAIL', '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Give the administrator\'s email with --email or POD_ADMIN_EMAIL.');

            return self::FAILURE;
        }

        $exists   = $accounts->exists($email);
        $password = null;
        if (!$exists || $this->option('reset-password')) {
            $password = $this->password();
            if ($password === null) {
                return self::FAILURE;
            }
        }

        $organisation = (string) ($this->option('organisation') ?: env('POD_ORGANISATION', 'Field Distribution'));

        try {
            $result = $accounts->ensure($email, (string) $this->option('name'), $organisation, $password);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($result['created']
            ? "Created the super administrator {$email} for \"{$result['organisation']}\". Sign in to the console with it."
            : "{$email} already existed: it is now a super administrator" . ($password !== null ? ', with the new password.' : ' (password unchanged).'));

        return self::SUCCESS;
    }

    private function password(): ?string
    {
        $fromEnv = env('POD_ADMIN_PASSWORD');
        if (is_string($fromEnv) && $fromEnv !== '') {
            $password = $fromEnv;
        } else {
            if (!$this->input->isInteractive()) {
                $this->error('No password: run this in a terminal to be asked for it, or set POD_ADMIN_PASSWORD for this run only.');

                return null;
            }
            $password = (string) $this->secret('Password (not shown)');
            if ($password !== (string) $this->secret('Same password again')) {
                $this->error('The two passwords are different. Nothing was changed.');

                return null;
            }
        }

        $problem = self::weakness($password);
        if ($problem) {
            $this->error($problem . ' Nothing was changed.');

            return null;
        }

        return $password;
    }

    public static function weakness(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            return 'Use at least ' . self::MIN_PASSWORD . ' characters.';
        }
        $kinds = (int) preg_match('/[a-z]/', $password) + (int) preg_match('/[A-Z]/', $password) + (int) preg_match('/\d/', $password) + (int) preg_match('/[^a-zA-Z\d]/', $password);
        if ($kinds < 3) {
            return 'Mix at least three of: lower case, upper case, digits, symbols.';
        }

        return null;
    }
}
