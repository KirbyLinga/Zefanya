<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Admin\OperationsAuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Read-only: a Logistics company sees its own operations history. */
class OperationsAuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $action = array_key_exists((string) $request->query('action'), OperationsAuditLog::ACTIONS) ? $request->query('action') : null;

        return view('Logistics.AuditLogs.index', [
            'logs' => OperationsAuditLog::query()
                ->where('logistics_provider_id', $request->user('logistics')->getKey())
                ->when($action, fn ($query) => $query->where('action', $action))
                ->latest('id')
                ->paginate(25)->withQueryString(),
            'action' => $action,
        ]);
    }
}
