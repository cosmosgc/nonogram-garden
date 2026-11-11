<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Nonogram editor</title>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body>
    @include('layouts.feature-navigation')
    <div id="nonogram-editor"
         data-puzzle-id="{{ $puzzle?->id }}"
         data-user-id="{{ auth()->id() }}"
         data-draft-store-url="{{ route('puzzles.drafts.store') }}"
         data-draft-show-url-template="{{ route('puzzles.drafts.show', ['puzzle' => '__PUZZLE_ID__']) }}"
         data-draft-update-url-template="{{ route('puzzles.drafts.update', ['puzzle' => '__PUZZLE_ID__']) }}"
         data-publish-url-template="{{ route('puzzles.drafts.publish', ['puzzle' => '__PUZZLE_ID__']) }}"
         data-puzzle-url-template="{{ route('puzzles.show', ['puzzle' => '__PUZZLE_ID__']) }}"
         data-editor-new-url="{{ route('editor.new') }}"
         data-home-url="{{ route('home') }}"
         data-import-url="{{ route('editor.import') }}"
         data-editor-edit-url-template="{{ route('editor.edit', ['puzzle' => '__PUZZLE_ID__']) }}"></div>
</body>
</html>
