<?php

namespace App;

use App\Services\SettingsService;
use App\Services\StatsService;
use App\Services\RewardService;
use App\Services\GoogleSheetsService;
use App\Services\UserSettingsService;
use App\Services\SubscriptionService;
use App\Services\RewardContextService;
use App\Services\RewardHistoryService;
use App\Services\CoachingService;
use App\Services\HealthService;
use App\Services\RosterService;
use App\Services\ArchiveService;
use App\Reports\RewardSheet;
use App\Reports\RewardCsv;
use App\Reports\MultiChatReport;
use App\Reports\ExecutiveSummary;
use App\Reports\TrendReport;
use App\Logger;
use DateTimeImmutable;
use DateTimeZone;

class UpdateHandler
{
    private Database $db;
    private Telegram $tg;
    private array $config;
    private SettingsService $settings;
    private UserSettingsService $userSettings;
    private SubscriptionService $subscriptions;
    private StatsService $stats;
    private RewardService $rewards;
    private RewardContextService $rewardContext;
    private RewardHistoryService $rewardHistory;
    private CoachingService $coaching;
    private HealthService $health;
    private RosterService $roster;
    private ArchiveService $archive;
    private RewardSheet $rewardSheet;
    private RewardCsv $rewardCsv;
    private MultiChatReport $multiChatReport;
    private ExecutiveSummary $executiveSummary;
    private TrendReport $trendReport;
    private GoogleSheetsService $googleSheets;

    public function __construct(Database $db, Telegram $tg, array $config)
    {
        $this->db = $db;
        $this->tg = $tg;
        $this->config = $config;
        $this->settings = new SettingsService($db, $config);
        $this->userSettings = new UserSettingsService($db);
        $this->subscriptions = new SubscriptionService($db, $config);
        $this->stats = new StatsService($db, $this->settings, $config);
        $this->rewards = new RewardService($config);
        $this->rewardContext = new RewardContextService($db, $config);
        $this->rewardHistory = new RewardHistoryService($db);
        $this->archive = new ArchiveService($db);
        $this->coaching = new CoachingService($this->stats, $this->settings, $config);
        $this->health = new HealthService($this->stats, $this->settings, $config);
        $this->roster = new RosterService($db);
        $this->rewardSheet = new RewardSheet($this->stats, $this->rewards, $config, $this->rewardContext, $this->rewardHistory, $this->archive);
        $this->rewardCsv = new RewardCsv($this->stats, $this->rewards, $this->rewardContext, $this->rewardHistory, $this->archive);
        $this->multiChatReport = new MultiChatReport($this->stats, $this->rewards, $config);
        $this->executiveSummary = new ExecutiveSummary($this->stats, $this->rewards, $config, $this->rewardContext, $this->archive);
        $this->trendReport = new TrendReport($this->stats, $this->rewards, $config, $this->rewardContext, $this->archive);
        $this->googleSheets = new GoogleSheetsService($config);
    }

    public function handleUpdate(array $update): void
    {
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
            return;
        }

        if (isset($update['chat_member'])) {
            $this->handleChatMember($update['chat_member']);
        }
    }

    private function handleMessage(array $message): void
    {
        if (!isset($message['chat'])) {
            return;
        }

        $chat = $message['chat'];
        $chatId = $chat['id'];
        $chatType = $chat['type'] ?? 'private';

        $this->upsertChat($chat);
        $this->settings->get($chatId);

        if (isset($message['from'])) {
            $from = $message['from'];
            if (!empty($from['is_bot'])) {
                return;
            }
            $this->upsertUser($from);
            $this->ensureChatMember($chatId, $from['id']);
        }

        if (isset($message['new_chat_members'])) {
            foreach ($message['new_chat_members'] as $member) {
                $this->upsertUser($member);
                $this->ensureChatMember($chatId, $member['id']);
                $this->recordMembershipJoin($chatId, $member['id'], $message['date']);
            }
        }

        if (isset($message['left_chat_member'])) {
            $member = $message['left_chat_member'];
            $this->upsertUser($member);
            $this->ensureChatMember($chatId, $member['id']);
            $this->recordMembershipLeave($chatId, $member['id'], $message['date']);
        }

        if ($this->isRegularMessage($message)) {
            $this->recordMessage($chatId, $message);
            if ($this->shouldLog('log_updates')) {
                $fromId = $message['from']['id'] ?? 'unknown';
                Logger::info('Message update in chat ' . $chatId . ' from ' . $fromId . ' msg ' . ($message['message_id'] ?? ''));
            }
        }

        if (!isset($message['text'])) {
            return;
        }

        $parsed = $this->parseCommand($message['text']);
        if (!$parsed) {
            return;
        }

        if ($this->shouldLog('log_commands')) {
            Logger::info('Command ' . $parsed['command'] . ' from user ' . ($message['from']['id'] ?? 'unknown') . ' in chat ' . $chatId);
        }

        $this->handleCommand($chatId, $message, $parsed['command'], $parsed['args'], $chatType);
    }

    private function handleChatMember(array $chatMemberUpdate): void
    {
        if (!isset($chatMemberUpdate['chat'], $chatMemberUpdate['new_chat_member'], $chatMemberUpdate['old_chat_member'])) {
            return;
        }

        $chat = $chatMemberUpdate['chat'];
        $chatId = $chat['id'];
        $this->upsertChat($chat);
        $this->settings->get($chatId);

        $new = $chatMemberUpdate['new_chat_member'];
        $old = $chatMemberUpdate['old_chat_member'];
        $user = $new['user'] ?? $old['user'] ?? null;

        if (!$user) {
            return;
        }

        $this->upsertUser($user);
        $this->ensureChatMember($chatId, $user['id']);

        $joinedStatuses = ['member', 'administrator', 'creator'];
        $leftStatuses = ['left', 'kicked'];

        $oldStatus = $old['status'] ?? null;
        $newStatus = $new['status'] ?? null;

        $timestamp = $chatMemberUpdate['date'] ?? time();

        if (in_array($oldStatus, $leftStatuses, true) && in_array($newStatus, $joinedStatuses, true)) {
            $this->recordMembershipJoin($chatId, $user['id'], $timestamp);
        }

        if (in_array($newStatus, $leftStatuses, true)) {
            $this->recordMembershipLeave($chatId, $user['id'], $timestamp);
        }
    }

    private function handleCommand(int|string $chatId, array $message, string $command, string $args, string $chatType): void
    {
        $from = $message['from'];
        $userId = $from['id'];
        $isPrivate = $chatType === 'private';

        $privateCommands = [
            'stats', 'leaderboard', 'report', 'reportcsv', 'exportgsheet', 'summary',
            'setbudget', 'settimezone', 'setactivity', 'autoreport', 'autoprogress', 'progress', 'mychats',
            'usechat', 'modadd', 'modremove', 'modlist',
            'plan', 'setplan', 'coach', 'health', 'trend', 'execsummary', 'archive',
            'rosteradd', 'rosterremove', 'rosterlist', 'rosterrole',
            'premium', 'benefits', 'pricing', 'guide',
        ];
        $moderationCommands = ['warn', 'mute', 'ban', 'unmute', 'unban', 'mod'];

        if ($isPrivate) {
            if (in_array($command, ['help', 'start'], true)) {
                $this->tg->sendMessage($chatId, $this->helpText(true), ['parse_mode' => 'HTML']);
                return;
            }

            if (in_array($command, $moderationCommands, true)) {
                $this->tg->sendMessage($chatId, 'Moderation commands are disabled in this bot. It only handles analytics and rewards.', ['parse_mode' => 'HTML']);
                return;
            }

            if ($command === 'mychats') {
                $this->handleMyChats($chatId, $userId);
                return;
            }

            if ($command === 'usechat') {
                $this->handleUseChat($chatId, $userId, $args);
                return;
            }

            if ($command === 'guide') {
                $this->handleGuide($chatId);
                return;
            }

            if (in_array($command, $privateCommands, true)) {
                [$targetChatId, $cleanArgs] = $this->resolveTargetChatId($userId, $chatId, $args);
                if (!$targetChatId) {
                    return;
                }

                if (!$this->isAuthorized($targetChatId, $userId)) {
                    $this->tg->sendMessage($chatId, 'You do not have permission for that chat. Make sure you are admin/mod there and use /mychats for the correct chat id.', ['parse_mode' => 'HTML']);
                    return;
                }

                switch ($command) {
                    case 'stats':
                        $this->handleStats($chatId, $targetChatId, $message, $cleanArgs);
                        return;
                    case 'leaderboard':
                        $this->handleLeaderboard($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'report':
                        $this->handleReport($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'reportcsv':
                        $this->handleReportCsv($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'exportgsheet':
                        $this->handleExportGoogleSheet($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'summary':
                        $this->handleSummaryReport($chatId, $userId, $cleanArgs);
                        return;
                    case 'setbudget':
                        $this->handleSetBudget($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'settimezone':
                        $this->handleSetTimezone($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'setactivity':
                        $this->handleSetActivity($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'autoreport':
                        $this->handleAutoReport($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'autoprogress':
                        $this->handleAutoProgress($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'progress':
                        $this->handleProgressReport($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'modadd':
                        $this->handleModAddPrivate($chatId, $targetChatId, $cleanArgs, $message);
                        return;
                    case 'modremove':
                        $this->handleModRemovePrivate($chatId, $targetChatId, $cleanArgs, $message);
                        return;
                    case 'modlist':
                        $this->handleModListPrivate($chatId, $targetChatId);
                        return;
                    case 'plan':
                        $this->handlePlan($chatId, $targetChatId);
                        return;
                    case 'setplan':
                        $this->handleSetPlan($chatId, $targetChatId, $cleanArgs, $userId);
                        return;
                    case 'coach':
                        $this->handleCoach($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'health':
                        $this->handleHealth($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'trend':
                        $this->handleTrendReport($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'execsummary':
                        $this->handleExecutiveSummary($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'archive':
                        $this->handleArchive($chatId, $targetChatId);
                        return;
                    case 'rosteradd':
                        $this->handleRosterAdd($chatId, $targetChatId, $cleanArgs, $message);
                        return;
                    case 'rosterremove':
                        $this->handleRosterRemove($chatId, $targetChatId, $cleanArgs, $message);
                        return;
                    case 'rosterlist':
                        $this->handleRosterList($chatId, $targetChatId);
                        return;
                    case 'rosterrole':
                        $this->handleRosterRole($chatId, $targetChatId, $cleanArgs, $message);
                        return;
                    case 'premium':
                    case 'benefits':
                        $this->handlePremiumBenefits($chatId, $targetChatId);
                        return;
                    case 'pricing':
                        $this->handlePricing($chatId, $targetChatId);
                        return;
                }
            }

            return;
        }

        // Group chat behavior
        if (in_array($command, ['help', 'start'], true)) {
            $this->tg->sendMessage($chatId, $this->helpText(false), ['parse_mode' => 'HTML']);
            return;
        }

        if (in_array($command, $privateCommands, true)) {
            $this->tg->sendMessage($chatId, 'Please DM me to use analytics commands.', ['parse_mode' => 'HTML']);
            return;
        }

        if (in_array($command, $moderationCommands, true)) {
            $this->tg->sendMessage($chatId, 'Moderation commands are disabled in this bot. It only handles analytics and rewards.', ['parse_mode' => 'HTML']);
            return;
        }
    }

    private function handleStats(int|string $responseChatId, int|string $chatId, array $message, string $args): void
    {
        $targetUserId = $message['from']['id'];
        $targetUsername = null;
        $month = null;

        if (isset($message['reply_to_message']['from'])) {
            $targetUserId = $message['reply_to_message']['from']['id'];
            $targetUsername = $message['reply_to_message']['from']['username'] ?? null;
        }

        $tokens = preg_split('/\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}$/', $token)) {
                $month = $token;
                continue;
            }
            if (strpos($token, '@') === 0) {
                $targetUsername = substr($token, 1);
                $targetUserId = null;
            }
        }

        $stats = $this->stats->getMonthlyStats($chatId, $month);
        if (empty($stats['mods'])) {
            $this->tg->sendMessage($responseChatId, 'No mods are configured yet. Use /modadd [chat_id] @username in private chat (or /usechat), or /mod add (reply) in the group.', ['parse_mode' => 'HTML']);
            return;
        }

        $target = null;
        foreach ($stats['mods'] as $mod) {
            if ($targetUserId !== null && (int)$mod['user_id'] === (int)$targetUserId) {
                $target = $mod;
                break;
            }
            if ($targetUsername !== null && $mod['username'] && strtolower($mod['username']) === strtolower($targetUsername)) {
                $target = $mod;
                break;
            }
        }

        if (!$target) {
            $this->tg->sendMessage($responseChatId, 'Mod not found in this chat.', ['parse_mode' => 'HTML']);
            return;
        }

        $text = $this->formatStatsMessage($target, $stats['range']);
        $this->tg->sendMessage($responseChatId, $text, ['parse_mode' => 'HTML']);
    }

    private function handleLeaderboard(int|string $responseChatId, int|string $chatId, string $args): void
    {
        $month = null;
        $budget = null;

        $tokens = preg_split('/\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}$/', $token)) {
                $month = $token;
                continue;
            }
            if (is_numeric($token)) {
                $budget = (float)$token;
            }
        }

        $stats = $this->stats->getMonthlyStats($chatId, $month);
        if (empty($stats['mods'])) {
            $this->tg->sendMessage($responseChatId, 'No mods are configured yet. Use /modadd [chat_id] @username in private chat (or /usechat), or /mod add (reply) in the group.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($budget === null) {
            $budget = (float)$stats['settings']['reward_budget'];
        }

        $context = $this->rewardContext->build($chatId, $stats['range']['month']);
        $ranked = $this->rewards->rankAndReward($stats['mods'], $budget, $context);
        $text = $this->formatLeaderboardMessage($ranked, $stats['range'], $budget);
        $this->tg->sendMessage($responseChatId, $text, ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_reports')) {
            Logger::info('Leaderboard generated for chat ' . $chatId . ' month ' . ($stats['range']['month'] ?? ''));
        }
    }

    private function handleReport(int|string $responseChatId, int|string $chatId, string $args): void
    {
        $month = null;
        $budget = null;

        $tokens = preg_split('/\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}$/', $token)) {
                $month = $token;
                continue;
            }
            if (is_numeric($token)) {
                $budget = (float)$token;
            }
        }

        $stats = $this->stats->getMonthlyStats($chatId, $month);
        if (empty($stats['mods'])) {
            $this->tg->sendMessage($responseChatId, 'No mods are configured yet. Use /modadd [chat_id] @username in private chat (or /usechat), or /mod add (reply) in the group.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($budget === null) {
            $budget = (float)$stats['settings']['reward_budget'];
        }

        $filePath = $this->rewardSheet->generate($chatId, $month, $budget);
        $caption = 'Reward sheet for ' . $stats['range']['label'] . ' (budget: ' . number_format($budget, 2) . ')';
        $this->tg->sendDocument($responseChatId, $filePath, $caption);
        if ($this->shouldLog('log_reports')) {
            Logger::info('Reward sheet generated for chat ' . $chatId . ' month ' . ($stats['range']['month'] ?? ''));
        }
    }

    private function handleReportCsv(int|string $responseChatId, int|string $chatId, string $args): void
    {
        $month = null;
        $budget = null;

        $tokens = preg_split('/\\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\\d{4}-\\d{2}$/', $token)) {
                $month = $token;
                continue;
            }
            if (is_numeric($token)) {
                $budget = (float)$token;
            }
        }

        $stats = $this->stats->getMonthlyStats($chatId, $month);
        if (empty($stats['mods'])) {
            $this->tg->sendMessage($responseChatId, 'No mods are configured yet. Use /modadd [chat_id] @username in private chat (or /usechat), or /mod add (reply) in the group.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($budget === null) {
            $budget = (float)$stats['settings']['reward_budget'];
        }

        $filePath = $this->rewardCsv->generate($chatId, $month, $budget);
        $caption = 'CSV reward sheet for ' . $stats['range']['label'] . ' (budget: ' . number_format($budget, 2) . ')';
        $this->tg->sendDocument($responseChatId, $filePath, $caption);
        if ($this->shouldLog('log_reports')) {
            Logger::info('Reward CSV generated for chat ' . $chatId . ' month ' . ($stats['range']['month'] ?? ''));
        }
    }

    private function handleSummaryReport(int|string $responseChatId, int|string $userId, string $args): void
    {
        $month = null;
        $budget = null;

        $tokens = preg_split('/\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}$/', $token)) {
                $month = $token;
                continue;
            }
            if (is_numeric($token)) {
                $budget = (float)$token;
            }
        }

        $chats = $this->getUserChats($userId, 50);
        if (empty($chats)) {
            $this->tg->sendMessage($responseChatId, 'No group chats found yet. Add me to a group and send a message there.', ['parse_mode' => 'HTML']);
            return;
        }

        $chatIds = [];
        foreach ($chats as $chat) {
            $chatId = (int)$chat['id'];
            if ($this->isAuthorized($chatId, $userId)) {
                $chatIds[] = $chatId;
            }
        }

        if (empty($chatIds)) {
            $this->tg->sendMessage($responseChatId, 'No authorized chats found for multi-chat summary.', ['parse_mode' => 'HTML']);
            return;
        }

        $bundle = $this->stats->getMonthlyStatsForChats($chatIds, $month);
        $label = $bundle['range']['label'] ?? ($month ?? 'current month');

        $filePath = $this->multiChatReport->generate($chatIds, $month, $budget);
        $caption = 'Multi-chat summary for ' . $label;
        if ($budget !== null) {
            $caption .= ' (budget: ' . number_format($budget, 2) . ')';
        }
        $this->tg->sendDocument($responseChatId, $filePath, $caption);
        if ($this->shouldLog('log_reports')) {
            Logger::info('Multi-chat summary generated for user ' . $userId . ' month ' . ($bundle['range']['month'] ?? ''));
        }
    }

    private function handleExportGoogleSheet(int|string $responseChatId, int|string $chatId, string $args): void
    {
        $month = null;
        $budget = null;

        $tokens = preg_split('/\\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\\d{4}-\\d{2}$/', $token)) {
                $month = $token;
                continue;
            }
            if (is_numeric($token)) {
                $budget = (float)$token;
            }
        }

        $stats = $this->stats->getMonthlyStats($chatId, $month);
        if (empty($stats['mods'])) {
            $this->tg->sendMessage($responseChatId, 'No mods are configured yet. Use /modadd [chat_id] @username in private chat (or /usechat), or /mod add (reply) in the group.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($budget === null) {
            $budget = (float)$stats['settings']['reward_budget'];
        }

        $context = $this->rewardContext->build($chatId, $stats['range']['month']);
        $ranked = $this->rewards->rankAndReward($stats['mods'], $budget, $context);
        $rewardMap = [];
        foreach ($ranked as $mod) {
            $rewardMap[$mod['user_id']] = $mod['reward'];
        }

        $rows = [];
        $rank = 1;
        foreach ($stats['mods'] as $mod) {
            $rows[] = [
                'rank' => $rank,
                'mod' => $mod['display_name'],
                'score' => $mod['score'],
                'messages' => $mod['messages'],
                'warnings' => $mod['warnings'],
                'mutes' => $mod['mutes'],
                'bans' => $mod['bans'],
                'active_hours' => round($mod['active_minutes'] / 60, 2),
                'membership_hours' => round($mod['membership_minutes'] / 60, 2),
                'days_active' => $mod['days_active'],
                'improvement' => $mod['improvement'],
                'reward' => $rewardMap[$mod['user_id']] ?? 0.0,
            ];
            $rank++;
        }

        $payload = [
            'chat_id' => $chatId,
            'month' => $stats['range']['month'],
            'label' => $stats['range']['label'],
            'budget' => $budget,
            'summary' => $stats['summary'],
            'rows' => $rows,
        ];

        $result = $this->googleSheets->export($payload);
        if ($result['ok']) {
            $this->tg->sendMessage($responseChatId, 'Exported to Google Sheets successfully.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_reports')) {
                Logger::info('Google Sheets export completed for chat ' . $chatId . ' month ' . ($stats['range']['month'] ?? ''));
            }
        } else {
            $error = $result['error'] ?? 'unknown error';
            $this->tg->sendMessage($responseChatId, 'Google Sheets export failed: ' . $this->escape((string)$error), ['parse_mode' => 'HTML']);
            Logger::error('Google Sheets export failed for chat ' . $chatId . ': ' . $error);
        }
    }

    private function handleModAddPrivate(int|string $responseChatId, int|string $chatId, string $args, array $message): void
    {
        $target = $this->resolveTargetUser($args);
        if (!$target) {
            $target = $this->resolveTargetUserFromMessage($message);
        }
        if (!$target) {
            $this->tg->sendMessage($responseChatId, 'Usage: /modadd [chat_id] &lt;@username|user_id&gt; (or set /usechat). You can also forward a user\'s message here and reply /modadd.', ['parse_mode' => 'HTML']);
            return;
        }

        $this->ensureChatMember($chatId, $target['id']);
        $this->setModStatus($chatId, $target['id'], true);
        $this->tg->sendMessage($responseChatId, 'Mod added: ' . $this->displayName($target), ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::info('Mod added in chat ' . $chatId . ' -> ' . $target['id']);
        }
    }

    private function handleModRemovePrivate(int|string $responseChatId, int|string $chatId, string $args, array $message): void
    {
        $target = $this->resolveTargetUser($args);
        if (!$target) {
            $target = $this->resolveTargetUserFromMessage($message);
        }
        if (!$target) {
            $this->tg->sendMessage($responseChatId, 'Usage: /modremove [chat_id] &lt;@username|user_id&gt; (or set /usechat). You can also forward a user\'s message here and reply /modremove.', ['parse_mode' => 'HTML']);
            return;
        }

        $this->ensureChatMember($chatId, $target['id']);
        $this->setModStatus($chatId, $target['id'], false);
        $this->tg->sendMessage($responseChatId, 'Mod removed: ' . $this->displayName($target), ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::info('Mod removed in chat ' . $chatId . ' -> ' . $target['id']);
        }
    }

    private function handleModListPrivate(int|string $responseChatId, int|string $chatId): void
    {
        $mods = $this->db->fetchAll(
            'SELECT u.id, u.username, u.first_name, u.last_name FROM chat_members cm JOIN users u ON u.id = cm.user_id WHERE cm.chat_id = ? AND cm.is_mod = 1',
            [$chatId]
        );

        if (empty($mods)) {
            $this->tg->sendMessage($responseChatId, 'No mods are configured for this chat yet.', ['parse_mode' => 'HTML']);
            return;
        }

        $lines = ['Mods in this chat:'];
        foreach ($mods as $mod) {
            $lines[] = $mod['id'] . ' | ' . $this->displayName($mod);
        }
        $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_reports')) {
            Logger::info('Coach report generated for chat ' . $chatId . ' range ' . ($report['range']['month'] ?? ''));
        }
    }

    private function handlePlan(int|string $responseChatId, int|string $chatId): void
    {
        $sub = $this->subscriptions->get($chatId);
        $plan = strtoupper($sub['plan'] ?? 'FREE');
        $status = $sub['status'] ?? 'active';
        $expires = $sub['expires_at'] ?? null;
        $lines = [
            '<b>Subscription Plan</b>',
            'Plan: ' . $this->escape($plan),
            'Status: ' . $this->escape($status),
            'Expires: ' . ($expires ? $this->escape($expires) : 'never'),
            'Tip: owners can upgrade with /setplan premium 30',
        ];
        $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_reports')) {
            Logger::info('Health report generated for chat ' . $chatId . ' range ' . ($report['range']['month'] ?? ''));
        }
    }

    private function handleSetPlan(int|string $responseChatId, int|string $chatId, string $args, int|string $userId): void
    {
        if (!$this->isOwner($userId)) {
            $this->tg->sendMessage($responseChatId, 'Only bot owners can set subscription plans.', ['parse_mode' => 'HTML']);
            return;
        }
        $parts = preg_split('/\\s+/', trim($args));
        $plan = $parts[0] ?? '';
        $days = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
        if ($plan === '') {
            $this->tg->sendMessage($responseChatId, 'Usage: /setplan &lt;free|premium&gt; [days]', ['parse_mode' => 'HTML']);
            return;
        }

        $sub = $this->subscriptions->setPlan($chatId, $plan, $days);
        $msg = 'Plan updated: ' . strtoupper($sub['plan']) . '.';
        if (!empty($sub['expires_at'])) {
            $msg .= ' Expires ' . $sub['expires_at'] . '.';
        }
        $this->tg->sendMessage($responseChatId, $msg, ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::info('Plan updated for chat ' . $chatId . ' -> ' . strtoupper($sub['plan']));
        }
    }

    private function handleCoach(int|string $responseChatId, int|string $chatId, string $args): void
    {
        if (!$this->requirePremium($chatId, $responseChatId)) {
            return;
        }
        $month = null;
        $token = trim($args);
        if (preg_match('/^\\d{4}-\\d{2}$/', $token)) {
            $month = $token;
        }

        $report = $this->coaching->buildReport($chatId, $month);
        $lines = [
            '<b>Coaching Report</b>',
            'Range: ' . $this->escape($report['range']['label'] ?? ''),
        ];

        if (!empty($report['missed'])) {
            $lines[] = 'Inactive alerts:';
            foreach (array_slice($report['missed'], 0, 5) as $missed) {
                $lines[] = '- ' . $this->escape($missed);
            }
        }

        if (!empty($report['tips'])) {
            $lines[] = 'Tips:';
            foreach (array_slice($report['tips'], 0, 6) as $tip) {
                $lines[] = '- ' . $this->escape($tip);
            }
        } else {
            $lines[] = 'Tips: all good so far.';
        }

        $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function handleHealth(int|string $responseChatId, int|string $chatId, string $args): void
    {
        if (!$this->requirePremium($chatId, $responseChatId)) {
            return;
        }
        $month = null;
        $token = trim($args);
        if (preg_match('/^\\d{4}-\\d{2}$/', $token)) {
            $month = $token;
        }

        $report = $this->health->buildReport($chatId, $month);
        $coverage = $report['coverage'] ?? [];

        $lines = [
            '<b>Team Health</b>',
            'Range: ' . $this->escape($report['range']['label'] ?? ''),
        ];

        if (!empty($report['top_mod'])) {
            $share = ($report['top_share'] ?? 0) * 100;
            $lines[] = 'Top workload: ' . $this->escape($report['top_mod']['display_name']) . ' (' . number_format($share, 1) . '% of messages)';
        }

        if (!empty($report['burnout'])) {
            $lines[] = 'Burnout risk:';
            foreach (array_slice($report['burnout'], 0, 5) as $name) {
                $lines[] = '- ' . $this->escape($name);
            }
        }

        if (!empty($coverage)) {
            $sortedLow = $coverage;
            asort($sortedLow);
            $lowHours = array_slice($sortedLow, 0, 5, true);
            $lines[] = 'Low coverage hours:';
            foreach ($lowHours as $hour => $count) {
                $lines[] = '- ' . sprintf('%02d:00', $hour) . ' (' . $count . ')';
            }
        }

        $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function handleTrendReport(int|string $responseChatId, int|string $chatId, string $args): void
    {
        if (!$this->requirePremium($chatId, $responseChatId)) {
            return;
        }
        $month = null;
        $budget = null;
        $tokens = preg_split('/\\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\\d{4}-\\d{2}$/', $token)) {
                $month = $token;
                continue;
            }
            if (is_numeric($token)) {
                $budget = (float)$token;
            }
        }
        $stats = $this->stats->getMonthlyStats($chatId, $month);
        $budget = $budget ?? (float)$stats['settings']['reward_budget'];

        $file = $this->trendReport->generate($chatId, $month, $budget);
        $caption = 'Trend report for ' . $stats['range']['label'];
        $this->tg->sendDocument($responseChatId, $file, $caption);
        if ($this->shouldLog('log_reports')) {
            Logger::info('Trend report generated for chat ' . $chatId . ' month ' . ($stats['range']['month'] ?? ''));
        }
    }

    private function handleExecutiveSummary(int|string $responseChatId, int|string $chatId, string $args): void
    {
        if (!$this->requirePremium($chatId, $responseChatId)) {
            return;
        }
        $month = null;
        $budget = null;
        $tokens = preg_split('/\\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\\d{4}-\\d{2}$/', $token)) {
                $month = $token;
                continue;
            }
            if (is_numeric($token)) {
                $budget = (float)$token;
            }
        }
        $stats = $this->stats->getMonthlyStats($chatId, $month);
        $budget = $budget ?? (float)$stats['settings']['reward_budget'];

        $file = $this->executiveSummary->generate($chatId, $month, $budget);
        $caption = 'Executive summary for ' . $stats['range']['label'];
        $this->tg->sendDocument($responseChatId, $file, $caption);
        if ($this->shouldLog('log_reports')) {
            Logger::info('Executive summary generated for chat ' . $chatId . ' month ' . ($stats['range']['month'] ?? ''));
        }
    }

    private function handleArchive(int|string $responseChatId, int|string $chatId): void
    {
        $rows = $this->archive->list((int)$chatId, null, 8);
        if (empty($rows)) {
            $this->tg->sendMessage($responseChatId, 'No archived reports yet.', ['parse_mode' => 'HTML']);
            return;
        }
        $lines = ['Recent archived reports:'];
        foreach ($rows as $row) {
            $lines[] = $row['month'] . ' | ' . $row['report_type'] . ' | ' . basename($row['file_path']);
        }
        $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function handlePremiumBenefits(int|string $responseChatId, int|string $chatId): void
    {
        $sub = $this->subscriptions->get($chatId);
        $plan = strtoupper($sub['plan'] ?? 'FREE');
        $lines = [
            '<b>Premium Benefits</b>',
            'Current plan: ' . $this->escape($plan),
            '',
            'Premium unlocks:',
            '- Coaching tips + consistency score',
            '- Team health (coverage gaps, workload balance, burnout risk)',
            '- Executive summary + trend report',
            '- PDF export for reward sheets',
            '- Import wizard (ChatKeeper + Combot CSVs)',
            '- Owner notifications (auto report DM, mid-month alerts, congrats)',
            '- Reward upgrades (max-share cap, stability bonus, penalty decay)',
            '',
            'See tiers: /pricing',
            'Upgrade (owner only): /setplan premium 30',
        ];
        $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::info('Premium benefits viewed for chat ' . $chatId);
        }
    }

    private function handlePricing(int|string $responseChatId, int|string $chatId): void
    {
        $sub = $this->subscriptions->get($chatId);
        $plan = strtoupper($sub['plan'] ?? 'FREE');

        $lines = [
            '<b>SP NET MOD TOOL Pricing</b>',
            'Current plan: ' . $this->escape($plan),
            '',
            '<b>Free</b>',
            '- Core analytics + reward sheets',
            '- Dashboard + CSV export',
            '- Auto monthly reports + mid‑month progress',
            '- Multi‑chat summary',
            '- ChatKeeper/Combot CLI imports',
            '',
            '<b>Premium</b>',
            '- Everything in Free',
            '- Coaching tips + consistency score',
            '- Team health (coverage gaps, workload balance, burnout risk)',
            '- Executive summary + trend report',
            '- PDF export for reward sheets',
            '- Import wizard (browser upload)',
            '- Owner notifications (DM reports, mid‑month alerts, congrats)',
            '- Reward upgrades (max‑share cap, stability bonus, penalty decay)',
            '',
            '<b>Enterprise</b>',
            '- Everything in Premium',
            '- Custom onboarding + setup help',
            '- White‑label branding',
            '- Dedicated support + SLA',
            '',
            'Upgrade (owner only): /setplan premium 30',
        ];

        $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::info('Pricing viewed for chat ' . $chatId);
        }
    }

    private function handleRosterAdd(int|string $responseChatId, int|string $chatId, string $args, array $message): void
    {
        $parts = preg_split('/\\s+/', trim($args), 3);
        $targetToken = $parts[0] ?? '';
        $role = $parts[1] ?? 'Mod';
        $notes = $parts[2] ?? null;

        $target = $this->resolveTargetUser($targetToken);
        if (!$target) {
            $target = $this->resolveTargetUserFromMessage($message);
        }
        if (!$target) {
            $this->tg->sendMessage($responseChatId, 'Usage: /rosteradd &lt;@username|user_id&gt; &lt;role&gt; [notes]', ['parse_mode' => 'HTML']);
            return;
        }

        $this->roster->setRole($chatId, $target['id'], $role, $notes);
        $this->tg->sendMessage($responseChatId, 'Roster updated for ' . $this->displayName($target) . ' (' . $this->escape($role) . ').', ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::info('Roster add/update in chat ' . $chatId . ' -> ' . $target['id'] . ' role ' . $role);
        }
    }

    private function handleRosterRemove(int|string $responseChatId, int|string $chatId, string $args, array $message): void
    {
        $target = $this->resolveTargetUser($args);
        if (!$target) {
            $target = $this->resolveTargetUserFromMessage($message);
        }
        if (!$target) {
            $this->tg->sendMessage($responseChatId, 'Usage: /rosterremove &lt;@username|user_id&gt;', ['parse_mode' => 'HTML']);
            return;
        }
        $this->roster->remove($chatId, $target['id']);
        $this->tg->sendMessage($responseChatId, 'Removed from roster: ' . $this->displayName($target), ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::info('Roster removed in chat ' . $chatId . ' -> ' . $target['id']);
        }
    }

    private function handleRosterList(int|string $responseChatId, int|string $chatId): void
    {
        $rows = $this->roster->list($chatId);
        if (empty($rows)) {
            $this->tg->sendMessage($responseChatId, 'Roster is empty.', ['parse_mode' => 'HTML']);
            return;
        }
        $lines = ['Mod roster:'];
        foreach ($rows as $row) {
            $name = $this->displayName($row);
            $line = $name . ' | ' . $row['role'];
            if (!empty($row['notes'])) {
                $line .= ' | ' . $row['notes'];
            }
            $lines[] = $line;
        }
        $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function handleRosterRole(int|string $responseChatId, int|string $chatId, string $args, array $message): void
    {
        $parts = preg_split('/\\s+/', trim($args), 3);
        $targetToken = $parts[0] ?? '';
        $role = $parts[1] ?? null;
        $notes = $parts[2] ?? null;
        if (!$role) {
            $this->tg->sendMessage($responseChatId, 'Usage: /rosterrole &lt;@username|user_id&gt; &lt;role&gt; [notes]', ['parse_mode' => 'HTML']);
            return;
        }
        $target = $this->resolveTargetUser($targetToken);
        if (!$target) {
            $target = $this->resolveTargetUserFromMessage($message);
        }
        if (!$target) {
            $this->tg->sendMessage($responseChatId, 'Usage: /rosterrole &lt;@username|user_id&gt; &lt;role&gt; [notes]', ['parse_mode' => 'HTML']);
            return;
        }
        $this->roster->setRole($chatId, $target['id'], $role, $notes);
        $this->tg->sendMessage($responseChatId, 'Role updated for ' . $this->displayName($target) . ' → ' . $this->escape($role) . '.', ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::info('Roster role updated in chat ' . $chatId . ' -> ' . $target['id'] . ' role ' . $role);
        }
    }

    private function requirePremium(int|string $chatId, int|string $responseChatId): bool
    {
        if ($this->subscriptions->isPremium($chatId)) {
            return true;
        }
        $this->tg->sendMessage($responseChatId, 'This feature is premium. Ask the owner to upgrade with /setplan premium 30.', ['parse_mode' => 'HTML']);
        return false;
    }

    private function shouldLog(string $key): bool
    {
        $logging = $this->config['logging'] ?? [];
        return !empty($logging[$key]);
    }

    private function handleSetBudget(int|string $responseChatId, int|string $chatId, string $args): void
    {
        $value = trim($args);
        if ($value === '' || !is_numeric($value)) {
            $this->tg->sendMessage($responseChatId, 'Usage: /setbudget &lt;amount&gt; [chat_id]', ['parse_mode' => 'HTML']);
            return;
        }
        $amount = (float)$value;
        $this->settings->updateBudget($chatId, $amount);
        $this->tg->sendMessage($responseChatId, 'Budget set to ' . number_format($amount, 2) . '.', ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::info('Budget updated for chat ' . $chatId . ' -> ' . number_format($amount, 2));
        }
    }

    private function handleSetTimezone(int|string $responseChatId, int|string $chatId, string $args): void
    {
        $tz = trim($args);
        if ($tz === '') {
            $this->tg->sendMessage($responseChatId, 'Usage: /settimezone &lt;Region/City&gt; [chat_id]', ['parse_mode' => 'HTML']);
            return;
        }
        try {
            new DateTimeZone($tz);
        } catch (\Exception $e) {
            $this->tg->sendMessage($responseChatId, 'Invalid timezone. Example: Asia/Kolkata', ['parse_mode' => 'HTML']);
            return;
        }
        $this->settings->updateTimezone($chatId, $tz);
        $this->tg->sendMessage($responseChatId, 'Timezone updated to ' . $this->escape($tz) . '.', ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::info('Timezone updated for chat ' . $chatId . ' -> ' . $tz);
        }
    }

    private function handleSetActivity(int|string $responseChatId, int|string $chatId, string $args): void
    {
        $parts = preg_split('/\s+/', trim($args));
        $gap = isset($parts[0]) ? (int)$parts[0] : 0;
        $floor = isset($parts[1]) ? (int)$parts[1] : 0;
        if ($gap <= 0 || $floor < 0) {
            $this->tg->sendMessage($responseChatId, 'Usage: /setactivity &lt;gap_minutes&gt; &lt;floor_minutes&gt; [chat_id]', ['parse_mode' => 'HTML']);
            return;
        }
        $this->settings->updateActivitySettings($chatId, $gap, $floor);
        $this->tg->sendMessage($responseChatId, 'Activity settings updated.', ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::info('Activity settings updated for chat ' . $chatId . ' gap ' . $gap . ' floor ' . $floor);
        }
    }

    private function resolveTargetUser(string $args): ?array
    {
        $token = trim($args);
        if ($token === '') {
            return null;
        }

        if (strpos($token, '@') === 0) {
            $username = substr($token, 1);
            if ($username === '') {
                return null;
            }
            $row = $this->db->fetch('SELECT id, username, first_name, last_name FROM users WHERE username = ? LIMIT 1', [$username]);
            if (!$row) {
                return null;
            }
            return [
                'id' => (int)$row['id'],
                'username' => $row['username'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
            ];
        }

        if (is_numeric($token)) {
            $userId = (int)$token;
            $row = $this->db->fetch('SELECT id, username, first_name, last_name FROM users WHERE id = ? LIMIT 1', [$userId]);
            if ($row) {
                return [
                    'id' => (int)$row['id'],
                    'username' => $row['username'],
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                ];
            }
            return [
                'id' => $userId,
                'username' => null,
                'first_name' => null,
                'last_name' => null,
            ];
        }

        return null;
    }

    private function resolveTargetUserFromMessage(array $message): ?array
    {
        $user = null;

        if (isset($message['reply_to_message'])) {
            $reply = $message['reply_to_message'];
            if (isset($reply['forward_from'])) {
                $user = $reply['forward_from'];
            } elseif (isset($reply['from'])) {
                $user = $reply['from'];
            }
        }

        if (!$user && isset($message['forward_from'])) {
            $user = $message['forward_from'];
        }

        if (!$user && isset($message['entities']) && is_array($message['entities'])) {
            foreach ($message['entities'] as $entity) {
                if (($entity['type'] ?? '') === 'text_mention' && isset($entity['user'])) {
                    $user = $entity['user'];
                    break;
                }
            }
        }

        if (!$user || !isset($user['id'])) {
            return null;
        }

        $this->upsertUser($user);

        return [
            'id' => (int)$user['id'],
            'username' => $user['username'] ?? null,
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
        ];
    }

    private function handleMyChats(int|string $responseChatId, int|string $userId): void
    {
        $chats = $this->getUserChats($userId, 20);
        if (empty($chats)) {
            $this->tg->sendMessage($responseChatId, 'No group chats found yet. Add me to a group and send a message there.', ['parse_mode' => 'HTML']);
            return;
        }

        $defaultChatId = $this->userSettings->getDefaultChatId($userId);
        $lines = ['Your chats (use /usechat &lt;chat_id&gt; to set a default):'];
        foreach ($chats as $chat) {
            $title = $chat['title'] ?: 'Untitled';
            $mod = !empty($chat['is_mod']) ? ' (mod)' : '';
            $isDefault = ($defaultChatId !== null && (int)$defaultChatId === (int)$chat['id']) ? ' (default)' : '';
            $lines[] = $chat['id'] . ' | ' . $this->escape($title) . $mod . $isDefault;
        }

        $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function handleUseChat(int|string $responseChatId, int|string $userId, string $args): void
    {
        $token = trim($args);
        if ($token === '' || strtolower($token) === 'help') {
            $chats = $this->getUserChats($userId, 10);
            if (empty($chats)) {
                $this->tg->sendMessage($responseChatId, 'No group chats found yet. Add me to a group and send a message there.', ['parse_mode' => 'HTML']);
                return;
            }
            if (count($chats) === 1) {
                $chatId = (int)$chats[0]['id'];
                $this->userSettings->setDefaultChatId($userId, $chatId);
                $this->tg->sendMessage($responseChatId, 'Default chat set to ' . $chatId . '.', ['parse_mode' => 'HTML']);
                return;
            }
            $lines = [
                'Usage: /usechat &lt;chat_id&gt; or /usechat &lt;part of title&gt;',
                'Use /mychats to see your chat ids.',
            ];
            $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
            return;
        }

        $lower = strtolower($token);
        if (in_array($lower, ['off', 'clear', 'none'], true)) {
            $this->userSettings->clearDefaultChatId($userId);
            $this->tg->sendMessage($responseChatId, 'Default chat cleared.', ['parse_mode' => 'HTML']);
            return;
        }

        if (preg_match('/^-?\\d{6,}$/', $token)) {
            $chatId = (int)$token;
            if (!$this->userHasChat($userId, $chatId)) {
                $this->tg->sendMessage($responseChatId, 'Chat id not found. Use /mychats to get the correct id.', ['parse_mode' => 'HTML']);
                return;
            }
            $this->userSettings->setDefaultChatId($userId, $chatId);
            $this->tg->sendMessage($responseChatId, 'Default chat set to ' . $chatId . '.', ['parse_mode' => 'HTML']);
            return;
        }

        $chats = $this->getUserChats($userId, 20);
        $matches = [];
        foreach ($chats as $chat) {
            $title = $chat['title'] ?: '';
            if ($title !== '' && stripos($title, $token) !== false) {
                $matches[] = $chat;
            }
        }

        if (empty($matches)) {
            $this->tg->sendMessage($responseChatId, 'No chats matched that title. Use /mychats for IDs.', ['parse_mode' => 'HTML']);
            return;
        }

        if (count($matches) > 1) {
            $lines = ['Multiple chats matched. Please be more specific:'];
            foreach ($matches as $chat) {
                $title = $chat['title'] ?: 'Untitled';
                $lines[] = $chat['id'] . ' | ' . $this->escape($title);
            }
            $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
            return;
        }

        $chatId = (int)$matches[0]['id'];
        $this->userSettings->setDefaultChatId($userId, $chatId);
        $this->tg->sendMessage($responseChatId, 'Default chat set to ' . $chatId . '.', ['parse_mode' => 'HTML']);
    }

    private function resolveTargetChatId(int|string $userId, int|string $responseChatId, string $args): array
    {
        $tokens = preg_split('/\s+/', trim($args));
        $chatId = null;
        $rest = [];

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^(?:chat|chatid|group|id):(-?\d+)$/i', $token, $match)) {
                if ($chatId === null) {
                    $chatId = (int)$match[1];
                    continue;
                }
            }
            if ($chatId === null && preg_match('/^-?\d{6,}$/', $token)) {
                $chatId = (int)$token;
                continue;
            }
            $rest[] = $token;
        }

        $cleanArgs = trim(implode(' ', $rest));
        if ($chatId !== null) {
            return [$chatId, $cleanArgs];
        }

        $defaultChatId = $this->userSettings->getDefaultChatId($userId);
        if ($defaultChatId !== null) {
            if ($this->userHasChat($userId, $defaultChatId)) {
                return [(int)$defaultChatId, $cleanArgs];
            }
            $this->userSettings->clearDefaultChatId($userId);
        }

        $chats = $this->getUserChats($userId, 10);
        if (count($chats) === 1) {
            return [(int)$chats[0]['id'], $cleanArgs];
        }

        if (empty($chats)) {
            $this->tg->sendMessage($responseChatId, 'No group chats found yet. Add me to a group and send a message there.', ['parse_mode' => 'HTML']);
            return [null, $cleanArgs];
        }

        $lines = [
            'Please include a chat id or set /usechat. Example: /stats -1001234567890',
            'Your chats:',
        ];
        foreach ($chats as $chat) {
            $title = $chat['title'] ?: 'Untitled';
            $mod = !empty($chat['is_mod']) ? ' (mod)' : '';
            $lines[] = $chat['id'] . ' | ' . $this->escape($title) . $mod;
        }
        $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
        return [null, $cleanArgs];
    }

    private function getUserChats(int|string $userId, int $limit): array
    {
        $limit = max(1, min(50, (int)$limit));
        $sql = 'SELECT c.id, c.title, c.type, cm.is_mod, cm.updated_at
                FROM chat_members cm
                JOIN chats c ON c.id = cm.chat_id
                WHERE cm.user_id = ? AND c.type IN (\'group\', \'supergroup\')
                ORDER BY cm.updated_at DESC
                LIMIT ' . $limit;

        return $this->db->fetchAll($sql, [$userId]);
    }

    private function userHasChat(int|string $userId, int|string $chatId): bool
    {
        $row = $this->db->fetch(
            'SELECT 1 FROM chat_members cm JOIN chats c ON c.id = cm.chat_id WHERE cm.user_id = ? AND cm.chat_id = ? AND c.type IN (\'group\', \'supergroup\') LIMIT 1',
            [$userId, $chatId]
        );
        return (bool)$row;
    }

    private function handleModCommand(int|string $chatId, array $message, string $args): void
    {
        if (!isset($message['reply_to_message']['from'])) {
            $this->tg->sendMessage($chatId, 'Reply to a user with /mod add or /mod remove.', ['parse_mode' => 'HTML']);
            return;
        }

        $target = $message['reply_to_message']['from'];
        $this->upsertUser($target);
        $this->ensureChatMember($chatId, $target['id']);

        $action = strtolower(trim($args));
        if ($action === 'add') {
            $this->setModStatus($chatId, $target['id'], true);
            $this->tg->sendMessage($chatId, 'Mod added: ' . $this->displayName($target), ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::info('Mod added via group command in chat ' . $chatId . ' -> ' . $target['id']);
            }
            return;
        }

        if ($action === 'remove') {
            $this->setModStatus($chatId, $target['id'], false);
            $this->tg->sendMessage($chatId, 'Mod removed: ' . $this->displayName($target), ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::info('Mod removed via group command in chat ' . $chatId . ' -> ' . $target['id']);
            }
            return;
        }

        $this->tg->sendMessage($chatId, 'Usage: /mod add or /mod remove (reply to a user).', ['parse_mode' => 'HTML']);
    }

    private function handleAutoReport(int|string $responseChatId, int|string $chatId, string $args): void
    {
        $parts = preg_split('/\\s+/', trim($args));
        $action = strtolower($parts[0] ?? '');

        if ($action === 'status' || $action === '') {
            $settings = $this->settings->get($chatId);
            $status = !empty($settings['auto_report_enabled']) ? 'ON' : 'OFF';
            $day = $settings['auto_report_day'] ?? 1;
            $hour = $settings['auto_report_hour'] ?? 9;
            $this->tg->sendMessage($responseChatId, 'Auto report: ' . $status . ' | Day ' . $day . ' at ' . $hour . ':00.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($action === 'on') {
            $day = isset($parts[1]) ? (int)$parts[1] : null;
            $hour = isset($parts[2]) ? (int)$parts[2] : null;
            if ($day !== null && ($day < 1 || $day > 28)) {
                $this->tg->sendMessage($responseChatId, 'Day must be between 1 and 28.', ['parse_mode' => 'HTML']);
                return;
            }
            if ($hour !== null && ($hour < 0 || $hour > 23)) {
                $this->tg->sendMessage($responseChatId, 'Hour must be between 0 and 23.', ['parse_mode' => 'HTML']);
                return;
            }
            $this->settings->updateAutoReport($chatId, true, $day, $hour);
            $this->tg->sendMessage($responseChatId, 'Auto report enabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::info('Auto report enabled for chat ' . $chatId);
            }
            return;
        }

        if ($action === 'off') {
            $this->settings->updateAutoReport($chatId, false, null, null);
            $this->tg->sendMessage($responseChatId, 'Auto report disabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::info('Auto report disabled for chat ' . $chatId);
            }
            return;
        }

        $this->tg->sendMessage($responseChatId, 'Usage: /autoreport on [day] [hour] [chat_id] | /autoreport off [chat_id] | /autoreport status [chat_id]', ['parse_mode' => 'HTML']);
    }

    private function handleAutoProgress(int|string $responseChatId, int|string $chatId, string $args): void
    {
        $parts = preg_split('/\\s+/', trim($args));
        $action = strtolower($parts[0] ?? '');

        if ($action === 'status' || $action === '') {
            $settings = $this->settings->get($chatId);
            $status = !empty($settings['progress_report_enabled']) ? 'ON' : 'OFF';
            $day = $settings['progress_report_day'] ?? 15;
            $hour = $settings['progress_report_hour'] ?? 12;
            $this->tg->sendMessage($responseChatId, 'Progress report: ' . $status . ' | Day ' . $day . ' at ' . $hour . ':00.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($action === 'on') {
            $day = isset($parts[1]) ? (int)$parts[1] : null;
            $hour = isset($parts[2]) ? (int)$parts[2] : null;
            if ($day !== null && ($day < 1 || $day > 28)) {
                $this->tg->sendMessage($responseChatId, 'Day must be between 1 and 28.', ['parse_mode' => 'HTML']);
                return;
            }
            if ($hour !== null && ($hour < 0 || $hour > 23)) {
                $this->tg->sendMessage($responseChatId, 'Hour must be between 0 and 23.', ['parse_mode' => 'HTML']);
                return;
            }
            $this->settings->updateProgressReport($chatId, true, $day, $hour);
            $this->tg->sendMessage($responseChatId, 'Progress report enabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::info('Progress report enabled for chat ' . $chatId);
            }
            return;
        }

        if ($action === 'off') {
            $this->settings->updateProgressReport($chatId, false, null, null);
            $this->tg->sendMessage($responseChatId, 'Progress report disabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::info('Progress report disabled for chat ' . $chatId);
            }
            return;
        }

        $this->tg->sendMessage($responseChatId, 'Usage: /autoprogress on [day] [hour] [chat_id] | /autoprogress off [chat_id] | /autoprogress status [chat_id]', ['parse_mode' => 'HTML']);
    }

    private function handleProgressReport(int|string $responseChatId, int|string $chatId, string $args): void
    {
        $budget = null;
        $tokens = preg_split('/\\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (is_numeric($token)) {
                $budget = (float)$token;
            }
        }

        $bundle = $this->stats->getMonthToDateStats($chatId);
        if (empty($bundle['mods'])) {
            $this->tg->sendMessage($responseChatId, 'No mods are configured yet. Use /modadd [chat_id] @username in private chat (or /usechat).', ['parse_mode' => 'HTML']);
            return;
        }

        if ($budget === null) {
            $budget = (float)($bundle['settings']['reward_budget'] ?? 0);
        }

        $suffix = ($bundle['range']['month'] ?? 'mtd') . '-mtd';
        $filePath = $this->rewardSheet->generate($chatId, null, $budget, $bundle, $suffix);
        $caption = 'Progress report (MTD) for ' . $bundle['range']['label'] . ' (budget: ' . number_format($budget, 2) . ')';
        $this->tg->sendDocument($responseChatId, $filePath, $caption);
        if ($this->shouldLog('log_reports')) {
            Logger::info('Progress report generated for chat ' . $chatId . ' month ' . ($bundle['range']['month'] ?? ''));
        }
    }

    private function handleModerationCommand(int|string $chatId, array $message, string $command, string $args): void
    {
        if (!isset($message['reply_to_message']['from'])) {
            $this->tg->sendMessage($chatId, 'Reply to a user to perform moderation.', ['parse_mode' => 'HTML']);
            return;
        }

        $actor = $message['from'];
        $target = $message['reply_to_message']['from'];
        $this->upsertUser($target);
        $this->ensureChatMember($chatId, $actor['id']);
        $this->setModStatus($chatId, $actor['id'], true);

        $reason = trim($args);
        $durationMinutes = null;

        if ($command === 'mute') {
            $parts = preg_split('/\s+/', trim($args));
            if (!empty($parts[0]) && is_numeric($parts[0])) {
                $durationMinutes = (int)$parts[0];
                array_shift($parts);
                $reason = trim(implode(' ', $parts));
            } else {
                $durationMinutes = 60;
            }
        }

        $reasonSafe = $reason !== '' ? $this->escape($reason) : '';

        if ($command === 'warn') {
            $this->recordAction($chatId, $actor['id'], $target['id'], 'warn', $reason, null);
            $this->tg->sendMessage($chatId, 'Warned ' . $this->displayName($target) . ($reasonSafe ? ': ' . $reasonSafe : '.'), ['parse_mode' => 'HTML']);
            return;
        }

        if ($command === 'mute') {
            $until = time() + ($durationMinutes * 60);
            $this->tg->restrictChatMember($chatId, $target['id'], $until);
            $this->recordAction($chatId, $actor['id'], $target['id'], 'mute', $reason, $durationMinutes);
            $this->tg->sendMessage($chatId, 'Muted ' . $this->displayName($target) . ' for ' . $durationMinutes . ' minutes.' . ($reasonSafe ? ' ' . $reasonSafe : ''), ['parse_mode' => 'HTML']);
            return;
        }

        if ($command === 'ban') {
            $this->tg->banChatMember($chatId, $target['id']);
            $this->recordAction($chatId, $actor['id'], $target['id'], 'ban', $reason, null);
            $this->tg->sendMessage($chatId, 'Banned ' . $this->displayName($target) . ($reasonSafe ? ': ' . $reasonSafe : '.'), ['parse_mode' => 'HTML']);
            return;
        }

        if ($command === 'unmute') {
            $this->tg->unrestrictChatMember($chatId, $target['id']);
            $this->recordAction($chatId, $actor['id'], $target['id'], 'unmute', $reason, null);
            $this->tg->sendMessage($chatId, 'Unmuted ' . $this->displayName($target) . '.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($command === 'unban') {
            $this->tg->unbanChatMember($chatId, $target['id']);
            $this->recordAction($chatId, $actor['id'], $target['id'], 'unban', $reason, null);
            $this->tg->sendMessage($chatId, 'Unbanned ' . $this->displayName($target) . '.', ['parse_mode' => 'HTML']);
        }
    }

    private function isAuthorized(int|string $chatId, int|string $userId): bool
    {
        if ($this->isOwner($userId)) {
            return true;
        }
        $isMod = $this->isMod($chatId, $userId);
        if (!$this->config['use_telegram_admins']) {
            return $isMod;
        }

        $resp = $this->tg->getChatMember($chatId, $userId);
        if (!($resp['ok'] ?? false)) {
            return $isMod;
        }

        $status = $resp['result']['status'] ?? '';
        if (in_array($status, ['administrator', 'creator'], true)) {
            return true;
        }

        return $isMod;
    }

    private function isOwner(int|string $userId): bool
    {
        $owners = $this->config['owner_user_ids'] ?? [];
        if (!is_array($owners)) {
            return false;
        }
        $userId = (int)$userId;
        foreach ($owners as $owner) {
            if ((int)$owner === $userId) {
                return true;
            }
        }
        return false;
    }

    private function isMod(int|string $chatId, int|string $userId): bool
    {
        $row = $this->db->fetch('SELECT is_mod FROM chat_members WHERE chat_id = ? AND user_id = ?', [$chatId, $userId]);
        return $row ? (bool)$row['is_mod'] : false;
    }

    private function setModStatus(int|string $chatId, int|string $userId, bool $isMod): void
    {
        $this->db->exec(
            'UPDATE chat_members SET is_mod = ?, updated_at = ? WHERE chat_id = ? AND user_id = ?',
            [$isMod ? 1 : 0, $this->nowUtc(), $chatId, $userId]
        );
    }

    private function recordMessage(int|string $chatId, array $message): void
    {
        if (!isset($message['from'])) {
            return;
        }
        $userId = $message['from']['id'];
        $messageId = $message['message_id'] ?? null;
        if ($messageId === null) {
            return;
        }
        $sentAt = gmdate('Y-m-d H:i:s', $message['date']);

        $this->db->exec(
            'INSERT IGNORE INTO messages (chat_id, user_id, message_id, sent_at)
             VALUES (?, ?, ?, ?)',
            [$chatId, $userId, $messageId, $sentAt]
        );
    }

    private function recordAction(int|string $chatId, int|string $modId, int|string $targetId, string $type, ?string $reason, ?int $durationMinutes): void
    {
        $this->db->exec(
            'INSERT INTO actions (chat_id, mod_id, target_user_id, action_type, reason, duration_minutes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$chatId, $modId, $targetId, $type, $reason ?: null, $durationMinutes, $this->nowUtc()]
        );
    }

    private function recordMembershipJoin(int|string $chatId, int|string $userId, int $timestamp): void
    {
        $joinedAt = gmdate('Y-m-d H:i:s', $timestamp);
        $this->db->exec(
            'INSERT INTO memberships (chat_id, user_id, joined_at, left_at)
             VALUES (?, ?, ?, NULL)',
            [$chatId, $userId, $joinedAt]
        );
    }

    private function recordMembershipLeave(int|string $chatId, int|string $userId, int $timestamp): void
    {
        $leftAt = gmdate('Y-m-d H:i:s', $timestamp);
        $existing = $this->db->fetch(
            'SELECT id FROM memberships WHERE chat_id = ? AND user_id = ? AND left_at IS NULL ORDER BY joined_at DESC LIMIT 1',
            [$chatId, $userId]
        );

        if ($existing) {
            $this->db->exec('UPDATE memberships SET left_at = ? WHERE id = ?', [$leftAt, $existing['id']]);
            return;
        }

        $this->db->exec(
            'INSERT INTO memberships (chat_id, user_id, joined_at, left_at)
             VALUES (?, ?, ?, ?)',
            [$chatId, $userId, $leftAt, $leftAt]
        );
    }

    private function upsertUser(array $user): void
    {
        $this->db->exec(
            'INSERT INTO users (id, username, first_name, last_name, is_bot, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE username = VALUES(username), first_name = VALUES(first_name), last_name = VALUES(last_name), is_bot = VALUES(is_bot), updated_at = VALUES(updated_at)',
            [
                $user['id'],
                $user['username'] ?? null,
                $user['first_name'] ?? null,
                $user['last_name'] ?? null,
                !empty($user['is_bot']) ? 1 : 0,
                $this->nowUtc(),
                $this->nowUtc(),
            ]
        );
    }

    private function upsertChat(array $chat): void
    {
        $this->db->exec(
            'INSERT INTO chats (id, title, type, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), type = VALUES(type), updated_at = VALUES(updated_at)',
            [
                $chat['id'],
                $chat['title'] ?? ($chat['username'] ?? null),
                $chat['type'] ?? 'unknown',
                $this->nowUtc(),
                $this->nowUtc(),
            ]
        );
    }

    private function ensureChatMember(int|string $chatId, int|string $userId): void
    {
        $this->db->exec(
            'INSERT INTO chat_members (chat_id, user_id, is_mod, created_at, updated_at)
             VALUES (?, ?, 0, ?, ?)
             ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)',
            [$chatId, $userId, $this->nowUtc(), $this->nowUtc()]
        );
    }

    private function isRegularMessage(array $message): bool
    {
        if (isset($message['new_chat_members']) || isset($message['left_chat_member'])) {
            return false;
        }

        $types = [
            'text', 'photo', 'video', 'document', 'audio', 'voice', 'sticker',
            'animation', 'video_note', 'contact', 'location', 'venue', 'poll'
        ];

        foreach ($types as $type) {
            if (isset($message[$type])) {
                return true;
            }
        }

        return false;
    }

    private function parseCommand(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || $text[0] !== '/') {
            return null;
        }

        $parts = preg_split('/\s+/', $text, 2);
        $commandPart = $parts[0];
        $args = $parts[1] ?? '';

        $commandPart = ltrim($commandPart, '/');
        $commandBits = explode('@', $commandPart, 2);
        $command = strtolower($commandBits[0]);
        $botMention = $commandBits[1] ?? null;

        $botUsername = $this->config['bot_username'] ?? null;
        if ($botMention && $botUsername && strcasecmp($botMention, $botUsername) !== 0) {
            return null;
        }

        return [
            'command' => $command,
            'args' => $args,
        ];
    }

    private function helpText(bool $private): string
    {
        if ($private) {
            return implode("\n", [
                '<b>SP NET MOD TOOL</b>',
                'Use these in private chat.',
                'Tip: set a default chat with /usechat &lt;chat_id&gt; to skip chat ids.',
                '/mychats - list your group chats',
                '/usechat &lt;chat_id&gt; | /usechat &lt;title&gt; | /usechat off',
                '/guide (full usage guide with examples)',
                '/stats [chat_id] [YYYY-MM] [@user]',
                '/leaderboard [chat_id] [YYYY-MM] [budget]',
                '/report [chat_id] [YYYY-MM] [budget]',
                '/reportcsv [chat_id] [YYYY-MM] [budget]',
                '/exportgsheet [chat_id] [YYYY-MM] [budget]',
                '/summary [YYYY-MM] [budget]',
                '/plan',
                '/setplan &lt;free|premium&gt; [days] (owner only)',
                '/coach [YYYY-MM]',
                '/health [YYYY-MM]',
                '/trend [YYYY-MM] [budget]',
                '/execsummary [YYYY-MM] [budget]',
                '/archive',
                '/premium (view premium benefits)',
                '/pricing (tiers + features)',
                '/setbudget &lt;amount&gt; [chat_id]',
                '/settimezone &lt;Region/City&gt; [chat_id]',
                '/setactivity &lt;gap_minutes&gt; &lt;floor_minutes&gt; [chat_id]',
                '/autoreport on [day] [hour] [chat_id]',
                '/autoprogress on [day] [hour] [chat_id]',
                '/progress [chat_id] [budget]',
                '/modadd [chat_id] &lt;@username|user_id&gt;',
                '/modremove [chat_id] &lt;@username|user_id&gt;',
                '/modlist [chat_id]',
                '/rosteradd &lt;@username|user_id&gt; &lt;role&gt; [notes]',
                '/rosterrole &lt;@username|user_id&gt; &lt;role&gt; [notes]',
                '/rosterremove &lt;@username|user_id&gt;',
                '/rosterlist',
            ]);
        }

        return implode("\n", [
            '<b>SP NET MOD TOOL</b>',
            'Moderation commands are disabled.',
            'Analytics commands are available in private chat with me.',
        ]);
    }

    private function handleGuide(int|string $chatId): void
    {
        foreach ($this->guideTextParts() as $part) {
            $resp = $this->tg->sendMessage($chatId, $part, ['parse_mode' => 'HTML']);
            if (!($resp['ok'] ?? false)) {
                $plain = strip_tags($part);
                $plain = html_entity_decode($plain, ENT_QUOTES, 'UTF-8');
                $this->tg->call('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $plain,
                    'disable_web_page_preview' => true,
                ]);
            }
        }
    }

    private function guideTextParts(): array
    {
        $parts = [];

        $parts[] = implode("\n", [
            '<b>SP NET MOD TOOL – Usage Guide (1/3)</b>',
            'All commands are used in <b>private chat</b> with the bot.',
            '',
            '<b>Step 1: Add bot to groups</b>',
            '1) Add bot to the group',
            '2) Make it admin or disable privacy mode in BotFather',
            '3) Send any message in the group',
            '',
            '<b>Step 2: Pick a default chat</b>',
            '<code>/mychats</code>',
            '<code>/usechat &lt;chat_id&gt;</code>',
            '',
            '<b>Step 3: Add mods</b>',
            '<code>/modadd @alex</code>',
            '<code>/modadd 123456789</code>',
            '<code>/modremove @alex</code>',
            '<code>/modlist</code>',
            '',
            'Tip: Forward a user message here and reply <code>/modadd</code>.',
        ]);

        $parts[] = implode("\n", [
            '<b>Usage Guide (2/3) – Stats + Rewards</b>',
            '<b>Stats</b>',
            '<code>/stats</code>',
            '<code>/stats 2026-02</code>',
            '<code>/stats @alex</code>',
            '<code>/stats &lt;chat_id&gt; 2026-02 @alex</code>',
            '',
            '<b>Leaderboards</b>',
            '<code>/leaderboard</code>',
            '<code>/leaderboard 2026-02</code>',
            '',
            '<b>Reward sheets</b>',
            '<code>/report 2026-02 5000</code>',
            '<code>/reportcsv 2026-02 5000</code>',
            '',
            '<b>Mid-month progress</b>',
            '<code>/progress</code>',
            '<code>/progress 7500</code>',
            '',
            '<b>Multi-chat summary</b>',
            '<code>/summary 2026-02 12000</code>',
        ]);

        $parts[] = implode("\n", [
            '<b>Usage Guide (3/3) – Automation + Extras</b>',
            '<b>Automation</b>',
            '<code>/autoreport on 1 9</code>',
            '<code>/autoprogress on 15 12</code>',
            '',
            '<b>Premium insights</b>',
            '<code>/coach 2026-02</code>',
            '<code>/health 2026-02</code>',
            '<code>/trend 2026-02 5000</code>',
            '<code>/execsummary 2026-02 5000</code>',
            '',
            '<b>Dashboard</b>',
            'Open in browser:',
            '<code>http://127.0.0.1:8000/dashboard.php?token=YOUR_TOKEN</code>',
            '',
            '<b>Imports</b>',
            '<code>php bin/import-chatkeeper.php --file=/path/analysis_users.csv --chat=&lt;chat_id&gt; --month=2026-02</code>',
            '<code>php bin/import-combot.php --file=/path/combot.csv --chat=&lt;chat_id&gt; --month=2026-02</code>',
            '',
            '<b>Troubleshooting</b>',
            'If bot is silent: check DNS/network and ensure it can reach api.telegram.org.',
        ]);

        return $parts;
    }

    private function formatStatsMessage(array $stat, array $range): string
    {
        $name = $stat['display_name'];
        $lines = [];
        $lines[] = '<b>Stats for ' . $this->escape($name) . '</b>';
        $lines[] = 'Month: ' . $range['label'];
        $lines[] = 'Score: ' . number_format($stat['score'], 2);
        $lines[] = 'Messages: ' . $stat['messages'];
        $lines[] = 'Warnings issued: ' . $stat['warnings'];
        $lines[] = 'Mutes issued: ' . $stat['mutes'];
        $lines[] = 'Bans issued: ' . $stat['bans'];
        $lines[] = 'Active minutes: ' . number_format($stat['active_minutes'], 1);
        $lines[] = 'Membership minutes: ' . number_format($stat['membership_minutes'], 1);
        $lines[] = 'Days active: ' . $stat['days_active'];
        $lines[] = 'Peak hour: ' . $stat['peak_hour'];
        if ($stat['improvement'] !== null) {
            $lines[] = 'Improvement vs last month: ' . number_format($stat['improvement'], 1) . '%';
        }
        return implode("\n", $lines);
    }

    private function formatLeaderboardMessage(array $ranked, array $range, float $budget): string
    {
        $lines = [];
        $lines[] = '<b>Leaderboard - ' . $range['label'] . '</b>';
        $lines[] = 'Budget: ' . number_format($budget, 2);
        $lines[] = '';

        $rank = 1;
        foreach ($ranked as $mod) {
            $lines[] = $rank . '. ' . $this->escape($mod['display_name']) .
                ' | Score ' . number_format($mod['score'], 2) .
                ' | Reward ' . number_format($mod['reward'], 2);
            $rank++;
        }

        return implode("\n", $lines);
    }

    private function displayName(array $user): string
    {
        if (!empty($user['username'])) {
            return $this->escape('@' . $user['username']);
        }
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        $value = $name !== '' ? $name : 'User ' . $user['id'];
        return $this->escape($value);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
