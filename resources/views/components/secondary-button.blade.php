<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-[#fffdf8] border border-amber-900/20 rounded-lg font-semibold text-xs text-ink uppercase tracking-widest shadow-sm hover:bg-[#f4eadb] focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
