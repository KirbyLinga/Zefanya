@extends('Buyer.Layouts.app')
@section('title', 'Following')
@section('content')
<div class="bg-cream">
    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-8">
        @include('Components.buyer.account-tabs', ['active' => 'following'])
        <h1 class="mb-6 font-serif text-[32px] font-semibold text-buyer-ink">Following</h1>
        @if (session('success'))
            <p role="status" class="mb-4 text-sm text-buyer-ink">{{ session('success') }}</p>
        @endif
        <ul class="space-y-3">
            @forelse ($follows as $follow)
                <li class="flex items-center justify-between gap-4 rounded-2xl border border-buyer-line bg-white p-5">
                    <a href="{{ route('buyer.stores.show', $follow->seller) }}" class="font-semibold text-buyer-ink hover:underline">{{ $follow->seller->business_name }}</a>
                    <form method="POST" action="{{ route('buyer.stores.follow', $follow->seller) }}">
                        @csrf
                        <button type="submit" class="rounded-full border border-buyer-line px-4 py-2 text-sm font-semibold text-buyer-ink">Unfollow</button>
                    </form>
                </li>
            @empty
                <li class="rounded-2xl border border-buyer-line bg-white p-8 text-center text-buyer-ink-soft">You are not following any stores yet.</li>
            @endforelse
        </ul>
        <div class="mt-6">{{ $follows->links() }}</div>
    </div>
</div>
@endsection
