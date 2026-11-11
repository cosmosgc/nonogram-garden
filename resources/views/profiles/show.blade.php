<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $profile->name }} · Nonogram profile</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    @include('layouts.feature-navigation')
    <main class="mx-auto max-w-5xl p-6">
        <header class="border-b pb-6">
            <p class="text-sm text-slate-600">Creator profile</p>
            <h1 class="mt-2 text-3xl font-bold">{{ $profile->name }}</h1>
            <p class="mt-1 text-slate-600">{{ '@'.$profile->username }}</p>
            @if ($profile->bio)<p class="mt-4 max-w-2xl whitespace-pre-line">{{ $profile->bio }}</p>@endif
        </header>

        <section class="mt-8" aria-labelledby="creator-puzzles-heading">
            <h2 id="creator-puzzles-heading" class="text-xl font-semibold">Public puzzles</h2>
            @if ($puzzles->isEmpty())
                <p class="mt-4 rounded border border-dashed bg-white p-6 text-slate-600">This creator has no public puzzles yet.</p>
            @else
                <ul class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($puzzles as $puzzle)
                        <li class="rounded-lg border bg-white p-5 shadow-sm">
                            <a class="font-semibold underline" href="{{ route('puzzles.show', $puzzle) }}">{{ $puzzle->title }}</a>
                            @if ($puzzle->moderation_status === 'pending')<span class="ml-2 rounded bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900">Pending review</span>@endif
                            @if ($puzzle->description)<p class="mt-2 line-clamp-3 text-sm text-slate-600">{{ $puzzle->description }}</p>@endif
                            @if ($puzzle->tags)<p class="mt-3 flex flex-wrap gap-2">@foreach ($puzzle->tags as $tag)<span class="rounded bg-slate-100 px-2 py-1 text-xs">{{ $tag }}</span>@endforeach</p>@endif
                            <p class="mt-4 text-sm text-slate-600">{{ $puzzle->publishedRevision->width }}×{{ $puzzle->publishedRevision->height }}</p>
                        </li>
                    @endforeach
                </ul>
                <nav class="mt-8" aria-label="Creator puzzle pages">{{ $puzzles->links() }}</nav>
            @endif
        </section>

        <section class="mt-10" aria-labelledby="creator-collections-heading">
            <h2 id="creator-collections-heading" class="text-xl font-semibold">Public collections</h2>
            @if ($collections->isEmpty())
                <p class="mt-4 text-slate-600">No public collections yet.</p>
            @else
                <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($collections as $collection)
                        <li class="rounded-lg border bg-white p-5 shadow-sm">
                            <a class="font-semibold underline" href="{{ route('collections.show', $collection) }}">{{ $collection->title }}</a>
                            @if ($collection->description)<p class="mt-2 text-sm text-slate-600">{{ $collection->description }}</p>@endif
                        </li>
                    @endforeach
                </ul>
                <nav class="mt-8" aria-label="Creator collection pages">{{ $collections->links() }}</nav>
            @endif
        </section>
    </main>
</body>
</html>
