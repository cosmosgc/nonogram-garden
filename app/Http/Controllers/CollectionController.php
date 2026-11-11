<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCollectionRequest;
use App\Http\Requests\SyncCollectionPuzzlesRequest;
use App\Http\Requests\UpdateCollectionRequest;
use App\Models\Collection;
use App\Models\Puzzle;
use App\Services\Nonogram\DiscoverySettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CollectionController extends Controller
{
    public function index(Request $request): View
    {
        $query = Collection::query()->where('visibility', 'public');
        if ($request->user()) {
            $query->orWhere('user_id', $request->user()->id);
        }
        $collections = $query->with('owner:id,name,username')->orderByDesc('updated_at')->paginate(12);

        return view('collections.index', ['collections' => $collections]);
    }

    public function show(Request $request, Collection $collection, DiscoverySettings $settings): View
    {
        if ($collection->visibility === 'private' && $request->user()?->id !== $collection->user_id) {
            abort(404);
        }
        $collection->load([
            'owner:id,name,username',
            'puzzles' => fn ($query) => $query->where('status', 'published')->where('visibility', 'public')->whereIn('moderation_status', $settings->visibleModerationStatuses())
                ->with(['owner:id,name,username', 'publishedRevision:id,puzzle_id,width,height']),
        ]);

        return view('collections.show', ['collection' => $collection]);
    }

    public function create(): View
    {
        return view('collections.create');
    }

    public function store(StoreCollectionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $base = Str::slug($data['title']) ?: 'collection';
        if ($base === 'create') {
            $base = 'create-collection';
        }
        $slug = $base;
        $suffix = 2;
        while (Collection::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        $collection = $request->user()->collections()->create([
            ...$data,
            'slug' => $slug,
        ]);

        return redirect()->route('collections.edit', $collection)->with('status', 'Collection created. Add public puzzles below.');
    }

    public function edit(Collection $collection, DiscoverySettings $settings): View
    {
        Gate::authorize('update', $collection);
        $currentPuzzles = $collection->puzzles()->with('owner:id,name,username')->get();
        $availablePuzzles = Puzzle::query()
            ->where('status', 'published')->where('visibility', 'public')->whereIn('moderation_status', $settings->visibleModerationStatuses())
            ->with('owner:id,name,username')->orderBy('title')->limit(max(0, 100 - $currentPuzzles->count()))->get();
        $puzzles = $currentPuzzles->merge($availablePuzzles)->unique('id')->sortBy('title')->values();
        $positions = $currentPuzzles
            ->filter(fn (Puzzle $puzzle) => $settings->canDiscoverPuzzle($puzzle))
            ->mapWithKeys(fn (Puzzle $puzzle) => [$puzzle->id => $puzzle->pivot->position]);

        return view('collections.edit', compact('collection', 'puzzles', 'positions'));
    }

    public function update(UpdateCollectionRequest $request, Collection $collection): RedirectResponse
    {
        Gate::authorize('update', $collection);
        $collection->fill($request->validated())->save();

        return redirect()->route('collections.edit', $collection)->with('status', 'Collection updated.');
    }

    public function syncPuzzles(SyncCollectionPuzzlesRequest $request, Collection $collection, DiscoverySettings $settings): RedirectResponse
    {
        Gate::authorize('update', $collection);
        $positions = $request->validated()['positions'] ?? [];
        $selected = [];
        foreach ($positions as $id => $position) {
            if (! ctype_digit((string) $id) || (int) $id < 1) {
                throw ValidationException::withMessages(['positions' => 'Puzzle identifiers are invalid.']);
            }
            if ((int) $position > 0) {
                $selected[(int) $id] = (int) $position;
            }
        }
        if (count($selected) > 100 || count(array_unique(array_values($selected))) !== count($selected)) {
            throw ValidationException::withMessages(['positions' => 'Choose at most 100 puzzles with unique positive positions.']);
        }
        $accessible = Puzzle::query()->whereIn('id', array_keys($selected))
            ->where('status', 'published')->where('visibility', 'public')->whereIn('moderation_status', $settings->visibleModerationStatuses())->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($accessible) !== count($selected)) {
            throw ValidationException::withMessages(['positions' => 'Collections can contain only publicly visible published puzzles.']);
        }
        asort($selected);
        $sync = [];
        foreach (array_keys($selected) as $index => $puzzleId) {
            $sync[$puzzleId] = ['position' => $index + 1];
        }

        DB::transaction(function () use ($collection, $sync): void {
            $collection->puzzles()->detach();
            $collection->puzzles()->sync($sync);
        });

        return redirect()->route('collections.edit', $collection)->with('status', 'Puzzle order saved.');
    }

    public function destroy(Collection $collection): RedirectResponse
    {
        Gate::authorize('delete', $collection);
        $collection->delete();

        return redirect()->route('collections.index')->with('status', 'Collection deleted.');
    }
}
