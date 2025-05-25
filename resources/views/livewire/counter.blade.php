<div class="flex items-center gap-3">
    <!-- Decrement button -->
    <button wire:click="decrement"
            class="px-3 py-1 bg-gray-300 rounded hover:bg-gray-400">
        −
    </button>

    <!-- Current count -->
    <span class="text-lg font-semibold">{{ $count }}</span>

    <!-- Increment button -->
    <button wire:click="increment"
            class="px-3 py-1 bg-gray-300 rounded hover:bg-gray-400">
        +
    </button>
</div>
