{{-- resources/views/Components/product-card.blade.php
     Usage: @include('Components.product-card', [
         'image' => 'product-image.png',   // file inside public/img/
         'title' => 'Product Name',        // may contain <br/> for line breaks
         'price' => '$24.50',
         'oldPrice' => '$32.00',           // optional, shows strikethrough
         'badge' => 'SALE',                // optional: SALE (pink) / NEW (green)
         'badgeStyle' => 'new',            // optional: 'new' switches badge color
     ]) --}}
<div class="product">
    <div class="background-border">
        <div class="product-image" style="background-image: url('{{ asset('img/' . $image) }}')"></div>

        <div class="button-wrapper">
            <button class="button-6"><div class="text-13">QUICK VIEW</div></button>
        </div>

        @if(!empty($badge))
            <div class="{{ ($badgeStyle ?? '') === 'new' ? 'background-4' : 'background-3' }}">
                <div class="{{ ($badgeStyle ?? '') === 'new' ? 'text-20' : 'text-14' }}">{{ $badge }}</div>
            </div>
        @endif
    </div>

    <div class="container-8">
        <div class="heading-4">
            <div class="text-15">{!! $title !!}</div>
        </div>

        @if(!empty($oldPrice))
            <div class="paragraph">
                <div class="text-16">{{ $price }}</div>
                <div class="text-17">{{ $oldPrice }}</div>
            </div>
        @else
            <div class="container-9">
                <div class="text-19">{{ $price }}</div>
            </div>
        @endif

        <button class="button-7">
            <div class="div-3"><i data-lucide="plus" width="14" height="14"></i></div>
            <div class="text-18">ADD</div>
        </button>
    </div>
</div>
