const express = require('express');
const cors = require('cors');
const dayjs = require('dayjs');
require('dotenv').config();

const db = require('./db');
const { minutesForTask, classifyProgress, sortByPriority } = require('./lib/scheduler');

const app = express();
app.use(cors());
app.use(express.json());

app.get('/health', async (_req, res) => {
  const [[row]] = await db.query('SELECT NOW() AS now');
  res.json({ ok: true, dbTime: row.now });
});

app.get('/api/products', async (_req, res) => {
  const [rows] = await db.query('SELECT * FROM products ORDER BY id DESC');
  res.json(rows);
});

app.post('/api/products', async (req, res) => {
  const { name, sku } = req.body;
  const [result] = await db.query('INSERT INTO products (name, sku) VALUES (?, ?)', [name, sku || null]);
  res.status(201).json({ id: result.insertId });
});

app.post('/api/processes', async (req, res) => {
  const { name, standard_time_minutes, standard_output_rate, can_parallel } = req.body;
  const [result] = await db.query(
    'INSERT INTO processes (name, standard_time_minutes, standard_output_rate, can_parallel) VALUES (?, ?, ?, ?)',
    [name, standard_time_minutes, standard_output_rate, can_parallel ? 1 : 0]
  );
  res.status(201).json({ id: result.insertId });
});

app.get('/api/processes', async (_req, res) => {
  const [rows] = await db.query('SELECT * FROM processes ORDER BY id DESC');
  res.json(rows);
});

app.post('/api/product-process', async (req, res) => {
  const { product_id, process_id, sequence_no, depends_on_process_id } = req.body;
  await db.query(
    'INSERT INTO product_process (product_id, process_id, sequence_no, depends_on_process_id) VALUES (?, ?, ?, ?)',
    [product_id, process_id, sequence_no, depends_on_process_id || null]
  );
  res.status(201).json({ ok: true });
});

app.post('/api/bom', async (req, res) => {
  const { product_id, material_name, qty_per_unit, unit } = req.body;
  await db.query('INSERT INTO bom (product_id, material_name, qty_per_unit, unit) VALUES (?, ?, ?, ?)', [
    product_id,
    material_name,
    qty_per_unit,
    unit,
  ]);
  res.status(201).json({ ok: true });
});

app.post('/api/planning/preview', async (req, res) => {
  const { product_id, quantity, start_date, priority = 'normal' } = req.body;
  const [routing] = await db.query(
    `SELECT pp.sequence_no, pp.depends_on_process_id, p.id as process_id, p.name, p.standard_time_minutes, p.standard_output_rate, p.can_parallel
     FROM product_process pp JOIN processes p ON p.id = pp.process_id
     WHERE pp.product_id = ? ORDER BY pp.sequence_no ASC`,
    [product_id]
  );

  const [bomRows] = await db.query('SELECT material_name, qty_per_unit, unit FROM bom WHERE product_id = ?', [product_id]);
  const materials = bomRows.map((m) => ({ ...m, required_qty: Number(m.qty_per_unit) * Number(quantity) }));

  const timeline = [];
  const processEndMap = new Map();
  let cursor = dayjs(start_date);

  for (const step of routing) {
    const depEnd = step.depends_on_process_id ? processEndMap.get(step.depends_on_process_id) : null;
    const plannedStart = depEnd ? dayjs(depEnd) : cursor;
    const durationMin = minutesForTask(quantity, step.standard_time_minutes, step.standard_output_rate, 1);
    const plannedEnd = plannedStart.add(durationMin, 'minute');
    timeline.push({
      process_id: step.process_id,
      process_name: step.name,
      planned_start: plannedStart.toISOString(),
      planned_end: plannedEnd.toISOString(),
      duration_min: durationMin,
      can_parallel: !!step.can_parallel,
      depends_on_process_id: step.depends_on_process_id,
      priority,
    });
    processEndMap.set(step.process_id, plannedEnd.toISOString());
    if (!step.can_parallel) cursor = plannedEnd;
  }

  res.json({ materials, timeline, expected_dispatch: timeline[timeline.length - 1]?.planned_end || start_date });
});

app.post('/api/orders/release', async (req, res) => {
  const { product_id, quantity, start_date, priority = 'normal' } = req.body;
  const conn = await db.getConnection();
  try {
    await conn.beginTransaction();
    const [orderResult] = await conn.query(
      'INSERT INTO orders (product_id, quantity, start_date, priority, status) VALUES (?, ?, ?, ?, ?)',
      [product_id, quantity, start_date, priority, 'released']
    );
    const orderId = orderResult.insertId;

    const [routing] = await conn.query(
      `SELECT pp.sequence_no, pp.depends_on_process_id, p.id as process_id, p.standard_time_minutes, p.standard_output_rate, p.can_parallel
       FROM product_process pp JOIN processes p ON p.id = pp.process_id
       WHERE pp.product_id = ? ORDER BY pp.sequence_no ASC`,
      [product_id]
    );

    let cursor = dayjs(start_date);
    const taskIdsByProcess = new Map();
    for (const step of routing) {
      const depTaskId = step.depends_on_process_id ? taskIdsByProcess.get(step.depends_on_process_id) : null;
      const [depTask] = depTaskId ? await conn.query('SELECT planned_end FROM tasks WHERE id = ?', [depTaskId]) : [[]];
      const plannedStart = depTaskId && depTask[0]?.planned_end ? dayjs(depTask[0].planned_end) : cursor;
      const duration = minutesForTask(quantity, step.standard_time_minutes, step.standard_output_rate, 1);
      const plannedEnd = plannedStart.add(duration, 'minute');
      const [taskResult] = await conn.query(
        `INSERT INTO tasks
         (order_id, process_id, target_quantity, workers_assigned, planned_start, planned_end, status, depends_on_task_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
        [orderId, step.process_id, quantity, 1, plannedStart.format('YYYY-MM-DD HH:mm:ss'), plannedEnd.format('YYYY-MM-DD HH:mm:ss'), 'pending', depTaskId || null]
      );
      taskIdsByProcess.set(step.process_id, taskResult.insertId);
      if (!step.can_parallel) cursor = plannedEnd;
    }

    await conn.commit();
    res.status(201).json({ order_id: orderId });
  } catch (e) {
    await conn.rollback();
    res.status(500).json({ error: e.message });
  } finally {
    conn.release();
  }
});

app.get('/api/tasks/today', async (_req, res) => {
  const [rows] = await db.query(
    `SELECT t.*, p.name as process_name, o.priority,
            COALESCE(t.priority_override, o.priority) AS priority_effective,
            dt.completed_quantity as dep_completed, dt.target_quantity as dep_target
     FROM tasks t
     JOIN processes p ON p.id = t.process_id
     JOIN orders o ON o.id = t.order_id
     LEFT JOIN tasks dt ON dt.id = t.depends_on_task_id
     WHERE DATE(t.planned_start) <= CURDATE() AND o.status IN ('released','in_progress')
     ORDER BY t.planned_start ASC`
  );

  const enriched = sortByPriority(rows).map((task) => {
    const block = task.depends_on_task_id && Number(task.dep_completed || 0) < Number(task.dep_target || 0);
    const progress = classifyProgress(task);
    let status = task.status;
    if (task.pause_flag) status = 'paused';
    else if (block) status = 'blocked';
    else if (progress.state === 'risk') status = 'risk';
    return { ...task, status, expected_progress: progress.expected };
  });

  res.json(enriched);
});

app.post('/api/logs', async (req, res) => {
  const { task_id, employee_name, output_qty, reject_qty = 0, hours_worked } = req.body;
  const conn = await db.getConnection();
  try {
    await conn.beginTransaction();
    await conn.query(
      'INSERT INTO logs (task_id, employee_name, output_qty, reject_qty, hours_worked) VALUES (?, ?, ?, ?, ?)',
      [task_id, employee_name, output_qty, reject_qty, hours_worked]
    );
    await conn.query(
      'UPDATE tasks SET completed_quantity = completed_quantity + ?, reject_quantity = reject_quantity + ?, status = IF(completed_quantity + ? >= target_quantity, "completed", "in_progress"), actual_start = COALESCE(actual_start, NOW()), actual_end = IF(completed_quantity + ? >= target_quantity, NOW(), actual_end) WHERE id = ?',
      [output_qty, reject_qty, output_qty, output_qty, task_id]
    );
    await conn.commit();
    res.status(201).json({ ok: true });
  } catch (e) {
    await conn.rollback();
    res.status(500).json({ error: e.message });
  } finally {
    conn.release();
  }
});

app.post('/api/tasks/:id/override', async (req, res) => {
  const { workers_assigned, force_start, force_deadline, pause_flag, manual_lock, priority_override } = req.body;
  await db.query(
    `UPDATE tasks SET workers_assigned = COALESCE(?, workers_assigned),
      force_start = COALESCE(?, force_start),
      force_deadline = COALESCE(?, force_deadline),
      pause_flag = COALESCE(?, pause_flag),
      manual_lock = COALESCE(?, manual_lock),
      priority_override = COALESCE(?, priority_override)
      WHERE id = ?`,
    [
      workers_assigned ?? null,
      force_start ?? null,
      force_deadline ?? null,
      pause_flag ?? null,
      manual_lock ?? null,
      priority_override ?? null,
      req.params.id,
    ]
  );
  await recomputeSchedule();
  res.json({ ok: true });
});

async function recomputeSchedule() {
  const [tasks] = await db.query(
    `SELECT t.*, p.standard_time_minutes, p.standard_output_rate, p.can_parallel,
            COALESCE(t.priority_override, o.priority) AS priority_effective
     FROM tasks t
     JOIN processes p ON p.id = t.process_id
     JOIN orders o ON o.id = t.order_id
     WHERE o.status IN ('released','in_progress')`
  );
  const unlocked = sortByPriority(tasks.filter((t) => !t.manual_lock));
  const byId = new Map(tasks.map((t) => [t.id, t]));

  for (const t of unlocked) {
    if (t.pause_flag) continue;
    const dep = t.depends_on_task_id ? byId.get(t.depends_on_task_id) : null;
    const baseStart = dep?.planned_end ? dayjs(dep.planned_end) : dayjs(t.planned_start);
    const start = t.force_start ? dayjs(t.force_start) : baseStart;
    const remain = Math.max(Number(t.target_quantity) - Number(t.completed_quantity || 0), 0);
    const duration = minutesForTask(remain || t.target_quantity, t.standard_time_minutes, t.standard_output_rate, t.workers_assigned);
    const end = t.force_deadline ? dayjs(t.force_deadline) : start.add(duration, 'minute');
    await db.query('UPDATE tasks SET planned_start = ?, planned_end = ? WHERE id = ?', [
      start.format('YYYY-MM-DD HH:mm:ss'),
      end.format('YYYY-MM-DD HH:mm:ss'),
      t.id,
    ]);
    byId.set(t.id, { ...t, planned_start: start.toISOString(), planned_end: end.toISOString() });
  }
}

app.get('/api/dashboard/summary', async (_req, res) => {
  const [[today]] = await db.query(
    `SELECT COUNT(*) as today_tasks,
            SUM(status='blocked') as blocked,
            SUM(status='risk') as risk,
            SUM(status='delayed') as delayed
     FROM tasks WHERE DATE(planned_start)=CURDATE()`
  );
  const [[dispatch]] = await db.query(
    `SELECT MAX(planned_end) as expected_dispatch FROM tasks t
     JOIN orders o ON o.id = t.order_id WHERE o.status IN ('released','in_progress')`
  );
  const [worker] = await db.query(
    `SELECT employee_name, SUM(output_qty) as output_qty, SUM(hours_worked) as hours_worked,
            ROUND(SUM(output_qty)/NULLIF(SUM(hours_worked),0),2) as productivity
     FROM logs GROUP BY employee_name ORDER BY productivity DESC LIMIT 10`
  );
  res.json({ today, expected_dispatch: dispatch.expected_dispatch, worker_productivity: worker });
});

const port = Number(process.env.API_PORT || 4000);
app.listen(port, () => console.log(`API running on http://localhost:${port}`));
