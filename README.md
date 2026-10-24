# Crewline

Crewline is a lightweight project tracker for teams, like a small Trello or Asana. A **crew** is your team, and a **line** is the path work takes from idea to done.

Teams plan projects, break them into tasks, assign work and track progress on a Kanban board or a filterable list. What each person can see and do depends on their role.

## Features

- **Teams and roles:** a user can belong to several teams and switch between them. Each membership has a role:

  | Role   | Can do                                                          |
  | ------ | --------------------------------------------------------------- |
  | Owner  | Everything, including deleting the team and transferring ownership |
  | Admin  | Manage members and invites, and fully manage every project      |
  | Member | Create and edit tasks, comment and attach files on team-visible projects |
  | Viewer | View team-visible projects and comment on tasks                 |

- **Project-level roles:** give someone a different role on one project than their team role, such as a contractor who is a team Viewer but a Contributor on the one project they were hired for. Projects can also be made **private**, so only people added to them (plus team Owners and Admins) can see them. See [Project-level roles](#project-level-roles) below.

- **Invitations:** invite people by email with a role. They accept or decline from a link.
- **Projects:** description, color, start and end dates, a progress bar, archive and restore.
- **Tasks:** assignee, status, priority, due date, checklist, labels and file attachments.
- **Board and list views:** drag-and-drop between status columns, or filter the list by assignee, status, priority and due date.
- **Collaboration:** comments with `@mentions`, and an activity log per task and per project.
- **Notifications:** in-app and email notifications for assignments, mentions and due dates, with per-user preferences.
- **Dashboard:** my tasks, overdue tasks, tasks due in the next 7 days, and team stats.

## Tech stack

- PHP 8.4 and Laravel 13
- Livewire 4 single-file components with Flux UI (free edition)
- Tailwind CSS 4, built with Vite
- Laravel Fortify for authentication (login, registration, password reset, email verification, two-factor and passkeys)
- SQLite by default, and the database driver for queues, cache and sessions
- Pest 5 for tests, Pint for formatting and Larastan (PHPStan) for static analysis

## Requirements

- PHP 8.3 or newer (8.4 is what CI and local development use), with the `sqlite3` and `pdo_sqlite` extensions
- Composer 2
- Node.js 22 and npm

[Laravel Herd](https://herd.laravel.com) on macOS or Windows provides all of these and serves the app at `http://crewline.test` with no extra setup.

## Getting started

### 1. Clone and install

```bash
git clone https://github.com/jayclydeTags/crewline.git
cd crewline
composer setup
```

`composer setup` runs these steps:

1. Installs PHP dependencies.
2. Copies `.env.example` to `.env`.
3. Generates `APP_KEY`.
4. Runs the migrations.
5. Installs npm packages.
6. Builds the front-end assets.

The default `.env` uses SQLite, so you don't need a separate database server. If `database/database.sqlite` doesn't exist yet, the migrate step creates it.

### 2. Link public storage

Team logos are stored on the `public` disk, so create the symlink once:

```bash
php artisan storage:link
```

### 3. Load demo data (optional)

```bash
php artisan db:seed
```

This creates an **Acme Studio** team with three projects, tasks, labels and checklists. Every demo account uses the password `password`:

| Email               | Role   |
| ------------------- | ------ |
| `test@example.com`  | Owner  |
| `ana@example.com`   | Admin  |
| `ben@example.com`   | Member |
| `chloe@example.com` | Member |
| `dev@example.com`   | Viewer, but a Contributor on Website Redesign |

Ben is also the Manager of **Payroll Revamp**, a private project that Chloe and Dev can't see.

Log in as different users to see how the permissions change. To start over with fresh demo data:

```bash
php artisan migrate:fresh --seed
```

### 4. Run the app

```bash
composer run dev
```

This runs `php artisan dev`, which starts these processes side by side:

| Process | Command                                          | Purpose                                     |
| ------- | ------------------------------------------------ | ------------------------------------------- |
| server  | `php artisan serve`                              | Serves the app at `http://localhost:8000`   |
| queue   | `php artisan queue:listen --tries=1 --timeout=0` | Sends queued notifications and emails       |
| logs    | `php artisan pail --timeout=0`                   | Streams the application log                 |
| vite    | `npm run dev`                                    | Rebuilds CSS and JS with hot reloading      |

**If you use Herd,** the app is also served at `http://crewline.test`. Set `APP_URL=http://crewline.test` in `.env` so links in emails and notifications point there.

## Environment configuration

The defaults in `.env.example` are set up for local development. These are the settings that matter most:

| Variable           | Default                 | Notes |
| ------------------ | ----------------------- | ----- |
| `APP_NAME`         | `Crewline`              | Shown in the page title and in emails. |
| `APP_URL`          | `http://localhost:8000` | Used to build absolute links in emails. Match it to how you open the app. |
| `DB_CONNECTION`    | `sqlite`                | To use MySQL or Postgres, set this and uncomment the `DB_*` lines. |
| `QUEUE_CONNECTION` | `database`              | Notifications are queued, so a worker must be running (`composer run dev` starts one). |
| `MAIL_MAILER`      | `log`                   | Emails are written to `storage/logs/laravel.log` instead of being sent. |
| `FILESYSTEM_DISK`  | `local`                 | Task attachments are stored privately in `storage/app/private` and served through an authorized download route. |

### Viewing emails

With the default `MAIL_MAILER=log`, invitation, assignment, mention and reminder emails appear in `storage/logs/laravel.log` (and in the `logs` process of `composer run dev`).

To view them in an inbox instead, point mail at a local SMTP catcher such as Herd's mail service, Mailpit or Mailtrap. For Herd:

```env
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
```

Then run `php artisan config:clear`.

### Queues

Assignment, mention, due-date and invitation notifications are queued. If notifications aren't appearing, make sure a worker is running:

```bash
php artisan queue:listen
```

### Scheduler

A due-date reminder runs every day at 08:00. It notifies assignees about open tasks due today or tomorrow. To run the scheduler locally:

```bash
php artisan schedule:work
```

To send the reminders immediately:

```bash
php artisan tasks:send-due-reminders
```

In production, add the standard Laravel cron entry: `* * * * * php artisan schedule:run`.

## Testing and code quality

```bash
php artisan test --compact
```

The feature tests cover each role's permissions, team context isolation, invitations, the board, @mentions, attachments and the reminder command. Tests use an in-memory SQLite database, the `array` mailer and the `sync` queue (see `phpunit.xml`), so they don't touch your local data.

Other checks:

```bash
vendor/bin/pint          # Format PHP code
vendor/bin/phpstan analyse   # Run static analysis
composer test            # Run the Pint check, PHPStan and the full test suite, as CI does
```

GitHub Actions runs the tests workflow (`.github/workflows/tests.yml`) on pushes to `main` and on pull requests.

## Project structure

```
app/
├── Console/Commands/      SendDueDateReminders (tasks:send-due-reminders)
├── Enums/                 TeamRole, ProjectRole, ProjectVisibility, TaskStatus, TaskPriority
├── Http/Middleware/       EnsureTeamContext keeps users inside their current team
├── Livewire/Forms/        ProjectForm and TaskForm, which hold validation rules
├── Models/                Team, Membership and ProjectMembership (pivots), Project, Task, Comment, Label,
│                          ChecklistItem, Attachment, Activity, TeamInvitation
├── Notifications/         TaskAssigned, MentionedInComment, TaskDueSoon, TeamInvitationSent
└── Policies/              TeamPolicy, ProjectPolicy, TaskPolicy, CommentPolicy
resources/views/
├── pages/                 Full-page Livewire components (dashboard, projects, tasks, teams, settings)
└── components/            Blade components (task card, team switcher, activity feed, ...)
routes/web.php             App routes; most sit behind the `team` middleware
```

## Project-level roles

A project role overrides someone's team role on that one project. Manage them from a project's **Members** tab, which also has the visibility switch.

| Action                         | Manager | Contributor | Viewer |
| ------------------------------ | ------- | ----------- | ------ |
| View project and tasks         | Yes     | Yes         | Yes    |
| Comment on tasks               | Yes     | Yes         | Yes    |
| Create and edit tasks          | Yes     | Yes         | No     |
| Delete tasks                   | Yes     | Own only    | No     |
| Edit project settings          | Yes     | No          | No     |
| Add, remove or change members  | Yes     | No          | No     |
| Archive project                | Yes     | No          | No     |
| Delete project                 | No (team Owner or Admin only) | No | No |

The effective role is resolved in this order (see `ProjectRole::resolve()`):

1. Team Owners and Admins are always Managers of every project and can't be downgraded.
2. If the user has a project role, it applies.
3. Otherwise the team role applies, but only on **team-visible** projects: a team Member acts as a Contributor and a team Viewer as a Viewer. **Private** projects are hidden from anyone without a project role.

Other rules:

- Only existing team members can be added to a project. Removing someone from the team also removes their project roles.
- A private project must keep at least one Manager. Only a team Owner or Admin can remove or demote the last one.
- Role and visibility changes are recorded in the project's activity log.

### How access control works

- Each user has a role on each team, stored in the `team_user` pivot and cast to the `TeamRole` enum. Project roles live in the `project_user` pivot and are cast to the `ProjectRole` enum.
- `ProjectRole::resolve()` is the single place that decides someone's role on a project. `User::effectiveProjectRole()`, the policies, the assignee and @mention lists, and the UI all go through it. The `visibleTo()` query scopes on `Project` and `Task` apply the same rules in SQL to filter lists, and the tests check that both agree.
- **Policies** decide what a role may do, and every Livewire action calls `authorize()` before it changes anything, so the rules apply to every request, not just to what the UI shows. Projects and tasks someone can't see return a 404, so private projects don't reveal that they exist.
- **`EnsureTeamContext`** (the `team` route middleware) makes sure the user has a current team they belong to. Opening a project or task from another of the user's teams switches to that team. Resources from teams the user isn't in return a 404.
