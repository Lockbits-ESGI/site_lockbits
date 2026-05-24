<?php
declare(strict_types=1);

function check_rate_limit(string $key = 'default', int $max_requests = 60, int $window_minutes = 1): void
{
    $rate_file = sys_get_temp_dir() . '/ratelimit_' . md5($_SERVER['REMOTE_ADDR'] . '_' . $key);
    $now = time();

    $data = [];
    if (file_exists($rate_file)) {
        $data = json_decode(file_get_contents($rate_file), true) ?: [];
    }

    $data = array_filter($data, fn(int $t): bool => $t > $now - ($window_minutes * 60));

    if (count($data) >= $max_requests) {
        header('Retry-After: ' . ($window_minutes * 60));
        http_response_code(429);
        die(json_encode(['error' => 'Too many requests', 'retry_after' => $window_minutes * 60]));
    }

    $data[] = $now;
    file_put_contents($rate_file, json_encode(array_values($data)));
}
