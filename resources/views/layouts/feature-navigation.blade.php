<header class="site-header">
    <nav class="site-nav" aria-label="Main navigation">
        <a href="{{ route('home') }}" class="site-brand"><span class="brand-mark" aria-hidden="true">▦</span><span>Nonogram<span class="brand-period">.</span></span></a>
        <details class="audio-menu">
            <summary>Audio</summary>
            <div class="audio-panel" aria-label="Audio settings">
                <p>Make this space yours</p>
                <button type="button" data-audio-sounds aria-pressed="false">Sound effects: off</button>
                <button type="button" data-audio-music aria-pressed="false">Background music: off</button>
                <small>Music starts only when you turn it on. Outside the puzzle player, use the D-pad or left stick to move between controls, A to activate, and B to close an open menu or dialog.</small>
            </div>
        </details>
        @auth
            <a href="{{ route('profile.edit') }}" class="nav-profile inline-flex min-h-11 items-center rounded-full border border-emerald-900 px-4 py-2 text-sm font-semibold text-emerald-950 hover:bg-emerald-50">Edit profile</a>
        @endauth
        <details class="site-menu" open>
            <summary aria-label="Toggle navigation">Menu <span aria-hidden="true">⌄</span></summary>
            <div class="site-links">
                <a href="{{ route('puzzles.index') }}">Browse puzzles</a>
                <a href="{{ route('collections.index') }}">Collections</a>
                @auth
                    <a href="{{ route('editor.new') }}">Create puzzle</a>
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    @if (auth()->user()->is_moderator || auth()->user()->is_admin)
                        <a href="{{ route('moderation.index') }}">Moderation</a>
                    @endif
                    @if (auth()->user()->username)
                        <a href="{{ route('profiles.show', auth()->user()->username) }}">My profile</a>
                    @endif
                    <a href="{{ route('profile.edit') }}">Settings</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="nav-signin">Log out</button></form>
                @else
                    <a href="{{ route('login') }}">Log in</a>
                    <a href="{{ route('register') }}" class="nav-signin">Register</a>
                @endauth
            </div>
        </details>
    </nav>
</header>
@vite('resources/js/audio.ts')
