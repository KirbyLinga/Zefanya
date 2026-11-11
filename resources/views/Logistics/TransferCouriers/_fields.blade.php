@php($prefix = $courier ? 'courier-'.$courier->id.'-' : 'new-courier-')
@php($useOld = $courier === null || (int) old('_courier', 0) === (int) $courier->id)
<input type="hidden" name="_courier" value="{{ $courier?->id ?? 0 }}">
<label for="{{ $prefix }}name" class="{{ $label }}">Full name
    <input id="{{ $prefix }}name" name="name" required maxlength="100" value="{{ $useOld ? old('name', $courier?->name) : $courier?->name }}" class="{{ $field }}">
</label>
<label for="{{ $prefix }}contact" class="{{ $label }}">Mobile number
    <input id="{{ $prefix }}contact" name="contact_no" required maxlength="20" inputmode="tel" placeholder="09171234567" value="{{ $useOld ? old('contact_no', $courier?->contact_no) : $courier?->contact_no }}" class="{{ $field }} font-mono">
</label>
<label for="{{ $prefix }}vehicle" class="{{ $label }}">Vehicle
    <select id="{{ $prefix }}vehicle" name="vehicle_type" required class="{{ $field }}">
        @foreach ($vehicles as $vehicle)
            <option value="{{ $vehicle }}" @selected(($useOld ? old('vehicle_type', $courier?->vehicle_type) : $courier?->vehicle_type) === $vehicle)>{{ $vehicle }}</option>
        @endforeach
    </select>
</label>
<label for="{{ $prefix }}plate" class="{{ $label }}">Plate (optional)
    <input id="{{ $prefix }}plate" name="plate_number" maxlength="20" placeholder="ABC 1234" value="{{ $useOld ? old('plate_number', $courier?->plate_number) : $courier?->plate_number }}" class="{{ $field }} font-mono uppercase">
</label>
