<?php

namespace Fleetbase\Pod\Services;

use Fleetbase\Models\Company;
use Fleetbase\Models\Role;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * The installation's super administrator: a Fleetbase platform administrator
 * (user type "admin") who owns an organisation and has its Administrator
 * role, so they can do everything in Fleetbase and PoD and give others roles.
 *
 * The same steps as Fleetbase's first-account sign-up (OnboardController),
 * without the sign-up screen.
 */
class AdminAccount
{
    public function exists(string $email): bool
    {
        return User::where('email', $email)->exists();
    }

    /**
     * @return array{created: bool, user: string, organisation: string}
     */
    public function ensure(string $email, string $name, string $organisation, ?string $password): array
    {
        if (!Role::where('name', 'Administrator')->exists()) {
            Artisan::call('fleetbase:create-permissions');
        }

        $user = User::where('email', $email)->first();
        if ($user) {
            if ($password !== null) {
                $user->password = $password;
                $user->save();
            }
            $user->setUserType('admin');
            $company = $user->company ?? Company::where('owner_uuid', $user->uuid)->first();
            if ($company) {
                $user->assignCompany($company, 'Administrator');
            }
            $user->assignSingleRole('Administrator');

            return ['created' => false, 'user' => $user->uuid, 'organisation' => $company?->name ?? ''];
        }

        if ($password === null) {
            throw new \InvalidArgumentException('A new account needs a password.');
        }

        $company = Company::create(['name' => $organisation, 'onboarding_completed_at' => now()]);
        $user    = User::create([
            'name'              => $name,
            'email'             => $email,
            'username'          => Str::slug(Str::before($email, '@') . '_' . Str::random(4), '_'),
            'company_uuid'      => $company->uuid,
            'status'            => 'active',
            'timezone'          => config('app.timezone', 'Africa/Freetown'),
            'email_verified_at' => now(),
        ]);
        $user->password = $password;
        $user->save();
        $user->setUserType('admin');
        $company->setOwner($user, true)->save();
        $user->assignCompany($company, 'Administrator');
        $user->assignSingleRole('Administrator');

        return ['created' => true, 'user' => $user->uuid, 'organisation' => $company->name];
    }
}
