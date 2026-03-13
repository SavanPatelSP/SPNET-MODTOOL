# SP NET MOD TOOL Mod Analytics Bot (PHP + MySQL)

Tracks moderator activity in Telegram groups and generates monthly reward sheets with budget-based suggestions.

## Features
- Messages sent, warnings, bans, mutes
- Active time (message-based) + membership time (join/leave)
- Monthly leaderboard and reward suggestions
- Attractive HTML reward sheet (shareable)
- Insights: most active, most improved, most consistent, peak hour
- Live dashboard page (auto-refresh)
- CSV export + Google Sheets webhook export
- Auto-scheduled monthly reports

## Quick Start
1. Ensure MySQL or MariaDB is running.
2. Run the migration:
   - `mysql -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/001_init.sql`
   - If you use MariaDB CLI: `mariadb -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/001_init.sql`
3. Run the auto-report migration:
   - `mysql -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/002_auto_reports.sql`
   - If you use MariaDB CLI: `mariadb -u root -p < /Users/savanpatel/Documents/SPNET-MODTOOL/migrations/002_auto_reports.sql`
4. Copy config overrides:
   - `cp /Users/savanpatel/Documents/SPNET-MODTOOL/config.example.php /Users/savanpatel/Documents/SPNET-MODTOOL/config.local.php`
5. Edit `/Users/savanpatel/Documents/SPNET-MODTOOL/config.local.php` with your bot token and DB creds.
6. Run in long-poll mode:
   - `php /Users/savanpatel/Documents/SPNET-MODTOOL/bin/poll.php`

## Commands
Analytics commands are handled in the bot’s private chat. Moderation commands stay in the group.
Use `/mychats` in private chat to get the correct `<chat_id>`.

Private chat commands:
- `/mychats` – list your group chat IDs
- `/stats <chat_id> [YYYY-MM] [@user]`
- `/leaderboard <chat_id> [YYYY-MM] [budget]`
- `/report <chat_id> [YYYY-MM] [budget]`
- `/reportcsv <chat_id> [YYYY-MM] [budget]`
- `/exportgsheet <chat_id> [YYYY-MM] [budget]`
- `/setbudget <amount> <chat_id>`
- `/settimezone <Region/City> <chat_id>`
- `/setactivity <gap_minutes> <floor_minutes> <chat_id>`
- `/autoreport on [day] [hour] <chat_id>`
- `/autoreport off <chat_id>`
- `/autoreport status <chat_id>`

Group chat commands:
- `/warn <reason>` (reply)
- `/mute <minutes> <reason>` (reply)
- `/ban <reason>` (reply)
- `/unmute` (reply)
- `/unban` (reply)
- `/mod add` (reply)
- `/mod remove` (reply)

## Notes
- The bot must be admin to ban/mute.
- “Active time” is estimated from message gaps (configurable).
- “Membership time” is time between join and leave events, not actual presence.

## Webhook (optional)
You can use `/Users/savanpatel/Documents/SPNET-MODTOOL/public/webhook.php` as your Telegram webhook handler.

## Live Dashboard
- Set `dashboard.token` in `config.local.php`
- Open `/Users/savanpatel/Documents/SPNET-MODTOOL/public/dashboard.php?token=YOUR_TOKEN`
- Optional: add `&chat_id=CHAT_ID&month=YYYY-MM`

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
