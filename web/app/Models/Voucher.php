<?php

namespace App\Models;

use App\Enums\VoucherType;
use Database\Factories\VoucherFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    /** @use HasFactory<VoucherFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'value',
        'min_spend',
        'is_active',
        'expires_at',
        'usage_limit',
        'times_used',
    ];

    protected $casts = [
        'type' => VoucherType::class,
        'value' => 'integer',
        'min_spend' => 'integer',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'usage_limit' => 'integer',
        'times_used' => 'integer',
    ];

    protected static function newFactory(): VoucherFactory
    {
        return VoucherFactory::new();
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Resolve a voucher code (as stored in the session) against a subtotal.
     *
     * The single place where a code is turned into a usable Voucher, so the
     * cart page, the cart JSON endpoints and checkout all validate identically.
     * A rejection reason is written to $notice when the voucher cannot be used.
     *
     * @param  string|null  $code  Raw code, case-insensitive
     * @param  int  $subtotalCentavos  Subtotal the voucher must be valid against
     * @param  string|null  $notice  Receives the rejection reason, if any
     */
    public static function resolveCode(?string $code, int $subtotalCentavos, ?string &$notice = null): ?self
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $voucher = static::where('code', strtoupper(trim($code)))->first();

        $reason = $voucher === null
            ? 'This voucher code is no longer valid.'
            : $voucher->rejectionReason($subtotalCentavos);

        if ($reason !== null) {
            $notice = $reason;

            return null;
        }

        return $voucher;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Calculate the discount in centavos for a given subtotal (also centavos).
     * Never returns more than the subtotal.
     */
    public function discountCentavos(int $subtotalCentavos): int
    {
        $discount = match ($this->type) {
            VoucherType::Fixed => $this->value,                        // already centavos
            VoucherType::Percent => (int) intdiv($subtotalCentavos * $this->value, 100),
            default => 0,
        };

        return min($discount, $subtotalCentavos);
    }

    /**
     * Validate the voucher against a subtotal and return a plain-English
     * rejection reason, or null if the voucher is valid.
     */
    public function rejectionReason(int $subtotalCentavos): ?string
    {
        if (! $this->is_active) {
            return 'This voucher is no longer active.';
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return 'This voucher has expired.';
        }

        if ($this->usage_limit !== null && $this->times_used >= $this->usage_limit) {
            return 'This voucher has reached its usage limit.';
        }

        if ($this->min_spend !== null && $subtotalCentavos < $this->min_spend) {
            $formatted = '₱'.number_format($this->min_spend / 100, 2);

            return "A minimum spend of {$formatted} is required to use this voucher.";
        }

        return null;
    }

    /** Human-readable discount label, e.g. "-₱200.00" or "-10% (-₱56.96)". */
    public function discountLabel(int $subtotalCentavos): string
    {
        if ($this->type === VoucherType::Percent) {
            return "-{$this->value}% (-₱".number_format($this->discountCentavos($subtotalCentavos) / 100, 2).')';
        }

        return '-₱'.number_format($this->discountCentavos($subtotalCentavos) / 100, 2);
    }
}
