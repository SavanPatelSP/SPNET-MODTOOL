<?php

return [
    // Telegram bot token
    'bot_token' => 'YOUR_TELEGRAM_BOT_TOKEN',

    // Optional: your bot username without @ (used to ignore commands for other bots)
    'bot_username' => null,

    // Default timezone (used when a chat has no custom timezone)
    'timezone' => 'UTC',

    // Use Telegram admin status for permission checks
    'use_telegram_admins' => true,

    // Optional: always-allowed user IDs (comma-separated via OWNER_USER_IDS env)
    'owner_user_ids' => [],
    // Optional: manager/supervisor user IDs (comma-separated via MANAGER_USER_IDS env)
    'manager_user_ids' => [],

    // Database settings
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=telegram_mods;charset=utf8mb4',
        'user' => 'root',
        'pass' => '',
        'options' => [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ],
    ],

    // Activity estimation
    'active_gap_minutes' => 5,
    'active_floor_minutes' => 1,

    // Score weights
    'score_weights' => [
        'message' => 1.0,
        'warn' => 5.0,
        'mute' => 10.0,
        'ban' => 15.0,
        'active_minute' => 0.2,
        'membership_minute' => 0.05,
        'day_active' => 2.0,
    ],

    // Score rules (anti-spam + normalization)
    'score_rules' => [
        'message_cap' => 2500,
        'active_minutes_cap' => 30000,
        'membership_minutes_cap' => 60000,
        'min_days_for_full' => 7,
        'normalize_by_days' => true,
    ],

    // Eligibility rules (minimums for rewards)
    'eligibility' => [
        'min_days_active' => 3,
        'min_messages' => 20,
        'min_score' => 0,
        'min_actions' => 0,
        'min_active_hours' => 0,
    ],

    // Reward distribution settings
    'reward' => [
        'top_n' => 5,
        'rank_multipliers' => [
            1 => 1.30,
            2 => 1.15,
            3 => 1.05,
        ],
        'min_reward' => 0.00,
        // Bonus pool for KPI awards (Top Mod / Most Active / Most Improved)
        'kpi_bonus_percent' => 0.10,
        'kpi_bonus_split' => [
            'top_mod' => 0.4,
            'most_active' => 0.35,
            'most_improved' => 0.25,
        ],
    ],

    // Role multipliers for score weighting (from /roster roles)
    'role_multipliers' => [
        'senior' => 1.15,
        'lead' => 1.10,
        'mod' => 1.00,
        'trial' => 0.85,
        'night' => 1.05,
    ],

    // Impact score weights (separate from reward score)
    'impact_weights' => [
        'message' => 0.2,
        'warn' => 2.0,
        'mute' => 5.0,
        'ban' => 8.0,
        'active_minute' => 0.05,
    ],
    'impact_recency_days' => 7,
    'impact_recency_multiplier' => 1.1,

    // Premium feature defaults
    'premium' => [
        'enabled' => true,
        'default_plan' => 'free',
        'reward' => [
            'max_share' => 0.35,
            'stability_months' => 3,
            'stability_bonus' => 0.10,
            'penalty_weight' => 0.20,
            'penalty_decay' => 0.50,
        ],
        'health' => [
            'workload_share_alert' => 0.45,
            'burnout_multiplier' => 1.8,
            'inactive_days_alert' => 7,
            'coverage_gap_hours' => 6,
        ],
        'notifications' => [
            'owner_dm' => true,
            'mid_month_alert' => true,
            'congrats' => true,
        ],
    ],

    // Approval workflow (reward sheets)
    'approvals' => [
        'enabled' => true,
        'default_required' => false,
    ],

    // Security / whitelist
    'security' => [
        'whitelist_chat_ids' => [],
    ],

    // Polling speed (long-poll)
    'polling' => [
        'timeout_seconds' => 10,
        'limit' => 50,
        'sleep_ms' => 0,
        'error_sleep_ms' => 1000,
        'allowed_updates' => ['message', 'chat_member', 'pre_checkout_query'],
    ],

    // Telegram network options (optional)
    'telegram' => [
        // Example: '1.1.1.1,8.8.8.8' if DNS resolution fails
        'dns_servers' => null,
        // 'v4' or 'v6' if needed
        'ip_resolve' => null,
        // Optional custom API root (e.g. local Bot API server)
        'api_root' => null,
        // Telegram test environment (Stars sandbox)
        'test_environment' => false,
    ],

    // Auto report defaults (used when chat settings are first created)
    'auto_report_defaults' => [
        'enabled' => false,
        'day' => 1,   // day of month
        'hour' => 9,  // hour in chat timezone (0-23)
    ],

    // Mid-month progress report defaults
    'progress_report_defaults' => [
        'enabled' => false,
        'day' => 15,
        'hour' => 12,
    ],

    // Report branding
    'report' => [
        'brand_name' => 'SP NET MOD TOOL',
        'accent_color' => '#ff7a59',
        'secondary_color' => '#1f2a44',
    ],

    // Dashboard access (set token to protect the page)
    'dashboard' => [
        'token' => null,
        'default_chat_id' => null,
        'refresh_seconds' => 0,
    ],

    // Log channel (optional)
    'logging' => [
        'channel_id' => null,        // Telegram channel id (e.g. -1001234567890)
        'min_level' => 'info',       // info|error
        'max_length' => 3500,
        'log_commands' => true,
        'log_reports' => true,
        'log_imports' => true,
        'log_changelog' => true,
        'log_updates' => false,      // true = log every message update (very noisy)
    ],

    // Google Sheets export (webhook-based; use Apps Script)
    'google_sheets' => [
        'webhook_url' => null,
        'timeout_seconds' => 10,
    ],

    // Payments (test mode for Telegram Stars / Crypto simulation)
    'payments' => [
        'test_mode' => true,
        'stars' => [
            'enabled' => true,
            'sandbox' => true,
            'title' => 'SP NET MOD TOOL Premium',
            'description' => 'Test purchase for SP NET MOD TOOL (Stars sandbox).',
        ],
        'stars_tiers' => [
            ['min' => 500, 'plan' => 'premium', 'days' => 30],
            ['min' => 1500, 'plan' => 'enterprise', 'days' => 30],
        ],
        'crypto_tiers' => [
            ['min' => 20, 'currency' => 'USDT', 'plan' => 'premium', 'days' => 30],
            ['min' => 50, 'currency' => 'USDT', 'plan' => 'enterprise', 'days' => 30],
        ],
    ],
];
