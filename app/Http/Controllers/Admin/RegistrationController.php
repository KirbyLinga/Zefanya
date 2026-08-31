<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Notifications\BuyerRegistrationDecision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class RegistrationController extends Controller
{
    /**
     * List pending registrations, with one selected for the detail panel.
     * Buyer-only for now — Seller/Logistics tables will merge in here
     * once they exist, unioned by status + submitted_at.
     */
    public function index(Request $request)
    {
        if (! Schema::hasTable('buyers')) {
            abort(404, 'The buyers table hasn\'t been created yet — this feature isn\'t available.');
        }

        $pending = Buyer::where('status', 'pending')
            ->orderBy('created_at')
            ->get();

        $selectedId = $request->query('view', $pending->first()?->id);
        $selected = $selectedId ? Buyer::find($selectedId) : null;

        return view('Admin.Registrations.index', [
            'pending' => $pending,
            'selected' => $selected,
        ]);
    }

    public function approve(Buyer $buyer)
    {
        $buyer->update([
            'status' => 'approved',
            'approved_by' => Auth::guard('admin')->id(),
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        $buyer->notify(new BuyerRegistrationDecision('approved'));

        return back()->with('success', "{$buyer->fullName()}'s registration was approved.");
    }

    public function reject(Buyer $buyer, Request $request)
    {
        $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        $buyer->update([
            'status' => 'rejected',
            'approved_by' => Auth::guard('admin')->id(),
            'approved_at' => now(),
            'rejection_reason' => $request->rejection_reason,
        ]);

        $buyer->notify(new BuyerRegistrationDecision('rejected', $request->rejection_reason));

        return back()->with('success', "{$buyer->fullName()}'s registration was rejected.");
    }
}