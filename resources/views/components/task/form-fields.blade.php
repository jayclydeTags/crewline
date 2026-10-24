@props(['members', 'prefix' => 'taskForm'])

<flux:input wire:model="{{ $prefix }}.title" :label="__('Title')" required autocomplete="off" />

<flux:textarea wire:model="{{ $prefix }}.description" :label="__('Description')" rows="4" />

<div class="grid gap-4 sm:grid-cols-2">
    <flux:select wire:model="{{ $prefix }}.assignee_id" :label="__('Assignee')">
        <flux:select.option value="">{{ __('Unassigned') }}</flux:select.option>
        @foreach ($members as $member)
            <flux:select.option value="{{ $member->id }}">{{ $member->name }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:input type="date" wire:model="{{ $prefix }}.due_on" :label="__('Due date')" />

    <flux:select wire:model="{{ $prefix }}.status" :label="__('Status')">
        @foreach (\App\Enums\TaskStatus::cases() as $status)
            <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:select wire:model="{{ $prefix }}.priority" :label="__('Priority')">
        @foreach (\App\Enums\TaskPriority::cases() as $priority)
            <flux:select.option value="{{ $priority->value }}">{{ $priority->label() }}</flux:select.option>
        @endforeach
    </flux:select>
</div>
