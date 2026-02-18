# Workshop Production Planning & Management System

A localhost-first **PHP + MySQL** finite-capacity production scheduler and shop-floor execution system designed for easy XAMPP deployment.

## Key Updates in this Revision

- Added a built-in **Installer** (`public/install.php`) to collect DB host/port/name/user/password.
- Installer validates connection, creates database (if needed), and initializes tables from `docs/schema.sql`.
- Added explicit **database connection error feedback** in both API responses and UI banner.
- Updated product modeling so **BOM and Processes are product attributes** entered in one Product Setup form.

## Features

- Product setup in one flow: Product + BOM + Process attributes (sequence, dependency, output rate, standard time, parallel flag).
- Production planning preview: material requirement + process timeline before release.
- Release order to production with dependency-linked tasks.
- Live task board with blocked/risk/paused status and 30-second refresh.
- Work logging per employee with real-time task progression.
- Delay risk detection: `actual < 85% expected`.
- Auto-rescheduling respecting manual locks, force start/deadline, worker assignments, and dependencies.
- Dashboard outputs: today’s tasks, dispatch prediction, delay alerts, worker productivity.

## Quick Start (XAMPP / localhost)

1. Place project under web root and serve `public/`.
2. Open installer:
   - `http://localhost:8080/install.php`
3. Enter DB credentials and click **Save & Initialize**.
4. Use app:
   - Dashboard: `http://localhost:8080/index.php`
   - Health: `http://localhost:8080/api.php?action=health`

## Screen Mapping

- Screen 1: `product_setup.php`
- Screen 2: `planning.php`
- Screen 3: `task_board.php`
- Screen 4: `update_task.php`
- Screen 5: `override.php`
- Installer: `install.php`

## Notes

- This is a production execution control board, not a full ERP.
- Workers should use the board as the authoritative "what to do now" source.
