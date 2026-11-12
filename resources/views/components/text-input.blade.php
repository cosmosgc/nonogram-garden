@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-amber-900/20 focus:border-leaf focus:ring-amber-500 rounded-lg bg-amber-50/30 shadow-sm']) }}>
