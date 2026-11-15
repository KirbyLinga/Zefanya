@extends(($internal ?? false) ? 'Admin.Layouts.app' : 'Buyer.Layouts.app')
@section('title', $document->title)
@section('content')
@php
    $link = 'underline underline-offset-2 hover:text-buyer-ink';
@endphp
<div class="{{ ($internal ?? false) ? '' : 'bg-cream' }}">
    <div class="{{ ($internal ?? false) ? '' : 'mx-auto max-w-[1440px] px-4 py-8 sm:px-8 lg:px-16' }}">
        <div class="max-w-[990px]">
            @if ($internal ?? false)
                <p class="mb-2 inline-block rounded-full bg-neutral-900 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-white">Internal — not for public pages</p>
            @endif
            <h1 class="font-serif text-[32px] sm:text-[40px] font-semibold text-buyer-ink mb-2">{{ $document->title }}</h1>
            <p class="mb-6 text-[13px] text-buyer-ink-soft" data-policy-meta>
                Version {{ $document->version }}
                @if ($document->effectiveDate)
                    · Effective {{ $document->effectiveDate }}
                @else
                    · Effective date to be announced
                @endif
            </p>

            @if ($document->hasUnconfirmedFields && ! ($internal ?? false))
                <p class="mb-6 rounded-2xl border border-buyer-line bg-white p-4 text-[13px] text-buyer-ink-soft" role="note">
                    Some details in this document, such as the operator's registered identity and contact channels, are still being confirmed and are marked <span class="policy-tbc">[to be confirmed before publication]</span>.
                </p>
            @endif

            @if (count($document->sections) > 2)
                <nav aria-label="On this page" class="mb-6 rounded-2xl border border-buyer-line bg-white p-5 text-[14px]">
                    <h2 class="mb-2 font-serif text-[18px] text-buyer-ink">On this page</h2>
                    <ul class="space-y-1 text-buyer-ink-soft">
                        @foreach ($document->sections as $section)
                            <li><a class="{{ $link }}" href="#{{ $section['id'] }}">{{ $section['label'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            <article class="policy-body rounded-2xl border border-buyer-line bg-white p-6 text-[14px] leading-relaxed text-buyer-ink-soft
                [&_h2]:mt-8 [&_h2]:mb-3 [&_h2]:scroll-mt-24 [&_h2]:font-serif [&_h2]:text-[22px] [&_h2]:text-buyer-ink
                [&_p]:my-3 [&_ul]:my-3 [&_ul]:list-disc [&_ul]:space-y-2 [&_ul]:pl-5
                [&_ol]:my-3 [&_ol]:list-decimal [&_ol]:space-y-2 [&_ol]:pl-5
                [&_strong]:text-buyer-ink [&_a]:underline [&_a]:underline-offset-2
                [&_table]:my-4 [&_table]:block [&_table]:w-full [&_table]:overflow-x-auto [&_table]:text-left
                [&_th]:border-b [&_th]:border-buyer-line [&_th]:px-3 [&_th]:py-2 [&_th]:text-buyer-ink
                [&_td]:border-b [&_td]:border-buyer-line [&_td]:px-3 [&_td]:py-2 [&_td]:align-top
                [&_.policy-tbc]:rounded [&_.policy-tbc]:bg-amber-100 [&_.policy-tbc]:px-1 [&_.policy-tbc]:text-amber-900">
                {!! $document->html !!}
            </article>

            @unless ($internal ?? false)
                <nav aria-label="Other policies" class="mt-6 rounded-2xl border border-buyer-line bg-white p-5 text-[14px]">
                    <h2 class="mb-2 font-serif text-[18px] text-buyer-ink">Other Zefanya policies</h2>
                    <ul class="space-y-1 text-buyer-ink-soft">
                        @foreach ($related as $other)
                            @continue($other->slug === $document->slug)
                            <li><a class="{{ $link }}" href="{{ route('policies.show', $other->slug) }}">{{ $other->title }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endunless
        </div>
    </div>
</div>
@endsection
