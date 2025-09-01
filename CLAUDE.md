# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Coolify is an open-source, self-hostable platform for deploying applications and managing servers - an alternative to Heroku/Netlify/Vercel. It's built with Laravel (PHP) and uses Docker for containerization.

## Development Commands

### Frontend Development
- `npm run dev` - Start Vite development server for frontend assets
- `npm run build` - Build frontend assets for production

### Backend Development
Only run artisan commands inside "coolify" container when in development.
- `php artisan serve` - Start Laravel development server
- `php artisan migrate` - Run database migrations
- `php artisan queue:work` - Start queue worker for background jobs
- `php artisan horizon` - Start Laravel Horizon for queue monitoring
- `php artisan tinker` - Start interactive PHP REPL

### Code Quality
- `./vendor/bin/pint` - Run Laravel Pint for code formatting
- `./vendor/bin/phpstan` - Run PHPStan for static analysis
- `./vendor/bin/pest` - Run Pest tests

## Architecture Overview

### Technology Stack
- **Backend**: Laravel 12 (PHP 8.4)
- **Frontend**: Livewire 3.5+ with Alpine.js and Tailwind CSS 4.1+
- **Database**: PostgreSQL 15 (primary), Redis 7 (cache/queues)
- **Real-time**: Soketi (WebSocket server)
- **Containerization**: Docker & Docker Compose
- **Queue Management**: Laravel Horizon

### Key Components

#### Core Models
- `Application` - Deployed applications with Git integration (74KB, highly complex)
- `Server` - Remote servers managed by Coolify (46KB, complex)
- `Service` - Docker Compose services (58KB, complex)
- `Database` - Standalone database instances (PostgreSQL, MySQL, MongoDB, Redis, etc.)
- `Team` - Multi-tenancy support
- `Project` - Grouping of environments and resources
- `Environment` - Environment isolation (staging, production, etc.)

#### Job System
- Uses Laravel Horizon for queue management
- Key jobs: `ApplicationDeploymentJob`, `ServerCheckJob`, `DatabaseBackupJob`
- `ServerManagerJob` and `ServerConnectionCheckJob` handle job scheduling

#### Deployment Flow
1. Git webhook triggers deployment
2. `ApplicationDeploymentJob` handles build and deployment
3. Docker containers are managed on target servers
4. Proxy configuration (Nginx/Traefik) is updated

#### Server Management
- SSH-based server communication via `ExecuteRemoteCommand` trait
- Docker installation and management
- Proxy configuration generation
- Resource monitoring and cleanup

### Directory Structure
- `app/Actions/` - Domain-specific actions (Application, Database, Server, etc.)
- `app/Jobs/` - Background queue jobs
- `app/Livewire/` - Frontend components (full-stack with Livewire)
- `app/Models/` - Eloquent models
- `app/Rules/` - Custom validation rules
- `app/Http/Middleware/` - HTTP middleware
- `bootstrap/helpers/` - Helper functions for various domains
- `database/migrations/` - Database schema evolution
- `routes/` - Application routing (web.php, api.php, webhooks.php, channels.php)
- `resources/views/livewire/` - Livewire component views
- `tests/` - Pest tests (Feature and Unit)

## Development Guidelines

### Frontend Philosophy
Coolify uses a **server-side first** approach with minimal JavaScript:
- **Livewire** for server-side rendering with reactive components
- **Alpine.js** for lightweight client-side interactions
- **Tailwind CSS** for utility-first styling with dark mode support
- **Enhanced Form Components** with built-in authorization system
- Real-time updates via WebSocket without page refreshes

### Form Authorization Pattern
**IMPORTANT**: When creating or editing forms, ALWAYS include authorization:

#### For Form Components (Input, Select, Textarea, Checkbox, Button):
Use `canGate` and `canResource` attributes for automatic authorization:
```html
<x-forms.input canGate="update" :canResource="$resource" id="name" label="Name" />
<x-forms.select canGate="update" :canResource="$resource" id="type" label="Type">...</x-forms.select>
<x-forms.checkbox instantSave canGate="update" :canResource="$resource" id="enabled" label="Enabled" />
<x-forms.button canGate="update" :canResource="$resource" type="submit">Save</x-forms.button>
```

#### For Modal Components:
Wrap with `@can` directives:
```html
@can('update', $resource)
    <x-modal-confirmation title="Confirm Action?" buttonTitle="Confirm">...</x-modal-confirmation>
    <x-modal-input buttonTitle="Edit" title="Edit Settings">...</x-modal-input>
@endcan
```

#### In Livewire Components:
Always add the `AuthorizesRequests` trait and check permissions:
```php
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class MyComponent extends Component
{
    use AuthorizesRequests;
    
    public function mount()
    {
        $this->authorize('view', $this->resource);
    }
    
    public function update()
    {
        $this->authorize('update', $this->resource);
        // ... update logic
    }
}
```

### Livewire Component Structure
- Components located in `app/Livewire/`
- Views in `resources/views/livewire/`
- State management handled on the server
- Use wire:model for two-way data binding
- Dispatch events for component communication

### Code Organization Patterns
- **Actions Pattern**: Use Actions for complex business logic (`app/Actions/`)
- **Livewire Components**: Handle UI and user interactions
- **Jobs**: Handle asynchronous operations
- **Traits**: Provide shared functionality (e.g., `ExecuteRemoteCommand`)
- **Helper Functions**: Domain-specific helpers in `bootstrap/helpers/`

### Database Patterns
- Use Eloquent ORM for database interactions
- Implement relationships properly (HasMany, BelongsTo, etc.)
- Use database transactions for critical operations
- Leverage query scopes for reusable queries
- Apply indexes for performance-critical queries

### Security Best Practices
- **Authentication**: Multi-provider auth via Laravel Fortify & Sanctum
- **Authorization**: Team-based access control with policies and enhanced form components
- **Form Component Security**: Built-in `canGate` authorization system for UI components
- **API Security**: Token-based auth with IP allowlisting
- **Secrets Management**: Never log or expose sensitive data
- **Input Validation**: Always validate user input with Form Requests or Rules
- **SQL Injection Prevention**: Use Eloquent ORM or parameterized queries

### API Development
- RESTful endpoints in `routes/api.php`
- Use API Resources for response formatting
- Implement rate limiting for public endpoints
- Version APIs when making breaking changes
- Document endpoints with clear examples

### Testing Strategy
- **Framework**: Pest for expressive testing
- **Structure**: Feature tests for user flows, Unit tests for isolated logic
- **Coverage**: Test critical paths and edge cases
- **Mocking**: Use Laravel's built-in mocking for external services
- **Database**: Use RefreshDatabase trait for test isolation

### Routing Conventions
- Group routes by middleware and prefix
- Use route model binding for cleaner controllers
- Name routes consistently (resource.action)
- Implement proper HTTP verbs (GET, POST, PUT, DELETE)

### Error Handling
- Use `handleError()` helper for consistent error handling
- Log errors with appropriate context
- Return user-friendly error messages
- Implement proper HTTP status codes

### Performance Considerations
- Use eager loading to prevent N+1 queries
- Implement caching for frequently accessed data
- Queue heavy operations
- Optimize database queries with proper indexes
- Use chunking for large data operations

### Code Style
- Follow PSR-12 coding standards
- Use Laravel Pint for automatic formatting
- Write descriptive variable and method names
- Keep methods small and focused
- Document complex logic with clear comments

## Cloud Instance Considerations

We have a cloud instance of Coolify (hosted version) with:
- 2 Horizon worker servers
- Thousands of connected servers
- Thousands of active users
- High-availability requirements

When developing features:
- Consider scalability implications
- Test with large datasets
- Implement efficient queries
- Use queues for heavy operations
- Consider rate limiting and resource constraints
- Implement proper error recovery mechanisms

## Important Reminders

- Always run code formatting: `./vendor/bin/pint`
- Test your changes: `./vendor/bin/pest`
- Check for static analysis issues: `./vendor/bin/phpstan`
- Use existing patterns and helpers
- Follow the established directory structure
- Maintain backward compatibility
- Document breaking changes
- Consider performance impact on large-scale deployments

## Additional Documentation

For more detailed guidelines and patterns, refer to the `.cursor/rules/` directory:

### Architecture & Patterns
- [Application Architecture](.cursor/rules/application-architecture.mdc) - Detailed application structure
- [Deployment Architecture](.cursor/rules/deployment-architecture.mdc) - Deployment patterns and flows
- [Database Patterns](.cursor/rules/database-patterns.mdc) - Database design and query patterns
- [Frontend Patterns](.cursor/rules/frontend-patterns.mdc) - Livewire and Alpine.js patterns
- [API & Routing](.cursor/rules/api-and-routing.mdc) - API design and routing conventions

### Development & Security
- [Development Workflow](.cursor/rules/development-workflow.mdc) - Development best practices
- [Security Patterns](.cursor/rules/security-patterns.mdc) - Security implementation details
- [Form Components](.cursor/rules/form-components.mdc) - Enhanced form components with authorization
- [Testing Patterns](.cursor/rules/testing-patterns.mdc) - Testing strategies and examples

### Project Information
- [Project Overview](.cursor/rules/project-overview.mdc) - High-level project structure
- [Technology Stack](.cursor/rules/technology-stack.mdc) - Detailed tech stack information
- [Cursor Rules Guide](.cursor/rules/cursor_rules.mdc) - How to maintain cursor rules


# === BACKLOG.MD GUIDELINES START ===
# Instructions for the usage of Backlog.md CLI Tool

## What is Backlog.md?

**Backlog.md is the complete project management system for this codebase.** It provides everything needed to manage tasks, track progress, and collaborate on development - all through a powerful CLI that operates on markdown files.

### Core Capabilities

✅ **Task Management**: Create, edit, assign, prioritize, and track tasks with full metadata
✅ **Acceptance Criteria**: Granular control with add/remove/check/uncheck by index
✅ **Board Visualization**: Terminal-based Kanban board (`backlog board`) and web UI (`backlog browser`)
✅ **Git Integration**: Automatic tracking of task states across branches
✅ **Dependencies**: Task relationships and subtask hierarchies
✅ **Documentation & Decisions**: Structured docs and architectural decision records
✅ **Export & Reporting**: Generate markdown reports and board snapshots
✅ **AI-Optimized**: `--plain` flag provides clean text output for AI processing

### Why This Matters to You (AI Agent)

1. **Comprehensive system** - Full project management capabilities through CLI
2. **The CLI is the interface** - All operations go through `backlog` commands
3. **Unified interaction model** - You can use CLI for both reading (`backlog task 1 --plain`) and writing (`backlog task edit 1`)
4. **Metadata stays synchronized** - The CLI handles all the complex relationships

### Key Understanding

- **Tasks** live in `backlog/tasks/` as `task-<id> - <title>.md` files
- **You interact via CLI only**: `backlog task create`, `backlog task edit`, etc.
- **Use `--plain` flag** for AI-friendly output when viewing/listing
- **Never bypass the CLI** - It handles Git, metadata, file naming, and relationships

---

# ⚠️ CRITICAL: NEVER EDIT TASK FILES DIRECTLY

**ALL task operations MUST use the Backlog.md CLI commands**
- ✅ **DO**: Use `backlog task edit` and other CLI commands
- ✅ **DO**: Use `backlog task create` to create new tasks
- ✅ **DO**: Use `backlog task edit <id> --check-ac <index>` to mark acceptance criteria
- ❌ **DON'T**: Edit markdown files directly
- ❌ **DON'T**: Manually change checkboxes in files
- ❌ **DON'T**: Add or modify text in task files without using CLI

**Why?** Direct file editing breaks metadata synchronization, Git tracking, and task relationships.

---

## 1. Source of Truth & File Structure

### 📖 **UNDERSTANDING** (What you'll see when reading)
- Markdown task files live under **`backlog/tasks/`** (drafts under **`backlog/drafts/`**)
- Files are named: `task-<id> - <title>.md` (e.g., `task-42 - Add GraphQL resolver.md`)
- Project documentation is in **`backlog/docs/`**
- Project decisions are in **`backlog/decisions/`**

### 🔧 **ACTING** (How to change things)
- **All task operations MUST use the Backlog.md CLI tool**
- This ensures metadata is correctly updated and the project stays in sync
- **Always use `--plain` flag** when listing or viewing tasks for AI-friendly text output

---

## 2. Common Mistakes to Avoid

### ❌ **WRONG: Direct File Editing**
```markdown
# DON'T DO THIS:
1. Open backlog/tasks/task-7 - Feature.md in editor
2. Change "- [ ]" to "- [x]" manually
3. Add notes directly to the file
4. Save the file
```

### ✅ **CORRECT: Using CLI Commands**
```bash
# DO THIS INSTEAD:
backlog task edit 7 --check-ac 1  # Mark AC #1 as complete
backlog task edit 7 --notes "Implementation complete"  # Add notes
backlog task edit 7 -s "In Progress" -a @agent-k  # Multiple commands: change status and assign the task
```

---

## 3. Understanding Task Format (Read-Only Reference)

⚠️ **FORMAT REFERENCE ONLY** - The following sections show what you'll SEE in task files.
**Never edit these directly! Use CLI commands to make changes.**

### Task Structure You'll See

```markdown
---
id: task-42
title: Add GraphQL resolver
status: To Do
assignee: [@sara]
labels: [backend, api]
---

## Description
Brief explanation of the task purpose.

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 First criterion
- [x] #2 Second criterion (completed)
- [ ] #3 Third criterion
<!-- AC:END -->

## Implementation Plan
1. Research approach
2. Implement solution

## Implementation Notes
Summary of what was done.
```

### How to Modify Each Section

| What You Want to Change | CLI Command to Use |
|------------------------|-------------------|
| Title | `backlog task edit 42 -t "New Title"` |
| Status | `backlog task edit 42 -s "In Progress"` |
| Assignee | `backlog task edit 42 -a @sara` |
| Labels | `backlog task edit 42 -l backend,api` |
| Description | `backlog task edit 42 -d "New description"` |
| Add AC | `backlog task edit 42 --ac "New criterion"` |
| Check AC #1 | `backlog task edit 42 --check-ac 1` |
| Uncheck AC #2 | `backlog task edit 42 --uncheck-ac 2` |
| Remove AC #3 | `backlog task edit 42 --remove-ac 3` |
| Add Plan | `backlog task edit 42 --plan "1. Step one\n2. Step two"` |
| Add Notes | `backlog task edit 42 --notes "What I did"` |

---

## 4. Defining Tasks

### Creating New Tasks

**Always use CLI to create tasks:**
```bash
backlog task create "Task title" -d "Description" --ac "First criterion" --ac "Second criterion"
```

### Title (one liner)
Use a clear brief title that summarizes the task.

### Description (The "why")
Provide a concise summary of the task purpose and its goal. Explains the context without implementation details.

### Acceptance Criteria (The "what")

**Understanding the Format:**
- Acceptance criteria appear as numbered checkboxes in the markdown files
- Format: `- [ ] #1 Criterion text` (unchecked) or `- [x] #1 Criterion text` (checked)

**Managing Acceptance Criteria via CLI:**

⚠️ **IMPORTANT: How AC Commands Work**
- **Adding criteria (`--ac`)** accepts multiple flags: `--ac "First" --ac "Second"` ✅
- **Checking/unchecking/removing** accept multiple flags too: `--check-ac 1 --check-ac 2` ✅
- **Mixed operations** work in a single command: `--check-ac 1 --uncheck-ac 2 --remove-ac 3` ✅

```bash
# Add new criteria (MULTIPLE values allowed)
backlog task edit 42 --ac "User can login" --ac "Session persists"

# Check specific criteria by index (MULTIPLE values supported)
backlog task edit 42 --check-ac 1 --check-ac 2 --check-ac 3  # Check multiple ACs
# Or check them individually if you prefer:
backlog task edit 42 --check-ac 1    # Mark #1 as complete
backlog task edit 42 --check-ac 2    # Mark #2 as complete

# Mixed operations in single command
backlog task edit 42 --check-ac 1 --uncheck-ac 2 --remove-ac 3

# ❌ STILL WRONG - These formats don't work:
# backlog task edit 42 --check-ac 1,2,3  # No comma-separated values
# backlog task edit 42 --check-ac 1-3    # No ranges
# backlog task edit 42 --check 1         # Wrong flag name

# Multiple operations of same type
backlog task edit 42 --uncheck-ac 1 --uncheck-ac 2  # Uncheck multiple ACs
backlog task edit 42 --remove-ac 2 --remove-ac 4    # Remove multiple ACs (processed high-to-low)
```

**Key Principles for Good ACs:**
- **Outcome-Oriented:** Focus on the result, not the method
- **Testable/Verifiable:** Each criterion should be objectively testable
- **Clear and Concise:** Unambiguous language
- **Complete:** Collectively cover the task scope
- **User-Focused:** Frame from end-user or system behavior perspective

Good Examples:
- "User can successfully log in with valid credentials"
- "System processes 1000 requests per second without errors"

Bad Example (Implementation Step):
- "Add a new function handleLogin() in auth.ts"

### Task Breakdown Strategy

1. Identify foundational components first
2. Create tasks in dependency order (foundations before features)
3. Ensure each task delivers value independently
4. Avoid creating tasks that block each other

### Task Requirements

- Tasks must be **atomic** and **testable** or **verifiable**
- Each task should represent a single unit of work for one PR
- **Never** reference future tasks (only tasks with id < current task id)
- Ensure tasks are **independent** and don't depend on future work

---

## 5. Implementing Tasks

### Implementation Plan (The "how") (only after starting work)
```bash
backlog task edit 42 -s "In Progress" -a @{myself}
backlog task edit 42 --plan "1. Research patterns\n2. Implement\n3. Test"
```

### Implementation Notes (Imagine you need to copy paste this into a PR description)
```bash
backlog task edit 42 --notes "Implemented using pattern X, modified files Y and Z"
```

**IMPORTANT**: Do NOT include an Implementation Plan when creating a task. The plan is added only after you start implementation.
- Creation phase: provide Title, Description, Acceptance Criteria, and optionally labels/priority/assignee.
- When you begin work, switch to edit and add the plan: `backlog task edit <id> --plan "..."`.
- Add Implementation Notes only after completing the work: `backlog task edit <id> --notes "..."`.

Phase discipline: What goes where
- Creation: Title, Description, Acceptance Criteria, labels/priority/assignee.
- Implementation: Implementation Plan (after moving to In Progress).
- Wrap-up: Implementation Notes, AC and Definition of Done checks.

**IMPORTANT**: Only implement what's in the Acceptance Criteria. If you need to do more, either:
1. Update the AC first: `backlog task edit 42 --ac "New requirement"`
2. Or create a new task: `backlog task create "Additional feature"`

---

## 6. Typical Workflow

```bash
# 1. Identify work
backlog task list -s "To Do" --plain

# 2. Read task details
backlog task 42 --plain

# 3. Start work: assign yourself & change status
backlog task edit 42 -a @myself -s "In Progress"

# 4. Add implementation plan
backlog task edit 42 --plan "1. Analyze\n2. Refactor\n3. Test"

# 5. Work on the task (write code, test, etc.)

# 6. Mark acceptance criteria as complete (supports multiple in one command)
backlog task edit 42 --check-ac 1 --check-ac 2 --check-ac 3  # Check all at once
# Or check them individually if preferred:
# backlog task edit 42 --check-ac 1
# backlog task edit 42 --check-ac 2
# backlog task edit 42 --check-ac 3

# 7. Add implementation notes
backlog task edit 42 --notes "Refactored using strategy pattern, updated tests"

# 8. Mark task as done
backlog task edit 42 -s Done
```

---

## 7. Definition of Done (DoD)

A task is **Done** only when **ALL** of the following are complete:

### ✅ Via CLI Commands:
1. **All acceptance criteria checked**: Use `backlog task edit <id> --check-ac <index>` for each
2. **Implementation notes added**: Use `backlog task edit <id> --notes "..."`
3. **Status set to Done**: Use `backlog task edit <id> -s Done`

### ✅ Via Code/Testing:
4. **Tests pass**: Run test suite and linting
5. **Documentation updated**: Update relevant docs if needed
6. **Code reviewed**: Self-review your changes
7. **No regressions**: Performance, security checks pass

⚠️ **NEVER mark a task as Done without completing ALL items above**

---

## 8. Quick Reference: DO vs DON'T

### Viewing Tasks
| Task | ✅ DO | ❌ DON'T |
|------|-------|----------|
| View task | `backlog task 42 --plain` | Open and read .md file directly |
| List tasks | `backlog task list --plain` | Browse backlog/tasks folder |
| Check status | `backlog task 42 --plain` | Look at file content |

### Modifying Tasks
| Task | ✅ DO | ❌ DON'T |
|------|-------|----------|
| Check AC | `backlog task edit 42 --check-ac 1` | Change `- [ ]` to `- [x]` in file |
| Add notes | `backlog task edit 42 --notes "..."` | Type notes into .md file |
| Change status | `backlog task edit 42 -s Done` | Edit status in frontmatter |
| Add AC | `backlog task edit 42 --ac "New"` | Add `- [ ] New` to file |

---

## 9. Complete CLI Command Reference

### Task Creation
| Action | Command |
|--------|---------|
| Create task | `backlog task create "Title"` |
| With description | `backlog task create "Title" -d "Description"` |
| With AC | `backlog task create "Title" --ac "Criterion 1" --ac "Criterion 2"` |
| With all options | `backlog task create "Title" -d "Desc" -a @sara -s "To Do" -l auth --priority high` |
| Create draft | `backlog task create "Title" --draft` |
| Create subtask | `backlog task create "Title" -p 42` |

### Task Modification
| Action | Command |
|--------|---------|
| Edit title | `backlog task edit 42 -t "New Title"` |
| Edit description | `backlog task edit 42 -d "New description"` |
| Change status | `backlog task edit 42 -s "In Progress"` |
| Assign | `backlog task edit 42 -a @sara` |
| Add labels | `backlog task edit 42 -l backend,api` |
| Set priority | `backlog task edit 42 --priority high` |

### Acceptance Criteria Management
| Action | Command |
|--------|---------|
| Add AC | `backlog task edit 42 --ac "New criterion" --ac "Another"` |
| Remove AC #2 | `backlog task edit 42 --remove-ac 2` |
| Remove multiple ACs | `backlog task edit 42 --remove-ac 2 --remove-ac 4` |
| Check AC #1 | `backlog task edit 42 --check-ac 1` |
| Check multiple ACs | `backlog task edit 42 --check-ac 1 --check-ac 3` |
| Uncheck AC #3 | `backlog task edit 42 --uncheck-ac 3` |
| Mixed operations | `backlog task edit 42 --check-ac 1 --uncheck-ac 2 --remove-ac 3 --ac "New"` |

### Task Content
| Action | Command |
|--------|---------|
| Add plan | `backlog task edit 42 --plan "1. Step one\n2. Step two"` |
| Add notes | `backlog task edit 42 --notes "Implementation details"` |
| Add dependencies | `backlog task edit 42 --dep task-1 --dep task-2` |

### Task Operations
| Action | Command |
|--------|---------|
| View task | `backlog task 42 --plain` |
| List tasks | `backlog task list --plain` |
| Filter by status | `backlog task list -s "In Progress" --plain` |
| Filter by assignee | `backlog task list -a @sara --plain` |
| Archive task | `backlog task archive 42` |
| Demote to draft | `backlog task demote 42` |

---

## 10. Troubleshooting

### If You Accidentally Edited a File Directly

1. **DON'T PANIC** - But don't save or commit
2. Revert the changes
3. Make changes properly via CLI
4. If already saved, the metadata might be out of sync - use `backlog task edit` to fix

### Common Issues

| Problem | Solution |
|---------|----------|
| "Task not found" | Check task ID with `backlog task list --plain` |
| AC won't check | Use correct index: `backlog task 42 --plain` to see AC numbers |
| Changes not saving | Ensure you're using CLI, not editing files |
| Metadata out of sync | Re-edit via CLI to fix: `backlog task edit 42 -s <current-status>` |

---

## Remember: The Golden Rule

**🎯 If you want to change ANYTHING in a task, use the `backlog task edit` command.**
**📖 Only READ task files directly, never WRITE to them.**

Full help available: `backlog --help`

# === BACKLOG.MD GUIDELINES END ===

