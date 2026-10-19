<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\RiderApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RiderApplicationDocumentController extends Controller
{
    public function show(Request $request, RiderApplication $riderApplication, string $document)
    {
        abort_unless((int) $riderApplication->logistics_provider_id === (int) $request->user('logistics')->id, 404);
        abort_unless($riderApplication->email_verified_at !== null, 404);
        $path = match ($document) {
            'id' => $riderApplication->upload_id_path,
            'or-cr' => $riderApplication->or_cr_path,
            default => abort(404),
        };
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, basename($path), [
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private',
        ]);
    }
}
