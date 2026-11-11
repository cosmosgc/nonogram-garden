<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Import an image') }} · {{ config('app.name', 'Nonogram') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body>
    @include('layouts.feature-navigation')
    <div id="nonogram-image-importer"
         data-user-id="{{ auth()->id() }}"
         data-editor-new-url="{{ route('editor.new') }}">
        <main class="mx-auto max-w-5xl space-y-6 p-6">
            <h1 class="text-3xl font-bold">{{ __('Import an image') }}</h1>
            <p>{{ __('Loading the image importer… If this message stays visible, reload the page or check that JavaScript is enabled.') }}</p>
        </main>
    </div>
</body>
</html>
