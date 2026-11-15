<?php

namespace App\Http\Requests\Api\Rider;

use App\Http\Requests\ParcelIncident\StoreIncidentRequest;
use App\Models\Rider\Rider;

/**
 * Bearer-token twin of StoreIncidentRequest. The rules, normalisation and
 * messages are inherited unchanged; only the caller check differs, because a
 * Sanctum token authenticates on the request, not on the `rider` session guard.
 */
class StoreIncidentApiRequest extends StoreIncidentRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Rider;
    }
}
