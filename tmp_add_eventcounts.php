<?php

$path = 'resources/views/admin/Incidents/detail.blade.php';
$content = file_get_contents($path);

$search = '                                @if ($event->detail)
                                    <span class="block text-xs text-neutral-400">{{ $event->detail }}</span>
                                @endif';
$replace = '                                @if ($event->detail)
                                    <span class="block text-xs text-neutral-400">{{ $event->detail }}</span>
                                @endif
                                @if (isset($eventCounts[$event->event]))
                                    <p class="text-xs text-neutral-400">Total {{ $event->event }} events: {{ $eventCounts[$event->event] }}</p>
                                @endif';

if (strpos($content, $search) === false) {
    echo "SEARCH_NOT_FOUND\n";
    exit(1);
}

file_put_contents($path, str_replace($search, $replace, $content));
echo "OK\n";
