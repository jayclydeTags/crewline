<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Notifications\TaskDueSoon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tasks:send-due-reminders')]
#[Description('Notify assignees about open tasks that are due today or tomorrow')]
class SendDueDateReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = 0;

        Task::query()
            ->open()
            ->whereNotNull('assignee_id')
            ->whereDate('due_on', '>=', today())
            ->whereDate('due_on', '<=', today()->addDay())
            ->whereHas('project', fn ($projects) => $projects->whereNull('archived_at'))
            ->with(['assignee', 'project'])
            ->chunkById(200, function ($tasks) use (&$count) {
                foreach ($tasks as $task) {
                    $task->assignee->notify(new TaskDueSoon($task));
                    $count++;
                }
            });

        $this->components->info("Queued {$count} due date reminders.");

        return self::SUCCESS;
    }
}
