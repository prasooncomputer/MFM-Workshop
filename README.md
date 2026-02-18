# Workshop Production Planning & Management System

A localhost-first **PHP + Node.js + MySQL** finite-capacity production scheduler and shop-floor execution system.

## Features Implemented

- Product setup: products, reusable process definitions, routing dependencies, and BOM.
- Production planning preview: material requirement + timeline before commit.
- Release order to production: generates tasks with dependency links.
- Live task board: today’s actionable tasks with risk/blocked/paused status.
- Work logging: per employee output/reject/hours updates task progress.
- Delay prediction: marks task as `risk` when actual < 85% expected.
- Auto rescheduling engine: re-computes unlocked tasks after overrides/log changes.
- Manual manager override: workers, forced start/deadline, pause, lock, priority override.
- Dashboard summary: dispatch prediction, blocked/risk counts, worker productivity.

## Stack

- Node.js + Express API (`api/`)
- MySQL database (`docs/schema.sql`)
- PHP UI (`public/`) with mobile-friendly simple pages

## Quick Start (localhost)

1. Create DB schema:
   ```bash
   mysql -u root -p < docs/schema.sql
   ```
2. Install Node dependencies:
   ```bash
   npm install
   ```
3. Create env file:
   ```bash
   cp .env.example .env
   ```
4. Start API:
   ```bash
   npm start
   ```
5. Start PHP UI server:
   ```bash
   php -S localhost:8080 -t public
   ```
6. Open app:
   - UI: http://localhost:8080
   - API health: http://localhost:4000/health

## Screen Mapping

- Screen 1: `product_setup.php`
- Screen 2: `planning.php`
- Screen 3: `task_board.php`
- Screen 4: `update_task.php`
- Screen 5: `override.php`

## Notes

- This system focuses on **live execution control**, not full ERP accounting.
- Task board is intended as the authoritative instruction source for workers.
