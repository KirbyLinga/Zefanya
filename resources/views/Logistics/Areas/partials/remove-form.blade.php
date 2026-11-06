{{-- Remove-coverage form shared by the row menu and the needs-attention list. --}}
<form method="POST" action="{{ route('logistics.areas.destroy', $area) }}"
      data-confirm="Remove {{ $area->barangay_name ?? $area->name }}? This affects {{ $area->couriers_count }} {{ (int) $area->couriers_count === 1 ? 'rider' : 'riders' }} and {{ $pendingPickups[$area->id] ?? 0 }} pending {{ ($pendingPickups[$area->id] ?? 0) === 1 ? 'pickup' : 'pickups' }}. Linked riders, parcel history, and active work can block removal. Unlink riders and finish pending work first.">
    @csrf
    @method('DELETE')
    <button type="submit" class="{{ $buttonClass }}">Remove</button>
</form>
