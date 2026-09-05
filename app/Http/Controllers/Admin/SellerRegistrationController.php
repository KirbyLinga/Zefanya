<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Seller;
use App\Notifications\SellerRegistrationDecision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class SellerRegistrationController extends Controller
{
    /**
     * Kept separate from Admin\RegistrationController (buyers) for now —
     * merge into a unified queue (unioned by status + submitted_at) once
     * Logistics registration exists too, per the original plan.
     */
    public function index(Request $request)
    {
        if (! Schema::hasTable('sellers')) {
            abort(404, 'The sellers table hasn\'t been created yet — this feature isn\'t available.');
        }

        $pending = Seller::where('status', 'pending_approval')
            ->orderBy('created_at')
            ->get();

        $selectedId = $request->query('view', $pending->first()?->id);
        $selected = $selectedId ? Seller::find($selectedId) : null;

        return view('Admin.Registrations.sellers', [
            'pending' => $pending,
            'selected' => $selected,
        ]);
    }

    public function approve(Seller $seller)
    {
        $seller->update([
            'status' => 'approved',
            'approved_by' => Auth::guard('admin')->id(),
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        $seller->notify(new SellerRegistrationDecision('approved'));

        return back()->with('success', "{$seller->fullName()}'s seller registration was approved.");
    }

    public function reject(Seller $seller, Request $request)
    {
        $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        $seller->update([
            'status' => 'rejected',
            'approved_by' => Auth::guard('admin')->id(),
            'approved_at' => now(),
            'rejection_reason' => $request->rejection_reason,
        ]);

        $seller->notify(new SellerRegistrationDecision('rejected', $request->rejection_reason));

        return back()->with('success', "{$seller->fullName()}'s seller registration was rejected.");
    }
}