<?php

namespace App\Http\Controllers;

use App\Support\Policies\PolicyRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Public policy pages and the public policy API. Internal documents are never served here. */
class PolicyController extends Controller
{
    public function __construct(private readonly PolicyRepository $policies) {}

    public function show(Request $request, string $slug): View
    {
        $document = $this->policies->findPublic($slug);
        abort_if($document === null, 404);

        // Seller and logistics users reach policies from inside their panel; keep that
        // shell only when the matching guard is signed in, otherwise use the public one.
        $shell = $request->query('shell');
        $shell = in_array($shell, ['seller', 'logistics'], true) && auth($shell)->check() ? $shell : null;

        return view('Policies.show', ['document' => $document, 'related' => $this->policies->allPublic(), 'shell' => $shell]);
    }

    /** Admin-only internal SOP. The route sits behind the admin guard and role middleware. */
    public function internal(): View
    {
        $document = $this->policies->find('admin-sop');
        abort_if($document === null, 404);

        return view('Policies.show', ['document' => $document, 'related' => [], 'internal' => true]);
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn ($d) => collect($d->toArray())->only(['slug', 'title', 'version', 'effective_date', 'sha256', 'has_unconfirmed_fields'])->all(),
                $this->policies->allPublic(),
            ),
        ]);
    }

    public function showJson(string $slug): JsonResponse
    {
        $document = $this->policies->findPublic($slug);
        abort_if($document === null, 404);

        return response()->json(['data' => $document->toArray()]);
    }
}
