<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Your Nonogram dashboard') }}</h2>
    </x-slot>

    <div class="py-10">
        <div class="feature-page mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">
            <section class="feature-panel flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-white p-6 shadow-sm">
                <div>
                    <h3 class="text-lg font-semibold">Ready to make a puzzle?</h3>
                    <p class="mt-1 text-sm text-gray-600">Create a draft, test it, and publish once its solution is verified.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('editor.new') }}" class="rounded bg-emerald-900 px-4 py-2 font-semibold text-white">Create a puzzle</a>
                    <a href="{{ route('editor.import') }}" class="rounded border px-4 py-2 font-semibold">Import from image</a>
                    <a href="{{ route('collections.create') }}" class="rounded border px-4 py-2 font-semibold">Create collection</a>
                </div>
            </section>

            <section class="feature-panel rounded-2xl bg-white p-6 shadow-sm">
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-semibold">Your recent puzzles</h3>
                        <p class="text-sm text-gray-600">Drafts stay private; published puzzles can be played publicly.</p>
                    </div>
                    <a href="{{ route('puzzles.index') }}" class="font-medium underline">Browse public puzzles</a>
                </div>
                @if ($puzzles->isEmpty())
                    <p class="rounded border border-dashed p-6 text-gray-600">No puzzles yet. Create your first draft to get started.</p>
                @else
                    <ul class="divide-y">
                        @foreach ($puzzles as $puzzle)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-4">
                                <div>
                                    <h4 class="font-medium">{{ $puzzle->title }}</h4>
                                    <p class="text-sm text-gray-600">{{ ucfirst($puzzle->status) }} · updated {{ $puzzle->updated_at->diffForHumans() }}</p>
                                    @if ($puzzle->status === 'published' && $puzzle->moderation_status === 'approved' && $puzzle->publishedRevision)
                                        <p class="mt-1 text-sm text-gray-600">Registered starts: {{ $puzzle->publishedRevision->progress_records_count }} · verified completions: {{ $puzzle->publishedRevision->verified_completions_count }}</p>
                                    @endif
                                </div>
                                @if ($puzzle->status === 'draft')
                                    <a href="{{ route('editor.edit', $puzzle) }}" class="rounded border px-3 py-2 text-sm">Continue editing</a>
                                @elseif ($puzzle->status === 'published')
                                    <a href="{{ route('puzzles.show', $puzzle) }}" class="rounded border px-3 py-2 text-sm">Play / manage</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="feature-panel rounded-2xl bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold">Your recent games</h3>
                @if ($recentProgress->isEmpty())
                    <p class="mt-4 rounded border border-dashed p-6 text-gray-600">No saved games yet. Play any public puzzle to keep progress here.</p>
                @else
                    <ul class="mt-3 divide-y">
                        @foreach ($recentProgress as $progress)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-4">
                                <div>
                                    <h4 class="font-medium">{{ $progress->revision->puzzle->title }}</h4>
                                    <p class="text-sm text-gray-600">
                                        Revision {{ $progress->revision->revision_number }} · {{ gmdate('i:s', $progress->elapsed_seconds) }}
                                        @if ($progress->completed_at) · Completed @endif
                                    </p>
                                </div>
                                <a class="rounded border px-3 py-2 text-sm" href="{{ route('puzzles.revisions.show', ['puzzle' => $progress->revision->puzzle_id, 'revision' => $progress->puzzle_revision_id]) }}">
                                    {{ $progress->completed_at ? 'View revision' : 'Resume game' }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
