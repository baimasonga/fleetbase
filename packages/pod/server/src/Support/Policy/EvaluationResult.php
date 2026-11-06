<?php

namespace Fleetbase\Pod\Support\Policy;

final class EvaluationResult
{
    /**
     * @param list<array> $missing the requirement nodes not met, for the 422 response and the driver app checklist
     */
    public function __construct(
        public readonly bool $satisfied,
        public readonly array $missing = [],
    ) {
    }

    public function toArray(): array
    {
        return ['satisfied' => $this->satisfied, 'missing' => $this->missing];
    }
}
