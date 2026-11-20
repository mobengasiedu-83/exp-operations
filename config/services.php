<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'exp_pbx' => [
        'host' => env('EXP_PBX_HOST'),
        'port' => (int) env('EXP_PBX_AMI_PORT', 5038),
        'username' => env('EXP_PBX_AMI_USERNAME'),
        'password' => env('EXP_PBX_AMI_PASSWORD'),
        'connection_name' => env('EXP_PBX_NAME', 'VitalPBX EXP'),
        'timeout' => (int) env('EXP_PBX_AMI_TIMEOUT', 10),
        'inbound_dids' => array_values(array_filter(explode(',', (string) env('EXP_PBX_INBOUND_DIDS', '')))),
        'inbound_contexts' => array_values(array_filter(explode(',', (string) env('EXP_PBX_INBOUND_CONTEXTS', '')))) ?: ['incoming-calls'],
        // VitalPBX names its menu contexts IVR-<id>; app-ivr/app-announcement are the stock
        // modules that collect the key press. Wildcards are supported.
        'ivr_contexts' => array_values(array_filter(explode(',', (string) env('EXP_PBX_IVR_CONTEXTS', '')))) ?: ['IVR-*', 'app-ivr', 'app-announcement'],
        // High-volume AMI noise that carries no journey evidence. Wildcards are supported.
        'ignored_events' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'EXP_PBX_IGNORED_EVENTS',
            'VarSet,RTCP*,ContactStatus,ExtensionStatus,DeviceStateChange,QueueMemberStatus,MessageWaiting,HangupHandler*,SoftHangupRequest,NewConnectedLine,MusicOnHold*,FullyBooted,Reload,NewAccountCode,DTMFBegin',
        ))))),
        'log_each_event' => (bool) env('EXP_PBX_LOG_EACH_EVENT', false),
        'reconciliation_tolerance_seconds' => (int) env('EXP_PBX_RECONCILIATION_TOLERANCE_SECONDS', 2),
        'screening_extension' => env('EXP_SCREENING_EXTENSION', '204'),
        'live_refresh_seconds' => (int) env('EXP_LIVE_REFRESH_SECONDS', 3),
        'retention_days' => (int) env('EXP_PBX_RETENTION_DAYS', 7),
        'max_records' => (int) env('EXP_PBX_MAX_RECORDS', 100000),
        'listener_lock_path' => env('EXP_AMI_LISTENER_LOCK_PATH'),
        'heartbeat_stale_seconds' => (int) env('EXP_AMI_HEARTBEAT_STALE_SECONDS', 90),
        'heartbeat_interval_seconds' => max(5, (int) env('EXP_PBX_HEARTBEAT_INTERVAL_SECONDS', 20)),
        'heartbeat_timeout_seconds' => max(10, (int) env('EXP_PBX_HEARTBEAT_TIMEOUT_SECONDS', 45)),
        'prune_interval_seconds' => max(10, (int) env('EXP_PBX_PRUNE_INTERVAL_SECONDS', 60)),
        'long_ringing_seconds' => (int) env('EXP_LONG_RINGING_SECONDS', 45),
        'alert_lookback_minutes' => (int) env('EXP_ALERT_LOOKBACK_MINUTES', 1440),
        'agent_presence_window_minutes' => (int) env('EXP_AGENT_PRESENCE_WINDOW_MINUTES', 10),
        'dentist_unmatched_lookback_days' => (int) env('EXP_DENTIST_UNMATCHED_LOOKBACK_DAYS', 7),
        'dentist_performance_window_days' => (int) env('EXP_DENTIST_PERFORMANCE_WINDOW_DAYS', 30),
    ],

    'exp_call_control' => [
        'enabled' => (bool) env('EXP_CALL_CONTROL_ENABLED', false),
        'lab_approved' => (bool) env('EXP_CALL_CONTROL_LAB_APPROVED', false),
        'url' => env('EXP_CALL_CONTROL_URL'),
        'token' => env('EXP_CALL_CONTROL_TOKEN'),
        'identity' => env('EXP_CALL_CONTROL_IDENTITY'),
        'snapshot_max_age_seconds' => 5,
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
