<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $puzzle->title }} · Nonogram</title>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    @include('layouts.feature-navigation')
    <main class="mx-auto max-w-5xl p-6">
        <header class="my-8">
            <h1 class="text-3xl font-bold">{{ $puzzle->title }}</h1>
            @if ($puzzle->moderation_status === 'pending')<p role="status" class="mt-3 rounded border border-amber-300 bg-amber-50 p-3 text-amber-950">This puzzle is pending moderator review but is currently visible under the site-wide preview setting.</p>@endif
            @if ($puzzle->description)<p class="mt-3">{{ $puzzle->description }}</p>@endif
            @if ($puzzle->tags)<p class="mt-3 flex flex-wrap gap-2">@foreach ($puzzle->tags as $tag)<a class="rounded bg-slate-100 px-2 py-1 text-xs underline" href="{{ route('puzzles.index', ['tag' => $tag]) }}">{{ $tag }}</a>@endforeach</p>@endif
            <div class="mt-3 flex items-center gap-2 text-sm text-slate-600">
                @if ($puzzle->owner->avatar_path)
                    <img src="{{ asset('storage/'.$puzzle->owner->avatar_path) }}" alt="{{ $puzzle->owner->name }}'s profile avatar" class="h-10 w-10 rounded-full object-cover">
                @endif
                <span>{{ $revision->width }}×{{ $revision->height }} · by {{ $puzzle->owner->name }}</span>
            </div>
            @auth
                @if (auth()->id() === $puzzle->user_id)
                    <form method="post" action="{{ route('puzzles.unpublish', $puzzle) }}" class="mt-4">
                        @csrf
                        <input type="hidden" name="confirm" value="1">
                        <button type="submit" class="rounded border px-3 py-2 text-sm">Unpublish and edit</button>
                        <p class="mt-1 text-xs text-slate-600">This hides the puzzle from play until you publish a new revision.</p>
                    </form>
                @endif
            @endauth
        </header>
        <div id="nonogram-player"
             data-width="{{ $revision->width }}"
             data-height="{{ $revision->height }}"
             data-puzzle-id="{{ $puzzle->id }}"
             data-revision-id="{{ $revision->id }}"
             data-mode="{{ $revision->mode }}"
             data-palette="{{ json_encode($revision->palette ?? []) }}"
             data-progress-url="{{ route('puzzles.progress.show', ['puzzle' => $puzzle->id, 'revision' => $revision->id]) }}"
             data-completion-url="{{ route('puzzles.progress.complete', ['puzzle' => $puzzle->id, 'revision' => $revision->id]) }}"
             data-game-session-url="{{ route('puzzles.game-session.show', ['puzzle' => $puzzle->id, 'revision' => $revision->id]) }}"
             data-game-mode-url="{{ route('puzzles.game-session.mode', ['puzzle' => $puzzle->id, 'revision' => $revision->id]) }}"
             data-life-check-url="{{ route('puzzles.game-session.life-check', ['puzzle' => $puzzle->id, 'revision' => $revision->id]) }}"
             data-game-restart-url="{{ route('puzzles.game-session.restart', ['puzzle' => $puzzle->id, 'revision' => $revision->id]) }}"
             @auth data-user-id="{{ auth()->id() }}" @endauth
             data-row-clues="{{ json_encode($revision->row_clues) }}"
             data-column-clues="{{ json_encode($revision->column_clues) }}"
             data-start-hint="{{ json_encode($startHint) }}"></div>
    </main>
</body>
</html>
