<?php

namespace Fleetbase\Pod\Support\Cards;

/**
 * Signed programme card codes (AGRI-INPUTS.md §7.2).
 *
 * Every verified farmer gets a card with a QR code holding:
 *
 *   FBP1.<programme>.<beneficiary>.<issue>.<signature>
 *
 *   programme    the programme's public id
 *   beneficiary  the beneficiary's public id
 *   issue        the card's issue number (1 for the first card; reissuing a
 *                lost card increments it, which revokes the old card)
 *   signature    HMAC-SHA256 over the other parts, truncated to 128 bits,
 *                base32 (no padding), so the QR stays small and is easy to
 *                type if the camera fails
 *
 * The driver app checks the signature offline with the programme key in the
 * route bundle, and checks the issue number against the manifest.
 */
final class ProgrammeCard
{
    public const PREFIX = 'FBP1';

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A per-programme signing key derived from the installation secret.
     * The route bundle carries this key, never the installation secret.
     */
    public static function programmeKey(string $installationSecret, string $programmeId): string
    {
        if ($installationSecret === '') {
            throw new \InvalidArgumentException('The card secret is not configured (POD_CARD_SECRET).');
        }

        return hash_hmac('sha256', 'pod-card|' . $programmeId, $installationSecret, true);
    }

    public static function issue(string $programmeKey, string $programmeId, string $beneficiaryId, int $issueNumber = 1): string
    {
        self::assertPart($programmeId, 'programme');
        self::assertPart($beneficiaryId, 'beneficiary');
        if ($issueNumber < 1) {
            throw new \InvalidArgumentException('The issue number starts at 1.');
        }

        $body = implode('.', [self::PREFIX, $programmeId, $beneficiaryId, $issueNumber]);

        return $body . '.' . self::sign($programmeKey, $body);
    }

    /**
     * @param array<string, int> $currentIssues beneficiary id => current issue number (from the manifest);
     *                                          when given, older cards are refused as revoked
     */
    public static function verify(string $code, string $programmeKey, ?string $expectedProgrammeId = null, array $currentIssues = []): CardVerification
    {
        $parts = explode('.', trim($code));

        if (count($parts) !== 5 || strtoupper($parts[0]) !== self::PREFIX) {
            return CardVerification::invalid('malformed');
        }

        [, $programmeId, $beneficiaryId, $issue, $signature] = $parts;
        if (!ctype_digit($issue) || (int) $issue < 1) {
            return CardVerification::invalid('malformed');
        }

        $body = implode('.', [self::PREFIX, $programmeId, $beneficiaryId, (int) $issue]);
        if (!hash_equals(self::sign($programmeKey, $body), strtoupper($signature))) {
            return CardVerification::invalid('bad_signature');
        }

        if ($expectedProgrammeId !== null && $programmeId !== $expectedProgrammeId) {
            return CardVerification::invalid('wrong_programme', $programmeId, $beneficiaryId, (int) $issue);
        }

        if ($currentIssues !== []) {
            $current = $currentIssues[$beneficiaryId] ?? null;
            if ($current === null) {
                return CardVerification::invalid('not_in_manifest', $programmeId, $beneficiaryId, (int) $issue);
            }
            if ((int) $issue !== $current) {
                return CardVerification::invalid('revoked', $programmeId, $beneficiaryId, (int) $issue);
            }
        }

        return CardVerification::valid($programmeId, $beneficiaryId, (int) $issue);
    }

    private static function sign(string $key, string $body): string
    {
        return self::base32(substr(hash_hmac('sha256', $body, $key, true), 0, 16));
    }

    private static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function assertPart(string $value, string $name): void
    {
        if ($value === '' || str_contains($value, '.')) {
            throw new \InvalidArgumentException("The {$name} id must be non-empty and contain no dots.");
        }
    }
}
