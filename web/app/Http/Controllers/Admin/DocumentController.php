<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /**
     * Stream a document from the private disk for admin viewing.
     * Only accessible to authenticated admins.
     */
    public function stream(Request $request, string $type, int $id, string $document): StreamedResponse|BinaryFileResponse
    {
        $user = $request->user('admin');
        if (! $user) {
            abort(403);
        }

        $model = match ($type) {
            'seller' => Seller::findOrFail($id),
            'logistics' => LogisticsProvider::findOrFail($id),
            'buyer' => Buyer::findOrFail($id),
            default => abort(404),
        };

        $path = $this->getDocumentPath($model, $document);
        if (! $path) {
            abort(404, 'Document not found.');
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            abort(404, 'Document file not found on disk.');
        }

        $mimeType = $disk->mimeType($path) ?? 'application/octet-stream';
        $filename = basename($path);

        // application/octet-stream forces download; when the browser does
        // render a file inline (images via <img>/iframe), nosniff stops it
        // from sniffing the content type, which would otherwise allow an
        // uploaded HTML/SVG to execute as script in the admin's session.
        return $disk->download($path, $filename, [
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Get the document path from the model based on document type.
     */
    private function getDocumentPath(Seller|LogisticsProvider|Buyer $model, string $document): ?string
    {
        return match (true) {
            $model instanceof Seller && $document === 'id' => $model->upload_id_path,
            $model instanceof Seller && $document === 'business_permit' => $model->business_permit_path,
            $model instanceof LogisticsProvider && $document === 'id' => $model->upload_id_path,
            $model instanceof LogisticsProvider && $document === 'dti_permit' => $model->dti_permit_path,
            $model instanceof Buyer && $document === 'id' => $model->upload_id_path,
            default => null,
        };
    }
}
