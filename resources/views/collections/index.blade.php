<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Collections · Nonogram</title>@vite('resources/css/app.css')</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    @include('layouts.feature-navigation')
    <main class="mx-auto max-w-5xl p-6">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div><h1 class="text-3xl font-bold">Puzzle collections</h1><p class="mt-2 text-slate-600">Curate ordered sets of public puzzles.</p></div>
            @auth<a href="{{ route('collections.create') }}" class="rounded bg-emerald-900 px-4 py-2 text-white">Create collection</a>@endauth
        </header>
        @if (session('status'))<p class="mt-4" role="status">{{ session('status') }}</p>@endif
        @if ($collections->isEmpty())
            <p class="mt-8 rounded border border-dashed bg-white p-6 text-slate-600">No visible collections yet.</p>
        @else
            <ul class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($collections as $collection)
                    <li class="rounded-lg border bg-white p-5 shadow-sm">
                        <a href="{{ route('collections.show', $collection) }}" class="font-semibold underline">{{ $collection->title }}</a>
                        <p class="mt-2 text-sm text-slate-600">By {{ $collection->owner->name }} · {{ ucfirst($collection->visibility) }}</p>
                        @if ($collection->description)<p class="mt-3 text-sm">{{ $collection->description }}</p>@endif
                        @auth @if ($collection->user_id === auth()->id())<a href="{{ route('collections.edit', $collection) }}" class="mt-3 inline-block text-sm underline">Manage</a>@endif @endauth
                    </li>
                @endforeach
            </ul>
            <nav class="mt-8" aria-label="Collection pages">{{ $collections->links() }}</nav>
        @endif
    </main>
</body>
</html>
