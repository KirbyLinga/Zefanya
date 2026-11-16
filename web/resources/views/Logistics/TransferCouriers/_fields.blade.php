<label class="{{ $label }}">
    Name
    <input type="text" name="name" value="{{ old('name', $courier?->name) }}" class="{{ $field }}" maxlength="100" required>
</label>

<label class="{{ $label }}">
    Contact number
    <input type="tel" name="contact_no" value="{{ old('contact_no', $courier?->contact_no) }}" class="{{ $field }}" maxlength="20" required>
</label>

<label class="{{ $label }}">
    Vehicle
    <select name="vehicle_type" class="{{ $field }}" required>
        <option value="">Select a vehicle</option>
        @foreach ($vehicles as $vehicle)
            <option value="{{ $vehicle }}" @selected(old('vehicle_type', $courier?->vehicle_type) === $vehicle)>{{ $vehicle }}</option>
        @endforeach
    </select>
</label>

<label class="{{ $label }}">
    Plate number (optional)
    <input type="text" name="plate_number" value="{{ old('plate_number', $courier?->plate_number) }}" class="{{ $field }}" maxlength="20">
</label>
