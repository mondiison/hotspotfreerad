<?php

return [
    'security_activity_retention_days' => (int) env('SECURITY_ACTIVITY_RETENTION_DAYS', 180),
    'router_metrics_retention_days' => (int) env('ROUTER_METRICS_RETENTION_DAYS', 90),

    // 2026-10-17: on by default (unlike WIREGUARD_MANAGE_PEERS/AUTO_PROVISION_ROUTERS/
    // ZEROTIER_MANAGE_MEMBERS, which gate live-infrastructure-mutating scheduled
    // commands and stay off until deliberately enabled on the Pi) -- capturing a
    // device's DHCP hostname is read-only and meant to just work in production with
    // no setup. Explicitly off in phpunit.xml's testing environment instead: every
    // Router row created in a test auto-generates real-looking API credentials
    // (Router::booted()), so CaptureSubscriptionDeviceHostname::handle() would
    // otherwise attempt a genuine (and genuinely slow, ~5s-per-candidate-host)
    // RouterOS connection against a fake test IP on every one of the many existing
    // tests that call RadiusProvisioningService::grantSubscriptionAccess() --
    // none of which have anything to do with this feature. Tests for the feature
    // itself call the job's handle() directly (mirroring SyncRouterWalledGardenTest)
    // or flip this config on for the one assertion that needs it.
    'capture_device_hostname' => (bool) env('CAPTURE_DEVICE_HOSTNAME', true),
];
