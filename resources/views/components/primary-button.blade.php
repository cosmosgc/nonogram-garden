<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-leaf border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-[#174f3a] focus:bg-[#174f3a] active:bg-[#123c2d] focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
