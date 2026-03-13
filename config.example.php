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

    // Reward distribution settings
    'reward' => [
        'top_n' => 5,
        'rank_multipliers' => [
            1 => 1.30,
            2 => 1.15,
            3 => 1.05,
        ],
        'min_reward' => 0.00,
    ],

    // Auto report defaults (used when chat settings are first created)
    'auto_report_defaults' => [
        'enabled' => false,
        'day' => 1,   // day of month
        'hour' => 9,  // hour in chat timezone (0-23)
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
        'refresh_seconds' => 60,
    ],

    // Google Sheets export (webhook-based; use Apps Script)
    'google_sheets' => [
        'webhook_url' => null,
        'timeout_seconds' => 10,
    ],
];
