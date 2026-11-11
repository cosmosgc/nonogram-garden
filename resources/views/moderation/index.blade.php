<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Puzzle moderation · Nonogram</title>@vite('resources/css/app.css')</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    @include('layouts.feature-navigation')
    <main class="mx-auto max-w-5xl space-y-6 p-6">
        <header>
            <h1 class="text-3xl font-bold">Puzzle moderation</h1>
            <p class="mt-2 text-slate-600">Review uniquely solved published puzzles before public discovery.</p>
        </header>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if ($isAdmin)
            <section class="rounded-lg border bg-white p-5">
                <h2 class="text-lg font-semibold">Site-wide discovery</h2>
                <p class="mt-2 text-sm text-slate-600">When enabled, pending public puzzles appear for everyone in browse, profiles, public collections, and play before moderator approval. Private, unlisted, draft, rejected, and removed puzzles remain hidden.</p>
                <form method="post" action="{{ route('moderation.settings.update') }}" class="mt-4 flex flex-wrap items-center gap-4">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="show_pending_public_puzzles" value="0">
                    <label class="flex items-center gap-2"><input type="checkbox" name="show_pending_public_puzzles" value="1" @checked($showPendingPublicPuzzles)> Show pending public puzzles site-wide</label>
                    <button type="submit" class="rounded bg-emerald-900 px-4 py-2 text-white">Save setting</button>
                </form>
            </section>
        @endif
        @if ($puzzles->isEmpty())
            <p class="rounded border border-dashed bg-white p-6 text-slate-600">No puzzles are awaiting review.</p>
        @else
            <ul class="space-y-5">
                @foreach ($puzzles as $puzzle)
                    <li class="rounded-lg border bg-white p-6 shadow-sm">
                        <header class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-xl font-semibold">{{ $puzzle->title }}</h2>
                                <p class="mt-1 text-sm text-slate-600">By {{ $puzzle->owner->name }} · {{ $puzzle->publishedRevision->width }}×{{ $puzzle->publishedRevision->height }} · {{ ucfirst($puzzle->moderation_status) }} · {{ $puzzle->publishedRevision->mode === 'color' ? 'Color Picross' : 'Classic' }}</p>
                            </div>
                        </header>
                        @if ($puzzle->description)<p class="mt-3 whitespace-pre-line">{{ $puzzle->description }}</p>@endif
                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            <div><h3 class="font-medium">Rows</h3><ol class="mt-1 list-inside list-decimal text-sm">@foreach ($puzzle->publishedRevision->row_clues as $line)<li>{{ $formatClue($puzzle->publishedRevision, $line) }}</li>@endforeach</ol></div>
                            <div><h3 class="font-medium">Columns</h3><ol class="mt-1 list-inside list-decimal text-sm">@foreach ($puzzle->publishedRevision->column_clues as $line)<li>{{ $formatClue($puzzle->publishedRevision, $line) }}</li>@endforeach</ol></div>
                        </div>
                        @if ($puzzle->moderation_note)<p class="mt-3 text-sm text-amber-800">Previous note: {{ $puzzle->moderation_note }}</p>@endif
                        <form method="post" action="{{ route('moderation.update', $puzzle) }}" class="mt-5 grid gap-3 border-t pt-4 sm:grid-cols-[1fr_auto]">
                            @csrf
                            <input name="note" maxlength="500" placeholder="Optional note for the creator" class="rounded border-slate-300">
                            <div class="flex flex-wrap gap-2">
                                <button name="action" value="approve" class="rounded bg-emerald-900 px-3 py-2 text-white">Approve</button>
                                <button name="action" value="reject" class="rounded border border-amber-700 px-3 py-2 text-amber-900">Reject</button>
                                <button name="action" value="remove" class="rounded border border-red-700 px-3 py-2 text-red-900">Remove</button>
                            </div>
                        </form>
                        @error('action')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
                        @error('note')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
                    </li>
                @endforeach
            </ul>
            <nav aria-label="Moderation queue pages">{{ $puzzles->links() }}</nav>
        @endif
    </main>
</body>
</html>
