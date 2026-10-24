@props(['prefix' => 'form'])

<flux:input wire:model="{{ $prefix }}.name" :label="__('Name')" required autocomplete="off" />

<flux:textarea wire:model="{{ $prefix }}.description" :label="__('Description')" rows="3" />

<fieldset class="space-y-2">
    <legend class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Color') }}</legend>
    <div class="flex flex-wrap gap-2">
        @foreach (\App\Models\Project::COLORS as $color)
            <label class="flex size-8 cursor-pointer items-center justify-center rounded-full bg-{{ $color }}-500 ring-offset-2 ring-offset-white has-checked:ring-2 has-checked:ring-zinc-900 has-focus-visible:outline-2 has-focus-visible:outline-offset-4 dark:ring-offset-zinc-800 dark:has-checked:ring-white">
                <input type="radio" value="{{ $color }}" wire:model="{{ $prefix }}.color" class="peer sr-only">
                <flux:icon.check variant="micro" class="hidden text-white peer-checked:block" />
                <span class="sr-only">{{ ucfirst($color) }}</span>
            </label>
        @endforeach
    </div>
    <flux:error name="{{ $prefix }}.color" />
</fieldset>

<flux:radio.group wire:model="{{ $prefix }}.visibility" :label="__('Visibility')">
    @foreach (\App\Enums\ProjectVisibility::cases() as $visibility)
        <flux:radio :value="$visibility->value" :label="$visibility->label()" :description="$visibility->description()" />
    @endforeach
</flux:radio.group>

<div class="grid gap-4 sm:grid-cols-2">
    <flux:input type="date" wire:model="{{ $prefix }}.starts_on" :label="__('Start date')" />
    <flux:input type="date" wire:model="{{ $prefix }}.ends_on" :label="__('End date')" />
</div>
