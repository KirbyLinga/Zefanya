{{-- resources/views/Components/product-card.blade.php
     Usage: @include('Components.product-card', [
         'image' => 'product-image.png',   // file inside public/img/
         'title' => 'Product Name',        // may contain <br/> for line breaks
         'price' => '$24.50',
         'oldPrice' => '$32.00',           // optional, shows strikethrough
         'badge' => 'SALE',                // optional: SALE (pink) / NEW (green)
         'badgeStyle' => 'new',            // optional: 'new' switches badge color
     ])
     Tailwind-only (migrated from _products.scss). Landing page only. --}}
<div class="group flex h-full flex-col overflow-hidden rounded-[10px] border border-line bg-white transition-[box-shadow,transform] duration-[250ms] hover:-translate-y-1 hover:shadow-[0_16px_32px_rgba(41,38,38,0.12)]">
    <div class="relative block aspect-[0.82] overflow-hidden bg-blush">
        <div class="block aspect-[0.8] h-auto w-full bg-secondary-300 bg-cover bg-center bg-no-repeat" style="background-image: url('{{ asset('img/' . $image) }}')"></div>

        <div class="absolute bottom-0 left-0 right-0 translate-y-2 bg-[linear-gradient(180deg,rgba(30,27,27,0)_0%,rgba(30,27,27,0.55)_100%)] p-3.5 opacity-0 transition-[opacity,transform] duration-[250ms] group-hover:translate-y-0 group-hover:opacity-100">
            <button class="inline-flex h-[42px] w-full cursor-pointer items-center justify-center rounded border-0 bg-white text-xs font-semibold tracking-[1.4px] text-neutral-950 transition-colors duration-200 hover:bg-primary-800 hover:text-white"><div>QUICK VIEW</div></button>
        </div>

        @if(!empty($badge))
            <div class="absolute left-3.5 top-3.5 z-[1] rounded px-3 py-1.5 {{ ($badgeStyle ?? '') === 'new' ? 'bg-[#4f7a58]' : 'bg-[#d14a5f]' }}">
                <div class="font-sans text-[11px] font-semibold tracking-[1.4px] text-white">{{ $badge }}</div>
            </div>
        @endif
    </div>

    <div class="flex flex-1 flex-col gap-3.5 px-[18px] pb-5 pt-[18px] max-sm:gap-3 max-sm:px-3 max-sm:pb-4 max-sm:pt-3.5">
        <div>
            <div class="font-sans text-sm font-semibold leading-[1.5] text-neutral-950 max-sm:text-[13px]">{!! $title !!}</div>
        </div>

        @if(!empty($oldPrice))
            <div class="flex items-baseline gap-2.5">
                <div class="font-sans text-base font-bold text-primary-800 max-sm:text-sm">{{ $price }}</div>
                <div class="font-sans text-sm text-price-muted line-through">{{ $oldPrice }}</div>
            </div>
        @else
            <div class="flex">
                <div class="font-sans text-base font-bold text-neutral-950 max-sm:text-sm">{{ $price }}</div>
            </div>
        @endif

        <button class="mt-auto inline-flex h-[42px] cursor-pointer items-center justify-center rounded border border-primary-800 bg-transparent text-xs font-semibold tracking-[1.4px] text-primary-800 transition-colors duration-200 hover:bg-primary-800 hover:text-white">
            <div class="inline-flex items-center justify-center"><i data-lucide="plus" width="14" height="14"></i></div>
            <div>ADD</div>
        </button>
    </div>
</div>