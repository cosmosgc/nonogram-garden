<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $puzzle->title }} · Awaiting review</title>@vite('resources/css/app.css')</head>
<body class="min-h-screen text-slate-900">
    @include('layouts.feature-navigation')
    <main class="feature-page mx-auto max-w-3xl p-6">
        <section class="feature-panel rounded-2xl border bg-white p-6">
            <p class="text-sm font-semibold uppercase text-amber-800">{{ ucfirst($puzzle->moderation_status) }} moderation</p>
            <h1 class="mt-2 text-3xl font-bold">{{ $puzzle->title }}</h1>
            <p class="mt-4 text-slate-700">
                @if ($puzzle->moderation_status === 'pending')
                    This puzzle is published by you and is waiting for moderator approval. It is not visible in public browse, profiles, collections, or play until approved.
                @elseif ($puzzle->moderation_status === 'rejected')
                    This puzzle is not publicly visible. Review the moderator note, unpublish it, and edit before requesting another review.
                @else
                    This puzzle has been removed from public visibility. Contact a moderator if you believe this was a mistake.
                @endif
            </p>
            @if ($puzzle->moderation_note)<p class="mt-4 rounded bg-amber-50 p-3 whitespace-pre-line">{{ $puzzle->moderation_note }}</p>@endif
            @if ($puzzle->moderation_status !== 'removed')
                <form method="post" action="{{ route('puzzles.unpublish', $puzzle) }}" class="mt-6">
                    @csrf
                    <input type="hidden" name="confirm" value="1">
                    <button class="rounded border px-4 py-2">Unpublish and edit</button>
                </form>
            @endif
        </section>
    </main>
</body>
</html>
