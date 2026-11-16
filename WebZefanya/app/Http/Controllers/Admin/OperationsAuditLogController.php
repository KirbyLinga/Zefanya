<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\OperationsAuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Read-only: Admin sees the operations history of every Logistics company. */
class OperationsAuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $action = array_key_exists((string) $request->query('action'), OperationsAuditLog::ACTIONS) ? $request->query('action') : null;

        return view('Admin.AuditLogs.index', [
            'logs' => OperationsAuditLog::query()
                ->when($action, fn ($query) => $query->where('action', $action))
                ->latest('id')
                ->paginate(25)->withQueryString(),
            'action' => $action,
        ]);
    }
}
