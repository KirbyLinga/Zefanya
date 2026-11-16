<?php

namespace App\Services\Buyer;

use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\ConsumerClaim;
use App\Models\Buyer\ConsumerClaimUpdate;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use App\Notifications\ConsumerClaimChanged;
use App\Support\DocumentUpload;
use App\Support\ImageSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConsumerClaimService
{
    public const BASES = ['manufacturer_warranty', 'seller_warranty', 'consumer_remedy'];

    public const CATEGORIES = ['defect', 'not_as_described', 'warranty_service', 'other'];

    public const DECISIONS = ['request_buyer_information', 'request_seller_response', 'approve', 'reject', 'close'];

    /** No universal expiry and no dependency on the ordinary return deadline/confirmation gate. */
    public function eligible(SellerOrder $shipment): bool
    {
        return $shipment->statusHistories()->where('to_status', OrderStatus::Delivered->value)
            ->whereNotNull('created_at')->where('created_at', '<=', now())->exists();
    }

    public function authorize(ConsumerClaim $claim, Model $actor, string $role): void
    {
        abort_unless(match ($role) {
            'buyer' => $actor instanceof Buyer && (int) $claim->buyer_id === (int) $actor->id,
            'seller' => $actor instanceof Seller && (int) $claim->seller_id === (int) $actor->id,
            'admin' => $actor instanceof Admin && in_array($actor->role, ['admin', 'super_admin'], true),
            default => false,
        }, 403);
    }

    private function evidenceRules(): array
    {
        return ['evidence' => ['nullable', 'array', 'max:5'], 'evidence.*' => DocumentUpload::rules()];
    }

    public function submit(Buyer $buyer, SellerOrder $shipment, array $input): ConsumerClaim
    {
        app(ConsumerClaimAvailability::class)->requireAvailable();
        abort_unless((int) $shipment->order->buyer_id === (int) $buyer->id, 403);
        foreach (['description', 'warranty_details', 'message'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }
        $data = Validator::make($input, [
            'submission_key' => ['required', 'uuid'], 'order_item_id' => ['required', 'integer'],
            'basis' => ['required', Rule::in(self::BASES)], 'category' => ['required', Rule::in(self::CATEGORIES)],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'warranty_details' => ['nullable', 'string', 'max:2000'],
        ] + $this->evidenceRules())->validate();
        $stored = [];
        try {
            return DB::transaction(function () use ($buyer, $shipment, $data, &$stored): ConsumerClaim {
                $locked = SellerOrder::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
                abort_unless((int) $locked->order->buyer_id === (int) $buyer->id, 403);
                if (! $this->eligible($locked)) {
                    throw ValidationException::withMessages(['claim' => 'A recorded delivery is needed for this claim pathway. Contact support for an undelivered-order concern.']);
                }
                $item = $locked->orderItems()->whereKey($data['order_item_id'])->firstOrFail();
                $existing = ConsumerClaim::query()->where('buyer_id', $buyer->id)->where('submission_key', $data['submission_key'])->first();
                if ($existing !== null) {
                    abort_unless((int) $existing->order_item_id === (int) $item->id, 409);

                    return $existing;
                }
                $active = ConsumerClaim::query()->where('order_item_id', $item->id)->where('category', $data['category'])
                    ->where('basis', $data['basis'])
                    ->where('status', '!=', 'closed')->first();
                if ($active !== null) {
                    return $active;
                }
                $claim = new ConsumerClaim;
                $claim->forceFill(collect($data)->except(['evidence'])->all() + [
                    'buyer_id' => $buyer->id, 'seller_id' => $locked->seller_id, 'seller_order_id' => $locked->id,
                    'product_name' => $item->product_name, 'status' => 'submitted',
                ])->save();
                $this->append($claim, $buyer, 'buyer', 'submitted', null, $data['description'], $data['submission_key'], $data['evidence'] ?? [], $stored);
                $this->notifyAfterCommit($claim, 'buyer', 'submitted');

                return $claim;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($stored);
            throw $exception;
        }
    }

    public function update(ConsumerClaim $claim, Model $actor, string $role, array $input): ConsumerClaim
    {
        app(ConsumerClaimAvailability::class)->requireAvailable();
        $this->authorize($claim, $actor, $role);
        foreach (['description', 'warranty_details', 'message'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }
        $data = Validator::make($input, [
            'submission_key' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:0'],
            'action' => ['required', Rule::in($role === 'admin' ? self::DECISIONS : ['response'])],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ] + $this->evidenceRules())->validate();
        $stored = [];
        try {
            return DB::transaction(function () use ($claim, $actor, $role, $data, &$stored): ConsumerClaim {
                $locked = ConsumerClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
                $this->authorize($locked, $actor, $role);
                if ($locked->updates()->where('actor_type', $role)->where('actor_id', $actor->id)->where('submission_key', $data['submission_key'])->exists()) {
                    return $locked;
                }
                if ((int) $locked->revision !== (int) $data['revision']) {
                    throw ValidationException::withMessages(['claim' => 'This case changed. Refresh it before submitting your update.']);
                }
                if ($locked->status === 'closed') {
                    throw ValidationException::withMessages(['claim' => 'This case is closed. Contact support if further review is needed.']);
                }
                $old = $locked->status;
                $status = match ($data['action']) {
                    'request_buyer_information' => 'awaiting_buyer', 'request_seller_response' => 'awaiting_seller',
                    'approve' => 'approved', 'reject' => 'rejected', 'close' => 'closed',
                    default => $role === 'seller' || in_array($old, ['awaiting_buyer', 'submitted'], true) ? 'under_review' : $old,
                };
                if ($role === 'seller' && $old !== 'awaiting_seller') {
                    throw ValidationException::withMessages(['claim' => 'A Seller response is available when Admin requests it.']);
                }
                $locked->forceFill(['status' => $status, 'revision' => (int) $locked->revision + 1])->save();
                $this->append($locked, $actor, $role, $data['action'], $old, $data['message'], $data['submission_key'], $data['evidence'] ?? [], $stored);
                if ($role === 'admin') {
                    OperationsAuditLog::record($actor, 'consumer_claim_'.$data['action'], $locked, 'Consumer claim #'.$locked->id,
                        ['status' => $old], ['status' => $status], $data['message']);
                }
                $this->notifyAfterCommit($locked, $role, $data['action']);

                return $locked;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($stored);
            throw $exception;
        }
    }

    private function append(ConsumerClaim $claim, Model $actor, string $role, string $action, ?string $old, string $message, string $key, array $files, array &$stored): void
    {
        $evidence = [];
        foreach ($files as $file) {
            $directory = 'consumer-claims/'.$claim->id;
            try {
                $path = str_starts_with((string) $file->getMimeType(), 'image/')
                    ? ImageSanitizer::store($file, $directory, 'local', 'evidence') : $file->store($directory, 'local');
            } catch (\ValueError|\ErrorException) {
                throw ValidationException::withMessages(['evidence' => 'This image could not be processed. Choose a valid JPG or PNG, or attach a PDF.']);
            }
            if (! is_string($path) || $path === '') {
                throw ValidationException::withMessages(['evidence' => 'The evidence could not be saved. Please try again.']);
            }
            $stored[] = $path;
            $evidence[] = ['path' => $path, 'name' => 'Evidence '.(count($evidence) + 1).'.'.pathinfo($path, PATHINFO_EXTENSION)];
        }
        (new ConsumerClaimUpdate)->forceFill(['consumer_claim_id' => $claim->id, 'actor_type' => $role, 'actor_id' => $actor->id,
            'submission_key' => $key, 'action' => $action, 'from_status' => $old, 'to_status' => $claim->status,
            'message' => $message, 'evidence' => $evidence])->save();
    }

    private function notifyAfterCommit(ConsumerClaim $claim, string $actorRole, string $action): void
    {
        DB::afterCommit(function () use ($claim, $actorRole, $action): void {
            try {
                if ($actorRole !== 'buyer') {
                    $claim->buyer?->notify(new ConsumerClaimChanged($claim, 'buyer', $action));
                }
                if ($actorRole === 'admin' && in_array($action, ['request_seller_response', 'approve', 'reject', 'close'], true)) {
                    $claim->seller?->notify(new ConsumerClaimChanged($claim, 'seller', $action));
                }
                if ($actorRole !== 'admin') {
                    foreach (Admin::query()->whereIn('role', ['admin', 'super_admin'])->get() as $admin) {
                        $admin->notify(new ConsumerClaimChanged($claim, 'admin', $action));
                    }
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}
