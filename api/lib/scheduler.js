const dayjs = require('dayjs');

function minutesForTask(quantity, standardTimeMinutes, standardOutputRate, workers = 1) {
  const base = (quantity / standardOutputRate) * standardTimeMinutes;
  return Math.max(1, Math.ceil(base / Math.max(workers, 1)));
}

function expectedProgress(targetQuantity, plannedStart, plannedEnd, now = dayjs()) {
  const start = dayjs(plannedStart);
  const end = dayjs(plannedEnd);
  if (now.isBefore(start)) return 0;
  const totalMin = Math.max(end.diff(start, 'minute'), 1);
  const elapsedMin = Math.min(Math.max(now.diff(start, 'minute'), 0), totalMin);
  return Math.round((elapsedMin / totalMin) * targetQuantity);
}

function classifyProgress(task, now = dayjs()) {
  const expected = expectedProgress(task.target_quantity, task.planned_start, task.planned_end, now);
  const actual = Number(task.completed_quantity || 0);
  if (task.status === 'completed') return { expected, actual, state: 'completed' };
  if (actual < 0.85 * expected) {
    return { expected, actual, state: expected > 0 ? 'risk' : task.status };
  }
  return { expected, actual, state: task.status };
}

function sortByPriority(tasks) {
  const rank = { urgent: 4, high: 3, normal: 2, low: 1 };
  return [...tasks].sort((a, b) => {
    const ar = rank[a.priority_effective] || 2;
    const br = rank[b.priority_effective] || 2;
    if (ar !== br) return br - ar;
    return dayjs(a.planned_start).valueOf() - dayjs(b.planned_start).valueOf();
  });
}

module.exports = {
  minutesForTask,
  expectedProgress,
  classifyProgress,
  sortByPriority,
};
