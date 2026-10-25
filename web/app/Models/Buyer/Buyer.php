<?php

namespace App\Models\Buyer;

use App\Models\CartItem;
use Carbon\Carbon;
use Database\Factories\BuyerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

class Buyer extends Authenticatable
{
    // HasApiTokens is what lets a buyer hold a bearer token: the mobile app has
    // no session cookie and no CSRF token, so a token is the only credential it
    // can present. Added to this model rather than to all four at once — the API
    // signs buyers in so far, and a trait on a model nothing authenticates is an
    // untested claim.
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'last_name',
        'first_name',
        'middle_initial',
        'sex',
        'email',
        'pending_email',
        'pending_email_code',
        'pending_email_expires_at',
        'password',
        'contact_no',
        'pending_phone',
        'pending_phone_code',
        'pending_phone_expires_at',
        'birthday',
        'age',
        'address_mode',
        'province_code',
        'province_name',
        'municipality_code',
        'municipality_name',
        'barangay_code',
        'barangay_name',
        'street',
        'house_number',
        'address_detail',
        'upload_id_path',
        'email_verification_code',
        'email_verification_expires_at',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_code',
        'pending_email_code',
        'pending_phone_code',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'pending_email_expires_at' => 'datetime',
            'pending_phone_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function fullName(): string
    {
        $middle = $this->middle_initial ? " {$this->middle_initial}." : '';

        return trim("{$this->first_name}{$middle} {$this->last_name}");
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    protected static function newFactory(): BuyerFactory
    {
        return BuyerFactory::new();
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function isVerificationLinkExpired(): bool
    {
        return $this->email_verification_expires_at !== null
            && $this->email_verification_expires_at->isPast();
    }

    /**
     * Marks the email verified and moves the buyer into the admin queue.
     * Called by VerifyBuyerEmailController once the token checks out.
     */
    public function markEmailVerified(): void
    {
        $this->forceFill([
            'email_verified_at' => now(),
            'email_verification_code' => null,
            'email_verification_expires_at' => null,
            'status' => 'pending_approval',
        ])->save();
    }

    /**
     * Whether the email gate is behind this account.
     *
     * The web OTP controller and the API's sign-in gate both ask this, and they
     * must agree: if one of them considered the gate open while the other did
     * not, the app would offer a confirmation the server treats as finished (or
     * refuse one it is waiting for). `status` is the source of truth because it
     * is what `markEmailVerified()` moves — `email_verified_at` is set at the
     * same moment but a row can predate the column.
     *
     * `rejected` is *not* past verification: a rejected account never confirmed
     * its email, and reporting otherwise would tell someone their application
     * was accepted.
     */
    public function isPastEmailVerification(): bool
    {
        return in_array($this->status, ['pending_approval', 'approved'], true);
    }

    /**
     * Generates a fresh 6-digit code, stores its hash, and returns the raw code
     * for the caller to email.
     *
     * All three steps or none: the row keeps only the hash, so a caller that
     * generated a code without storing it would email something the server can
     * never match. This lives on the model because the hash algorithm and the
     * 10-minute window are one policy — spelled separately in the registration
     * and resend paths it would be two chances to drift.
     */
    public function issueEmailVerificationCode(): string
    {
        $code = (string) random_int(100000, 999999);

        $this->forceFill([
            'email_verification_code' => hash('sha256', $code),
            'email_verification_expires_at' => now()->addMinutes(10),
        ])->save();

        return $code;
    }

    /**
     * Whether `$code` is the code this row is waiting for.
     *
     * `hash_equals` rather than `===`, so a wrong code cannot be found one byte
     * at a time by timing the comparison. A null column never matches anything,
     * which is what makes an already-verified row (code cleared) reject every
     * attempt instead of accepting `hash('sha256', '')`.
     */
    public function verifyEmailCode(string $code): bool
    {
        if ($this->email_verification_code === null) {
            return false;
        }

        return hash_equals($this->email_verification_code, hash('sha256', $code));
    }

    /**
     * Issues a code for a *change* of email, not for registration.
     *
     * Kept off `email_verification_code` on purpose: that column gates whether
     * an account may enter the app at all, and reusing it here would make a
     * profile change look like a registration gate to `isPastEmailVerification`.
     * The pending value also means the old address keeps working until the new
     * one is proven to receive mail.
     */
    public function issuePendingEmailCode(string $email): string
    {
        $code = (string) random_int(100000, 999999);

        $this->forceFill([
            'pending_email' => $email,
            'pending_email_code' => hash('sha256', $code),
            'pending_email_expires_at' => now()->addMinutes(10),
        ])->save();

        return $code;
    }

    public function verifyPendingEmailCode(string $code): bool
    {
        if ($this->pending_email_code === null) {
            return false;
        }

        return hash_equals($this->pending_email_code, hash('sha256', $code));
    }

    public function isPendingEmailExpired(): bool
    {
        return $this->pending_email_expires_at === null
            || $this->pending_email_expires_at->isPast();
    }

    public function applyPendingEmail(): void
    {
        $this->forceFill([
            'email' => $this->pending_email,
            // Receiving the code at the new address is the same proof the
            // registration gate asks for, so the account does not drop back
            // into `pending_verification` for a change made while signed in.
            'email_verified_at' => now(),
            'pending_email' => null,
            'pending_email_code' => null,
            'pending_email_expires_at' => null,
        ])->save();
    }

    /**
     * Phone changes mirror email changes. There is no SMS gateway wired up yet,
     * so the controller logs the code; the column and expiry policy are already
     * the shape an SMS provider will use.
     */
    public function issuePendingPhoneCode(string $phone): string
    {
        $code = (string) random_int(100000, 999999);

        $this->forceFill([
            'pending_phone' => $phone,
            'pending_phone_code' => hash('sha256', $code),
            'pending_phone_expires_at' => now()->addMinutes(10),
        ])->save();

        return $code;
    }

    public function verifyPendingPhoneCode(string $code): bool
    {
        if ($this->pending_phone_code === null) {
            return false;
        }

        return hash_equals($this->pending_phone_code, hash('sha256', $code));
    }

    public function isPendingPhoneExpired(): bool
    {
        return $this->pending_phone_expires_at === null
            || $this->pending_phone_expires_at->isPast();
    }

    public function applyPendingPhone(): void
    {
        $this->forceFill([
            'contact_no' => $this->pending_phone,
            'pending_phone' => null,
            'pending_phone_code' => null,
            'pending_phone_expires_at' => null,
        ])->save();
    }

    /**
     * Creates a buyer row from a validated registration payload.
     *
     * The single spelling of "what a new buyer row contains", because there are
     * now two doors into it (the web modal and the mobile app) and they must
     * produce the same account. `age` is computed here rather than accepted from
     * the client, the address codes stay nullable for manual addresses, and the
     * row starts at `pending_verification` — the gate the code below it exists
     * to open.
     *
     * The verification code is *not* issued here: `issueEmailVerificationCode()`
     * does that, so both doors have exactly one code path.
     */
    public static function createFromRegistration(array $data, string $uploadIdPath): self
    {
        return static::create([
            'last_name' => $data['last_name'],
            'first_name' => $data['first_name'],
            'middle_initial' => $data['middle_initial'] ?? null,
            'sex' => $data['sex'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'contact_no' => $data['contact_no'],
            'birthday' => $data['birthday'],
            'age' => Carbon::parse($data['birthday'])->age,
            'address_mode' => $data['address_mode'],
            'province_code' => $data['province'] ?? null,
            'province_name' => $data['province_name'] ?? null,
            'municipality_code' => $data['municipality'] ?? null,
            'municipality_name' => $data['municipality_name'] ?? null,
            'barangay_code' => $data['barangay'] ?? null,
            'barangay_name' => $data['barangay_name'] ?? null,
            'street' => $data['street'] ?? null,
            'house_number' => $data['house_number'] ?? null,
            'address_detail' => $data['address_detail'] ?? null,
            'upload_id_path' => $uploadIdPath,
            'status' => 'pending_verification',
        ]);
    }
}
