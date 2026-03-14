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
8. Copy config overrides:
   - `cp /Users/savanpatel/Documents/SPNET-MODTOOL/config.example.php /Users/savanpatel/Documents/SPNET-MODTOOL/config.local.php`
9. Edit `/Users/savanpatel/Documents/SPNET-MODTOOL/config.local.php` with your bot token and DB creds.
10. Run in long-poll mode:
   - `php /Users/savanpatel/Documents/SPNET-MODTOOL/bin/poll.php`

## Commands
Analytics commands are handled in the bot’s private chat. Moderation commands are disabled in this bot.
Use `/mychats` in private chat to get chat IDs, then set a default with `/usechat`.
If you still get “no permission,” add your Telegram user id to `owner_user_ids` in `config.local.php`.

Private chat commands:
- `/mychats` – list your group chat IDs
- `/usechat <chat_id>` (or `/usechat <title>` or `/usechat off`)
- `/stats [chat_id] [YYYY-MM] [@user]`
- `/leaderboard [chat_id] [YYYY-MM] [budget]`
- `/report [chat_id] [YYYY-MM] [budget]`
- `/reportcsv [chat_id] [YYYY-MM] [budget]`
- `/exportgsheet [chat_id] [YYYY-MM] [budget]`
- `/summary [YYYY-MM] [budget]` (multi-chat summary)
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

Tip: You can forward a user’s message to the bot in private chat and reply with `/modadd` (or `/modremove`) to avoid hunting for the user id.

Group chat commands:
- Moderation commands are disabled.
- `/mod remove` (reply)

## Notes
- Moderation commands are disabled; the bot is analytics-only.
- “Active time” is estimated from message gaps (configurable).
- “Membership time” is time between join and leave events, not actual presence.
- Scoring uses log/sqrt scaling and day normalization. Tune it in `score_weights` and `score_rules`.
- Reward eligibility is controlled by `eligibility` in `config.local.php`.

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
- Open `/Users/savanpatel/Documents/SPNET-MODTOOL/public/dashboard.php?token=YOUR_TOKEN`
- Optional: add `&chat_id=CHAT_ID&month=YYYY-MM`
- Use “All Chats” for the multi-chat view
- Export buttons call `/Users/savanpatel/Documents/SPNET-MODTOOL/public/export.php`

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
