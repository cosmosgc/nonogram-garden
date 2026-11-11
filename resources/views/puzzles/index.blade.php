<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Browse puzzles · Nonogram</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    @include('layouts.feature-navigation')
    <main class="mx-auto max-w-5xl p-6">
        <header class="flex flex-wrap items-center justify-between gap-4 border-b pb-5">
            <div>
                <h1 class="mt-3 text-3xl font-bold">Browse puzzles</h1>
                <p class="mt-2 text-slate-600">Play public, uniquely solvable Classic puzzles.</p>
            </div>
            @auth
                <a class="rounded bg-emerald-900 px-4 py-2 text-white" href="{{ route('editor.new') }}">Create a puzzle</a>
            @else
                <a class="rounded bg-emerald-900 px-4 py-2 text-white" href="{{ route('login') }}">Sign in to create</a>
            @endauth
        </header>

        <form method="get" action="{{ route('puzzles.index') }}" class="mt-6 grid gap-3 rounded-lg border bg-white p-4 sm:grid-cols-2 lg:grid-cols-6">
            <label class="grid gap-1 text-sm">Width
                <input name="width" type="number" min="1" max="30" value="{{ $filters['width'] ?? '' }}" class="rounded border-slate-300">
            </label>
            <label class="grid gap-1 text-sm">Height
                <input name="height" type="number" min="1" max="30" value="{{ $filters['height'] ?? '' }}" class="rounded border-slate-300">
            </label>
            <label class="grid gap-1 text-sm">Creator username
                <input name="creator" type="text" maxlength="30" value="{{ $filters['creator'] ?? '' }}" class="rounded border-slate-300">
            </label>
            <label class="grid gap-1 text-sm">Tag
                <input name="tag" type="text" maxlength="24" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="{{ $filters['tag'] ?? '' }}" class="rounded border-slate-300">
            </label>
            <label class="grid gap-1 text-sm">Sort
                <select name="sort" class="rounded border-slate-300">
                    <option value="newest" @selected(($filters['sort'] ?? 'newest') === 'newest')>Newest</option>
                    <option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>Oldest</option>
                </select>
            </label>
            <div class="flex items-end gap-3">
                <button type="submit" class="rounded bg-emerald-900 px-4 py-2 text-white">Filter</button>
                <a href="{{ route('puzzles.index') }}" class="underline">Clear</a>
            </div>
        </form>

        @if ($puzzles->isEmpty())
            <section class="mt-8 rounded border bg-white p-6">
                <h2 class="text-lg font-semibold">No approved public puzzles match these filters</h2>
                <p class="mt-2 text-slate-600">New publications appear in browse only after moderator approval. Try changing the filters, or create and publish a Classic puzzle for review.</p>
            </section>
        @else
            <ul class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($puzzles as $puzzle)
                    <li class="overflow-hidden rounded-2xl border border-emerald-100 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg">
                        @if ($completedSolutions->has($puzzle->published_revision_id))
                            @php($solutionPreview = $completedSolutions->get($puzzle->published_revision_id))
                            <div class="grid aspect-[5/3] place-items-center bg-[#edf4ed] p-5" aria-label="Completed puzzle image">
                                <svg viewBox="0 0 {{ $solutionPreview->width }} {{ $solutionPreview->height }}" class="h-full max-h-44 w-full max-w-64" role="img" aria-label="Solution image for {{ $puzzle->title }}" shape-rendering="crispEdges">
                                    <rect width="{{ $solutionPreview->width }}" height="{{ $solutionPreview->height }}" fill="white" />
                                    @for ($cellIndex = 0; $cellIndex < strlen($solutionPreview->solution_data); $cellIndex++)
                                        @if ($solutionPreview->solution_data[$cellIndex] === '1')
                                            <rect x="{{ $cellIndex % $solutionPreview->width }}" y="{{ intdiv($cellIndex, $solutionPreview->width) }}" width="1" height="1" fill="#236b50" />
                                        @endif
                                    @endfor
                                </svg>
                                <span class="sr-only">You completed this puzzle</span>
                            </div>
                        @else
                            <div class="grid aspect-[5/3] place-items-center bg-gradient-to-br from-emerald-50 via-stone-50 to-amber-50" aria-hidden="true"><span class="text-5xl font-black tracking-widest text-emerald-900/20">▦</span></div>
                        @endif
                        <div class="p-5">
                        <a class="text-lg font-semibold underline" href="{{ route('puzzles.show', $puzzle) }}">{{ $puzzle->title }}</a>
                        @if ($puzzle->moderation_status === 'pending')<span class="ml-2 rounded bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900">Pending review</span>@endif
                        @if ($puzzle->description)<p class="mt-2 line-clamp-3 text-sm text-slate-600">{{ $puzzle->description }}</p>@endif
                        @if ($puzzle->tags)<p class="mt-3 flex flex-wrap gap-2">@foreach ($puzzle->tags as $tag)<a class="rounded bg-slate-100 px-2 py-1 text-xs underline" href="{{ route('puzzles.index', ['tag' => $tag]) }}">{{ $tag }}</a>@endforeach</p>@endif
                        <div class="mt-4 flex items-center gap-2 text-sm text-slate-600">
                            @if ($puzzle->owner->avatar_path)
                                <img src="{{ asset('storage/'.$puzzle->owner->avatar_path) }}" alt="{{ $puzzle->owner->name }}'s profile avatar" class="h-9 w-9 rounded-full object-cover">
                            @endif
                            <span>{{ $puzzle->publishedRevision->width }}×{{ $puzzle->publishedRevision->height }} · by {{ $puzzle->owner->name }}</span>
                        </div>
                        </div>
                    </li>
                @endforeach
            </ul>
            <nav class="mt-8" aria-label="Puzzle pages">{{ $puzzles->links() }}</nav>
        @endif
    </main>
</body>
</html>
