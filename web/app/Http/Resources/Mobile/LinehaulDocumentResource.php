<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class LinehaulDocumentResource extends JsonResource
{
    public function toResponse($request)
    {
        $document = $this->resource;
        abort_unless($document && Storage::disk('local')->exists($document['path']), 404);
        $extension = match ($document['mime']) {
            'application/pdf' => 'pdf', 'image/png' => 'png', default => 'jpg',
        };

        return Storage::disk('local')->download($document['path'], 'courier-document.'.$extension,
            ['Content-Type' => $document['mime'], 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
