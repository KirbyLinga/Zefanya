<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\Admin\Admin;
use App\Models\Incidents;

class IncidentsPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return in_array($admin->role, [
            AdminRole::SuperAdmin->value,
            AdminRole::Admin->value,
            AdminRole::Moderator->value,
        ], true);
    }

    public function view(Admin $admin, Incidents $incident): bool
    {
        return $this->viewAny($admin)
            && in_array(strtolower($incident->type), ['lost', 'damaged'], true);
    }

    public function resolve(Admin $admin, Incidents $incident): bool
    {
        return $this->view($admin, $incident) && $incident->isOpen();
    }

    public function reject(Admin $admin, Incidents $incident): bool
    {
        return $this->resolve($admin, $incident);
    }

    public function delete(Admin $admin, Incidents $incident): bool
    {
        return false;
    }
}
