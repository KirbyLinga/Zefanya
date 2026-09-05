<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\Seller;
use App\Notifications\BuyerRegistrationDecision;
use App\Notifications\SellerRegistrationDecision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class RegistrationController extends Controller
{
    /**
     * Unified pending registration queue — buyers and sellers together,
     * sorted by submission time.
     */
    public function index(Request $request)
    {
        if (! Schema::hasTable('buyers') || ! Schema::hasTable('sellers')) {
            abort(404, 'Registration tables haven\'t been created yet — this feature isn\'t available.');
        }

        $buyers = Buyer::where('status', 'pending_approval')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($b) => (object) [
                'type' => 'buyer',
                'model' => $b,
                'id' => $b->id,
                'name' => $b->fullName(),
                'email' => $b->email,
                'created_at' => $b->created_at,
            ]);

        $sellers = Seller::where('status', 'pending_approval')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($s) => (object) [
                'type' => 'seller',
                'model' => $s,
                'id' => $s->id,
                'name' => $s->fullName(),
                'email' => $s->email,
                'created_at' => $s->created_at,
            ]);

        $pending = $buyers->merge($sellers)->sortBy('created_at');

        $selectedId = $request->query('view');
        $selectedType = $request->query('type');
        $selected = null;

        if ($selectedId && $selectedType) {
            $selected = $selectedType === 'seller'
                ? Seller::find($selectedId)
                : Buyer::find($selectedId);
        }

        return view('Admin.Registrations.index', [
            'pending' => $pending,
            'selected' => $selected,
            'selectedType' => $selectedType,
        ]);
    }

    public function approveBuyer(Buyer $buyer)
    {
        $buyer->update([
            'status' => 'approved',
            'approved_by' => Auth::guard('admin')->id(),
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        $buyer->notify(new BuyerRegistrationDecision('approved'));

        return back()->with('success', "{$buyer->fullName()}'s buyer registration was approved.");
    }

    public function rejectBuyer(Buyer $buyer, Request $request)
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

        return back()->with('success', "{$buyer->fullName()}'s buyer registration was rejected.");
    }

    public function approveSeller(Seller $seller)
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

    public function rejectSeller(Seller $seller, Request $request)
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
