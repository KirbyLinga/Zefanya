<?php

namespace App\Services\Shared;

use App\Models\Shared\FinancialReference;
use App\Support\RuntimeSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Keeps every external payment reference single-use within its scope: one
 * payout per reference across all payout types (Seller, direct settlement,
 * rider, Logistics share, compensation, return charge), and one refund per
 * reference.
 *
 * A reference stays protected after its payment is reversed or voided. It can
 * be used again only as the verified correction of that payment: the earlier
 * record was released by an audited reversal, and the new record is the same
 * payment type, to the same recipient, for the same amount. Anything else is
 * refused, including historical duplicates recorded before this registry.
 * A refund correction must also go to the same payment destination as the
 * voided refund, unless it carries verified external payment evidence (the
 * submitted proof a Super Admin confirmed); the evidence is stored with it.
 *
 * Locking: callers claim inside their own money transaction, after their
 * existing locks. The registry takes no locking reads (a FOR UPDATE on a
 * missing key gap-locks the unique index on MySQL and deadlocks unrelated
 * payments); the unique index on (scope, active_key) decides any race.
 */
class FinancialReferenceRegistry
{
    public static function normalize(string $reference): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', trim($reference)));
    }

    public static function tracksDestinations(): bool
    {
        return RuntimeSchema::hasColumn('financial_references', 'destination');
    }

    public static function normalizeDestination(?string $destination): ?string
    {
        $normalized = mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $destination)));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Refuse early (before any write) when the reference is not available to this record.
     * `$evidence` names the external payment evidence that the final claim will carry.
     */
    public function assertAvailable(string $scope, string $reference, ?Model $source = null, ?string $recipient = null, ?int $amountMinor = null, ?string $destination = null, ?string $evidence = null, string $field = 'reference'): void
    {
        $uses = $this->uses($scope, $reference);
        if ($source !== null && $uses->contains(fn (FinancialReference $use): bool => $use->active_key !== null && $use->released_at === null && $this->belongsTo($use, $source))) {
            return;
        }
        $this->assertNoBlockingUse($uses, $scope, $source, $recipient, $amountMinor, $destination, $evidence, $field);
    }

    /** Record that `$source` used the reference. Repeating the same claim is a no-op. */
    public function claim(string $scope, string $reference, Model $source, ?string $recipient = null, ?int $amountMinor = null, ?string $destination = null, ?string $evidence = null, string $field = 'reference'): void
    {
        $normalized = self::normalize($reference);
        if ($normalized === '') {
            throw ValidationException::withMessages([$field => 'A payment reference is required.']);
        }

        $uses = $this->uses($scope, $reference);
        if ($uses->contains(fn (FinancialReference $use): bool => $use->active_key !== null && $use->released_at === null && $this->belongsTo($use, $source))) {
            return;
        }
        $corrected = $this->assertNoBlockingUse($uses, $scope, $source, $recipient, $amountMinor, $destination, $evidence, $field);

        try {
            if ($corrected !== null && $corrected->active_key !== null) {
                // Hand the key from the reversed record to its correction, keeping both rows.
                FinancialReference::query()->whereKey($corrected->id)->whereNotNull('released_at')
                    ->update(['active_key' => null, 'updated_at' => now()]);
            }
            (new FinancialReference)->forceFill([
                'scope' => $scope,
                'reference' => trim($reference),
                'normalized' => $normalized,
                'active_key' => $normalized,
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'recipient' => $recipient,
                'amount_minor' => $amountMinor,
                'reuses_id' => $corrected?->id,
            ] + (self::tracksDestinations() ? [
                // Before the destination migration runs, no destination is stored, so every later
                // correction of this refund needs verified evidence (the stricter rule).
                'destination' => self::normalizeDestination($destination),
                'evidence' => $evidence,
            ] : []))->save();
        } catch (UniqueConstraintViolationException) {
            // Another transaction committed a use after our snapshot.
            throw $this->duplicate($scope, $field, false);
        }
    }

    /**
     * Mark the references `$source` holds as reversed. They stay protected; only
     * a verified correction of the same payment may use them again.
     */
    public function release(string $scope, Model $source): void
    {
        FinancialReference::query()
            ->where('scope', $scope)
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->whereNull('released_at')
            ->update(['released_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Every recorded use blocks, except a reversed one that this record verifiably
     * corrects. Returns that reversed use, or null when the reference is unused.
     */
    private function assertNoBlockingUse(Collection $uses, string $scope, ?Model $source, ?string $recipient, ?int $amountMinor, ?string $destination, ?string $evidence, string $field): ?FinancialReference
    {
        $corrected = null;
        $superseded = $uses->pluck('reuses_id')->filter()->map(fn ($id): int => (int) $id)->all();
        foreach ($uses as $use) {
            // A reversed use that was already corrected is represented by its correction.
            if ($use->released_at !== null && in_array((int) $use->id, $superseded, true)) {
                continue;
            }
            if ($use->released_at === null) {
                throw $this->duplicate($scope, $field, false);
            }
            if (! $this->verifiedCorrection($use, $source, $recipient, $amountMinor)) {
                throw $this->duplicate($scope, $field, true);
            }
            if ($scope === FinancialReference::REFUND && ! $this->sameDestinationOrEvidenced($use, $destination, $evidence)) {
                throw ValidationException::withMessages([$field => 'This reference belongs to a voided refund sent to a different destination. Reuse it only with verified payment evidence: submit the proof and have a Super Admin confirm it.']);
            }
            $corrected = $use;
        }

        return $corrected;
    }

    private function verifiedCorrection(FinancialReference $use, ?Model $source, ?string $recipient, ?int $amountMinor): bool
    {
        return $source !== null
            && $use->source_type === $source->getMorphClass()
            && $use->recipient !== null && $recipient !== null && $use->recipient === $recipient
            && $use->amount_minor !== null && $amountMinor !== null && (int) $use->amount_minor === $amountMinor;
    }

    /**
     * The same transfer re-recorded to the same destination needs no more proof. A different
     * (or unknown historical) destination needs verified external payment evidence.
     */
    private function sameDestinationOrEvidenced(FinancialReference $use, ?string $destination, ?string $evidence): bool
    {
        $destination = self::normalizeDestination($destination);
        if ($use->destination !== null && $destination !== null && $use->destination === $destination) {
            return true;
        }

        return filled($evidence);
    }

    /** @return Collection<int, FinancialReference> */
    private function uses(string $scope, string $reference): Collection
    {
        return FinancialReference::query()->where('scope', $scope)->where('normalized', self::normalize($reference))->orderBy('id')->get();
    }

    private function belongsTo(FinancialReference $reference, Model $source): bool
    {
        return $reference->source_type === $source->getMorphClass()
            && (int) $reference->source_id === (int) $source->getKey();
    }

    private function duplicate(string $scope, string $field, bool $reversed): ValidationException
    {
        $kind = $scope === FinancialReference::REFUND ? 'refund' : 'payout';

        return ValidationException::withMessages([$field => $reversed
            ? "This reference belongs to a reversed {$kind}. It can only be reused to correct that same {$kind}: same recipient and same amount."
            : "This reference was already used for another {$kind}. Check the transfer and enter its own reference."]);
    }
}
