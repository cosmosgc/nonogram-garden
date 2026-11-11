<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Manage {{ $collection->title }} · Nonogram</title>@vite('resources/css/app.css')</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    @include('layouts.feature-navigation')
    <main class="mx-auto max-w-4xl space-y-8 p-6">
        <header>
            <a href="{{ route('collections.index') }}" class="underline">← Collections</a>
            <h1 class="mt-4 text-3xl font-bold">Manage {{ $collection->title }}</h1>
            <p class="mt-2 text-slate-600">Collection slugs are global and remain stable if you rename the collection.</p>
        </header>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <form method="post" action="{{ route('collections.update', $collection) }}" class="grid gap-5 rounded-lg border bg-white p-6">
            @csrf @method('put')
            <label class="grid gap-1">Title<input name="title" maxlength="120" required value="{{ old('title', $collection->title) }}" class="rounded border-slate-300">@error('title')<span class="text-sm text-red-700">{{ $message }}</span>@enderror</label>
            <label class="grid gap-1">Description<textarea name="description" maxlength="1000" rows="4" class="rounded border-slate-300">{{ old('description', $collection->description) }}</textarea>@error('description')<span class="text-sm text-red-700">{{ $message }}</span>@enderror</label>
            <label class="grid gap-1">Visibility<select name="visibility" class="rounded border-slate-300">
                @foreach (['private' => 'Private', 'unlisted' => 'Unlisted', 'public' => 'Public'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('visibility', $collection->visibility) === $value)>{{ $label }}</option>
                @endforeach
            </select></label>
            @error('visibility')<span class="text-sm text-red-700">{{ $message }}</span>@enderror
            <button class="w-fit rounded bg-emerald-900 px-4 py-2 text-white">Save details</button>
        </form>

        <form method="post" action="{{ route('collections.puzzles.sync', $collection) }}" class="rounded-lg border bg-white p-6">
            @csrf @method('put')
            <h2 class="text-xl font-semibold">Order public puzzles</h2>
            <p class="mt-1 text-sm text-slate-600">Set a unique position from 1–100. Use 0 to remove a puzzle. Items are renumbered without gaps.</p>
            @error('positions')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
            @if ($puzzles->isEmpty())
                <p class="mt-4 text-slate-600">There are no public published puzzles available yet.</p>
            @else
                <ol class="mt-4 divide-y">
                    @foreach ($puzzles as $puzzle)
                        <li class="flex flex-wrap items-center justify-between gap-4 py-3">
                            <label for="position-{{ $puzzle->id }}" class="min-w-0 flex-1">
                                <span class="font-medium">{{ $puzzle->title }}</span>
                                <span class="block text-sm text-slate-600">By {{ $puzzle->owner->name }} · {{ $puzzle->width }}×{{ $puzzle->height }}</span>
                            </label>
                            <input id="position-{{ $puzzle->id }}" type="number" name="positions[{{ $puzzle->id }}]" min="0" max="100" value="{{ old('positions.'.$puzzle->id, $positions[$puzzle->id] ?? 0) }}" aria-label="Position for {{ $puzzle->title }}" class="w-24 rounded border-slate-300">
                        </li>
                    @endforeach
                </ol>
            @endif
            <button class="mt-5 rounded bg-emerald-900 px-4 py-2 text-white">Save puzzle order</button>
        </form>

        <section class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-red-200 bg-white p-6">
            <div><h2 class="font-semibold">Delete collection</h2><p class="text-sm text-slate-600">This removes the collection and its ordered memberships, not the puzzles.</p></div>
            <form method="post" action="{{ route('collections.destroy', $collection) }}">
                @csrf @method('delete')
                <button class="rounded border border-red-700 px-4 py-2 text-red-800">Delete collection</button>
            </form>
        </section>
    </main>
</body>
</html>
