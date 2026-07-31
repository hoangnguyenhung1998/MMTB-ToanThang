# AGENTS.md

This repository is maintained with Codex.

Read this file BEFORE writing code.

---

# Project

Machine Management System (MMTB)

Laravel 11

MySQL

Blade

No Livewire.

No Inertia.

---

# Architecture

Database

↓

Service

↓

Controller

↓

Blade

Business logic MUST stay inside Services.

Controllers should remain thin.

---

# Coding Style

- Laravel conventions
- Small methods
- Clear variable names
- Dependency Injection
- Route Model Binding
- Transactions for critical updates

---

# Business Rules

MySQL is source of truth.

Excel is export only.

OCR is an external Python application.

Never merge OCR into Laravel.

Never store absolute image paths.

History is immutable.

Never overwrite:

- machine_events
- machine_driver_histories
- assignments

Always create new history.

---

# Git Rules

Current branch:

feature/phase-11-reconciliation

Never commit directly to main.

Small logical commits only.

One feature = one commit.

---

# Scope

Only implement requested phase.

Never refactor unrelated modules.

Never rename database columns without approval.

Never remove existing routes.

---

# Before Coding

Always:

- Read related models.
- Read migrations.
- Read routes.
- Read services.
- Read policies.
- Read Blade views.

Understand existing architecture first.

---

# Before Modifying

Show implementation plan.

Wait for approval.


---

# Commit Message

Use:

Phase11: ...

Examples:

Phase11: add reconciliation service

Phase11: implement review workflow

Phase11: add export excel

---

# Never

Do not invent business rules.

If something is unclear,

STOP

and ask.
## Testing Safety

Never execute:

php artisan migrate:fresh

php artisan db:wipe

php artisan migrate:refresh

against the development database.

Before running tests:

Verify that the testing environment is NOT using the development database.

Never modify or destroy development data.

# Database Safety

This project contains production-like development data.

Never execute any command that changes database schema
or database contents unless explicitly approved.

Forbidden commands

- php artisan migrate
- php artisan migrate:fresh
- php artisan migrate:refresh
- php artisan db:wipe
- php artisan db:seed
- php artisan migrate --seed

Before running ANY artisan command

show the exact command first.

Wait for approval.

Never assume approval.

Always recommend creating a SQL backup before
database related operations.