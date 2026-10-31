<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit record for an admin viewing a private identity document.
 *
 * Stores only the admin id, the document reference (type + row id + whitelisted
 * key), the action and the timestamp. Document contents, storage paths and any
 * tokens are deliberately never written here.
 */
class DocumentAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'admin_document_access_logs';

    /** Reference-only: nothing here may be mass assigned from request data. */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Document access logs cannot be edited.');
        });

        static::deleting(function (): never {
            throw new LogicException('Document access logs cannot be deleted.');
        });
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * Record a successful document view. Values come from the resolved route
     * model and the authenticated admin — never from free-form request input.
     */
    public static function record(
        ?int $adminId,
        string $documentType,
        int $documentId,
        string $documentKey,
        ?string $ipAddress = null,
    ): self {
        return static::query()->forceCreate([
            'admin_id' => $adminId,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'document_key' => $documentKey,
            'action' => 'view',
            'ip_address' => $ipAddress,
        ]);
    }
}
