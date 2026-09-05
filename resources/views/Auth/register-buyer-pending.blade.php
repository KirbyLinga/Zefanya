@extends('Layouts.footer')

@push('styles')
  @vite(['resources/css/auth.css'])
@endpush

@section('content')
<div class="html-body" style="max-width: 480px; margin: 6rem auto; text-align: center;">
    <h1>Registration submitted</h1>

    @if (session('success'))
        <p>{{ session('success') }}</p>
    @else
        <p>Please wait for the administrator's approval, which will be sent to your email.</p>
    @endif

    <a href="{{ route('home') }}">Back to home</a>
</div>
@endsection
