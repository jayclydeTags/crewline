<?php

use App\Enums\TeamRole;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Notifications\MentionedInComment;
use App\Notifications\TaskDueSoon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('mentioning a teammate in a comment notifies only them', function () {
    Notification::fake();
    $task = Task::factory()->create();
    $team = $task->project->team;
    $author = teamUser($team, TeamRole::Member);
    $mentioned = teamUser($team, TeamRole::Viewer);
    $mentioned->update(['name' => 'Ana Cruz']);
    $bystander = teamUser($team, TeamRole::Member);
    $outsider = User::factory()->create(['name' => 'Ana']);

    Livewire::actingAs($author)
        ->test('pages::tasks.show', ['task' => $task])
        ->set('commentBody', 'Can you review this, @ana cruz?')
        ->call('postComment')
        ->assertHasNoErrors();

    expect($task->comments()->sole()->body)->toBe('Can you review this, @ana cruz?');
    Notification::assertSentTo($mentioned, MentionedInComment::class);
    Notification::assertNotSentTo([$bystander, $outsider, $author], MentionedInComment::class);
});

test('comment bodies are escaped when mentions are highlighted', function () {
    $user = User::factory()->make(['name' => 'Ana']);
    $comment = new Comment(['body' => '<script>x</script> @Ana']);

    expect((string) $comment->renderedBody(collect([$user])))
        ->toContain('&lt;script&gt;')
        ->toContain('>@Ana</span>');
});

test('notification preferences control the delivery channels', function () {
    $user = User::factory()->create();

    expect($user->notificationChannelsFor('mentions'))->toBe(['database', 'mail']);

    $user->forceFill(['notification_preferences' => ['mentions' => false, 'email' => false]])->save();

    expect($user->notificationChannelsFor('mentions'))->toBe([])
        ->and($user->notificationChannelsFor('assignments'))->toBe(['database']);
});

test('users can save their notification preferences', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.notifications')
        ->set('email', false)
        ->call('savePreferences');

    expect($user->fresh()->wantsNotification('email'))->toBeFalse()
        ->and($user->fresh()->wantsNotification('mentions'))->toBeTrue();
});

test('members can attach files that only teammates can download', function () {
    Storage::fake('local');
    $task = Task::factory()->create();
    $member = teamUser($task->project->team, TeamRole::Member);

    Livewire::actingAs($member)
        ->test('pages::tasks.show', ['task' => $task])
        ->set('uploads', [UploadedFile::fake()->create('brief.pdf', 200, 'application/pdf')])
        ->assertHasNoErrors();

    $attachment = $task->attachments()->sole();
    Storage::disk('local')->assertExists($attachment->path);
    expect($attachment->original_name)->toBe('brief.pdf');

    $this->actingAs($member)->get(route('attachments.download', $attachment))->assertOk()->assertDownload('brief.pdf');

    $outsider = teamUser(Team::factory()->create(), TeamRole::Owner);
    $this->actingAs($outsider)->get(route('attachments.download', $attachment))->assertNotFound();
});

test('viewers cannot attach files', function () {
    Storage::fake('local');
    $task = Task::factory()->create();

    Livewire::actingAs(teamUser($task->project->team, TeamRole::Viewer))
        ->test('pages::tasks.show', ['task' => $task])
        ->set('uploads', [UploadedFile::fake()->create('brief.pdf', 10)])
        ->assertForbidden();

    expect(Attachment::count())->toBe(0);
});

test('the reminder command notifies assignees of open tasks due today or tomorrow', function () {
    Notification::fake();
    $assignee = User::factory()->create();

    $dueTomorrow = Task::factory()->create(['assignee_id' => $assignee->id, 'due_on' => today()->addDay()]);
    Task::factory()->done()->create(['assignee_id' => $assignee->id, 'due_on' => today()]);
    Task::factory()->create(['assignee_id' => $assignee->id, 'due_on' => today()->addDays(5)]);

    $this->artisan('tasks:send-due-reminders')->assertSuccessful();

    Notification::assertSentToTimes($assignee, TaskDueSoon::class, 1);
    Notification::assertSentTo($assignee, TaskDueSoon::class, fn (TaskDueSoon $notification) => $notification->task->is($dueTomorrow));
});
