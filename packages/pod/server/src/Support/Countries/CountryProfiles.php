<?php

namespace Fleetbase\Pod\Support\Countries;

/**
 * The configured country profiles, with Sierra Leone as the default.
 */
final class CountryProfiles
{
    /** @var array<string, CountryProfile> */
    private array $profiles = [];

    public function __construct(array $countries, private string $defaultCode = 'SL')
    {
        foreach ($countries as $code => $data) {
            $profile                         = CountryProfile::fromArray($code, $data);
            $this->profiles[$profile->code] = $profile;
        }

        $this->defaultCode = strtoupper($defaultCode);
        if (!isset($this->profiles[$this->defaultCode])) {
            throw new \InvalidArgumentException("The default country [{$this->defaultCode}] has no profile.");
        }
    }

    public function get(?string $code = null): CountryProfile
    {
        $code = strtoupper($code ?: $this->defaultCode);
        if (!isset($this->profiles[$code])) {
            throw new \InvalidArgumentException("No country profile for [{$code}].");
        }

        return $this->profiles[$code];
    }

    public function default(): CountryProfile
    {
        return $this->profiles[$this->defaultCode];
    }

    public function has(string $code): bool
    {
        return isset($this->profiles[strtoupper($code)]);
    }

    /**
     * @return array<string, CountryProfile>
     */
    public function all(): array
    {
        return $this->profiles;
    }
}
