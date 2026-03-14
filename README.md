# SP NET MOD TOOL Mod Analytics Bot (PHP + MySQL)

Tracks moderator activity in Telegram groups and generates monthly reward sheets with budget-based suggestions.

## Features
- Messages sent, warnings, bans, mutes
- Active time (message-based) + membership time (join/leave)
- Monthly leaderboard and reward suggestions
- Attractive HTML reward sheet (shareable)
- Eligibility thresholds + anti-spam scoring
- Insights: most active, most improved, most consistent, peak hour
- Live dashboard page (auto-refresh)
- CSV export + Google Sheets webhook export
- Auto-scheduled monthly reports
- Mid-month progress reports (MTD)
- Multi-chat summary report
- External stats import (ChatKeeper/Combot)
- Premium subscriptions (plan gating + upgrades)
- Coaching tips + team health insights
- Executive summary + trend report + PDF export
- Import wizard (dashboard upload)
- Mod roster manager + report archive
- Owner notifications (report DM, mid-month alerts, congrats templates)

## Quick Start
1. Ensure MySQL or MariaDB is running.
2. Run the migration:
   - `mysql -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/001_init.sql`
   - If you use MariaDB CLI: `mariadb -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/001_init.sql`
3. Run the auto-report migration:
   - `mysql -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/002_auto_reports.sql`
   - If you use MariaDB CLI: `mariadb -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/002_auto_reports.sql`
4. Run the user settings migration:
   - `mysql -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/003_user_settings.sql`
   - If you use MariaDB CLI: `mariadb -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/003_user_settings.sql`
5. Run the external stats migration:
   - `mysql -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/004_external_user_stats.sql`
   - If you use MariaDB CLI: `mariadb -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/004_external_user_stats.sql`
6. Run the external stats extensions migration:
   - `mysql -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/005_external_user_stats_actions.sql`
   - If you use MariaDB CLI: `mariadb -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/005_external_user_stats_actions.sql`
7. Run the progress report migration:
   - `mysql -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/006_progress_reports.sql`
   - If you use MariaDB CLI: `mariadb -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/006_progress_reports.sql`
8. Run the subscriptions migration:
   - `mysql -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/007_subscriptions.sql`
9. Run the mod roster migration:
   - `mysql -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/008_mod_roster.sql`
10. Run the report archive migration:
   - `mysql -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/009_report_archive.sql`
11. Run the notification log migration:
   - `mysql -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/010_notification_log.sql`
12. Run the reward history migration:
   - `mysql -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/011_reward_history.sql`
13. Copy config overrides:
   - `cp /Users/savanpatel/Documents/SPNET-MODTOOL/config.example.php /Users/savanpatel/Documents/SPNET-MODTOOL/config.local.php`
14. Edit `/Users/savanpatel/Documents/SPNET-MODTOOL/config.local.php` with your bot token and DB creds.
15. Run in long-poll mode:
   - `php /Users/savanpatel/Documents/SPNET-MODTOOL/bin/poll.php`

## Commands
Analytics commands are handled in the bot’s private chat. Moderation commands are disabled in this bot.
Use `/mychats` in private chat to get chat IDs, then set a default with `/usechat`.
If you still get “no permission,” add your Telegram user id to `owner_user_ids` in `config.local.php`.

Private chat commands:
- `/mychats` – list your group chat IDs
- `/usechat <chat_id>` (or `/usechat <title>` or `/usechat off`)
- `/guide` (full usage guide with examples)
- `/stats [chat_id] [YYYY-MM] [@user]`
- `/leaderboard [chat_id] [YYYY-MM] [budget]`
- `/report [chat_id] [YYYY-MM] [budget]`
- `/reportcsv [chat_id] [YYYY-MM] [budget]`
- `/exportgsheet [chat_id] [YYYY-MM] [budget]`
- `/summary [YYYY-MM] [budget]` (multi-chat summary)
- `/plan`
- `/setplan <free|premium> [days]` (owner only)
- `/premium` (see premium benefits)
- `/pricing` (tiers + features)
- `/coach [YYYY-MM]` (premium)
- `/health [YYYY-MM]` (premium)
- `/trend [YYYY-MM] [budget]` (premium)
- `/execsummary [YYYY-MM] [budget]` (premium)
- `/archive`
- `/setbudget <amount> [chat_id]`
- `/settimezone <Region/City> [chat_id]`
- `/setactivity <gap_minutes> <floor_minutes> [chat_id]`
- `/autoreport on [day] [hour] [chat_id]`
- `/autoreport off [chat_id]`
- `/autoreport status [chat_id]`
- `/autoprogress on [day] [hour] [chat_id]`
- `/autoprogress off [chat_id]`
- `/autoprogress status [chat_id]`
- `/progress [chat_id] [budget]` (month-to-date)
- `/modadd [chat_id] <@username|user_id>`
- `/modremove [chat_id] <@username|user_id>`
- `/modlist [chat_id]`
- `/rosteradd <@username|user_id> <role> [notes]`
- `/rosterrole <@username|user_id> <role> [notes]`
- `/rosterremove <@username|user_id>`
- `/rosterlist`

Tip: You can forward a user’s message to the bot in private chat and reply with `/modadd` (or `/modremove`) to avoid hunting for the user id.

Group chat commands:
- Moderation commands are disabled.
- `/mod remove` (reply)

## In-Depth Usage Guide (with examples)
This section walks through the full workflow from setup to monthly rewards.

### 1) Add the bot to your groups
1. Add the bot to each Telegram group you want tracked.
2. Make the bot an admin or disable privacy mode in BotFather so it can read all messages.
3. Send any message in the group so the bot can discover the chat.
4. In private chat with the bot, run:
```text
/mychats
```
5. Set a default chat:
```text
/usechat -1001234567890
```

### 2) Add and manage mods
Use `/modadd` and `/modremove` in private chat.
```text
/modadd @alex
/modadd 123456789
/modremove @alex
/modlist
```
Tip: You can forward a user message to the bot in private chat and reply with `/modadd` to avoid searching for IDs.

### 3) Check stats and leaderboards
```text
/stats
/stats 2026-02
/stats @alex
/leaderboard
/leaderboard 2026-02
```
If you do not set `/usechat`, include the chat id first:
```text
/stats -1001234567890 2026-02 @alex
```

### 4) Generate reward sheets
Budget is optional. If provided, the bot splits it across eligible mods based on score.
```text
/report 2026-02 5000
/reportcsv 2026-02 5000
```
If you do not pass a budget, it will still rank mods and output reward suggestions using config defaults.

### 5) Mid-month progress check
```text
/progress
/progress 7500
```

### 6) Multi-chat summary (combined view)
```text
/summary 2026-02 12000
```

### 7) Coaching, health, trends (premium)
```text
/coach 2026-02
/health 2026-02
/trend 2026-02 5000
/execsummary 2026-02 5000
```

### 8) Budget and scoring controls
```text
/setbudget 8000
/settimezone Asia/Kolkata
/setactivity 5 1
```

### 9) Automation (auto reports + progress checks)
Enable monthly reports and mid-month progress:
```text
/autoreport on 1 9
/autoprogress on 15 12
```
The scheduler must run hourly:
```text
php /Users/savanpatel/Documents/SPNET-MODTOOL/bin/run-scheduled.php
```

### 10) Dashboard (live stats)
1. Start the local server:
```bash
php -S 127.0.0.1:8000 -t /Users/savanpatel/Documents/SPNET-MODTOOL/public
```
2. Open in browser:
```text
http://127.0.0.1:8000/dashboard.php?token=YOUR_TOKEN
```
3. Optional query params:
```text
http://127.0.0.1:8000/dashboard.php?token=YOUR_TOKEN&chat_id=-1001234567890&month=2026-02
```

### 11) Import historical data (ChatKeeper/Combot)
CLI import examples:
```bash
php /Users/savanpatel/Documents/SPNET-MODTOOL/bin/import-chatkeeper.php --file=/path/analysis_users.csv --chat=-1001234567890 --month=2026-02
php /Users/savanpatel/Documents/SPNET-MODTOOL/bin/import-combot.php --file=/path/combot.csv --chat=-1001234567890 --month=2026-02
```
Use `--replace` to overwrite an existing import for the same month.
Premium users can also upload from the browser:
```text
http://127.0.0.1:8000/import.php?token=YOUR_TOKEN
```

### 12) Roster management
```text
/rosteradd @alex Moderator Night shift lead
/rosterrole @alex Senior Moderator Handles appeals
/rosterlist
```

### 13) Premium plans
```text
/plan
/premium
/pricing
/setplan premium 30
```

### 14) Export to Google Sheets
```text
/exportgsheet 2026-02 5000
```

### 15) Troubleshooting
- Bot not responding: check DNS/network on the host, then run `curl -I https://api.telegram.org`.
- "No permission": ensure you are an admin in that group or add your user id to `owner_user_ids` in `/Users/savanpatel/Documents/SPNET-MODTOOL/config.local.php`.
- "No mods are added": run `/modadd` first.
- "No group chats found": add the bot to a group and send any message there, then run `/mychats`.

## Notes
- Moderation commands are disabled; the bot is analytics-only.
- “Active time” is estimated from message gaps (configurable).
- “Membership time” is time between join and leave events, not actual presence.
- Scoring uses log/sqrt scaling and day normalization. Tune it in `score_weights` and `score_rules`.
- Reward eligibility is controlled by `eligibility` in `config.local.php`.

## Premium Features
- Use `/plan` to view the current plan and `/setplan premium 30` to enable premium (owner only).
- Fair reward engine with anti-spam caps + day normalization.
- Smarter rewards with max-share cap, stability bonus, and penalty decay (see `premium.reward` in config).
- Coaching tips and team health (coverage gaps, workload balance, burnout risk).
- Executive summary + trend report + PDF export.
- Import wizard with ChatKeeper/Combot source breakdown.
- Report archive + reward history.
- Owner notifications (auto report DMs, mid-month alerts, congrats templates).
- Log channel + changelog updates.
- Enterprise add-ons: white-label branding, assisted setup, scoring calibration, dedicated support + SLA.
- PDF export requires `wkhtmltopdf` on the host.

## Webhook (optional)
You can use `/Users/savanpatel/Documents/SPNET-MODTOOL/public/webhook.php` as your Telegram webhook handler.

## Make It Live (launchd on macOS)
1. Copy the launchd plists:
   - `cp /Users/savanpatel/Documents/SPNET-MODTOOL/ops/launchd/com.spnet.modtool.bot.plist ~/Library/LaunchAgents/`
   - `cp /Users/savanpatel/Documents/SPNET-MODTOOL/ops/launchd/com.spnet.modtool.scheduler.plist ~/Library/LaunchAgents/`
2. Load them:
   - `launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.spnet.modtool.bot.plist`
   - `launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.spnet.modtool.scheduler.plist`
3. Check logs:
   - `/Users/savanpatel/Documents/SPNET-MODTOOL/storage/logs/poll.out.log`
   - `/Users/savanpatel/Documents/SPNET-MODTOOL/storage/logs/poll.err.log`
   - `/Users/savanpatel/Documents/SPNET-MODTOOL/storage/logs/scheduler.out.log`
   - `/Users/savanpatel/Documents/SPNET-MODTOOL/storage/logs/scheduler.err.log`

## Polling Speed
Adjust `polling` in `/Users/savanpatel/Documents/SPNET-MODTOOL/config.local.php` to control speed:
- `timeout_seconds` (long-poll timeout)
- `limit` (max updates per request)
- `sleep_ms` (pause between loops)

## Live Dashboard
- Set `dashboard.token` in `config.local.php`
- Start a local server:
  - `php -S 127.0.0.1:8000 -t /Users/savanpatel/Documents/SPNET-MODTOOL/public`
- Open `http://127.0.0.1:8000/dashboard.php?token=YOUR_TOKEN`
- Optional: add `&chat_id=CHAT_ID&month=YYYY-MM`
- Use “All Chats” for the multi-chat view
- Export buttons call `/Users/savanpatel/Documents/SPNET-MODTOOL/public/export.php`
- Filters: `search`, `min_messages`, `min_actions`, `min_active_hours`, `min_score`, `only_eligible`, `only_improving`, `limit`, `compact`, `show_sources`, `refresh`
- Premium: PDF export, executive summary, trend report, and import wizard

## Log Channel
Send bot logs + changelog updates to a Telegram channel.
1. Create a channel and add the bot as admin.
2. Set `logging.channel_id` in `/Users/savanpatel/Documents/SPNET-MODTOOL/config.local.php` (format: `-1001234567890`).
3. Optional: set `logging.log_updates = true` to log every message update (very noisy).

## Google Sheets Export (optional)
This uses a webhook URL from Google Apps Script. Set `google_sheets.webhook_url` in `config.local.php`.

Example Apps Script (deploy as Web App):
```javascript
function doPost(e) {
  var data = JSON.parse(e.postData.contents);
  var sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName('Rewards');
  if (!sheet) sheet = SpreadsheetApp.getActiveSpreadsheet().insertSheet('Rewards');
  sheet.clear();
  sheet.appendRow(['Rank','Mod','Score','Messages','Warnings','Mutes','Bans','Active Hours','Membership Hours','Days Active','Improvement %','Reward']);
  data.rows.forEach(function(r) {
    sheet.appendRow([r.rank,r.mod,r.score,r.messages,r.warnings,r.mutes,r.bans,r.active_hours,r.membership_hours,r.days_active,r.improvement,r.reward]);
  });
  return ContentService.createTextOutput('ok');
}
```

## Auto Reports
Run this script hourly via cron (or a scheduler):
- `php /Users/savanpatel/Documents/SPNET-MODTOOL/bin/run-scheduled.php`
It sends the previous month’s report on the configured day/hour in the chat’s timezone.
Progress reports (MTD) are sent when `/autoprogress` is enabled.

## Import ChatKeeper CSV (for backfill)
If you have a ChatKeeper export (like `analysis_users.csv`), import it with:
- `php /Users/savanpatel/Documents/SPNET-MODTOOL/bin/import-chatkeeper.php --file=/path/analysis_users.csv --chat=-1001234567890 --month=YYYY-MM`
Add `--replace` to overwrite an existing import for the same month.
Imported message counts are added to the monthly message total for each mod.

## Import Combot CSV (for backfill)
If you have a Combot export, import it with:
- `php /Users/savanpatel/Documents/SPNET-MODTOOL/bin/import-combot.php --file=/path/combot.csv --chat=-1001234567890 --month=YYYY-MM`
Add `--replace` to overwrite an existing import for the same month.
Warnings, mutes, bans, and active time (if present in the CSV) are merged into monthly stats.

## Import Wizard (Premium)
Open:
- `http://127.0.0.1:8000/import.php?token=YOUR_TOKEN`
Upload ChatKeeper/Combot CSV files directly from the browser.
