<?php

return [
    'stream' => [
        'max_duration_seconds' => (float) env('REALTIME_STREAM_MAX_DURATION_SECONDS', 25),
        'poll_interval_microseconds' => (int) env('REALTIME_STREAM_POLL_INTERVAL_MICROSECONDS', 750000),
    ],
];
