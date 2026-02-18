<?php
function minutes_for_task(float $quantity, float $standardTimeMinutes, float $standardOutputRate, int $workers = 1): int {
    $base = ($quantity / max($standardOutputRate, 0.0001)) * $standardTimeMinutes;
    return max(1, (int)ceil($base / max($workers, 1)));
}

function expected_progress(float $targetQuantity, string $plannedStart, string $plannedEnd): int {
    $now = new DateTimeImmutable('now');
    $start = new DateTimeImmutable($plannedStart);
    $end = new DateTimeImmutable($plannedEnd);

    if ($now < $start) {
        return 0;
    }

    $total = max(1, (int)(($end->getTimestamp() - $start->getTimestamp()) / 60));
    $elapsed = min(max((int)(($now->getTimestamp() - $start->getTimestamp()) / 60), 0), $total);
    return (int)round(($elapsed / $total) * $targetQuantity);
}

function priority_rank(string $p): int {
    return match($p) {
        'urgent' => 4,
        'high' => 3,
        'normal' => 2,
        'low' => 1,
        default => 2,
    };
}

function classify_status(array $task): array {
    $expected = expected_progress((float)$task['target_quantity'], $task['planned_start'], $task['planned_end']);
    $actual = (int)$task['completed_quantity'];
    $state = $task['status'];

    if ($task['status'] !== 'completed' && $expected > 0 && $actual < (0.85 * $expected)) {
        $state = 'risk';
    }

    return ['expected' => $expected, 'state' => $state];
}
