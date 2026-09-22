<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    /**
     * Sorting Center landing page.
     *
     * Returns static sample data only — renders without any database table
     * so the UI can be verified before the logistics CRUD surfaces are built.
     */
    public function index(): View
    {
        $statusPills = [
            'delivered' => ['label' => 'Delivered',        'class' => 'lg-pill--success'],
            'in_transit' => ['label' => 'In Transit',       'class' => 'lg-pill--transit'],
            'sorting' => ['label' => 'Sorting',          'class' => 'lg-pill--danger'],
            'pending_approval' => ['label' => 'Pending Approval', 'class' => 'lg-pill--primary'],
            'pending' => ['label' => 'Pending',          'class' => 'lg-pill--warning'],
        ];

        return view('Logistics.Dashboard.index', [
            'todayLabel' => now()->format('D, M j'),
            'notifications' => 3,
            'incoming' => [
                'value' => '2,845',
                'delta' => '+12%',
                'caption' => 'Peak morning batch cleared · Next manifest in 45m',
            ],
            'liveHub' => [
                ['label' => 'In-hub volume',   'value' => '1,980', 'chip' => '70% flow', 'progress' => 70, 'muted' => null,   'caption' => '865 units queueing for bay'],
                ['label' => 'Riders on-route', 'value' => '142',  'chip' => null,     'progress' => 82, 'muted' => 12,     'caption' => '18 resting / standby'],
                ['label' => 'On-time rate',    'value' => '98.4%', 'chip' => 'On target', 'progress' => 98, 'muted' => null,  'caption' => '+0.6% vs yesterday'],
            ],
            'statusOverview' => [
                ['label' => 'Pending intake', 'value' => '48',   'tone' => 'warning', 'icon' => 'inbox'],
                ['label' => 'In sorting',     'value' => '315',  'tone' => 'danger',  'icon' => 'boxes'],
                ['label' => 'Dispatched',     'value' => '420',  'tone' => 'chip',    'icon' => 'truck'],
                ['label' => 'Delivered',      'value' => '670',  'tone' => 'success', 'icon' => 'check'],
            ],
            'recentParcels' => [
                ['order_id' => 'ZEF-982410', 'customer' => 'Liana Cruz',    'product' => 'Ceramic Pour-over Set',   'destination' => 'Makati · BGC',       'zone' => 'standard',  'status' => 'delivered',        'courier' => ['type' => 'assigned',  'initials' => 'JD', 'name' => 'Jun Diaz']],
                ['order_id' => 'ZEF-982411', 'customer' => 'Marco Villena', 'product' => 'Air-dry Clay 5kg',        'destination' => 'QC · Katipunan',     'zone' => 'priority',  'status' => 'sorting',          'courier' => ['type' => 'assigned', 'initials' => 'AR', 'name' => 'Ana Reyes']],
                ['order_id' => 'ZEF-982412', 'customer' => 'Isabel Reyes',  'product' => 'Linen Table Runner',      'destination' => 'Makati · Poblacion', 'zone' => 'priority',  'status' => 'pending_approval', 'courier' => ['type' => 'approval']],
                ['order_id' => 'ZEF-982413', 'customer' => 'Paolo Mendoza', 'product' => 'Espresso Beans 1kg',      'destination' => 'Taguig · MWCD',      'zone' => 'standard',  'status' => 'pending',          'courier' => ['type' => 'assigning']],
                ['order_id' => 'ZEF-982414', 'customer' => 'Grace Lim',     'product' => 'Monstera Deliciosa',     'destination' => 'Pasig · Oranbo',     'zone' => 'standard',  'status' => 'in_transit',       'courier' => ['type' => 'assigned', 'initials' => 'RT', 'name' => 'Rico Tan']],
                ['order_id' => 'ZEF-982415', 'customer' => 'Bea Santos',    'product' => 'Soy Candle — Sampaguita',  'destination' => 'Makati · San Antonio', 'zone' => 'priority', 'status' => 'delivered',        'courier' => ['type' => 'approved']],
            ],
            'showing' => ['from' => 1, 'to' => 6, 'total' => 842],
            'statusPills' => $statusPills,
        ]);
    }
}
