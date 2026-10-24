<?php

use App\Livewire\Forms\ProjectForm;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Projects')] class extends Component {
    public ProjectForm $form;

    #[Url]
    public bool $archived = false;

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    #[Computed]
    public function projects()
    {
        return Auth::user()->currentTeam->projects()
            ->when($this->archived, fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->active())
            ->withProgress()
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a project in the current team.
     */
    public function createProject(): void
    {
        $team = Auth::user()->currentTeam;

        $this->authorize('create', [Project::class, $team]);

        $project = $team->projects()->create([...$this->form->attributes(), 'creator_id' => Auth::id()]);
        $project->recordActivity(__('created the project'), Auth::user());

        $this->form->reset();
        Flux::modal('create-project')->close();

        $this->redirectRoute('projects.show', $project, navigate: true);
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Projects') }}</flux:heading>
            <flux:subheading>{{ auth()->user()->currentTeam->name }}</flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            <flux:button.group>
                <flux:button size="sm" :variant="$archived ? 'ghost' : 'filled'" wire:click="$set('archived', false)" :aria-pressed="$archived ? 'false' : 'true'">{{ __('Active') }}</flux:button>
                <flux:button size="sm" :variant="$archived ? 'filled' : 'ghost'" wire:click="$set('archived', true)" :aria-pressed="$archived ? 'true' : 'false'">{{ __('Archived') }}</flux:button>
            </flux:button.group>

            @can('create', [\App\Models\Project::class, auth()->user()->currentTeam])
                <flux:modal.trigger name="create-project">
                    <flux:button variant="primary" icon="plus" data-test="new-project">{{ __('New project') }}</flux:button>
                </flux:modal.trigger>
            @endcan
        </div>
    </div>

    @if ($this->projects->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300 px-6 py-16 text-center dark:border-zinc-600">
            <flux:icon.folder class="size-10 text-zinc-400" />
            <flux:heading class="mt-4">{{ $archived ? __('No archived projects') : __('No projects yet') }}</flux:heading>
            <flux:text class="mt-1 max-w-sm">
                @if ($archived)
                    {{ __('Projects you archive will show up here.') }}
                @elsecan('create', [\App\Models\Project::class, auth()->user()->currentTeam])
                    {{ __('Create your first project to start planning work with your crew.') }}
                @else
                    {{ __('An admin hasn’t created any projects yet.') }}
                @endif
            </flux:text>
        </div>
    @else
        <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->projects as $project)
                <li wire:key="project-{{ $project->id }}">
                    <a href="{{ route('projects.show', $project) }}" wire:navigate
                        class="group flex h-full flex-col gap-4 rounded-xl border border-zinc-200 p-5 transition-colors hover:border-zinc-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent dark:border-zinc-700 dark:hover:border-zinc-500">
                        <div class="flex items-start gap-3">
                            <span class="mt-1.5 size-2.5 shrink-0 rounded-full bg-{{ $project->color }}-500" aria-hidden="true"></span>
                            <div class="min-w-0">
                                <flux:heading class="truncate">{{ $project->name }}</flux:heading>
                                @if ($project->description)
                                    <flux:text class="mt-1 line-clamp-2 text-sm">{{ $project->description }}</flux:text>
                                @endif
                            </div>
                        </div>

                        <div class="mt-auto space-y-2">
                            <div class="flex justify-between text-xs text-zinc-500 tabular-nums">
                                <span>{{ trans_choice(':done of :count task done|:done of :count tasks done', $project->tasks_count, ['done' => $project->completed_tasks_count, 'count' => $project->tasks_count]) }}</span>
                                <span>{{ $project->progress() }}%</span>
                            </div>
                            <flux:progress :value="$project->progress()" :color="$project->color" aria-label="{{ __(':name progress', ['name' => $project->name]) }}" />
                            @if ($project->ends_on)
                                <div class="flex items-center gap-1 text-xs text-zinc-500">
                                    <flux:icon.calendar variant="micro" />
                                    {{ __('Ends :date', ['date' => $project->ends_on->toFormattedDateString()]) }}
                                </div>
                            @endif
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <flux:modal name="create-project" class="w-full md:w-[32rem]">
        <form wire:submit="createProject" class="space-y-6">
            <flux:heading size="lg">{{ __('New project') }}</flux:heading>
            <x-project.form-fields />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create project') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
