<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TeamInvitation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * Get the current user's open tasks in the current team.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Task>
     */
    #[Computed]
    public function myTasks()
    {
        return $this->openTasks()
            ->where('assignee_id', Auth::id())
            ->orderByRaw('due_on is null')
            ->orderBy('due_on')
            ->limit(15)
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Task>
     */
    #[Computed]
    public function overdue()
    {
        return $this->openTasks()->whereDate('due_on', '<', today())->orderBy('due_on')->limit(10)->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Task>
     */
    #[Computed]
    public function dueThisWeek()
    {
        return $this->openTasks()
            ->whereDate('due_on', '>=', today())
            ->whereDate('due_on', '<=', today()->addDays(7))
            ->orderBy('due_on')
            ->limit(10)
            ->get();
    }

    /**
     * Count tasks completed in the last 30 days per member.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User>
     */
    #[Computed]
    public function completedPerMember()
    {
        $team = Auth::user()->currentTeam;

        return $team->members()
            ->withCount(['assignedTasks as completed_count' => fn (Builder $query) => $query
                ->where('status', TaskStatus::Done)
                ->where('completed_at', '>=', now()->subDays(30))
                ->whereHas('project', fn (Builder $projects) => $projects->where('team_id', $team->id))])
            ->orderByDesc('completed_count')
            ->orderBy('name')
            ->get();
    }

    /**
     * Count open tasks per active project.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Project>
     */
    #[Computed]
    public function openPerProject()
    {
        return Auth::user()->currentTeam->projects()
            ->active()
            ->visibleTo(Auth::user())
            ->withProgress()
            ->withCount(['tasks as open_count' => fn (Builder $query) => $query->open()])
            ->orderByDesc('open_count')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, TeamInvitation>
     */
    #[Computed]
    public function invitations()
    {
        return TeamInvitation::with('team')->where('email', strtolower(Auth::user()->email))->get();
    }

    /**
     * @return Builder<Task>
     */
    private function openTasks(): Builder
    {
        return Task::query()->inTeam(Auth::user()->currentTeam)->visibleTo(Auth::user())->open()->with(['project', 'assignee']);
    }
}; ?>

<div class="space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Welcome back, :name', ['name' => Str::before(auth()->user()->name, ' ')]) }}</flux:heading>
        <flux:subheading>{{ __('Here’s what’s happening in :team.', ['team' => auth()->user()->currentTeam->name]) }}</flux:subheading>
    </div>

    @foreach ($this->invitations as $invitation)
        <flux:callout icon="envelope" wire:key="invite-{{ $invitation->id }}">
            <flux:callout.heading>{{ __('You’re invited to join :team', ['team' => $invitation->team->name]) }}</flux:callout.heading>
            <x-slot name="actions">
                <flux:button size="sm" :href="route('invitations.show', $invitation)" wire:navigate>{{ __('View invitation') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endforeach

    <div class="grid gap-4 sm:grid-cols-3">
        @foreach ([
            [__('My open tasks'), $this->myTasks->count(), 'user'],
            [__('Overdue'), $this->overdue->count(), 'exclamation-triangle'],
            [__('Due in 7 days'), $this->dueThisWeek->count(), 'calendar'],
        ] as [$label, $value, $icon])
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="flex items-center gap-2 text-sm text-zinc-500">
                    <flux:icon :name="$icon" variant="micro" />
                    {{ $label }}
                </div>
                <div class="mt-2 text-3xl font-semibold tabular-nums">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-zinc-200 p-5 lg:col-span-2 dark:border-zinc-700" aria-labelledby="my-tasks-heading">
            <flux:heading level="2" id="my-tasks-heading" class="mb-4">{{ __('My tasks') }}</flux:heading>
            <x-task.compact-list :tasks="$this->myTasks" :empty="__('Nothing assigned to you. Enjoy the calm.')" :show-assignee="false" />
        </section>

        <section class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700" aria-labelledby="overdue-heading">
            <flux:heading level="2" id="overdue-heading" class="mb-4 flex items-center gap-2">
                <flux:icon.exclamation-triangle variant="mini" class="text-red-500" /> {{ __('Overdue') }}
            </flux:heading>
            <x-task.compact-list :tasks="$this->overdue" :empty="__('No overdue tasks.')" />
        </section>

        <section class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700" aria-labelledby="week-heading">
            <flux:heading level="2" id="week-heading" class="mb-4">{{ __('Due in the next 7 days') }}</flux:heading>
            <x-task.compact-list :tasks="$this->dueThisWeek" :empty="__('Nothing due in the next 7 days.')" />
        </section>

        <section class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700" aria-labelledby="completed-heading">
            <flux:heading level="2" id="completed-heading">{{ __('Completed per member') }}</flux:heading>
            <flux:text class="mb-4 text-sm">{{ __('Last 30 days') }}</flux:text>
            @php($maxCompleted = max(1, $this->completedPerMember->max('completed_count')))
            <ul class="space-y-3">
                @foreach ($this->completedPerMember as $member)
                    <li class="flex items-center gap-3 text-sm" wire:key="stat-member-{{ $member->id }}">
                        <flux:avatar size="xs" circle :name="$member->name" :initials="$member->initials()" />
                        <span class="w-32 truncate">{{ $member->name }}</span>
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700" aria-hidden="true">
                            <div class="h-full rounded-full bg-green-500" style="width: {{ $member->completed_count / $maxCompleted * 100 }}%"></div>
                        </div>
                        <span class="w-8 text-end tabular-nums">{{ $member->completed_count }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700" aria-labelledby="projects-heading">
            <flux:heading level="2" id="projects-heading" class="mb-4">{{ __('Open tasks per project') }}</flux:heading>
            <ul class="space-y-3">
                @forelse ($this->openPerProject as $project)
                    <li class="space-y-1.5 text-sm" wire:key="stat-project-{{ $project->id }}">
                        <div class="flex items-center justify-between gap-3">
                            <a href="{{ route('projects.show', $project) }}" wire:navigate class="flex min-w-0 items-center gap-2 hover:underline">
                                <span class="size-2 shrink-0 rounded-full bg-{{ $project->color }}-500" aria-hidden="true"></span>
                                <span class="truncate">{{ $project->name }}</span>
                            </a>
                            <span class="shrink-0 text-zinc-500 tabular-nums">{{ trans_choice(':count open|:count open', $project->open_count) }} · {{ $project->progress() }}%</span>
                        </div>
                        <flux:progress :value="$project->progress()" :color="$project->color" aria-label="{{ __(':name progress', ['name' => $project->name]) }}" />
                    </li>
                @empty
                    <li>
                        <flux:text class="text-sm">{{ __('No active projects.') }}</flux:text>
                        <flux:link :href="route('projects.index')" wire:navigate class="text-sm">{{ __('Go to projects') }}</flux:link>
                    </li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
