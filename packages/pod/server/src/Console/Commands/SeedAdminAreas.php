<?php

namespace Fleetbase\Pod\Console\Commands;

use Fleetbase\Pod\Models\AdminArea;
use Fleetbase\Pod\Services\AdminAreaRepository;
use Fleetbase\Pod\Support\AdminAreas\AdminAreaCsvReader;
use Fleetbase\Pod\Support\Countries\CountryProfiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loads a country's official admin areas from the package seed file (or a
 * file given with --file) into pod_admin_areas.
 *
 * Existing areas are matched by p-code: new ones are added and changed ones
 * updated. Areas missing from the file are only reported, never deleted,
 * because beneficiaries may point at them.
 */
class SeedAdminAreas extends Command
{
    protected $signature = 'pod:seed-admin-areas
                            {country? : Country code, e.g. SL (default: the default country)}
                            {--file= : A CSV in the seed format instead of the packaged seed file}
                            {--dry-run : Show what would change without saving}';

    protected $description = 'Load official admin areas (regions, districts, chiefdoms, sections) for a country';

    public function handle(CountryProfiles $countries, AdminAreaRepository $repository): int
    {
        $country = $countries->get($this->argument('country'));
        $file    = $this->option('file') ?: $repository->seedFile($country->code);

        if (!$file || !is_readable($file)) {
            $this->error("No seed file for {$country->name}. Pass one with --file.");

            return self::FAILURE;
        }

        $set      = (new AdminAreaCsvReader())->read($file, $country->code);
        $existing = AdminArea::where('country', $country->code)->get()->keyBy('pcode');

        $added   = 0;
        $updated = 0;
        $now     = now();

        DB::transaction(function () use ($set, $existing, $country, $now, &$added, &$updated) {
            foreach ($set->all() as $area) {
                $values = [
                    'level'        => $area->level,
                    'level_label'  => $area->levelLabel ?? $country->adminLevelLabel($area->level),
                    'name'         => $area->name,
                    'source_name'  => $area->sourceName,
                    'parent_pcode' => $area->parentPcode,
                    'center_lat'   => $area->lat,
                    'center_lon'   => $area->lon,
                    'review_flags' => $area->reviewFlags,
                ];

                $current = $existing->get($area->pcode);
                if (!$current) {
                    $added++;
                    if (!$this->option('dry-run')) {
                        AdminArea::create($values + ['country' => $country->code, 'pcode' => $area->pcode, 'uuid' => (string) Str::uuid()]);
                    }
                    continue;
                }

                $current->fill($values);
                if ($current->isDirty()) {
                    $updated++;
                    if (!$this->option('dry-run')) {
                        $current->save();
                    }
                }
            }
        });

        $missing = $existing->keys()->diff(array_keys($set->all()));

        $this->info(sprintf('%s: %d areas in the file, %d added, %d updated%s.', $country->name, count($set), $added, $updated, $this->option('dry-run') ? ' (dry run, nothing saved)' : ''));
        foreach ([1, 2, 3, 4] as $level) {
            $this->line(sprintf('  %-10s %d', $country->adminLevelLabel($level), count($set->atLevel($level))));
        }
        if ($missing->isNotEmpty()) {
            $this->warn($missing->count() . ' areas in the database are not in the file and were kept: ' . $missing->take(10)->implode(', '));
        }

        $flagged = array_filter($set->all(), fn ($area) => $area->reviewFlags);
        if ($flagged) {
            $this->warn(count($flagged) . ' areas are flagged for local review (see the seed README).');
        }

        $repository->flush();

        return self::SUCCESS;
    }
}
