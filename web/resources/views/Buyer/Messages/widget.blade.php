<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <base target="_top">
    <title>Messages — Zefanya</title>
    <x-fonts />
    @vite('resources/css/app.css')
</head>
<body class="m-0">
    @if(isset($widgetError))
        <div data-chat-widget-error class="p-6 text-center"><p>{{ $widgetError }}</p><a target="_self" href="{{ route('buyer.messages.index', ['widget' => 1]) }}" class="mt-4 inline-block underline">Open conversations</a></div>
    @else
        @include('Buyer.Messages._inbox', ['floating' => true])
    @endif
</body>
</html>
