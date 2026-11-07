<?php

namespace Fleetbase\Pod\Support\Import;

/**
 * A problem found in one imported row. Errors stop the row from being
 * imported; warnings are shown for checking but don't block it.
 */
final class RowIssue
{
    public const ERROR   = 'error';
    public const WARNING = 'warning';

    public function __construct(
        public readonly string $severity,
        public readonly string $field,
        public readonly string $code,
        public readonly string $message,
    ) {
    }

    public static function error(string $field, string $code, string $message): self
    {
        return new self(self::ERROR, $field, $code, $message);
    }

    public static function warning(string $field, string $code, string $message): self
    {
        return new self(self::WARNING, $field, $code, $message);
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'field'    => $this->field,
            'code'     => $this->code,
            'message'  => $this->message,
        ];
    }
}
