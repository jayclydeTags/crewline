@props(['tasks', 'empty', 'showAssignee' => true])

@if ($tasks->isEmpty())
    <flux:text class="text-sm">{{ $empty }}</flux:text>
@else
    <ul class="divide-y divide-zinc-100 dark:divide-zinc-700">
        @foreach ($tasks as $task)
            <li class="flex items-center gap-3 py-2 text-sm" wire:key="{{ $attributes->get('id', 'list') }}-task-{{ $task->id }}">
                <span class="size-2 shrink-0 rounded-full bg-{{ $task->project->color }}-500" aria-hidden="true"></span>
                <div class="min-w-0 flex-1">
                    <a href="{{ route('tasks.show', $task) }}" wire:navigate class="block truncate font-medium hover:underline">{{ $task->title }}</a>
                    <span class="block truncate text-xs text-zinc-500">{{ $task->project->name }} · {{ $task->status->label() }}</span>
                </div>
                <flux:badge size="sm" :color="$task->priority->color()" class="max-sm:hidden">{{ $task->priority->label() }}</flux:badge>
                @if ($task->due_on)
                    <span @class(['shrink-0 text-xs tabular-nums', 'font-medium text-red-600 dark:text-red-400' => $task->isOverdue(), 'text-zinc-500' => ! $task->isOverdue()])>
                        {{ $task->due_on->format('M j') }}
                    </span>
                @endif
                @if ($showAssignee && $task->assignee)
                    <flux:avatar size="xs" circle :name="$task->assignee->name" :initials="$task->assignee->initials()" :tooltip="$task->assignee->name" />
                @endif
            </li>
        @endforeach
    </ul>
@endif
