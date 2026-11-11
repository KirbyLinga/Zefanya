<li class="flex min-w-0 items-center gap-2 py-3 sm:gap-3 {{ ($wide ?? false) ? 'xl:col-span-2' : '' }}">
    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ $dim ? 'bg-neutral-100 text-neutral-500' : 'bg-primary-100 text-primary-800' }}">
        <i data-lucide="{{ $item['icon'] }}" class="size-[18px]" aria-hidden="true"></i>
    </span>
    <span class="min-w-0 flex-1 [overflow-wrap:anywhere]">
        <span class="block font-sans text-[13px] font-semibold text-neutral-950">{{ $item['label'] }}</span>
        <span class="block font-sans text-xs text-neutral-600">{{ $item['detail'] }}</span>
    </span>
    <span class="shrink-0 font-serif text-lg font-bold {{ $item['count'] > 0 ? 'text-neutral-950' : 'text-neutral-400' }}">{{ number_format($item['count']) }}</span>
    @if ($item['url'])
        <a href="{{ $item['url'] }}" class="inline-flex min-h-9 w-[76px] shrink-0 items-center justify-center rounded-lg border border-neutral-200 font-sans text-xs font-semibold text-primary-800 no-underline transition-colors hover:border-primary-800">{{ $item['action'] }}</a>
    @endif
</li>
