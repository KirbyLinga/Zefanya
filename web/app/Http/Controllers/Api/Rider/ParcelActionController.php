<?php

namespace App\Http\Controllers\Api\Rider;

use App\Enums\DeliveryFailureReason;
use App\Enums\OrderStatus;
use App\Exceptions\StaleStateException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Http\Requests\Api\Rider\CompleteDeliveryApiRequest;
use App\Http\Requests\Api\Rider\MarkDeliveryFailedApiRequest;
use App\Http\Resources\Mobile\RiderParcelResource;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\DeliveryOtpService;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Logistics\ParcelTrackingVerificationService;
use App\Services\Rider\RiderOperationsService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Rider mobile parcel handoffs: scan, pickup, start delivery, delivery code,
 * proof of delivery (with COD) and failed delivery.
 *
 * Each action mirrors the Rider web controller step for step: assert the
 * assignment, require a valid scan verification token unless the action is
 * an idempotent repeat, call the same RiderOperationsService method (which
 * owns the row lock, status transition, COD amount check, OTP consumption,
 * earnings and notifications), then consume the token only after success.
 *
 * Errors: 403/404 from the services' ownership aborts; 422 for validation;
 * 409 for stale state (the services' StaleStateException / 409 validation,
 * or any other InvalidArgumentException from a status transition).
 */
class ParcelActionController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly RiderOperationsService $operations,
        private readonly ParcelHandoffVerification $handoff,
    ) {}

    /** POST /api/rider/scan/verify — returns per-action verification tokens. */
    public function verify(Request $request, ParcelTrackingVerificationService $verification): JsonResponse
    {
        $data = $request->validate([
            'tracking_number' => ['required', 'string', 'max:64'],
            'method' => ['nullable', Rule::in([ParcelHandoffVerification::METHOD_CAMERA, ParcelHandoffVerification::METHOD_MANUAL])],
        ]);

        try {
            $result = $verification->verifyForRider(
                $request->user(),
                $data['tracking_number'],
                $data['method'] ?? ParcelHandoffVerification::METHOD_MANUAL,
            );
        } catch (ModelNotFoundException) {
            return $this->error('No parcel matches that tracking number.', ['tracking_number' => ['No parcel matches that tracking number.']], 404);
        } catch (AuthorizationException $e) {
            return $this->forbidden($e->getMessage() ?: 'You are not allowed to access this parcel.');
        }

        // Web URLs point at session routes; the app only needs the keys and tokens.
        $actions = collect($result['actions'])
            ->filter(fn (array $action): bool => isset($action['verification_token']))
            ->map(fn (array $action): array => ['key' => $action['key'], 'verification_token' => $action['verification_token']])
            ->values();

        return $this->success('Parcel verified.', [
            'context' => $result['context'],
            'parcel' => $result['parcel'],
            'actions' => $actions,
        ]);
    }

    /** POST /api/rider/pickups/{sellerOrder}/confirm */
    public function confirmPickup(Request $request, SellerOrder $sellerOrder): JsonResponse
    {
        $rider = $request->user();
        $this->operations->assertPickupAssigned($rider, $sellerOrder);

        return $this->guarded(function () use ($request, $rider, $sellerOrder): JsonResponse {
            $token = $this->token($request);
            $method = $this->assertToken($rider, $sellerOrder, ParcelHandoffVerification::ACTION_CONFIRM_PICKUP, $token, [OrderStatus::PickedUp]);
            $parcel = $this->operations->confirmPickup($rider, $sellerOrder, $method);
            $this->consume($rider, $sellerOrder, ParcelHandoffVerification::ACTION_CONFIRM_PICKUP, $token, $method);

            return $this->parcel('Pickup confirmed.', $parcel, 'pickup');
        });
    }

    /** POST /api/rider/deliveries/{sellerOrder}/start */
    public function startDelivery(Request $request, SellerOrder $sellerOrder): JsonResponse
    {
        $rider = $request->user();
        $this->operations->assertDeliveryAssigned($rider, $sellerOrder);

        return $this->guarded(function () use ($request, $rider, $sellerOrder): JsonResponse {
            $token = $this->token($request);
            $method = $this->assertToken($rider, $sellerOrder, ParcelHandoffVerification::ACTION_START_DELIVERY, $token, [OrderStatus::OutForDelivery]);
            $parcel = $this->operations->startDelivery($rider, $sellerOrder, $method);
            $this->consume($rider, $sellerOrder, ParcelHandoffVerification::ACTION_START_DELIVERY, $token, $method);

            return $this->parcel('Delivery started.', $parcel, 'delivery');
        });
    }

    /** POST /api/rider/deliveries/{sellerOrder}/otp — sends the code to the buyer; never returns it. */
    public function sendOtp(Request $request, SellerOrder $sellerOrder, DeliveryOtpService $otp): JsonResponse
    {
        $rider = $request->user();
        $this->operations->assertDeliveryAssigned($rider, $sellerOrder);

        return $this->guarded(function () use ($request, $rider, $sellerOrder, $otp): JsonResponse {
            $this->handoff->assertValidForRider($rider, $sellerOrder, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, $this->token($request));
            $otp->send($rider, $sellerOrder);

            return $this->success('Delivery code sent to the buyer. It expires in 10 minutes.');
        });
    }

    /** POST /api/rider/deliveries/{sellerOrder}/deliver (multipart) */
    public function deliver(CompleteDeliveryApiRequest $request, SellerOrder $sellerOrder): JsonResponse
    {
        $rider = $request->user();
        $this->operations->assertDeliveryAssigned($rider, $sellerOrder);

        return $this->guarded(function () use ($request, $rider, $sellerOrder): JsonResponse {
            $token = $this->token($request);
            // Assert only — the token is consumed after POD/COD/OTP succeed.
            $method = $this->assertToken($rider, $sellerOrder, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, $token, [OrderStatus::Delivered, OrderStatus::Completed]);

            $parcel = $this->operations->completeDelivery($rider, $sellerOrder, [
                'photo' => $request->file('proof_photo'),
                'received_by' => $request->input('received_by'),
                'notes' => $request->input('delivery_notes'),
                'cod_amount_minor' => $request->codAmountMinor(),
                'verification_method' => $method,
                'delivery_otp' => $request->input('delivery_otp'),
            ]);
            $this->consume($rider, $sellerOrder, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, $token, $method);

            return $this->parcel('Delivery recorded. Proof of delivery saved.', $parcel, 'delivery');
        });
    }

    /** POST /api/rider/deliveries/{sellerOrder}/failed */
    public function failDelivery(MarkDeliveryFailedApiRequest $request, SellerOrder $sellerOrder): JsonResponse
    {
        $rider = $request->user();
        $this->operations->assertDeliveryAssigned($rider, $sellerOrder);

        return $this->guarded(function () use ($request, $rider, $sellerOrder): JsonResponse {
            $token = $this->token($request);
            $method = $this->assertToken($rider, $sellerOrder, ParcelHandoffVerification::ACTION_FAIL_DELIVERY, $token, [OrderStatus::DeliveryFailed]);

            $parcel = $this->operations->failDelivery(
                $rider,
                $sellerOrder,
                DeliveryFailureReason::from((string) $request->input('failure_reason')),
                $request->input('failure_notes'),
                $method,
            );
            $this->consume($rider, $sellerOrder, ParcelHandoffVerification::ACTION_FAIL_DELIVERY, $token, $method);

            return $this->parcel('Delivery attempt recorded as failed. Logistics will decide the next step.', $parcel, 'delivery');
        });
    }

    public function completionStatus(Request $request, SellerOrder $sellerOrder): JsonResponse
    {
        $rider = $request->user();
        $state = DB::transaction(function () use ($rider, $sellerOrder): string {
            $parcel = SellerOrder::query()->whereKey($sellerOrder->id)->sharedLock()->firstOrFail();
            $this->operations->assertDeliveryAssigned($rider, $parcel);
            if (in_array($parcel->status, [OrderStatus::Delivered, OrderStatus::Completed], true)
                && $parcel->deliveryProof()->where('rider_id', $rider->id)
                    ->where('courier_id', $parcel->delivery_courier_id)->exists()) {
                return 'completed';
            }

            return $parcel->status === OrderStatus::OutForDelivery ? 'open' : 'unconfirmed';
        }, 3);

        return $this->success('Delivery outcome retrieved.', ['state' => $state])->header('Cache-Control', 'no-store, private');
    }

    private function token(Request $request): ?string
    {
        $token = $request->input('verification_token');

        return is_string($token) ? $token : null;
    }

    /**
     * Require a valid scan token unless the parcel is already in the action's
     * end state (an idempotent repeat, which the service answers without writing).
     *
     * @param  list<OrderStatus>  $doneStatuses
     */
    private function assertToken($rider, SellerOrder $parcel, string $action, ?string $token, array $doneStatuses): ?string
    {
        if (in_array($parcel->fresh()?->status, $doneStatuses, true)) {
            return null;
        }

        return $this->handoff->assertValidForRider($rider, $parcel, $action, $token)['method'] ?? null;
    }

    private function consume($rider, SellerOrder $parcel, string $action, ?string $token, ?string $method): void
    {
        if ($method === null) {
            return;
        }

        $this->handoff->consumeAfterSuccess(fn () => $this->handoff->consumeForRider($rider, $parcel, $action, $token));
    }

    private function parcel(string $message, SellerOrder $parcel, string $leg): JsonResponse
    {
        $parcel = $parcel->fresh(['order', 'seller', 'payment', 'orderItems']) ?? $parcel;

        return $this->success($message, (new RiderParcelResource($parcel))->withLeg($leg)->resolve());
    }

    /**
     * Validation (422/409) and HTTP aborts render through the framework's JSON
     * handler. A status-transition refusal that is not already a
     * StaleStateException is still a state conflict for the client: 409.
     */
    private function guarded(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (StaleStateException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), ['status' => [$e->getMessage()]], 409);
        }
    }
}
