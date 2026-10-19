<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\DecideRiderApplicationRequest;
use App\Models\RiderApplication;
use App\Models\RiderApplicationDecision;
use App\Policies\RiderApplicationPolicy;
use App\Services\RiderApplicationDecisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RiderController extends Controller
{
    public function index(Request $request): View
    {
        $riders = RiderApplication::where('logistics_provider_id', $request->user('logistics')->id)
            ->whereNotNull('email_verified_at')->latest('id')->paginate(20);

        return view('Logistics.Riders.index', compact('riders'));
    }

    public function show(Request $request, RiderApplication $riderApplication): View
    {
        abort_unless(app(RiderApplicationPolicy::class)->view($request->user('logistics'), $riderApplication), 404);
        $decisions = RiderApplicationDecision::where('rider_application_id', $riderApplication->id)->orderBy('id')->get();

        return view('Logistics.Riders.show', ['rider' => $riderApplication, 'decisions' => $decisions]);
    }

    public function decide(DecideRiderApplicationRequest $request, RiderApplication $riderApplication, RiderApplicationDecisionService $service): RedirectResponse|JsonResponse
    {
        try {
            $service->decide($request->user('logistics'), $riderApplication, $request->validated('decision'), $request->validated('reason'));
        } catch (HttpExceptionInterface|ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            // Transport exception text may contain private message content.
            if ($request->expectsJson()) {
                return response()->json(['message' => 'The decision could not be delivered. No decision was saved. Please retry.'], 503);
            }

            return back()->with('error', 'The decision could not be delivered. No decision was saved. Please retry.');
        }
        if ($request->expectsJson()) {
            return response()->json(['status' => $riderApplication->fresh()->status]);
        }

        return redirect()->route('logistics.riders.show', $riderApplication)->with('success', 'Rider decision saved and emailed.');
    }
}
