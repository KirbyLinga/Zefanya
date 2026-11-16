@extends('Buyer.Layouts.app')
@section('title', 'Search by image')
@section('content')
<div class="bg-[var(--bg-page)] px-4 py-8 sm:px-6 sm:py-10">
    <div class="mx-auto max-w-6xl">
        <h1 class="mb-2 font-serif text-3xl text-[var(--text-ink)]">Search by image</h1>
        <p class="mb-6 text-sm text-[var(--text-muted)]">{{ app(\App\Services\Buyer\VisualSemanticRecognition::class)->enabled() ? 'Find similar photos and products of the same recognized type.' : 'Compare image structure, colors and layout with available catalog photos.' }}</p>
        @if ($errors->any())<div role="alert" class="mb-4 rounded-lg border border-[var(--pill-danger-border)] bg-[var(--pill-danger-bg)] p-3 text-sm text-[var(--status-danger)]">{{ $errors->first() }}</div>@endif
        @isset($visualSearchError)<p role="alert" class="mb-4 text-sm text-[var(--status-danger)]">{{ $visualSearchError }}</p>@endisset
        <x-buyer.visual-search-panel id="page-visual-search" />
        @isset($products)<div class="mt-6">@include('Buyer.VisualSearch._results')</div>@endisset
    </div>
</div>
@endsection
