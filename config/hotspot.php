<?php

return [
    'security_activity_retention_days' => (int) env('SECURITY_ACTIVITY_RETENTION_DAYS', 180),
    'router_metrics_retention_days' => (int) env('ROUTER_METRICS_RETENTION_DAYS', 90),
];
