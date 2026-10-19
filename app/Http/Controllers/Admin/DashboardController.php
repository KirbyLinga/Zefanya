<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Support\RuntimeSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $admin = Auth::guard('admin')->user();

        return view('Admin.dashboard', [
            'admin' => $admin,
            'activeUsers' => $this->activeUserCount(),
            'pendingRegistrations' => $this->pendingRegistrationCount(),
        ]);
    }

    /**
     * Accounts an administrator has approved, across every role table that has
     * an approval lifecycle. Sellers and logistics providers are marketplace
     * users too, so they count alongside buyers.
     */
    private function activeUserCount(): int
    {
        return $this->countByStatus(Buyer::class, 'buyers', 'approved')
            + $this->countByStatus(Seller::class, 'sellers', 'approved')
            + $this->countByStatus(LogisticsProvider::class, 'logistics_providers', 'approved');
    }

    /**
     * Applicants awaiting an administrator's decision — mirrors the admin
     * registration queue (Admin\RegistrationController@index) exactly, so the
     * dashboard figure and the queue can never disagree.
     *
     * `unverified` (email not confirmed yet) is deliberately excluded:
     * those applicants are not yet awaiting an admin decision.
     */
    private function pendingRegistrationCount(): int
    {
        return $this->countByStatus(Buyer::class, 'buyers', 'pending')
            + $this->countByStatus(Seller::class, 'sellers', 'pending')
            + $this->countByStatus(LogisticsProvider::class, 'logistics_providers', 'pending');
    }

    /**
     * Count rows in $table holding the given status — 0 when that table has not
     * been migrated on this install yet (the live site is served from this tree).
     *
     * @param  class-string<Model>  $model
     */
    private function countByStatus(string $model, string $table, string $status): int
    {
        return RuntimeSchema::hasTable($table)
            ? $model::query()->where('status', $status)->count()
            : 0;
    }
}
