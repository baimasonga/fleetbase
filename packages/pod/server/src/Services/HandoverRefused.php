<?php

namespace Fleetbase\Pod\Services;

/**
 * A handover (or close) that the rules don't allow. Carries a machine-readable
 * code for the field app, a message for the officer, and details.
 */
class HandoverRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function toArray(): array
    {
        return ['errors' => [$this->getMessage()], 'error' => $this->reason] + $this->details;
    }
}
