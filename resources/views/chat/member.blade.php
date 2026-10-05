<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="flex min-h-dvh items-center justify-center bg-zinc-100 px-4 text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">
        <main class="w-full max-w-sm rounded-2xl border border-zinc-200 bg-white p-6 text-center shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <flux:avatar :name="$member->name" color="auto" size="lg" class="mx-auto" />
            <h1 class="mt-4 text-lg font-semibold">{{ $member->name }}</h1>
            @if ($member->title)
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $member->title }}</p>
            @endif
            <a href="{{ route('chat.index', ['chat' => $chat->id]) }}" class="mt-6 inline-block rounded-lg px-3 py-2 text-sm text-blue-600 hover:bg-zinc-100 dark:text-blue-400 dark:hover:bg-zinc-700">{{ __('Вернуться в чат') }}</a>
        </main>
        @fluxScripts
    </body>
</html>
