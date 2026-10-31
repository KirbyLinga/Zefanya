@foreach (['order_item_id', 'review', 'rating', 'body', 'photos', 'remove_photos'] as $field)
    @error($field)<p role="alert" class="text-sm text-red-600">{{ $message }}</p>@enderror
@endforeach
@foreach ($errors->get('photos.*') as $messages)
    @foreach ($messages as $message)
        <p role="alert" class="text-sm text-red-600">{{ $message }}</p>
    @endforeach
@endforeach
