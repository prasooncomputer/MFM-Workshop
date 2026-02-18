# Workshop Production Planning & Management System

A localhost-first **PHP + MySQL** finite-capacity production scheduler and shop-floor execution system designed for easy XAMPP deployment.

## What Changed

This version removes Node.js entirely and runs everything in PHP:

- Single PHP backend endpoint (`public/api.php`) for all business flows.
- Shared PHP scheduling helpers (`public/lib/scheduler.php`).
- MySQL access through PDO (`public/lib/db.php`).
- Existing UI screens retained and wired to the PHP backend.

## Features

- Product setup: products, reusable process definitions, routing dependencies, and BOM.
- Production planning preview: material requirement + process timeline before commit.
- Release order to production with dependency-linked tasks.
- Live task board with blocked/risk/paused status and 30-second auto refresh.
- Work logging per employee with real-time task progression.
- Delay risk detection: `actual < 85% expected`.
- Auto-rescheduling respecting manual locks, force start/deadline, worker assignments, and dependencies.
- Dashboard outputs: today’s tasks, dispatch prediction, delay alerts, worker productivity.

## Quick Start (XAMPP / localhost)

1. Create DB schema:
   ```bash
   mysql -u root -p < docs/schema.sql
   ```
2. Configure environment values (Apache `SetEnv` or shell export):
   - `DB_HOST` (default `127.0.0.1`)
   - `DB_PORT` (default `3306`)
   - `DB_NAME` (default `mfm_workshop`)
   - `DB_USER` (default `root`)
   - `DB_PASSWORD` (default empty)
3. Host the `public/` folder via Apache (XAMPP htdocs) or run:
   ```bash
   php -S localhost:8080 -t public
   ```
4. Open:
   - UI: `http://localhost:8080`
   - Health: `http://localhost:8080/api.php?action=health`

## Screen Mapping

- Screen 1: `product_setup.php`
- Screen 2: `planning.php`
- Screen 3: `task_board.php`
- Screen 4: `update_task.php`
- Screen 5: `override.php`

## Notes

- This is a production execution control board, not a full ERP.
- Workers should use the board as the authoritative "what to do now" source.
