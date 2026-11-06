<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResubmitRegistrationRequest;
use App\Models\Shared\Category;
use App\Services\Shared\RegistrationResubmissionService;
use App\Support\SignedRegistrationResubmission;

class RegistrationResubmissionController extends Controller
{
    public function show(ResubmitRegistrationRequest $request)
    {
        $account = $request->application();

        return response()->view('Auth.registration-resubmission', [
            'account' => $account, 'role' => $request->route('role'),
            'submitUrl' => SignedRegistrationResubmission::url($account),
            'categories' => Category::orderBy('label')->get(),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(ResubmitRegistrationRequest $request, RegistrationResubmissionService $service)
    {
        $service->resubmit($request->application(), $request->validated());

        return redirect()->route('register.'.$request->route('role').'.pending')
            ->with('success', 'Your corrected application was resubmitted and is pending review.');
    }
}
