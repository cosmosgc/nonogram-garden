<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Nonogram') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    @include('layouts.feature-navigation')

    <main class="mx-auto max-w-6xl px-6 py-14">
        <section class="max-w-3xl">
            <p class="font-semibold uppercase tracking-wide text-emerald-800">Classic Picross</p>
            <h1 class="mt-3 text-4xl font-bold tracking-tight sm:text-5xl">Create and solve nonograms.</h1>
            <p class="mt-5 text-lg text-slate-600">Build a monochrome puzzle, test it without exposing the answer, and publish only when its solution is verified as unique.</p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ route('puzzles.index') }}" class="rounded bg-emerald-900 px-5 py-3 font-semibold text-white">Browse puzzles</a>
                @auth
                    <a href="{{ route('editor.new') }}" class="rounded border border-slate-400 bg-white px-5 py-3 font-semibold">Open the editor</a>
                @else
                    <a href="{{ route('register') }}" class="rounded border border-slate-400 bg-white px-5 py-3 font-semibold">Create an account</a>
                @endauth
            </div>
        </section>

        <section class="mt-14 grid gap-5 md:grid-cols-3" aria-label="Available features">
            <article class="rounded-lg border bg-white p-6">
                <h2 class="text-lg font-semibold">Puzzle editor</h2>
                <p class="mt-2 text-slate-600">Resize grids, paint cells, view clues, undo edits, and recover drafts.</p>
                @auth<a class="mt-4 inline-block font-semibold underline" href="{{ route('editor.new') }}">Create a draft →</a>@else<a class="mt-4 inline-block font-semibold underline" href="{{ route('login') }}">Sign in to create →</a>@endauth
            </article>
            <article class="rounded-lg border bg-white p-6">
                <h2 class="text-lg font-semibold">Test and publish</h2>
                <p class="mt-2 text-slate-600">Try your puzzle with the answer hidden. Publishing requires a unique solution.</p>
            </article>
            <article class="rounded-lg border bg-white p-6">
                <h2 class="text-lg font-semibold">Play and resume</h2>
                <p class="mt-2 text-slate-600">Solve with keyboard or pointer, save progress, and request server-verified completion.</p>
                <a class="mt-4 inline-block font-semibold underline" href="{{ route('puzzles.index') }}">Find a puzzle →</a>
            </article>
        </section>
    </main>
</body>
</html>
