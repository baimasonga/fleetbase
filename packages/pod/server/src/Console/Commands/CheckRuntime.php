<?php

namespace Fleetbase\Pod\Console\Commands;

use Illuminate\Console\Command;

/**
 * Checks, before the web server starts, what its workers need at start-up.
 *
 * FrankenPHP's worker mode (Octane) builds the application once per worker.
 * When that fails, the error goes to the worker's output, which is not the
 * log, and the deploy just says "worker script has failed". This command
 * runs during the pre-deploy step, whose output is in the deploy log, and
 * says plainly what is wrong.
 */
class CheckRuntime extends Command
{
    protected $signature = 'pod:check-runtime';

    protected $description = 'Check the settings the web server needs at start-up (APP_KEY, APP_URL) and boot the app as a web worker does';

    public function handle(): int
    {
        $problems = [];

        // APP_KEY: every worker builds the encrypter at start-up.
        try {
            app('encrypter');
        } catch (\Throwable $e) {
            $problems[] = 'APP_KEY is not valid (' . $e->getMessage() . '). It must be "base64:" followed by the rest of the APP_KEY line from the secrets generator, '
                . 'with nothing before or after it: no spaces, no quotes, and not the name "APP_KEY=" itself.';
        }

        // APP_URL: used to build every URL, and parsed when the server starts.
        $url  = (string) config('app.url');
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $problems[] = sprintf(
                'APP_URL is "%s", which is not a web address. On Railway set it to https://${{RAILWAY_PUBLIC_DOMAIN}} and make sure the service has a public domain (Settings → Networking → Generate Domain).',
                $url
            );
        }

        // Boot the application the way an Octane worker does, warming the same services.
        if (!$problems && class_exists(\Laravel\Octane\ApplicationFactory::class)) {
            try {
                (new \Laravel\Octane\ApplicationFactory(base_path()))->createApplication();
            } catch (\Throwable $e) {
                $problems[] = sprintf('The application does not start the way a web worker starts it: %s: %s (%s:%d)', $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
            }
        }

        if ($problems) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }

        $this->info('Web server start-up check passed: APP_KEY and APP_URL are valid' . (class_exists(\Laravel\Octane\ApplicationFactory::class) ? ', and the app boots as a web worker.' : '.'));

        return self::SUCCESS;
    }
}
