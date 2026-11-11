<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $collection->title }} · Nonogram collection</title>@vite('resources/css/app.css')</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    @include('layouts.feature-navigation')
    <main class="mx-auto max-w-5xl p-6">
        <header class="border-b pb-6">
            <p class="text-sm text-slate-600">Puzzle collection by {{ $collection->owner->name }}</p>
            <h1 class="mt-2 text-3xl font-bold">{{ $collection->title }}</h1>
            @if ($collection->description)<p class="mt-3 max-w-2xl whitespace-pre-line">{{ $collection->description }}</p>@endif
            @auth
                @if (auth()->id() === $collection->user_id)
                    <a class="mt-4 inline-block underline" href="{{ route('collections.edit', $collection) }}">Manage collection</a>
                @endif
            @endauth
        </header>
        @if ($collection->puzzles->isEmpty())
            <p class="mt-8 rounded border border-dashed bg-white p-6 text-slate-600">This collection has no currently public puzzles.</p>
        @else
            <ol class="mt-8 space-y-3">
                @foreach ($collection->puzzles as $puzzle)
                    <li class="flex flex-wrap items-center justify-between gap-4 rounded-lg border bg-white p-5">
                        <div>
                            <span class="mr-3 text-sm text-slate-500">{{ $loop->iteration }}.</span>
                            <a class="font-semibold underline" href="{{ route('puzzles.show', $puzzle) }}">{{ $puzzle->title }}</a>
                            @if ($puzzle->moderation_status === 'pending')<span class="ml-2 rounded bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900">Pending review</span>@endif
                            <p class="mt-1 text-sm text-slate-600">{{ $puzzle->publishedRevision->width }}×{{ $puzzle->publishedRevision->height }} · by {{ $puzzle->owner->name }}</p>
                        </div>
                        <a class="rounded border px-3 py-2 text-sm" href="{{ route('puzzles.show', $puzzle) }}">Play</a>
                    </li>
                @endforeach
            </ol>
        @endif
    </main>
</body>
</html>
