<?php

use App\Enums\TaskStatus;
use App\Livewire\Forms\TaskForm;
use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use App\Notifications\MentionedInComment;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public Task $task;

    public TaskForm $form;

    public string $commentBody = '';

    public string $checklistBody = '';

    public string $labelName = '';

    public string $labelColor = 'blue';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('view', $this->task);

        $this->form->setTask($this->task);
    }

    /**
     * Render the component.
     */
    public function render(): \Illuminate\Contracts\View\View
    {
        $this->task->load(['checklistItems', 'attachments', 'labels', 'assignee', 'creator', 'project.team', 'project.members']);

        return $this->view()->title($this->task->title);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    #[Computed]
    public function members()
    {
        return $this->task->project->usersWithAccess();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Label>
     */
    #[Computed]
    public function teamLabels()
    {
        return $this->task->project->team->labels()->orderBy('name')->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Comment>
     */
    #[Computed]
    public function comments()
    {
        return $this->task->comments()->with('user')->oldest()->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Activity>
     */
    #[Computed]
    public function activities()
    {
        return $this->task->subjectActivities()->with('user')->limit(30)->get();
    }

    #[Computed]
    public function canUpdate(): bool
    {
        return Auth::user()->can('update', $this->task);
    }

    #[Computed]
    public function canComment(): bool
    {
        return Auth::user()->can('comment', $this->task);
    }

    /**
     * Save the task's details, recording what changed.
     */
    public function save(): void
    {
        $this->authorize('update', $this->task);

        $attributes = $this->form->attributesFor($this->task->project);
        $previousStatus = $this->task->status;
        $previousAssigneeId = $this->task->assignee_id;

        $this->task->fill(collect($attributes)->except('status')->all());
        $detailsChanged = $this->task->isDirty();
        $this->task->save();

        if ($attributes['status'] !== $previousStatus) {
            $this->task->moveTo($attributes['status'], PHP_INT_MAX, Auth::user());
        }

        if ($this->task->assignee_id !== $previousAssigneeId) {
            $this->task->load('assignee');
            $this->task->recordActivity(
                $this->task->assignee
                    ? __('assigned “:task” to :name', ['task' => $this->task->title, 'name' => $this->task->assignee->name])
                    : __('unassigned “:task”', ['task' => $this->task->title]),
                Auth::user(),
            );
            $this->task->notifyAssigneeIfChanged($previousAssigneeId, Auth::user());
        } elseif ($detailsChanged) {
            $this->task->recordActivity(__('updated “:task”', ['task' => $this->task->title]), Auth::user());
        }

        unset($this->activities);

        Flux::toast(variant: 'success', text: __('Task saved.'));
    }

    /**
     * Change the task's status from the quick status buttons.
     */
    public function setStatus(string $status): void
    {
        $this->authorize('update', $this->task);

        $this->task->moveTo(TaskStatus::from($status), PHP_INT_MAX, Auth::user());
        $this->form->status = $this->task->status->value;

        unset($this->activities);
    }

    /**
     * Delete the task.
     */
    public function deleteTask(): void
    {
        $this->authorize('delete', $this->task);

        $project = $this->task->project;

        $this->task->attachments->each->delete();
        $project->recordActivity(__('deleted “:task”', ['task' => $this->task->title]), Auth::user());
        $this->task->delete();

        Flux::toast(text: __('Task deleted.'));

        $this->redirectRoute('projects.show', $project, navigate: true);
    }

    /**
     * Add an item to the checklist.
     */
    public function addChecklistItem(): void
    {
        $this->authorize('update', $this->task);

        $this->validate(['checklistBody' => ['required', 'string', 'max:255']], attributes: ['checklistBody' => 'item']);

        $this->task->checklistItems()->create([
            'body' => $this->checklistBody,
            'position' => (int) $this->task->checklistItems()->max('position') + 1,
        ]);

        $this->reset('checklistBody');
    }

    /**
     * Tick or untick a checklist item.
     */
    public function toggleChecklistItem(int $itemId): void
    {
        $this->authorize('update', $this->task);

        $item = $this->task->checklistItems()->findOrFail($itemId);
        $item->update(['is_completed' => ! $item->is_completed]);
    }

    /**
     * Remove a checklist item.
     */
    public function deleteChecklistItem(int $itemId): void
    {
        $this->authorize('update', $this->task);

        $this->task->checklistItems()->whereKey($itemId)->delete();
    }

    /**
     * Add or remove one of the team's labels on the task.
     */
    public function toggleLabel(int $labelId): void
    {
        $this->authorize('update', $this->task);

        $label = $this->task->project->team->labels()->findOrFail($labelId);

        $this->task->labels()->toggle($label->id);
    }

    /**
     * Create a new team label and apply it to the task.
     */
    public function createLabel(): void
    {
        $this->authorize('update', $this->task);

        $team = $this->task->project->team;

        $validated = $this->validate([
            'labelName' => ['required', 'string', 'max:40', Rule::unique('labels', 'name')->where('team_id', $team->id)],
            'labelColor' => ['required', Rule::in(['zinc', 'red', 'orange', 'amber', 'green', 'teal', 'blue', 'indigo', 'violet', 'pink'])],
        ], attributes: ['labelName' => 'label name', 'labelColor' => 'label color']);

        $label = $team->labels()->create(['name' => $validated['labelName'], 'color' => $validated['labelColor']]);
        $this->task->labels()->attach($label->id);

        $this->reset('labelName', 'labelColor');
        unset($this->teamLabels);
    }

    /**
     * Store files as soon as they finish uploading.
     */
    public function updatedUploads(): void
    {
        $this->authorize('update', $this->task);

        $this->validate(['uploads.*' => ['file', 'max:10240']], attributes: ['uploads.*' => 'file']);

        foreach ($this->uploads as $upload) {
            $this->task->attachments()->create([
                'user_id' => Auth::id(),
                'disk' => 'local',
                'path' => $upload->store("attachments/{$this->task->id}", 'local'),
                'original_name' => $upload->getClientOriginalName(),
                'mime_type' => $upload->getMimeType(),
                'size' => $upload->getSize(),
            ]);
        }

        $this->task->recordActivity(
            trans_choice('attached a file to “:task”|attached :count files to “:task”', count($this->uploads), ['task' => $this->task->title]),
            Auth::user(),
        );

        $this->reset('uploads');
        unset($this->activities);
    }

    /**
     * Delete an attachment.
     */
    public function deleteAttachment(int $attachmentId): void
    {
        $this->authorize('update', $this->task);

        $this->task->attachments()->findOrFail($attachmentId)->delete();
    }

    /**
     * Post a comment and notify anyone @mentioned.
     */
    public function postComment(): void
    {
        $this->authorize('comment', $this->task);

        $this->validate(['commentBody' => ['required', 'string', 'max:5000']], attributes: ['commentBody' => 'comment']);

        $comment = $this->task->comments()->create([
            'user_id' => Auth::id(),
            'body' => $this->commentBody,
        ]);

        $mentioned = $comment->mentionedUsers($this->members)->reject(fn (User $user) => $user->id === Auth::id());

        Notification::send($mentioned, new MentionedInComment($comment));

        $this->task->recordActivity(__('commented on “:task”', ['task' => $this->task->title]), Auth::user());

        $this->reset('commentBody');
        unset($this->comments, $this->activities);
    }

    /**
     * Delete a comment.
     */
    public function deleteComment(int $commentId): void
    {
        $comment = $this->task->comments()->findOrFail($commentId);

        $this->authorize('delete', $comment);

        $comment->delete();

        unset($this->comments);
    }
}; ?>

@php($project = $task->project)
<div class="space-y-6">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>{{ __('Projects') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('projects.show', $project)" wire:navigate>{{ $project->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $task->title }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 space-y-2">
            <flux:heading size="xl" level="1">{{ $task->title }}</flux:heading>
            <div class="flex flex-wrap items-center gap-2">
                <flux:badge size="sm" :color="$task->status->color()" :icon="$task->status->icon()">{{ $task->status->label() }}</flux:badge>
                <flux:badge size="sm" :color="$task->priority->color()">{{ __(':priority priority', ['priority' => $task->priority->label()]) }}</flux:badge>
                @if ($task->due_on)
                    <flux:badge size="sm" :color="$task->isOverdue() ? 'red' : 'zinc'" icon="calendar">
                        {{ $task->isOverdue() ? __('Overdue · :date', ['date' => $task->due_on->toFormattedDateString()]) : __('Due :date', ['date' => $task->due_on->toFormattedDateString()]) }}
                    </flux:badge>
                @endif
                @if ($project->isArchived())
                    <flux:badge size="sm" icon="archive-box">{{ __('Project archived') }}</flux:badge>
                @endif
            </div>
        </div>

        @can('delete', $task)
            <flux:button variant="ghost" icon="trash" wire:click="deleteTask" wire:confirm="{{ __('Delete this task?') }}">{{ __('Delete') }}</flux:button>
        @endcan
    </div>

    @if ($this->canUpdate)
        <div role="group" aria-label="{{ __('Set status') }}">
            <flux:button.group>
                @foreach (\App\Enums\TaskStatus::cases() as $status)
                    <flux:button size="sm" :icon="$status->icon()" :variant="$task->status === $status ? 'filled' : 'ghost'" wire:click="setStatus('{{ $status->value }}')" :aria-pressed="$task->status === $status ? 'true' : 'false'">
                        {{ $status->label() }}
                    </flux:button>
                @endforeach
            </flux:button.group>
        </div>
    @endif

    <div class="grid gap-8 lg:grid-cols-3">
        <div class="space-y-8 lg:col-span-2">
            <section aria-labelledby="details-heading" class="space-y-4">
                <flux:heading size="lg" level="2" id="details-heading">{{ __('Details') }}</flux:heading>

                @if ($this->canUpdate)
                    <form wire:submit="save" class="space-y-6">
                        <x-task.form-fields :members="$this->members" prefix="form" />
                        <flux:button type="submit" variant="primary" data-test="save-task">{{ __('Save changes') }}</flux:button>
                    </form>
                @else
                    <dl class="grid gap-4 text-sm sm:grid-cols-2">
                        <div><dt class="text-zinc-500">{{ __('Assignee') }}</dt><dd>{{ $task->assignee->name ?? __('Unassigned') }}</dd></div>
                        <div><dt class="text-zinc-500">{{ __('Due date') }}</dt><dd>{{ $task->due_on?->toFormattedDateString() ?? '—' }}</dd></div>
                    </dl>
                    <div class="prose prose-sm max-w-none whitespace-pre-line text-sm dark:prose-invert">{{ $task->description ?: __('No description.') }}</div>
                @endif
            </section>

            <section aria-labelledby="checklist-heading" class="space-y-3">
                @php($items = $task->checklistItems)
                <div class="flex items-center justify-between">
                    <flux:heading size="lg" level="2" id="checklist-heading">{{ __('Checklist') }}</flux:heading>
                    @if ($items->isNotEmpty())
                        <span class="text-sm text-zinc-500 tabular-nums">{{ $items->where('is_completed', true)->count() }}/{{ $items->count() }}</span>
                    @endif
                </div>

                @if ($items->isNotEmpty())
                    <flux:progress :value="(int) round($items->where('is_completed', true)->count() / $items->count() * 100)" color="green" aria-label="{{ __('Checklist progress') }}" />
                @endif

                <ul class="space-y-1">
                    @forelse ($items as $item)
                        <li class="group flex items-center gap-3 rounded-md px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-900" wire:key="item-{{ $item->id }}">
                            <flux:checkbox :checked="$item->is_completed" wire:click="toggleChecklistItem({{ $item->id }})" :disabled="! $this->canUpdate" :label="$item->body"
                                @class(['flex-1', 'line-through opacity-60' => $item->is_completed]) />
                            @if ($this->canUpdate)
                                <flux:button size="xs" variant="subtle" icon="x-mark" wire:click="deleteChecklistItem({{ $item->id }})" aria-label="{{ __('Remove :item', ['item' => $item->body]) }}" />
                            @endif
                        </li>
                    @empty
                        <li><flux:text class="text-sm">{{ __('No checklist items.') }}</flux:text></li>
                    @endforelse
                </ul>

                @if ($this->canUpdate)
                    <form wire:submit="addChecklistItem" class="flex items-start gap-2">
                        <div class="flex-1">
                            <flux:input wire:model="checklistBody" :placeholder="__('Add an item…')" aria-label="{{ __('New checklist item') }}" autocomplete="off" />
                        </div>
                        <flux:button type="submit">{{ __('Add') }}</flux:button>
                    </form>
                @endif
            </section>

            <section aria-labelledby="comments-heading" class="space-y-4">
                <flux:heading size="lg" level="2" id="comments-heading">{{ __('Comments') }}</flux:heading>

                <ul class="space-y-4">
                    @forelse ($this->comments as $comment)
                        <li class="flex gap-3" wire:key="comment-{{ $comment->id }}">
                            <flux:avatar size="sm" circle :name="$comment->user->name" :initials="$comment->user->initials()" />
                            <div class="min-w-0 flex-1 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-900">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-sm">
                                        <span class="font-medium">{{ $comment->user->name }}</span>
                                        <time class="ms-1 text-xs text-zinc-500" datetime="{{ $comment->created_at->toIso8601String() }}">{{ $comment->created_at->diffForHumans() }}</time>
                                    </p>
                                    @can('delete', $comment)
                                        <flux:button size="xs" variant="subtle" icon="trash" wire:click="deleteComment({{ $comment->id }})" wire:confirm="{{ __('Delete this comment?') }}" aria-label="{{ __('Delete comment') }}" />
                                    @endcan
                                </div>
                                <div class="mt-1 text-sm break-words">{{ $comment->renderedBody($this->members) }}</div>
                            </div>
                        </li>
                    @empty
                        <li><flux:text class="text-sm">{{ __('No comments yet. Start the conversation.') }}</flux:text></li>
                    @endforelse
                </ul>

                @if ($this->canComment)
                    <form wire:submit="postComment" class="space-y-3" x-data>
                        <flux:textarea wire:model="commentBody" x-ref="commentBox" :label="__('Add a comment')" rows="3" :placeholder="__('Write a comment… type @ and a teammate’s name to mention them')" />
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs text-zinc-500">{{ __('Mention:') }}</span>
                            @foreach ($this->members->where('id', '!=', auth()->id()) as $member)
                                <button type="button" class="min-h-6 rounded-full border border-zinc-200 px-2 text-xs hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-accent dark:border-zinc-700 dark:hover:bg-zinc-700"
                                    x-on:click="$wire.commentBody = ($wire.commentBody ? $wire.commentBody.replace(/\s*$/, ' ') : '') + @js('@'.$member->name) + ' '; $nextTick(() => $refs.commentBox.focus())"
                                    wire:key="mention-{{ $member->id }}">
                                    {{ '@'.$member->name }}
                                </button>
                            @endforeach
                            <flux:spacer />
                            <flux:button type="submit" variant="primary" size="sm" data-test="post-comment">{{ __('Comment') }}</flux:button>
                        </div>
                    </form>
                @endif
            </section>
        </div>

        <aside class="space-y-8">
            <section aria-labelledby="labels-heading" class="space-y-3">
                <flux:heading level="2" id="labels-heading">{{ __('Labels') }}</flux:heading>
                @php($appliedLabelIds = $task->labels->pluck('id'))

                @if ($this->canUpdate)
                    <div class="flex flex-wrap gap-2">
                        @forelse ($this->teamLabels as $label)
                            @php($applied = $appliedLabelIds->contains($label->id))
                            <button type="button" wire:click="toggleLabel({{ $label->id }})" aria-pressed="{{ $applied ? 'true' : 'false' }}" wire:key="label-{{ $label->id }}"
                                class="rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                                <flux:badge size="sm" :color="$applied ? $label->color : 'zinc'" :variant="$applied ? 'solid' : 'outline'" :icon="$applied ? 'check' : null">{{ $label->name }}</flux:badge>
                            </button>
                        @empty
                            <flux:text class="text-sm">{{ __('No labels yet.') }}</flux:text>
                        @endforelse
                    </div>

                    <form wire:submit="createLabel" class="flex flex-wrap items-start gap-2">
                        <div class="basis-full">
                            <flux:input size="sm" wire:model="labelName" :placeholder="__('New label')" aria-label="{{ __('New label name') }}" autocomplete="off" />
                        </div>
                        <div class="flex-1">
                            <flux:select size="sm" wire:model="labelColor" aria-label="{{ __('Label color') }}">
                                @foreach (['zinc', 'red', 'orange', 'amber', 'green', 'teal', 'blue', 'indigo', 'violet', 'pink'] as $color)
                                    <flux:select.option value="{{ $color }}">{{ ucfirst($color) }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                        <flux:button size="sm" type="submit" icon="plus" aria-label="{{ __('Create label') }}" />
                    </form>
                @else
                    <div class="flex flex-wrap gap-2">
                        @forelse ($task->labels as $label)
                            <flux:badge size="sm" :color="$label->color">{{ $label->name }}</flux:badge>
                        @empty
                            <flux:text class="text-sm">{{ __('No labels.') }}</flux:text>
                        @endforelse
                    </div>
                @endif
            </section>

            <section aria-labelledby="attachments-heading" class="space-y-3">
                <flux:heading level="2" id="attachments-heading">{{ __('Attachments') }}</flux:heading>

                <ul class="space-y-2">
                    @forelse ($task->attachments as $attachment)
                        <li class="flex items-center gap-2 text-sm" wire:key="attachment-{{ $attachment->id }}">
                            <flux:icon.paper-clip variant="micro" class="shrink-0 text-zinc-400" />
                            <a href="{{ route('attachments.download', $attachment) }}" class="min-w-0 flex-1 truncate hover:underline">{{ $attachment->original_name }}</a>
                            <span class="shrink-0 text-xs text-zinc-500 tabular-nums">{{ $attachment->sizeForHumans() }}</span>
                            @if ($this->canUpdate)
                                <flux:button size="xs" variant="subtle" icon="x-mark" wire:click="deleteAttachment({{ $attachment->id }})" wire:confirm="{{ __('Delete :file?', ['file' => $attachment->original_name]) }}" aria-label="{{ __('Delete :file', ['file' => $attachment->original_name]) }}" />
                            @endif
                        </li>
                    @empty
                        <li><flux:text class="text-sm">{{ __('No files attached.') }}</flux:text></li>
                    @endforelse
                </ul>

                @if ($this->canUpdate)
                    <div wire:drop.file="$upload('uploads')" class="rounded-lg border border-dashed border-zinc-300 p-4 text-center text-sm data-dragging:border-accent data-dragging:bg-zinc-50 dark:border-zinc-600 dark:data-dragging:bg-zinc-900">
                        <flux:button size="sm" icon="arrow-up-tray" wire:click="$upload('uploads')">{{ __('Attach files') }}</flux:button>
                        <flux:text class="mt-2 text-xs">{{ __('or drop files here · up to 10 MB each') }}</flux:text>
                        <div wire:loading wire:target="uploads" class="mt-2 text-xs text-zinc-500">{{ __('Uploading…') }}</div>
                    </div>
                    <flux:error name="uploads.*" />
                @endif
            </section>

            <section aria-labelledby="activity-heading" class="space-y-3">
                <flux:heading level="2" id="activity-heading">{{ __('Activity') }}</flux:heading>
                <x-activity-feed :activities="$this->activities" />
            </section>

            <flux:text class="text-xs">
                {{ __('Created by :name :when', ['name' => $task->creator->name ?? __('someone'), 'when' => $task->created_at->diffForHumans()]) }}
            </flux:text>
        </aside>
    </div>
</div>
