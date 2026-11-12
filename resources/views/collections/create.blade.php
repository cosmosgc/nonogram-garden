<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Create collection · Nonogram</title>@vite('resources/css/app.css')</head>
<body class="min-h-screen text-slate-900">
    @include('layouts.feature-navigation')
    <main class="feature-page mx-auto max-w-2xl p-6">
        <a href="{{ route('collections.index') }}" class="underline">← Collections</a>
        <h1 class="mt-5 text-3xl font-bold">Create collection</h1>
        <form method="post" action="{{ route('collections.store') }}" class="feature-panel mt-6 grid gap-5 rounded-2xl border bg-white p-6">
            @csrf
            <label class="grid gap-1">Title<input name="title" maxlength="120" required value="{{ old('title') }}" class="rounded border-slate-300">@error('title')<span class="text-sm text-red-700">{{ $message }}</span>@enderror</label>
            <label class="grid gap-1">Description<textarea name="description" maxlength="1000" rows="4" class="rounded border-slate-300">{{ old('description') }}</textarea>@error('description')<span class="text-sm text-red-700">{{ $message }}</span>@enderror</label>
            <label class="grid gap-1">Visibility<select name="visibility" class="rounded border-slate-300"><option value="private">Private</option><option value="unlisted">Unlisted</option><option value="public">Public</option></select></label>
            @error('visibility')<span class="text-sm text-red-700">{{ $message }}</span>@enderror
            <button class="w-fit rounded bg-emerald-900 px-4 py-2 text-white">Create collection</button>
        </form>
    </main>
</body>
</html>
