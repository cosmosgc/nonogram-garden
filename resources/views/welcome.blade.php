<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Nonogram') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="min-h-screen text-slate-900">
    @include('layouts.feature-navigation')

    <main class="mx-auto max-w-6xl px-6 py-10 sm:py-16">
        <section class="home-hero">
            <div class="max-w-3xl">
            <p class="font-semibold uppercase tracking-wide text-emerald-800">A little space to puzzle</p>
            <h1 class="mt-3 text-4xl font-bold tracking-tight sm:text-5xl">Make a picture, one square at a time.</h1>
            <p class="mt-5 text-lg text-slate-600">Settle in with a nonogram, or create a puzzle of your own to share.</p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ route('puzzles.index') }}" class="rounded-xl bg-emerald-900 px-5 py-3 font-semibold text-white transition hover:bg-emerald-800">Browse puzzles</a>
                @auth
                    <a href="{{ route('editor.new') }}" class="rounded-xl border border-emerald-800 bg-white px-5 py-3 font-semibold transition hover:bg-emerald-50">Open the editor</a>
                @else
                    <a href="{{ route('register') }}" class="rounded-xl border border-emerald-800 bg-white px-5 py-3 font-semibold transition hover:bg-emerald-50">Create an account</a>
                @endauth
            </div>
            </div>
            <div class="home-hero-grid" aria-hidden="true">▦</div>
        </section>

        <section class="mt-14 grid gap-5 md:grid-cols-3" aria-label="Available features">
            <article class="rounded-2xl border border-amber-100 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                <h2 class="text-lg font-semibold">Puzzle editor</h2>
                <p class="mt-2 text-slate-600">Resize grids, paint cells, view clues, undo edits, and recover drafts.</p>
                @auth<a class="mt-4 inline-block font-semibold underline" href="{{ route('editor.new') }}">Create a draft →</a>@else<a class="mt-4 inline-block font-semibold underline" href="{{ route('login') }}">Sign in to create →</a>@endauth
            </article>
            <article class="rounded-2xl border border-amber-100 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                <h2 class="text-lg font-semibold">Test and publish</h2>
                <p class="mt-2 text-slate-600">Try your puzzle with the answer hidden. Publishing requires a unique solution.</p>
            </article>
            <article class="rounded-2xl border border-amber-100 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                <h2 class="text-lg font-semibold">Play and resume</h2>
                <p class="mt-2 text-slate-600">Solve with keyboard or pointer, save progress, and request server-verified completion.</p>
                <a class="mt-4 inline-block font-semibold underline" href="{{ route('puzzles.index') }}">Find a puzzle →</a>
            </article>
        </section>
    </main>
</body>
</html>
