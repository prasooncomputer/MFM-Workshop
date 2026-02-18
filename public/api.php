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

function out($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function db_error_response(Throwable $e): void {
    out(['ok' => false, 'error' => $e->getMessage(), 'setup_url' => '/install.php'], 500);
}

function sort_tasks(array &$rows): void {
    usort($rows, function($a, $b) {
        $r = priority_rank($b['priority_effective']) <=> priority_rank($a['priority_effective']);
        if ($r !== 0) return $r;
        return strtotime($a['planned_start']) <=> strtotime($b['planned_start']);
    });
}

function recompute_schedule(PDO $pdo): void {
    $tasks = $pdo->query("SELECT t.*, COALESCE(t.priority_override, o.priority) AS priority_effective FROM tasks t JOIN orders o ON o.id=t.order_id WHERE o.status IN ('released','in_progress')")->fetchAll();
    sort_tasks($tasks);

    $byId = [];
    foreach ($tasks as $t) $byId[$t['id']] = $t;

    $update = $pdo->prepare('UPDATE tasks SET planned_start=?, planned_end=? WHERE id=?');
    foreach ($tasks as $t) {
        if ((int)$t['manual_lock'] === 1 || (int)$t['pause_flag'] === 1) continue;

        $baseStart = new DateTimeImmutable($t['planned_start']);
        if (!empty($t['depends_on_task_id']) && isset($byId[$t['depends_on_task_id']])) {
            $baseStart = new DateTimeImmutable($byId[$t['depends_on_task_id']]['planned_end']);
        }

        $start = !empty($t['force_start']) ? new DateTimeImmutable($t['force_start']) : $baseStart;
        $remaining = max((int)$t['target_quantity'] - (int)$t['completed_quantity'], 0);
        $duration = minutes_for_task((float)($remaining ?: $t['target_quantity']), (float)$t['standard_time_minutes'], (float)$t['standard_output_rate'], (int)$t['workers_assigned']);
        $end = !empty($t['force_deadline']) ? new DateTimeImmutable($t['force_deadline']) : $start->modify("+{$duration} minutes");

        $update->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $t['id']]);
        $byId[$t['id']]['planned_start'] = $start->format('Y-m-d H:i:s');
        $byId[$t['id']]['planned_end'] = $end->format('Y-m-d H:i:s');
    }
}

try {
    $pdo = db();

    if ($action === 'health') {
        $row = $pdo->query('SELECT NOW() AS now')->fetch();
        out(['ok' => true, 'dbTime' => $row['now']]);
    }

    if ($action === 'products' && $method === 'GET') {
        out($pdo->query('SELECT * FROM products ORDER BY id DESC')->fetchAll());
    }

    if ($action === 'product_setup' && $method === 'POST') {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO products (name, sku) VALUES (?, ?)');
        $stmt->execute([$payload['name'], $payload['sku'] ?: null]);
        $productId = (int)$pdo->lastInsertId();

        $bomInsert = $pdo->prepare('INSERT INTO bom (product_id, material_name, qty_per_unit, unit) VALUES (?, ?, ?, ?)');
        foreach (($payload['bom'] ?? []) as $bom) {
            if (empty($bom['material_name'])) continue;
            $bomInsert->execute([$productId, $bom['material_name'], $bom['qty_per_unit'] ?? 0, $bom['unit'] ?? 'unit']);
        }

        $procInsert = $pdo->prepare('INSERT INTO product_process (product_id, process_name, sequence_no, depends_on_sequence_no, standard_time_minutes, standard_output_rate, can_parallel) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach (($payload['processes'] ?? []) as $proc) {
            if (empty($proc['process_name']) || empty($proc['sequence_no'])) continue;
            $procInsert->execute([
                $productId,
                $proc['process_name'],
                $proc['sequence_no'],
                $proc['depends_on_sequence_no'] ?: null,
                $proc['standard_time_minutes'] ?? 1,
                $proc['standard_output_rate'] ?? 1,
                !empty($proc['can_parallel']) ? 1 : 0,
            ]);
        }

        $pdo->commit();
        out(['ok' => true, 'id' => $productId], 201);
    }

    if ($action === 'planning_preview' && $method === 'POST') {
        $routing = $pdo->prepare('SELECT * FROM product_process WHERE product_id = ? ORDER BY sequence_no ASC');
        $routing->execute([$payload['product_id']]);
        $steps = $routing->fetchAll();

        $bomStmt = $pdo->prepare('SELECT material_name, qty_per_unit, unit FROM bom WHERE product_id = ?');
        $bomStmt->execute([$payload['product_id']]);
        $materials = array_map(function($m) use ($payload) {
            $m['required_qty'] = (float)$m['qty_per_unit'] * (float)$payload['quantity'];
            return $m;
        }, $bomStmt->fetchAll());

        $cursor = new DateTimeImmutable($payload['start_date']);
        $endBySeq = [];
        $timeline = [];

        foreach ($steps as $step) {
            $start = $cursor;
            if (!empty($step['depends_on_sequence_no']) && isset($endBySeq[$step['depends_on_sequence_no']])) {
                $start = $endBySeq[$step['depends_on_sequence_no']];
            }
            $duration = minutes_for_task((float)$payload['quantity'], (float)$step['standard_time_minutes'], (float)$step['standard_output_rate'], 1);
            $end = $start->modify("+{$duration} minutes");
            $timeline[] = [
                'process_id' => (int)$step['id'],
                'process_name' => $step['process_name'],
                'planned_start' => $start->format(DateTimeInterface::ATOM),
                'planned_end' => $end->format(DateTimeInterface::ATOM),
                'duration_min' => $duration,
            ];
            $endBySeq[$step['sequence_no']] = $end;
            if ((int)$step['can_parallel'] !== 1) $cursor = $end;
        }

        out(['materials' => $materials, 'timeline' => $timeline, 'expected_dispatch' => $timeline[count($timeline)-1]['planned_end'] ?? $payload['start_date']]);
    }

    if ($action === 'orders_release' && $method === 'POST') {
        $pdo->beginTransaction();
        $o = $pdo->prepare('INSERT INTO orders (product_id, quantity, start_date, priority, status) VALUES (?, ?, ?, ?, ?)');
        $o->execute([$payload['product_id'], $payload['quantity'], $payload['start_date'], $payload['priority'] ?? 'normal', 'released']);
        $orderId = (int)$pdo->lastInsertId();

        $routing = $pdo->prepare('SELECT * FROM product_process WHERE product_id = ? ORDER BY sequence_no ASC');
        $routing->execute([$payload['product_id']]);
        $steps = $routing->fetchAll();

        $cursor = new DateTimeImmutable($payload['start_date']);
        $taskBySeq = [];
        $taskEnd = [];
        $insert = $pdo->prepare('INSERT INTO tasks (order_id, product_process_id, process_name, target_quantity, workers_assigned, standard_time_minutes, standard_output_rate, can_parallel, planned_start, planned_end, status, depends_on_task_id, sequence_no) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($steps as $step) {
            $depTask = null;
            $start = $cursor;
            if (!empty($step['depends_on_sequence_no']) && isset($taskBySeq[$step['depends_on_sequence_no']])) {
                $depTask = $taskBySeq[$step['depends_on_sequence_no']];
                $start = $taskEnd[$depTask];
            }
            $duration = minutes_for_task((float)$payload['quantity'], (float)$step['standard_time_minutes'], (float)$step['standard_output_rate'], 1);
            $end = $start->modify("+{$duration} minutes");
            $insert->execute([
                $orderId,
                $step['id'],
                $step['process_name'],
                $payload['quantity'],
                1,
                $step['standard_time_minutes'],
                $step['standard_output_rate'],
                $step['can_parallel'],
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                'pending',
                $depTask,
                $step['sequence_no'],
            ]);
            $taskId = (int)$pdo->lastInsertId();
            $taskBySeq[$step['sequence_no']] = $taskId;
            $taskEnd[$taskId] = $end;
            if ((int)$step['can_parallel'] !== 1) $cursor = $end;
        }

        $pdo->commit();
        out(['order_id' => $orderId], 201);
    }

    if ($action === 'tasks_today' && $method === 'GET') {
        $rows = $pdo->query("SELECT t.*, o.priority, COALESCE(t.priority_override, o.priority) AS priority_effective, dt.completed_quantity AS dep_completed, dt.target_quantity AS dep_target FROM tasks t JOIN orders o ON o.id=t.order_id LEFT JOIN tasks dt ON dt.id=t.depends_on_task_id WHERE DATE(t.planned_start) <= CURDATE() AND o.status IN ('released','in_progress')")->fetchAll();
        sort_tasks($rows);

        $result = [];
        foreach ($rows as $task) {
            $blocked = !empty($task['depends_on_task_id']) && (int)$task['dep_completed'] < (int)$task['dep_target'];
            $progress = classify_status($task);
            $status = $task['status'];
            if ((int)$task['pause_flag'] === 1) $status = 'paused';
            elseif ($blocked) $status = 'blocked';
            elseif ($progress['state'] === 'risk') $status = 'risk';
            $task['status'] = $status;
            $task['expected_progress'] = $progress['expected'];
            $result[] = $task;
        }
        out($result);
    }

    if ($action === 'logs' && $method === 'POST') {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO logs (task_id, employee_name, output_qty, reject_qty, hours_worked) VALUES (?, ?, ?, ?, ?)')
            ->execute([$payload['task_id'], $payload['employee_name'], $payload['output_qty'], $payload['reject_qty'] ?? 0, $payload['hours_worked']]);
        $task = $pdo->prepare('SELECT completed_quantity, target_quantity FROM tasks WHERE id = ?');
        $task->execute([$payload['task_id']]);
        $t = $task->fetch();
        $done = ((int)$t['completed_quantity'] + (int)$payload['output_qty']) >= (int)$t['target_quantity'];
        $pdo->prepare('UPDATE tasks SET completed_quantity = completed_quantity + ?, reject_quantity = reject_quantity + ?, status = ?, actual_start = COALESCE(actual_start, NOW()), actual_end = ? WHERE id = ?')
            ->execute([$payload['output_qty'], $payload['reject_qty'] ?? 0, $done ? 'completed' : 'in_progress', $done ? date('Y-m-d H:i:s') : null, $payload['task_id']]);
        $pdo->commit();
        out(['ok' => true], 201);
    }

    if ($action === 'override' && $method === 'POST') {
        $taskId = (int)($_GET['task_id'] ?? 0);
        if ($taskId < 1) out(['error' => 'task_id missing'], 422);
        $pdo->prepare('UPDATE tasks SET workers_assigned = COALESCE(?, workers_assigned), force_start = COALESCE(?, force_start), force_deadline = COALESCE(?, force_deadline), pause_flag = COALESCE(?, pause_flag), manual_lock = COALESCE(?, manual_lock), priority_override = COALESCE(?, priority_override) WHERE id=?')
            ->execute([$payload['workers_assigned'] ?? null, $payload['force_start'] ?? null, $payload['force_deadline'] ?? null, $payload['pause_flag'] ?? null, $payload['manual_lock'] ?? null, $payload['priority_override'] ?? null, $taskId]);
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
} catch (RuntimeException $e) {
    db_error_response($e);
} catch (Throwable $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 500);
}
