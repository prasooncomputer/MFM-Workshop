<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/scheduler.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$pdo = null;

function out($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function to_sql_datetime(string $iso): string {
    $d = new DateTimeImmutable($iso);
    return $d->format('Y-m-d H:i:s');
}

try {
    $pdo = db();
    if ($action === 'health') {
        $now = $pdo->query('SELECT NOW() as now')->fetch();
        out(['ok' => true, 'dbTime' => $now['now']]);
    }

    if ($action === 'products' && $method === 'GET') {
        out($pdo->query('SELECT * FROM products ORDER BY id DESC')->fetchAll());
    }
    if ($action === 'products' && $method === 'POST') {
        $stmt = $pdo->prepare('INSERT INTO products (name, sku) VALUES (?, ?)');
        $stmt->execute([$payload['name'], $payload['sku'] ?: null]);
        out(['id' => (int)$pdo->lastInsertId()], 201);
    }

    if ($action === 'processes' && $method === 'GET') {
        out($pdo->query('SELECT * FROM processes ORDER BY id DESC')->fetchAll());
    }
    if ($action === 'processes' && $method === 'POST') {
        $stmt = $pdo->prepare('INSERT INTO processes (name, standard_time_minutes, standard_output_rate, can_parallel) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $payload['name'],
            $payload['standard_time_minutes'],
            $payload['standard_output_rate'],
            !empty($payload['can_parallel']) ? 1 : 0,
        ]);
        out(['id' => (int)$pdo->lastInsertId()], 201);
    }

    if ($action === 'product_process' && $method === 'POST') {
        $stmt = $pdo->prepare('INSERT INTO product_process (product_id, process_id, sequence_no, depends_on_process_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $payload['product_id'],
            $payload['process_id'],
            $payload['sequence_no'],
            $payload['depends_on_process_id'] ?: null,
        ]);
        out(['ok' => true], 201);
    }

    if ($action === 'bom' && $method === 'POST') {
        $stmt = $pdo->prepare('INSERT INTO bom (product_id, material_name, qty_per_unit, unit) VALUES (?, ?, ?, ?)');
        $stmt->execute([$payload['product_id'], $payload['material_name'], $payload['qty_per_unit'], $payload['unit']]);
        out(['ok' => true], 201);
    }

    if ($action === 'planning_preview' && $method === 'POST') {
        $routingStmt = $pdo->prepare('SELECT pp.sequence_no, pp.depends_on_process_id, p.id AS process_id, p.name, p.standard_time_minutes, p.standard_output_rate, p.can_parallel FROM product_process pp JOIN processes p ON p.id = pp.process_id WHERE pp.product_id = ? ORDER BY pp.sequence_no ASC');
        $routingStmt->execute([$payload['product_id']]);
        $routing = $routingStmt->fetchAll();

        $bomStmt = $pdo->prepare('SELECT material_name, qty_per_unit, unit FROM bom WHERE product_id = ?');
        $bomStmt->execute([$payload['product_id']]);
        $bom = $bomStmt->fetchAll();

        $materials = array_map(function($m) use ($payload) {
            $m['required_qty'] = (float)$m['qty_per_unit'] * (float)$payload['quantity'];
            return $m;
        }, $bom);

        $cursor = new DateTimeImmutable($payload['start_date']);
        $endByProcess = [];
        $timeline = [];
        foreach ($routing as $step) {
            $start = $cursor;
            if (!empty($step['depends_on_process_id']) && isset($endByProcess[$step['depends_on_process_id']])) {
                $start = $endByProcess[$step['depends_on_process_id']];
            }

            $duration = minutes_for_task((float)$payload['quantity'], (float)$step['standard_time_minutes'], (float)$step['standard_output_rate'], 1);
            $end = $start->modify("+{$duration} minutes");
            $timeline[] = [
                'process_id' => (int)$step['process_id'],
                'process_name' => $step['name'],
                'planned_start' => $start->format(DateTimeInterface::ATOM),
                'planned_end' => $end->format(DateTimeInterface::ATOM),
                'duration_min' => $duration,
                'can_parallel' => (bool)$step['can_parallel'],
                'depends_on_process_id' => $step['depends_on_process_id'] ? (int)$step['depends_on_process_id'] : null,
            ];
            $endByProcess[$step['process_id']] = $end;
            if (!(bool)$step['can_parallel']) {
                $cursor = $end;
            }
        }

        $expectedDispatch = !empty($timeline) ? $timeline[count($timeline) - 1]['planned_end'] : $payload['start_date'];
        out(['materials' => $materials, 'timeline' => $timeline, 'expected_dispatch' => $expectedDispatch]);
    }

    if ($action === 'orders_release' && $method === 'POST') {
        $pdo->beginTransaction();
        $orderStmt = $pdo->prepare('INSERT INTO orders (product_id, quantity, start_date, priority, status) VALUES (?, ?, ?, ?, ?)');
        $orderStmt->execute([$payload['product_id'], $payload['quantity'], $payload['start_date'], $payload['priority'] ?? 'normal', 'released']);
        $orderId = (int)$pdo->lastInsertId();

        $routingStmt = $pdo->prepare('SELECT pp.sequence_no, pp.depends_on_process_id, p.id AS process_id, p.standard_time_minutes, p.standard_output_rate, p.can_parallel FROM product_process pp JOIN processes p ON p.id = pp.process_id WHERE pp.product_id = ? ORDER BY pp.sequence_no ASC');
        $routingStmt->execute([$payload['product_id']]);
        $routing = $routingStmt->fetchAll();

        $cursor = new DateTimeImmutable($payload['start_date']);
        $taskIdByProcess = [];
        $taskEndById = [];

        $taskStmt = $pdo->prepare('INSERT INTO tasks (order_id, process_id, target_quantity, workers_assigned, planned_start, planned_end, status, depends_on_task_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($routing as $step) {
            $depTaskId = null;
            $start = $cursor;
            if (!empty($step['depends_on_process_id']) && isset($taskIdByProcess[$step['depends_on_process_id']])) {
                $depTaskId = $taskIdByProcess[$step['depends_on_process_id']];
                $start = $taskEndById[$depTaskId];
            }
            $duration = minutes_for_task((float)$payload['quantity'], (float)$step['standard_time_minutes'], (float)$step['standard_output_rate'], 1);
            $end = $start->modify("+{$duration} minutes");
            $taskStmt->execute([
                $orderId,
                $step['process_id'],
                $payload['quantity'],
                1,
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                'pending',
                $depTaskId,
            ]);
            $newTaskId = (int)$pdo->lastInsertId();
            $taskIdByProcess[$step['process_id']] = $newTaskId;
            $taskEndById[$newTaskId] = $end;
            if (!(bool)$step['can_parallel']) {
                $cursor = $end;
            }
        }

        $pdo->commit();
        out(['order_id' => $orderId], 201);
    }

    if ($action === 'tasks_today' && $method === 'GET') {
        $sql = "SELECT t.*, p.name AS process_name, o.priority, COALESCE(t.priority_override, o.priority) AS priority_effective, dt.completed_quantity AS dep_completed, dt.target_quantity AS dep_target
                FROM tasks t
                JOIN processes p ON p.id = t.process_id
                JOIN orders o ON o.id = t.order_id
                LEFT JOIN tasks dt ON dt.id = t.depends_on_task_id
                WHERE DATE(t.planned_start) <= CURDATE() AND o.status IN ('released','in_progress')";
        $rows = $pdo->query($sql)->fetchAll();

        usort($rows, function($a, $b) {
            $r = priority_rank($b['priority_effective']) <=> priority_rank($a['priority_effective']);
            if ($r !== 0) return $r;
            return strtotime($a['planned_start']) <=> strtotime($b['planned_start']);
        });

        $outRows = [];
        foreach ($rows as $task) {
            $blocked = !empty($task['depends_on_task_id']) && (int)$task['dep_completed'] < (int)$task['dep_target'];
            $progress = classify_status($task);
            $status = $task['status'];
            if ((int)$task['pause_flag'] === 1) {
                $status = 'paused';
            } elseif ($blocked) {
                $status = 'blocked';
            } elseif ($progress['state'] === 'risk') {
                $status = 'risk';
            }
            $task['status'] = $status;
            $task['expected_progress'] = $progress['expected'];
            $outRows[] = $task;
        }
        out($outRows);
    }

    if ($action === 'logs' && $method === 'POST') {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO logs (task_id, employee_name, output_qty, reject_qty, hours_worked) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$payload['task_id'], $payload['employee_name'], $payload['output_qty'], $payload['reject_qty'] ?? 0, $payload['hours_worked']]);

        $task = $pdo->prepare('SELECT completed_quantity, target_quantity FROM tasks WHERE id = ?');
        $task->execute([$payload['task_id']]);
        $t = $task->fetch();
        $newCompleted = (int)$t['completed_quantity'] + (int)$payload['output_qty'];
        $completed = $newCompleted >= (int)$t['target_quantity'];

        $update = $pdo->prepare('UPDATE tasks SET completed_quantity = completed_quantity + ?, reject_quantity = reject_quantity + ?, status = ?, actual_start = COALESCE(actual_start, NOW()), actual_end = ? WHERE id = ?');
        $update->execute([
            $payload['output_qty'],
            $payload['reject_qty'] ?? 0,
            $completed ? 'completed' : 'in_progress',
            $completed ? date('Y-m-d H:i:s') : null,
            $payload['task_id'],
        ]);
        $pdo->commit();
        out(['ok' => true], 201);
    }

    if ($action === 'override' && $method === 'POST') {
        $taskId = (int)($_GET['task_id'] ?? 0);
        if ($taskId <= 0) out(['error' => 'task_id missing'], 422);

        $update = $pdo->prepare('UPDATE tasks SET workers_assigned = COALESCE(?, workers_assigned), force_start = COALESCE(?, force_start), force_deadline = COALESCE(?, force_deadline), pause_flag = COALESCE(?, pause_flag), manual_lock = COALESCE(?, manual_lock), priority_override = COALESCE(?, priority_override) WHERE id = ?');
        $update->execute([
            $payload['workers_assigned'] ?? null,
            $payload['force_start'] ?? null,
            $payload['force_deadline'] ?? null,
            $payload['pause_flag'] ?? null,
            $payload['manual_lock'] ?? null,
            $payload['priority_override'] ?? null,
            $taskId,
        ]);

        recompute_schedule($pdo);
        out(['ok' => true]);
    }

    if ($action === 'dashboard_summary' && $method === 'GET') {
        $today = $pdo->query("SELECT COUNT(*) AS today_tasks, SUM(status='blocked') AS blocked, SUM(status='risk') AS risk, SUM(status='delayed') AS delayed FROM tasks WHERE DATE(planned_start)=CURDATE()")->fetch();
        $dispatch = $pdo->query("SELECT MAX(t.planned_end) AS expected_dispatch FROM tasks t JOIN orders o ON o.id=t.order_id WHERE o.status IN ('released','in_progress')")->fetch();
        $workers = $pdo->query("SELECT employee_name, SUM(output_qty) AS output_qty, SUM(hours_worked) AS hours_worked, ROUND(SUM(output_qty)/NULLIF(SUM(hours_worked),0),2) AS productivity FROM logs GROUP BY employee_name ORDER BY productivity DESC LIMIT 10")->fetchAll();
        out(['today' => $today, 'expected_dispatch' => $dispatch['expected_dispatch'], 'worker_productivity' => $workers]);
    }

    out(['error' => 'Not found'], 404);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    out(['error' => $e->getMessage()], 500);
}

function recompute_schedule(PDO $pdo): void {
    $sql = "SELECT t.*, p.standard_time_minutes, p.standard_output_rate, p.can_parallel, COALESCE(t.priority_override, o.priority) AS priority_effective
            FROM tasks t JOIN processes p ON p.id=t.process_id JOIN orders o ON o.id=t.order_id
            WHERE o.status IN ('released','in_progress')";
    $tasks = $pdo->query($sql)->fetchAll();

    usort($tasks, function($a, $b) {
        $r = priority_rank($b['priority_effective']) <=> priority_rank($a['priority_effective']);
        if ($r !== 0) return $r;
        return strtotime($a['planned_start']) <=> strtotime($b['planned_start']);
    });

    $byId = [];
    foreach ($tasks as $t) {
        $byId[$t['id']] = $t;
    }

    $update = $pdo->prepare('UPDATE tasks SET planned_start = ?, planned_end = ? WHERE id = ?');

    foreach ($tasks as $task) {
        if ((int)$task['manual_lock'] === 1 || (int)$task['pause_flag'] === 1) {
            continue;
        }

        $baseStart = new DateTimeImmutable($task['planned_start']);
        if (!empty($task['depends_on_task_id']) && isset($byId[$task['depends_on_task_id']])) {
            $baseStart = new DateTimeImmutable($byId[$task['depends_on_task_id']]['planned_end']);
        }

        $start = !empty($task['force_start']) ? new DateTimeImmutable($task['force_start']) : $baseStart;
        $remaining = max((int)$task['target_quantity'] - (int)$task['completed_quantity'], 0);
        $duration = minutes_for_task((float)($remaining ?: $task['target_quantity']), (float)$task['standard_time_minutes'], (float)$task['standard_output_rate'], (int)$task['workers_assigned']);
        $end = !empty($task['force_deadline']) ? new DateTimeImmutable($task['force_deadline']) : $start->modify("+{$duration} minutes");

        $update->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $task['id']]);
        $byId[$task['id']]['planned_start'] = $start->format('Y-m-d H:i:s');
        $byId[$task['id']]['planned_end'] = $end->format('Y-m-d H:i:s');
    }
}
