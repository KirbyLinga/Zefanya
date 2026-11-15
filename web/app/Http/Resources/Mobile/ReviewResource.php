<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'order_id' => (string) $this->orderItem->seller_order_id,
            'order_item_id' => (string) $this->order_item_id,
            'product_id' => (string) $this->product_id,
            'stars' => (int) $this->rating,
            'comment' => $this->body ?? '',
            'edit_count' => $this->buyer_edited_at === null ? 0 : 1,
            'completed_at' => $this->orderItem->sellerOrder->completionTime()?->toIso8601String(),
            'photo_urls' => $this->photos->map(fn ($photo): string => url('/storage/'.$photo->path))->values(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
