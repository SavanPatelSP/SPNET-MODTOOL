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
use App\Services\PerformanceReviewService;
use App\Services\RetentionRiskService;
use App\Services\RosterService;
use App\Services\ModGoalService;
use App\Services\ArchiveService;
use App\Services\ReportApprovalService;
use App\Services\AuditLogService;
use App\Services\PaymentService;
use App\Services\ReportChannelService;
use App\Reports\RewardSheet;
use App\Reports\RewardCsv;
use App\Reports\MultiChatReport;
use App\Reports\ExecutiveSummary;
use App\Reports\TrendReport;
use App\Reports\TimesheetCsv;
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
    private PerformanceReviewService $performanceReview;
    private RetentionRiskService $retentionRisk;
    private RosterService $roster;
    private ModGoalService $goals;
    private ArchiveService $archive;
    private ReportApprovalService $approvals;
    private AuditLogService $audit;
    private PaymentService $payments;
    private RewardSheet $rewardSheet;
    private RewardCsv $rewardCsv;
    private MultiChatReport $multiChatReport;
    private ExecutiveSummary $executiveSummary;
    private TrendReport $trendReport;
    private TimesheetCsv $timesheetCsv;
    private GoogleSheetsService $googleSheets;
    private ReportChannelService $reporter;

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
        $this->approvals = new ReportApprovalService($db);
        $this->audit = new AuditLogService($db);
        $this->rewards->setAuditLogger($this->audit);
        $this->payments = new PaymentService($db, $config);
        $this->coaching = new CoachingService($this->stats, $this->settings, $config);
        $this->health = new HealthService($this->stats, $this->settings, $config);
        $this->performanceReview = new PerformanceReviewService($this->stats, $this->settings, $config);
        $this->retentionRisk = new RetentionRiskService($this->stats, $this->settings, $config);
        $this->roster = new RosterService($db);
        $this->goals = new ModGoalService($db);
        $this->rewardSheet = new RewardSheet($this->stats, $this->rewards, $config, $this->rewardContext, $this->rewardHistory, $this->archive, $this->approvals);
        $this->rewardCsv = new RewardCsv($this->stats, $this->rewards, $this->rewardContext, $this->rewardHistory, $this->archive);
        $this->multiChatReport = new MultiChatReport($this->stats, $this->rewards, $config);
        $this->executiveSummary = new ExecutiveSummary($this->stats, $this->rewards, $config, $this->rewardContext, $this->archive);
        $this->trendReport = new TrendReport($this->stats, $this->rewards, $config, $this->rewardContext, $this->archive);
        $this->timesheetCsv = new TimesheetCsv($this->stats);
        $this->googleSheets = new GoogleSheetsService($config);
        $this->reporter = new ReportChannelService($tg, $config);
    }

    public function handleUpdate(array $update): void
    {
        if (isset($update['pre_checkout_query'])) {
            $this->handlePreCheckoutQuery($update['pre_checkout_query']);
            return;
        }

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

        if ($chatType !== 'private' && !$this->isWhitelisted($chatId)) {
            return;
        }

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

        if (!empty($message['successful_payment'])) {
            $this->handleSuccessfulPayment($message);
            return;
        }

        if ($this->isRegularMessage($message)) {
            $this->recordMessage($chatId, $message);
            if ($this->shouldLog('log_updates')) {
                Logger::infoContext('Message update', $this->logContextFromMessage($chatId, $message));
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
            Logger::infoContext(
                'Command received',
                $this->logContextFromMessage($chatId, $message, [
                    'command' => $parsed['command'],
                    'args' => $parsed['args'] !== '' ? $parsed['args'] : null,
                ])
            );
        }

        $this->logCommandAudit($message, $parsed, $chatType);
        $this->handleCommand($chatId, $message, $parsed['command'], $parsed['args'], $chatType);
    }

    private function handleChatMember(array $chatMemberUpdate): void
    {
        if (!isset($chatMemberUpdate['chat'], $chatMemberUpdate['new_chat_member'], $chatMemberUpdate['old_chat_member'])) {
            return;
        }

        $chat = $chatMemberUpdate['chat'];
        $chatId = $chat['id'];
        if (!$this->isWhitelisted($chatId)) {
            return;
        }
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
            'setbudget', 'settimezone', 'setactivity', 'autoreport', 'autoprogress', 'progress', 'forecast', 'mychats',
            'usechat', 'modadd', 'modremove', 'modlist',
            'plan', 'setplan', 'coach', 'health', 'trend', 'execsummary', 'archive',
            'rosteradd', 'rosterremove', 'rosterlist', 'rosterrole',
            'premium', 'benefits', 'pricing', 'guide', 'giftplan', 'grantplan', 'approval', 'approvereport', 'approvalstatus', 'auditlogcsv',
            'buy_stars_test', 'buy_crypto_test', 'paystatus', 'debughours', 'debughoursall', 'finduser', 'autoweekly', 'autoinactive', 'activityrank',
            'aireview', 'autoaireview', 'retention', 'autoretention', 'timesheet', 'compare', 'weeklysummary', 'autospike',
            'mydashboard', 'goalset', 'goalstatus', 'goalclear', 'autofeedback',
            'modaudit',
        ];
        $moderationCommands = ['warn', 'mute', 'ban', 'unmute', 'unban', 'mod'];

        if (!$isPrivate) {
            return;
        }

        if (!$this->isManager($userId)) {
            return;
        }

        if ($isPrivate) {
            if (in_array($command, ['help', 'start'], true)) {
                $this->tg->sendMessage($chatId, $this->helpText(true), ['parse_mode' => 'HTML']);
                return;
            }

            if ($command === 'whoami') {
                $this->handleWhoAmI($chatId, $userId);
                return;
            }

            if ($command === 'botusers') {
                $this->handleBotUsers($chatId, $args);
                return;
            }

            if ($command === 'linkedchat') {
                $this->handleLinkedChat($chatId, $userId);
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

            if (in_array($command, ['giftplan', 'grantplan'], true)) {
                $this->handleGiftPlan($chatId, $args, $userId);
                return;
            }

            if ($command === 'approval') {
                $this->handleApprovalToggle($chatId, $args, $userId);
                return;
            }

            if ($command === 'approvereport') {
                $this->handleApproveReport($chatId, $args, $userId);
                return;
            }

            if ($command === 'approvalstatus') {
                $this->handleApprovalStatus($chatId, $args, $userId);
                return;
            }

            if ($command === 'auditlogcsv') {
                $this->handleAuditLogCsv($chatId, $args, $userId);
                return;
            }
            if ($command === 'buy_stars_test') {
                $this->handleTestPurchase($chatId, $userId, $args, 'stars');
                return;
            }
            if ($command === 'buy_crypto_test') {
                $this->handleTestPurchase($chatId, $userId, $args, 'crypto');
                return;
            }
            if ($command === 'paystatus') {
                $this->handlePaymentStatus($chatId, $userId);
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
                    case 'autoweekly':
                        $this->handleAutoWeekly($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'autoinactive':
                        $this->handleAutoInactive($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'autoaireview':
                        $this->handleAutoAiReview($chatId, $targetChatId, $cleanArgs, $userId);
                        return;
                    case 'activityrank':
                        $this->handleActivityRank($chatId, $targetChatId, $cleanArgs, $userId);
                        return;
                    case 'aireview':
                        $this->handleAiReview($chatId, $targetChatId, $cleanArgs, $userId);
                        return;
                    case 'autoretention':
                        $this->handleAutoRetention($chatId, $targetChatId, $cleanArgs, $userId);
                        return;
                    case 'retention':
                        $this->handleRetentionAlert($chatId, $targetChatId, $cleanArgs, $userId);
                        return;
                    case 'weeklysummary':
                        $this->handleWeeklySummaryManual($chatId, $targetChatId, $cleanArgs, $userId);
                        return;
                    case 'autospike':
                        $this->handleAutoSpike($chatId, $targetChatId, $cleanArgs, $userId);
                        return;
                    case 'autofeedback':
                        $this->handleAutoFeedback($chatId, $targetChatId, $cleanArgs, $userId);
                        return;
                    case 'mydashboard':
                        $this->handleMyDashboard($chatId, $targetChatId, $cleanArgs, $userId);
                        return;
                    case 'goalset':
                        $this->handleGoalSet($chatId, $targetChatId, $cleanArgs, $message);
                        return;
                    case 'goalstatus':
                        $this->handleGoalStatus($chatId, $targetChatId, $cleanArgs, $message);
                        return;
                    case 'goalclear':
                        $this->handleGoalClear($chatId, $targetChatId, $cleanArgs, $message);
                        return;
                    case 'progress':
                        $this->handleProgressReport($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'forecast':
                        $this->handleForecast($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'modaudit':
                        $this->handleModAudit($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'debughours':
                        $this->handleDebugHours($chatId, $targetChatId, $message, $cleanArgs);
                        return;
                    case 'debughoursall':
                        $this->handleDebugHoursAll($chatId, $targetChatId, $cleanArgs);
                        return;
                    case 'timesheet':
                        $this->handleTimesheet($chatId, $targetChatId, $message, $cleanArgs);
                        return;
                    case 'compare':
                        $this->handleCompare($chatId, $targetChatId, $message, $cleanArgs);
                        return;
                    case 'finduser':
                        $this->handleFindUser($chatId, $targetChatId, $cleanArgs);
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
            $this->sendReportMessage('No mods are configured yet. Use /modadd [chat_id] @username in private chat (or /usechat), or /mod add (reply) in the group.', ['parse_mode' => 'HTML']);
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
            $this->sendReportMessage('Mod not found in this chat.', ['parse_mode' => 'HTML']);
            return;
        }

        $text = $this->formatStatsMessage($target, $stats['range']);
        $this->sendReportMessage($text, ['parse_mode' => 'HTML']);
    }

    private function handleDebugHours(int|string $responseChatId, int|string $chatId, array $message, string $args): void
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
            $this->sendReportMessage('No mods are configured yet. Use /modadd first.', ['parse_mode' => 'HTML']);
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
            $this->sendReportMessage('Mod not found in this chat.', ['parse_mode' => 'HTML']);
            return;
        }

        $range = $stats['range'];
        $lines = [];
        $lines[] = '<b>Debug Hours</b> — ' . $this->escape($target['display_name']);
        $lines[] = 'Month: ' . $this->escape($range['label']);
        $lines[] = 'Active minutes: ' . number_format((float)($target['active_minutes'] ?? 0), 2);
        $lines[] = 'Presence minutes: ' . number_format((float)($target['membership_minutes'] ?? 0), 2);
        $lines[] = 'Internal active minutes: ' . number_format((float)($target['internal_active_minutes'] ?? 0), 2);
        $lines[] = 'External active minutes: ' . number_format((float)($target['external_active_minutes'] ?? 0), 2);
        $lines[] = 'Messages: ' . number_format((int)($target['messages'] ?? 0));
        $lines[] = 'Internal messages: ' . number_format((int)($target['internal_messages'] ?? 0));
        $lines[] = 'External messages: ' . number_format((int)($target['external_messages'] ?? 0));
        $lines[] = 'Days active: ' . number_format((int)($target['days_active'] ?? 0));
        $lines[] = 'Membership minutes (raw) are derived from join/leave events.';
        $lines[] = 'Active minutes (raw) are computed from message timestamps and gap settings.';
        $this->sendReportMessage(implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function handleDebugHoursAll(int|string $responseChatId, int|string $chatId, string $args): void
    {
        $month = null;
        $tokens = preg_split('/\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}$/', $token)) {
                $month = $token;
                break;
            }
        }

        $stats = $this->stats->getMonthlyStats($chatId, $month);
        if (empty($stats['mods'])) {
            $this->sendReportMessage('No mods are configured yet. Use /modadd first.', ['parse_mode' => 'HTML']);
            return;
        }

        $range = $stats['range'];
        $lines = [];
        $lines[] = '<b>Debug Hours (All Mods)</b>';
        $lines[] = 'Month: ' . $this->escape($range['label']);
        $lines[] = '';

        foreach ($stats['mods'] as $mod) {
            $lines[] = $this->displayName($mod);
            $lines[] = 'Active minutes: ' . number_format((float)($mod['active_minutes'] ?? 0), 2);
            $lines[] = 'Presence minutes: ' . number_format((float)($mod['membership_minutes'] ?? 0), 2);
            $lines[] = 'Internal active minutes: ' . number_format((float)($mod['internal_active_minutes'] ?? 0), 2);
            $lines[] = 'External active minutes: ' . number_format((float)($mod['external_active_minutes'] ?? 0), 2);
            $lines[] = 'Messages: ' . number_format((int)($mod['messages'] ?? 0));
            $lines[] = 'Internal messages: ' . number_format((int)($mod['internal_messages'] ?? 0));
            $lines[] = 'External messages: ' . number_format((int)($mod['external_messages'] ?? 0));
            $lines[] = 'Days active: ' . number_format((int)($mod['days_active'] ?? 0));
            $lines[] = '';
        }

        $text = implode("\n", $lines);
        $this->sendReportMessage($text, ['parse_mode' => 'HTML']);
    }

    private function handleTimesheet(int|string $responseChatId, int|string $chatId, array $message, string $args): void
    {
        $targetUserId = $message['from']['id'] ?? null;
        $targetUsername = $message['from']['username'] ?? null;
        $explicitUser = false;
        $dates = [];

        if (isset($message['reply_to_message']['from'])) {
            $targetUserId = $message['reply_to_message']['from']['id'] ?? null;
            $targetUsername = $message['reply_to_message']['from']['username'] ?? null;
            $explicitUser = true;
        }

        $tokens = preg_split('/\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $token)) {
                $dates[] = $token;
                continue;
            }
            if (strpos($token, '@') === 0) {
                $targetUsername = substr($token, 1);
                $targetUserId = null;
                $explicitUser = true;
                continue;
            }
            if (is_numeric($token)) {
                $targetUserId = (int)$token;
                $targetUsername = null;
                $explicitUser = true;
            }
        }

        if ($targetUserId === null && $targetUsername === null) {
            $this->sendReportMessage('Usage: /timesheet &lt;@username|user_id&gt; [YYYY-MM-DD] [YYYY-MM-DD] [chat_id]', ['parse_mode' => 'HTML']);
            return;
        }

        $settings = $this->settings->get($chatId);
        $timezone = $settings['timezone'] ?? ($this->config['timezone'] ?? 'UTC');
        $tz = new DateTimeZone($timezone);

        $startLocal = null;
        $endLocal = null;
        if (count($dates) >= 2) {
            $startLocal = DateTimeImmutable::createFromFormat('Y-m-d', $dates[0], $tz);
            $endLocal = DateTimeImmutable::createFromFormat('Y-m-d', $dates[1], $tz);
        } elseif (count($dates) === 1) {
            $startLocal = DateTimeImmutable::createFromFormat('Y-m-d', $dates[0], $tz);
            $endLocal = $startLocal;
        } else {
            $now = new DateTimeImmutable('now', $tz);
            $startLocal = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $now->format('Y-m-01 00:00:00'), $tz);
            $endLocal = $now;
        }

        if (!$startLocal || !$endLocal) {
            $this->sendReportMessage('Invalid date format. Use YYYY-MM-DD.', ['parse_mode' => 'HTML']);
            return;
        }

        $userRow = $this->findChatUser($chatId, $targetUserId, $targetUsername);
        if (!$userRow) {
            $this->sendReportMessage('Mod not found in this chat. Use /finduser to locate.', ['parse_mode' => 'HTML']);
            return;
        }
        if (empty($userRow['is_mod'])) {
            $this->sendReportMessage('That user is not a mod in this chat.', ['parse_mode' => 'HTML']);
            return;
        }

        $filePath = $this->timesheetCsv->generate($chatId, (int)$userRow['id'], $this->displayName($userRow), $startLocal, $endLocal);
        $caption = 'Timesheet for ' . $this->displayName($userRow) . ' · ' . $startLocal->format('Y-m-d') . ' to ' . $endLocal->format('Y-m-d') . ' (' . $timezone . ')';
        $this->sendReportDocument($filePath, $caption);
        if (!$explicitUser && $this->shouldLog('log_commands')) {
            Logger::infoContext('Timesheet generated (self)', $this->logContextForChat($chatId, [
                'user_id' => $userRow['id'] ?? null,
            ]));
        } elseif ($this->shouldLog('log_reports')) {
            Logger::infoContext('Timesheet generated', $this->logContextForChat($chatId, [
                'user_id' => $userRow['id'] ?? null,
                'from' => $startLocal->format('Y-m-d'),
                'to' => $endLocal->format('Y-m-d'),
            ]));
        }
    }

    private function handleCompare(int|string $responseChatId, int|string $chatId, array $message, string $args): void
    {
        $userTokens = [];
        $month = null;
        $userAId = null;
        $userAUsername = null;

        if (isset($message['reply_to_message']['from'])) {
            $userAId = $message['reply_to_message']['from']['id'] ?? null;
            $userAUsername = $message['reply_to_message']['from']['username'] ?? null;
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
                $userTokens[] = ['username' => substr($token, 1)];
                continue;
            }
            if (is_numeric($token)) {
                $userTokens[] = ['id' => (int)$token];
            }
        }

        if ($userAId === null && $userAUsername === null) {
            $first = array_shift($userTokens);
            if ($first) {
                $userAId = $first['id'] ?? null;
                $userAUsername = $first['username'] ?? null;
            }
        }

        $second = array_shift($userTokens);
        $userBId = $second['id'] ?? null;
        $userBUsername = $second['username'] ?? null;

        if (($userAId === null && $userAUsername === null) || ($userBId === null && $userBUsername === null)) {
            $this->sendReportMessage('Usage: /compare &lt;@user1|id1&gt; &lt;@user2|id2&gt; [YYYY-MM] [chat_id]', ['parse_mode' => 'HTML']);
            return;
        }

        $stats = $this->stats->getMonthlyStats($chatId, $month);
        if (empty($stats['mods'])) {
            $this->sendReportMessage('No mods are configured yet. Use /modadd first.', ['parse_mode' => 'HTML']);
            return;
        }

        $modA = $this->findModInStats($stats['mods'], $userAId, $userAUsername);
        $modB = $this->findModInStats($stats['mods'], $userBId, $userBUsername);

        if (!$modA || !$modB) {
            $this->sendReportMessage('One or both mods not found in this chat.', ['parse_mode' => 'HTML']);
            return;
        }

        $rankMap = [];
        foreach ($stats['mods'] as $idx => $mod) {
            $rankMap[(int)$mod['user_id']] = $idx + 1;
        }
        $rankA = $rankMap[(int)$modA['user_id']] ?? null;
        $rankB = $rankMap[(int)$modB['user_id']] ?? null;

        $chatRow = $this->db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
        $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);

        $lines = [];
        $lines[] = '<b>Mod Comparison</b>';
        $lines[] = $this->escape($chatTitle) . ' · ' . $this->escape($stats['range']['label'] ?? '');
        $lines[] = '';
        $lines[] = $this->escape($modA['display_name']) . ' vs ' . $this->escape($modB['display_name']);
        $lines[] = 'Rank: #' . ($rankA ?? '-') . ' vs #' . ($rankB ?? '-');
        $lines[] = 'Score: ' . number_format((float)$modA['score'], 2) . ' vs ' . number_format((float)$modB['score'], 2);
        $lines[] = 'Impact: ' . number_format((float)($modA['impact_score'] ?? 0), 2) . ' vs ' . number_format((float)($modB['impact_score'] ?? 0), 2);
        $lines[] = 'Consistency: ' . number_format((float)($modA['consistency_index'] ?? 0), 1) . '% vs ' . number_format((float)($modB['consistency_index'] ?? 0), 1) . '%';
        $lines[] = 'Messages: ' . number_format((int)$modA['messages']) . ' vs ' . number_format((int)$modB['messages']);
        $lines[] = 'Active hours: ' . number_format(((float)($modA['active_minutes'] ?? 0)) / 60, 1) . 'h vs ' . number_format(((float)($modB['active_minutes'] ?? 0)) / 60, 1) . 'h';
        $lines[] = 'Presence hours: ' . number_format(((float)($modA['membership_minutes'] ?? 0)) / 60, 1) . 'h vs ' . number_format(((float)($modB['membership_minutes'] ?? 0)) / 60, 1) . 'h';
        $lines[] = 'Days active: ' . number_format((int)$modA['days_active']) . ' vs ' . number_format((int)$modB['days_active']);
        $lines[] = 'Actions (W/M/B): ' . (int)($modA['warnings'] ?? 0) . '/' . (int)($modA['mutes'] ?? 0) . '/' . (int)($modA['bans'] ?? 0) . ' vs ' . (int)($modB['warnings'] ?? 0) . '/' . (int)($modB['mutes'] ?? 0) . '/' . (int)($modB['bans'] ?? 0);

        $impA = $modA['improvement'] ?? null;
        $impB = $modB['improvement'] ?? null;
        $trendA = $modA['trend_3m'] ?? null;
        $trendB = $modB['trend_3m'] ?? null;
        $lines[] = 'Improvement: ' . ($impA !== null ? number_format((float)$impA, 1) . '%' : 'N/A') . ' vs ' . ($impB !== null ? number_format((float)$impB, 1) . '%' : 'N/A');
        if ($trendA !== null || $trendB !== null) {
            $lines[] = '3‑month trend: ' . ($trendA !== null ? (($trendA >= 0 ? '+' : '') . number_format((float)$trendA, 1) . '%') : 'N/A') .
                ' vs ' . ($trendB !== null ? (($trendB >= 0 ? '+' : '') . number_format((float)$trendB, 1) . '%') : 'N/A');
        }

        $this->sendReportMessage(implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function handleFindUser(int|string $responseChatId, int|string $chatId, string $args): void
    {
        $query = trim($args);
        if ($query === '' || strtolower($query) === 'help') {
            $this->sendReportMessage('Usage: /finduser &lt;name|@username|user_id&gt; [chat_id]', ['parse_mode' => 'HTML']);
            return;
        }

        if (strpos($query, '@') === 0) {
            $query = substr($query, 1);
        }

        if (is_numeric($query)) {
            $row = $this->db->fetch(
                'SELECT u.id, u.username, u.first_name, u.last_name, cm.is_mod
                 FROM chat_members cm
                 JOIN users u ON u.id = cm.user_id
                 WHERE cm.chat_id = ? AND u.id = ? LIMIT 1',
                [$chatId, (int)$query]
            );
            if (!$row) {
                $this->sendReportMessage('No matching user found in this chat.', ['parse_mode' => 'HTML']);
                return;
            }
            $role = !empty($row['is_mod']) ? 'mod' : 'member';
            $line = $row['id'] . ' | ' . $this->displayName($row) . ' | ' . $role;
            $this->sendReportMessage($line, ['parse_mode' => 'HTML']);
            return;
        }

        $like = '%' . $query . '%';
        $rows = $this->db->fetchAll(
            'SELECT u.id, u.username, u.first_name, u.last_name, cm.is_mod
             FROM chat_members cm
             JOIN users u ON u.id = cm.user_id
             WHERE cm.chat_id = ?
               AND (
                    u.username LIKE ?
                    OR u.first_name LIKE ?
                    OR u.last_name LIKE ?
                    OR CONCAT_WS(" ", u.first_name, u.last_name) LIKE ?
               )
             ORDER BY cm.is_mod DESC, u.username IS NULL, u.username ASC, u.first_name ASC
             LIMIT 15',
            [$chatId, $like, $like, $like, $like]
        );

        if (empty($rows)) {
            $this->sendReportMessage('No matching users found in this chat.', ['parse_mode' => 'HTML']);
            return;
        }

        $lines = ['Matches:'];
        foreach ($rows as $row) {
            $role = !empty($row['is_mod']) ? 'mod' : 'member';
            $lines[] = $row['id'] . ' | ' . $this->displayName($row) . ' | ' . $role;
        }
        $this->sendReportMessage(implode("\n", $lines), ['parse_mode' => 'HTML']);
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
            $this->sendReportMessage('No mods are configured yet. Use /modadd [chat_id] @username in private chat (or /usechat), or /mod add (reply) in the group.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($budget === null) {
            $budget = (float)$stats['settings']['reward_budget'];
        }

        $context = $this->rewardContext->build($chatId, $stats['range']['month']);
        $context['chat_id'] = (int)$chatId;
        $context['month'] = $stats['range']['month'] ?? $month;
        $context['source'] = 'leaderboard';
        $context['actor_id'] = (int)$responseChatId;
        $ranked = $this->rewards->rankAndReward($stats['mods'], $budget, $context);
        $text = $this->formatLeaderboardMessage($ranked, $stats['range'], $budget);
        $this->sendReportMessage($text, ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_reports')) {
            Logger::infoContext(
                'Leaderboard generated',
                $this->logContextForChat($chatId, [
                    'month' => $stats['range']['month'] ?? null,
                    'mods' => count($stats['mods']),
                    'budget' => number_format($budget, 2, '.', ''),
                ])
            );
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
            $this->sendReportMessage('No mods are configured yet. Use /modadd [chat_id] @username in private chat (or /usechat), or /mod add (reply) in the group.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($budget === null) {
            $budget = (float)$stats['settings']['reward_budget'];
        }

        $filePath = $this->rewardSheet->generate($chatId, $month, $budget);
        $caption = 'Reward sheet for ' . $stats['range']['label'] . ' (budget: ' . number_format($budget, 2) . ')';
        $this->sendReportDocument($filePath, $caption);
        if ($this->shouldLog('log_reports')) {
            Logger::infoContext(
                'Reward sheet generated',
                $this->logContextForChat($chatId, [
                    'month' => $stats['range']['month'] ?? null,
                    'mods' => count($stats['mods']),
                    'budget' => number_format($budget, 2, '.', ''),
                    'file' => basename($filePath),
                ])
            );
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
            $this->sendReportMessage('No mods are configured yet. Use /modadd [chat_id] @username in private chat (or /usechat), or /mod add (reply) in the group.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($budget === null) {
            $budget = (float)$stats['settings']['reward_budget'];
        }

        $filePath = $this->rewardCsv->generate($chatId, $month, $budget);
        $caption = 'CSV reward sheet for ' . $stats['range']['label'] . ' (budget: ' . number_format($budget, 2) . ')';
        $this->sendReportDocument($filePath, $caption);
        if ($this->shouldLog('log_reports')) {
            Logger::infoContext(
                'Reward CSV generated',
                $this->logContextForChat($chatId, [
                    'month' => $stats['range']['month'] ?? null,
                    'mods' => count($stats['mods']),
                    'budget' => number_format($budget, 2, '.', ''),
                    'file' => basename($filePath),
                ])
            );
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
            $this->sendReportMessage('No group chats found yet. Add me to a group and send a message there.', ['parse_mode' => 'HTML']);
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
            $this->sendReportMessage('No authorized chats found for multi-chat summary.', ['parse_mode' => 'HTML']);
            return;
        }

        $bundle = $this->stats->getMonthlyStatsForChats($chatIds, $month);
        $label = $bundle['range']['label'] ?? ($month ?? 'current month');

        $filePath = $this->multiChatReport->generate($chatIds, $month, $budget);
        $caption = 'Multi-chat summary for ' . $label;
        if ($budget !== null) {
            $caption .= ' (budget: ' . number_format($budget, 2) . ')';
        }
        $this->sendReportDocument($filePath, $caption);
        if ($this->shouldLog('log_reports')) {
            Logger::infoContext(
                'Multi-chat summary generated',
                $this->logContextForUser($userId, [
                    'month' => $bundle['range']['month'] ?? null,
                    'chats' => count($chatIds),
                    'budget' => $budget !== null ? number_format($budget, 2, '.', '') : null,
                    'file' => basename($filePath),
                ])
            );
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
            $this->sendReportMessage('No mods are configured yet. Use /modadd [chat_id] @username in private chat (or /usechat), or /mod add (reply) in the group.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($budget === null) {
            $budget = (float)$stats['settings']['reward_budget'];
        }

        $context = $this->rewardContext->build($chatId, $stats['range']['month']);
        $context['chat_id'] = (int)$chatId;
        $context['month'] = $stats['range']['month'] ?? $month;
        $context['source'] = 'export_gsheet';
        $context['actor_id'] = (int)$responseChatId;
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
            $this->sendReportMessage('Exported to Google Sheets successfully.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_reports')) {
                Logger::infoContext(
                    'Google Sheets export completed',
                    $this->logContextForChat($chatId, [
                        'month' => $stats['range']['month'] ?? null,
                        'rows' => count($rows),
                        'budget' => number_format($budget, 2, '.', ''),
                    ])
                );
            }
        } else {
            $error = $result['error'] ?? 'unknown error';
            $this->sendReportMessage('Google Sheets export failed: ' . $this->escape((string)$error), ['parse_mode' => 'HTML']);
            Logger::errorContext(
                'Google Sheets export failed',
                $this->logContextForChat($chatId, [
                    'month' => $stats['range']['month'] ?? null,
                    'error' => (string)$error,
                ])
            );
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
        $this->sendReportMessage('Mod added: ' . $this->displayName($target), ['parse_mode' => 'HTML']);
        $this->audit->log('mod_add', $message['from']['id'] ?? 0, (int)$chatId, [
            'target_id' => $target['id'],
            'target_username' => $target['username'] ?? null,
            'target_name' => $this->formatUserLabel($target),
        ]);
        if ($this->shouldLog('log_commands')) {
            Logger::infoContext(
                'Mod added',
                $this->logContextForChat($chatId, [
                    'actor_id' => $message['from']['id'] ?? null,
                    'actor_username' => $message['from']['username'] ?? null,
                    'actor_name' => $this->formatUserLabel($message['from'] ?? []),
                    'target_id' => $target['id'],
                    'target_username' => $target['username'] ?? null,
                    'target_name' => $this->formatUserLabel($target),
                ])
            );
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
        $this->sendReportMessage('Mod removed: ' . $this->displayName($target), ['parse_mode' => 'HTML']);
        $this->audit->log('mod_remove', $message['from']['id'] ?? 0, (int)$chatId, [
            'target_id' => $target['id'],
            'target_username' => $target['username'] ?? null,
            'target_name' => $this->formatUserLabel($target),
        ]);
        if ($this->shouldLog('log_commands')) {
            Logger::infoContext(
                'Mod removed',
                $this->logContextForChat($chatId, [
                    'actor_id' => $message['from']['id'] ?? null,
                    'actor_username' => $message['from']['username'] ?? null,
                    'actor_name' => $this->formatUserLabel($message['from'] ?? []),
                    'target_id' => $target['id'],
                    'target_username' => $target['username'] ?? null,
                    'target_name' => $this->formatUserLabel($target),
                ])
            );
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
        $this->sendReportMessage(implode("\n", $lines), ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::infoContext('Mod list viewed', $this->logContextForChat($chatId, ['mods' => count($mods)]));
        }
    }

    private function handleModAudit(int|string $responseChatId, int|string $chatId, string $args): void
    {
        $limit = 20;
        $tokens = preg_split('/\\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (is_numeric($token)) {
                $limit = (int)$token;
            }
        }
        $limit = max(1, min(50, $limit));

        $rows = $this->db->fetchAll(
            'SELECT al.action, al.actor_id, al.meta, al.created_at, u.username, u.first_name, u.last_name
             FROM audit_log al
             LEFT JOIN users u ON u.id = al.actor_id
             WHERE al.chat_id = ? AND al.action IN ("mod_add", "mod_remove")
             ORDER BY al.created_at DESC
             LIMIT ' . $limit,
            [$chatId]
        );

        if (empty($rows)) {
            $this->sendReportMessage('No mod changes logged yet.', ['parse_mode' => 'HTML']);
            return;
        }

        $settings = $this->settings->get($chatId);
        $timezone = $settings['timezone'] ?? ($this->config['timezone'] ?? 'UTC');

        $chatRow = $this->db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
        $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);

        $lines = [];
        $lines[] = '<b>Mod Audit</b>';
        $lines[] = $this->escape($chatTitle) . ' · Last ' . $limit . ' changes';
        $lines[] = '';

        foreach ($rows as $row) {
            $meta = [];
            if (!empty($row['meta'])) {
                $decoded = json_decode((string)$row['meta'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            $targetLabel = 'Unknown';
            if (!empty($meta['target_username'])) {
                $targetLabel = '@' . $meta['target_username'];
            } elseif (!empty($meta['target_name'])) {
                $targetLabel = (string)$meta['target_name'];
            } elseif (!empty($meta['target_id'])) {
                $targetLabel = 'User ' . $meta['target_id'];
            }

            $actorLabel = $this->formatUserLabel([
                'id' => $row['actor_id'] ?? null,
                'username' => $row['username'] ?? null,
                'first_name' => $row['first_name'] ?? null,
                'last_name' => $row['last_name'] ?? null,
            ]);

            $action = $row['action'] === 'mod_remove' ? '-' : '+';
            $when = $this->formatTimestamp((string)$row['created_at'], $timezone);

            $lines[] = $when . ' · ' . $action . ' ' . $this->escape($targetLabel) . ' by ' . $this->escape($actorLabel);
        }

        $this->sendReportMessage(implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function handleLinkedChat(int|string $responseChatId, int|string $userId): void
    {
        $defaultChatId = $this->userSettings->getDefaultChatId($userId);
        $lines = ['<b>Linked Chat</b>'];

        if ($defaultChatId !== null) {
            $row = $this->db->fetch('SELECT id, title FROM chats WHERE id = ? LIMIT 1', [$defaultChatId]);
            $title = $row['title'] ?? 'Unknown chat';
            $lines[] = 'Default: ' . $defaultChatId . ' · ' . $this->escape($title);
        } else {
            $lines[] = 'Default: not set';
        }

        $recent = $this->getUserChats($userId, 5);
        if (!empty($recent)) {
            $lines[] = '';
            $lines[] = 'Recent chats:';
            foreach ($recent as $chat) {
                $title = $chat['title'] ?: 'Untitled';
                $lines[] = $chat['id'] . ' | ' . $this->escape($title);
            }
        }

        $lines[] = '';
        $lines[] = 'Tip: set a default with /usechat &lt;chat_id&gt;';

        $this->sendReportMessage(implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function handleWhoAmI(int|string $responseChatId, int|string $userId): void
    {
        $role = $this->isOwner($userId) ? 'Owner' : ($this->isManager($userId) ? 'Manager' : 'User');
        $userRow = $this->db->fetch('SELECT id, username, first_name, last_name FROM users WHERE id = ? LIMIT 1', [$userId]);
        $label = $this->formatUserLabel($userRow ?: ['id' => $userId]);

        $defaultChatId = $this->userSettings->getDefaultChatId($userId);
        $defaultLabel = 'not set';
        if ($defaultChatId !== null) {
            $row = $this->db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$defaultChatId]);
            $title = $row['title'] ?? 'Unknown chat';
            $defaultLabel = $defaultChatId . ' · ' . $this->escape($title);
        }

        $lines = [
            '<b>Who Am I</b>',
            'User: ' . $this->escape($label),
            'User ID: ' . $userId,
            'Role: ' . $role,
            'Default chat: ' . $defaultLabel,
        ];

        $this->sendReportMessage(implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function handleBotUsers(int|string $responseChatId, string $args): void
    {
        $days = 30;
        $limit = 20;
        $tokens = preg_split('/\\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (is_numeric($token)) {
                if ($days === 30) {
                    $days = (int)$token;
                } else {
                    $limit = (int)$token;
                }
            }
        }
        $days = max(1, min(365, $days));
        $limit = max(1, min(50, $limit));

        $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
        $rows = $this->db->fetchAll(
            'SELECT al.actor_id, MIN(al.created_at) as first_seen, MAX(al.created_at) as last_seen, COUNT(*) as uses,
                    u.username, u.first_name, u.last_name
             FROM audit_log al
             LEFT JOIN users u ON u.id = al.actor_id
             WHERE al.action = "command_use" AND al.created_at >= ?
             GROUP BY al.actor_id, u.username, u.first_name, u.last_name
             ORDER BY last_seen DESC
             LIMIT ' . $limit,
            [$since]
        );

        if (empty($rows)) {
            $this->sendReportMessage('No command usage logged yet.', ['parse_mode' => 'HTML']);
            return;
        }

        $starts = $this->db->fetchAll(
            'SELECT actor_id, MIN(created_at) as first_start
             FROM audit_log
             WHERE action = "start"
             GROUP BY actor_id'
        );
        $startMap = [];
        foreach ($starts as $startRow) {
            $startMap[(int)$startRow['actor_id']] = $startRow['first_start'];
        }

        $timezone = $this->config['timezone'] ?? 'UTC';
        $lines = [];
        $lines[] = '<b>Bot Users</b>';
        $lines[] = 'Last ' . $days . ' days · Top ' . $limit;
        $lines[] = '';
        $rank = 1;
        foreach ($rows as $row) {
            $label = $this->formatUserLabel([
                'id' => $row['actor_id'] ?? null,
                'username' => $row['username'] ?? null,
                'first_name' => $row['first_name'] ?? null,
                'last_name' => $row['last_name'] ?? null,
            ]);
            $firstSeen = $this->formatTimestamp((string)$row['first_seen'], $timezone);
            $lastSeen = $this->formatTimestamp((string)$row['last_seen'], $timezone);
            $startSeen = isset($startMap[(int)$row['actor_id']]) ? $this->formatTimestamp((string)$startMap[(int)$row['actor_id']], $timezone) : 'n/a';
            $lines[] = $rank . '. ' . $this->escape($label) . ' · uses ' . (int)$row['uses'] . ' · last ' . $lastSeen . ' · first ' . $firstSeen . ' · start ' . $startSeen;
            $rank++;
        }

        $this->sendReportMessage(implode("\n", $lines), ['parse_mode' => 'HTML']);
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
            'Managers can gift with /giftplan &lt;chat_id&gt; premium 30',
        ];
        $this->sendReportMessage(implode("\n", $lines), ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::infoContext('Plan viewed', $this->logContextForChat($chatId, [
                'plan' => $plan,
                'status' => $status,
                'expires' => $expires ?: 'never',
            ]));
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
            $this->tg->sendMessage($responseChatId, 'Usage: /setplan &lt;free|premium|enterprise&gt; [days]', ['parse_mode' => 'HTML']);
            return;
        }

        $sub = $this->subscriptions->setPlan($chatId, $plan, $days);
        $msg = 'Plan updated: ' . strtoupper($sub['plan']) . '.';
        if (!empty($sub['expires_at'])) {
            $msg .= ' Expires ' . $sub['expires_at'] . '.';
        }
        $this->tg->sendMessage($responseChatId, $msg, ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::infoContext(
                'Plan updated',
                $this->logContextForChatUser($chatId, $userId, [
                    'plan' => strtoupper($sub['plan']),
                    'days' => $days,
                    'expires' => $sub['expires_at'] ?? null,
                ])
            );
        }
        $this->audit->log('plan_set', $userId, (int)$chatId, [
            'plan' => $sub['plan'],
            'days' => $days,
        ]);
    }

    private function handleGiftPlan(int|string $responseChatId, string $args, int|string $userId): void
    {
        if (!$this->isManager($userId)) {
            $this->tg->sendMessage($responseChatId, 'Only bot managers or owners can gift plans.', ['parse_mode' => 'HTML']);
            return;
        }

        $tokens = preg_split('/\\s+/', trim($args));
        $chatToken = $tokens[0] ?? '';
        $plan = $tokens[1] ?? '';
        if ($chatToken === '' || $plan === '') {
            $this->tg->sendMessage($responseChatId, 'Usage: /giftplan &lt;chat_id&gt; &lt;free|premium|enterprise&gt; [days] [note]', ['parse_mode' => 'HTML']);
            return;
        }
        if (!preg_match('/^-?\\d+$/', $chatToken)) {
            $this->tg->sendMessage($responseChatId, 'Chat id must be numeric. Example: /giftplan -1001234567890 premium 30', ['parse_mode' => 'HTML']);
            return;
        }

        $chatId = (int)$chatToken;
        $days = null;
        $note = '';
        $rest = array_slice($tokens, 2);
        if (!empty($rest) && is_numeric($rest[0])) {
            $days = (int)$rest[0];
            $rest = array_slice($rest, 1);
        }
        if (!empty($rest)) {
            $note = trim(implode(' ', $rest));
        }

        $sub = $this->subscriptions->setPlan($chatId, $plan, $days);
        $expires = $sub['expires_at'] ?? null;
        $msg = 'Gifted plan: ' . strtoupper($sub['plan'] ?? $plan) . ' for chat ' . $chatId;
        if ($expires) {
            $msg .= ' (expires ' . $this->escape($expires) . ')';
        }
        if ($note !== '') {
            $msg .= "\nNote: " . $this->escape($note);
        }
        $this->tg->sendMessage($responseChatId, $msg, ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::infoContext(
                'Plan gifted',
                $this->logContextForChatUser($chatId, $userId, [
                    'plan' => strtoupper($sub['plan'] ?? $plan),
                    'days' => $days,
                    'expires' => $expires,
                    'note' => $note !== '' ? $note : null,
                ])
            );
        }
        $this->audit->log('plan_gift', $userId, $chatId, [
            'plan' => $sub['plan'] ?? $plan,
            'days' => $days,
            'note' => $note,
        ]);
    }

    private function handleApprovalToggle(int|string $responseChatId, string $args, int|string $userId): void
    {
        if (!$this->isManager($userId)) {
            $this->tg->sendMessage($responseChatId, 'Only bot managers or owners can change approval settings.', ['parse_mode' => 'HTML']);
            return;
        }
        $tokens = preg_split('/\\s+/', trim($args));
        $mode = strtolower($tokens[0] ?? '');
        $chatToken = $tokens[1] ?? '';
        if (!in_array($mode, ['on', 'off'], true) || $chatToken === '') {
            $this->tg->sendMessage($responseChatId, 'Usage: /approval on|off &lt;chat_id&gt;', ['parse_mode' => 'HTML']);
            return;
        }
        if (!preg_match('/^-?\\d+$/', $chatToken)) {
            $this->tg->sendMessage($responseChatId, 'Chat id must be numeric.', ['parse_mode' => 'HTML']);
            return;
        }
        $chatId = (int)$chatToken;
        $required = $mode === 'on';
        $this->settings->updateApprovalRequired($chatId, $required);
        $this->tg->sendMessage($responseChatId, 'Approval requirement updated for ' . $chatId . ': ' . ($required ? 'ON' : 'OFF'), ['parse_mode' => 'HTML']);
        $this->audit->log('approval_toggle', $userId, $chatId, ['required' => $required]);
    }

    private function handleApproveReport(int|string $responseChatId, string $args, int|string $userId): void
    {
        if (!$this->isManager($userId)) {
            $this->tg->sendMessage($responseChatId, 'Only bot managers or owners can approve reports.', ['parse_mode' => 'HTML']);
            return;
        }
        $tokens = preg_split('/\\s+/', trim($args));
        $chatToken = $tokens[0] ?? '';
        $month = $tokens[1] ?? null;
        if ($chatToken === '') {
            $this->tg->sendMessage($responseChatId, 'Usage: /approvereport &lt;chat_id&gt; [YYYY-MM]', ['parse_mode' => 'HTML']);
            return;
        }
        if (!preg_match('/^-?\\d+$/', $chatToken)) {
            $this->tg->sendMessage($responseChatId, 'Chat id must be numeric.', ['parse_mode' => 'HTML']);
            return;
        }
        if ($month !== null && !preg_match('/^\\d{4}-\\d{2}$/', $month)) {
            $this->tg->sendMessage($responseChatId, 'Month must be YYYY-MM.', ['parse_mode' => 'HTML']);
            return;
        }
        $chatId = (int)$chatToken;
        $month = $month ?? gmdate('Y-m');
        $this->approvals->approve($chatId, $month, $userId, 'reward');
        $this->tg->sendMessage($responseChatId, 'Report approved for ' . $chatId . ' (' . $month . ').', ['parse_mode' => 'HTML']);
        $this->audit->log('report_approved', $userId, $chatId, ['month' => $month]);
    }

    private function handleApprovalStatus(int|string $responseChatId, string $args, int|string $userId): void
    {
        if (!$this->isManager($userId)) {
            $this->tg->sendMessage($responseChatId, 'Only bot managers or owners can view approval status.', ['parse_mode' => 'HTML']);
            return;
        }
        $tokens = preg_split('/\\s+/', trim($args));
        $chatToken = $tokens[0] ?? '';
        $month = $tokens[1] ?? null;
        if ($chatToken === '') {
            $this->tg->sendMessage($responseChatId, 'Usage: /approvalstatus &lt;chat_id&gt; [YYYY-MM]', ['parse_mode' => 'HTML']);
            return;
        }
        if (!preg_match('/^-?\\d+$/', $chatToken)) {
            $this->tg->sendMessage($responseChatId, 'Chat id must be numeric.', ['parse_mode' => 'HTML']);
            return;
        }
        if ($month !== null && !preg_match('/^\\d{4}-\\d{2}$/', $month)) {
            $this->tg->sendMessage($responseChatId, 'Month must be YYYY-MM.', ['parse_mode' => 'HTML']);
            return;
        }
        $chatId = (int)$chatToken;
        $month = $month ?? gmdate('Y-m');
        $status = $this->approvals->getStatus($chatId, $month, 'reward');
        $lines = [
            '<b>Approval Status</b>',
            'Chat: ' . $chatId,
            'Month: ' . $month,
            'Status: ' . $this->escape($status['status'] ?? 'pending'),
        ];
        if (!empty($status['approved_at'])) {
            $lines[] = 'Approved at: ' . $this->escape($status['approved_at']);
        }
        $this->sendReportMessage(implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function handleAuditLogCsv(int|string $responseChatId, string $args, int|string $userId): void
    {
        if (!$this->isManager($userId)) {
            $this->tg->sendMessage($responseChatId, 'Only bot managers or owners can export audit logs.', ['parse_mode' => 'HTML']);
            return;
        }
        $tokens = preg_split('/\\s+/', trim($args));
        $chatToken = $tokens[0] ?? null;
        $limit = isset($tokens[1]) && is_numeric($tokens[1]) ? (int)$tokens[1] : 200;
        $chatId = null;
        if ($chatToken && preg_match('/^-?\\d+$/', $chatToken)) {
            $chatId = (int)$chatToken;
        } elseif ($chatToken !== null && $chatToken !== '') {
            $this->tg->sendMessage($responseChatId, 'Usage: /auditlogcsv [chat_id] [limit]', ['parse_mode' => 'HTML']);
            return;
        }

        $rows = $this->audit->list($chatId, $limit);
        if (empty($rows)) {
            $this->tg->sendMessage($responseChatId, 'No audit logs found.', ['parse_mode' => 'HTML']);
            return;
        }

        $suffix = $chatId ? ('chat-' . $chatId) : 'all';
        $file = __DIR__ . '/../storage/reports/audit-log-' . $suffix . '-' . gmdate('Y-m-d') . '.csv';
        $fp = fopen($file, 'w');
        fputcsv($fp, ['action', 'actor_id', 'chat_id', 'meta', 'created_at']);
        foreach ($rows as $row) {
            fputcsv($fp, [
                $row['action'] ?? '',
                $row['actor_id'] ?? '',
                $row['chat_id'] ?? '',
                $row['meta'] ?? '',
                $row['created_at'] ?? '',
            ]);
        }
        fclose($fp);
        $this->tg->sendDocument($responseChatId, $file, 'Audit log export');
    }

    private function handleTestPurchase(int|string $responseChatId, int|string $userId, string $args, string $method): void
    {
        if (!$this->isManager($userId)) {
            $this->tg->sendMessage($responseChatId, 'Only bot managers or owners can run test purchases.', ['parse_mode' => 'HTML']);
            return;
        }
        if (!$this->payments->isTestMode()) {
            $this->tg->sendMessage($responseChatId, 'Test payments are disabled. Enable payments.test_mode in config.', ['parse_mode' => 'HTML']);
            return;
        }

        $tokens = preg_split('/\\s+/', trim($args));
        $amount = null;
        $chatIdFromArgs = null;
        $rest = [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if ($chatIdFromArgs === null && preg_match('/^(?:chat|chatid|group|id):(-?\\d+)$/i', $token, $match)) {
                $chatIdFromArgs = (int)$match[1];
                continue;
            }
            if ($chatIdFromArgs === null && preg_match('/^-?\\d{6,}$/', $token)) {
                $chatIdFromArgs = (int)$token;
                continue;
            }
            if ($amount === null && is_numeric($token)) {
                $amount = (float)$token;
                continue;
            }
            $rest[] = $token;
        }
        if ($amount === null || $amount <= 0) {
            $usage = $method === 'crypto' ? '/buy_crypto_test <amount> [chat_id]' : '/buy_stars_test <amount> [chat_id]';
            $this->tg->sendMessage($responseChatId, 'Usage: ' . $usage, ['parse_mode' => 'HTML']);
            return;
        }
        $cleanArgs = trim(implode(' ', $rest));
        $targetChatId = $chatIdFromArgs;
        if (!$targetChatId) {
            [$targetChatId, $cleanArgs] = $this->resolveTargetChatId($userId, $responseChatId, $cleanArgs);
            if (!$targetChatId) {
                return;
            }
        }
        if (!$this->isAuthorized($targetChatId, $userId)) {
            $this->tg->sendMessage($responseChatId, 'You do not have permission for that chat.', ['parse_mode' => 'HTML']);
            return;
        }

        $tier = $this->payments->selectTier($method, $amount);
        $plan = $tier['plan'] ?? null;
        $days = isset($tier['days']) ? (int)$tier['days'] : null;
        $currency = $tier['currency'] ?? ($method === 'crypto' ? 'USDT' : 'STARS');

        $paymentsConfig = $this->config['payments'] ?? [];
        $starsConfig = $paymentsConfig['stars'] ?? [];
        if ($method === 'stars' && !empty($starsConfig['enabled'])) {
            $this->sendStarsInvoice($responseChatId, $userId, (int)$targetChatId, $amount, $plan, $days);
            return;
        }
        if ($method === 'crypto') {
            $this->sendCryptoCheckout($responseChatId, $userId, (int)$targetChatId, $amount, $plan, $days);
            return;
        }

        if ($plan) {
            $this->subscriptions->setPlan($targetChatId, $plan, $days);
        }

        $this->payments->recordTest($targetChatId, $userId, $method, $amount, $currency, $plan, $days, [
            'raw_args' => $cleanArgs,
            'method' => $method,
        ]);
        $this->audit->log('payment_test', $userId, (int)$targetChatId, [
            'method' => $method,
            'amount' => $amount,
            'currency' => $currency,
            'plan' => $plan,
            'days' => $days,
        ]);

        $lines = [
            '<b>Test payment recorded</b>',
            'Chat: ' . $targetChatId,
            'Method: ' . strtoupper($method),
            'Amount: ' . number_format($amount, 2) . ' ' . $this->escape($currency),
        ];
        if ($plan) {
            $lines[] = 'Granted: ' . strtoupper($plan) . ($days ? (' for ' . $days . ' days') : '');
        } else {
            $lines[] = 'No plan matched. Update payments tiers in config.';
        }
        $this->sendReportMessage(implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function handlePaymentStatus(int|string $responseChatId, int|string $userId): void
    {
        $latest = $this->payments->latestForUser($userId);
        if (!$latest) {
            $this->tg->sendMessage($responseChatId, 'No payments recorded yet.', ['parse_mode' => 'HTML']);
            return;
        }
        $lines = [
            '<b>Latest Payment</b>',
            'Method: ' . strtoupper($latest['method'] ?? ''),
            'Amount: ' . number_format((float)($latest['amount'] ?? 0), 2) . ' ' . $this->escape($latest['currency'] ?? ''),
            'Status: ' . $this->escape($latest['status'] ?? ''),
            'Plan: ' . $this->escape($latest['plan'] ?? '-'),
            'Days: ' . $this->escape((string)($latest['days'] ?? '-')),
            'Chat: ' . $this->escape((string)($latest['chat_id'] ?? '')),
            'At: ' . $this->escape($latest['created_at'] ?? ''),
        ];
        $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function sendCryptoCheckout(int|string $responseChatId, int|string $userId, int $chatId, float $amount, ?string $plan, ?int $days): void
    {
        $paymentsConfig = $this->config['payments'] ?? [];
        $cryptoConfig = $paymentsConfig['crypto'] ?? [];
        $network = $cryptoConfig['network'] ?? 'TRC20';
        $currency = $cryptoConfig['currency'] ?? 'USDT';
        $dashboard = $this->config['dashboard'] ?? [];
        $token = $dashboard['token'] ?? null;
        $baseUrl = $dashboard['base_url'] ?? 'http://127.0.0.1:8000';
        $baseUrl = rtrim((string)$baseUrl, '/');
        if (!$token) {
            $this->tg->sendMessage($responseChatId, 'Set dashboard.token in config.local.php to open the crypto checkout page.', ['parse_mode' => 'HTML']);
            return;
        }
        $address = $this->generateTronAddress();

        $orderId = $this->payments->createPending($chatId, $userId, 'crypto', $amount, $currency, $plan, $days, [
            'network' => $network,
            'address' => $address,
        ]);
        $checkoutUrl = $baseUrl . '/crypto-checkout.php?token=' . urlencode((string)$token) . '&order=' . urlencode((string)$orderId);

        $lines = [
            '<b>Crypto Checkout Created</b>',
            'Order: ' . $orderId,
            'Amount: ' . number_format($amount, 2) . ' ' . $this->escape($currency),
            'Network: ' . $this->escape($network),
            'Address: <code>' . $this->escape($address) . '</code>',
            'Open checkout: ' . $checkoutUrl,
        ];
        if ($plan) {
            $lines[] = 'Plan on success: ' . strtoupper($plan) . ($days ? (' for ' . $days . ' days') : '');
        }

        $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
        $this->audit->log('crypto_checkout_created', $userId, $chatId, [
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'network' => $network,
            'plan' => $plan,
            'days' => $days,
        ]);
    }

    private function generateTronAddress(): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $addr = 'T';
        for ($i = 0; $i < 33; $i++) {
            $addr .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $addr;
    }

    private function handlePreCheckoutQuery(array $query): void
    {
        $id = $query['id'] ?? null;
        if (!$id) {
            return;
        }
        $currency = $query['currency'] ?? '';
        $paymentsConfig = $this->config['payments'] ?? [];
        $starsConfig = $paymentsConfig['stars'] ?? [];
        if (empty($starsConfig['enabled'])) {
            $this->tg->answerPreCheckoutQuery($id, false, 'Stars payments are disabled.');
            return;
        }
        if ($currency !== 'XTR') {
            $this->tg->answerPreCheckoutQuery($id, false, 'Only Telegram Stars are supported.');
            return;
        }
        $this->tg->answerPreCheckoutQuery($id, true);
    }

    private function handleSuccessfulPayment(array $message): void
    {
        $payment = $message['successful_payment'] ?? [];
        $currency = $payment['currency'] ?? '';
        $amount = (float)($payment['total_amount'] ?? 0);
        $payload = $payment['invoice_payload'] ?? '';
        $userId = $message['from']['id'] ?? null;
        $chatId = $message['chat']['id'] ?? null;
        if (!$userId || !$chatId) {
            return;
        }

        $method = $currency === 'XTR' ? 'stars' : 'payment';
        $parsed = $this->parseStarsPayload($payload);
        $targetChatId = $parsed['chat_id'] ?? null;
        $plan = $parsed['plan'] ?? null;
        $days = $parsed['days'] ?? null;

        if (!$plan) {
            $tier = $this->payments->selectTier($method, $amount);
            $plan = $tier['plan'] ?? null;
            $days = isset($tier['days']) ? (int)$tier['days'] : null;
        }

        if (!$targetChatId) {
            $defaultChatId = $this->userSettings->getDefaultChatId($userId);
            if ($defaultChatId !== null) {
                $targetChatId = (int)$defaultChatId;
            }
        }

        if ($plan && $targetChatId) {
            $this->subscriptions->setPlan($targetChatId, $plan, $days);
        }

        $status = $this->payments->isTestMode() ? 'test' : 'successful';
        $this->payments->record((int)($targetChatId ?? $chatId), $userId, $method, $amount, (string)$currency, $status, $plan, $days, [
            'payload' => $payload,
        ]);
        $this->audit->log('payment_success', $userId, $targetChatId ? (int)$targetChatId : null, [
            'method' => $method,
            'amount' => $amount,
            'currency' => $currency,
            'plan' => $plan,
            'days' => $days,
        ]);

        $lines = [
            '<b>Payment received</b>',
            'Method: ' . strtoupper($method),
            'Amount: ' . number_format($amount, 2) . ' ' . $this->escape((string)$currency),
        ];
        if ($plan && $targetChatId) {
            $lines[] = 'Applied to chat: ' . $targetChatId;
            $lines[] = 'Plan: ' . strtoupper($plan) . ($days ? (' for ' . $days . ' days') : '');
        } else {
            $lines[] = 'Plan not applied. Set /usechat and try again.';
        }
        $this->tg->sendMessage($chatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function sendStarsInvoice(int|string $responseChatId, int|string $userId, int $chatId, float $amount, ?string $plan, ?int $days): void
    {
        $paymentsConfig = $this->config['payments'] ?? [];
        $starsConfig = $paymentsConfig['stars'] ?? [];
        $title = $starsConfig['title'] ?? 'SP NET MOD TOOL';
        $description = $starsConfig['description'] ?? 'Telegram Stars test purchase.';
        $sandbox = !empty($starsConfig['sandbox']);
        $testEnv = !empty(($this->config['telegram']['test_environment'] ?? false));

        if ($sandbox && !$testEnv) {
            $this->tg->sendMessage($responseChatId, 'Stars sandbox requires telegram.test_environment=true and a test bot token.', ['parse_mode' => 'HTML']);
            return;
        }

        $amountInt = max(1, (int)round($amount));
        $payload = $this->buildStarsPayload($chatId, $plan, $days, $amountInt);

        $params = [
            'chat_id' => $responseChatId,
            'title' => $title,
            'description' => $description,
            'payload' => $payload,
            'currency' => 'XTR',
            'prices' => json_encode([
                ['label' => 'Stars', 'amount' => $amountInt],
            ]),
            'provider_token' => '',
        ];

        $resp = $this->tg->sendInvoice($params);
        if (!($resp['ok'] ?? false)) {
            $this->tg->sendMessage($responseChatId, 'Failed to send Stars invoice. Check bot token + test environment.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::errorContext(
                    'Stars invoice failed',
                    $this->logContextForChatUser($chatId, $userId, [
                        'amount' => $amountInt,
                        'plan' => $plan,
                        'days' => $days,
                        'error' => $resp['description'] ?? 'unknown',
                    ])
                );
            }
            return;
        }

        $this->audit->log('stars_invoice_sent', $userId, $chatId, [
            'amount' => $amountInt,
            'plan' => $plan,
            'days' => $days,
        ]);
    }

    private function buildStarsPayload(int $chatId, ?string $plan, ?int $days, int $amount): string
    {
        $plan = $plan ?: 'free';
        $days = $days ?: 0;
        $payload = 'stars|' . $chatId . '|' . $plan . '|' . $days . '|' . $amount;
        if (strlen($payload) > 120) {
            $payload = 'stars|' . $chatId . '|' . $amount;
        }
        return $payload;
    }

    private function parseStarsPayload(string $payload): ?array
    {
        if (strpos($payload, 'stars|') !== 0) {
            return null;
        }
        $parts = explode('|', $payload);
        if (count($parts) < 3) {
            return null;
        }
        $chatId = isset($parts[1]) ? (int)$parts[1] : null;
        $plan = $parts[2] ?? null;
        $days = isset($parts[3]) ? (int)$parts[3] : null;
        $amount = isset($parts[4]) ? (int)$parts[4] : null;
        if ($plan !== null && is_numeric($plan)) {
            $amount = (int)$plan;
            $plan = null;
            $days = null;
        }
        return [
            'chat_id' => $chatId,
            'plan' => $plan,
            'days' => $days,
            'amount' => $amount,
        ];
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

        if (!empty($report['coverage_gaps'])) {
            $lines[] = 'Coverage gaps (no recent activity):';
            foreach (array_slice($report['coverage_gaps'], 0, 6) as $gap) {
                $lines[] = '- ' . $this->escape($gap);
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
        $this->sendReportDocument($file, $caption);
        if ($this->shouldLog('log_reports')) {
            Logger::infoContext(
                'Trend report generated',
                $this->logContextForChat($chatId, [
                    'month' => $stats['range']['month'] ?? null,
                    'budget' => number_format($budget, 2, '.', ''),
                    'file' => basename($file),
                ])
            );
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
        $this->sendReportDocument($file, $caption);
        if ($this->shouldLog('log_reports')) {
            Logger::infoContext(
                'Executive summary generated',
                $this->logContextForChat($chatId, [
                    'month' => $stats['range']['month'] ?? null,
                    'budget' => number_format($budget, 2, '.', ''),
                    'file' => basename($file),
                ])
            );
        }
    }

    private function handleArchive(int|string $responseChatId, int|string $chatId): void
    {
        $rows = $this->archive->list((int)$chatId, null, 8);
        if (empty($rows)) {
            $this->sendReportMessage('No archived reports yet.', ['parse_mode' => 'HTML']);
            return;
        }
        $lines = ['Recent archived reports:'];
        foreach ($rows as $row) {
            $lines[] = $row['month'] . ' | ' . $row['report_type'] . ' | ' . basename($row['file_path']);
        }
        $this->sendReportMessage(implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function handlePremiumBenefits(int|string $responseChatId, int|string $chatId): void
    {
        $sub = $this->subscriptions->get($chatId);
        $plan = strtoupper($sub['plan'] ?? 'FREE');
        $lines = [
            '<b>Premium Benefits</b>',
            'Current plan: ' . $this->escape($plan),
            '',
            '<b>Why owners upgrade</b>',
            '- Fair rewards: day-normalized scoring + anti-spam caps + eligibility rules',
            '- Smarter rewards: stability bonus, penalty decay, max-share cap',
            '- Multi-chat rollups + per-chat breakdown',
            '- Executive summary + trend report + PDF export',
            '- Coaching tips + team health (coverage gaps, workload balance, burnout risk)',
            '- AI performance reviews per mod (monthly feedback summaries)',
            '- Retention risk alerts (month-over-month drop detection)',
            '- Inactivity spike alerts (rolling 7‑day drop vs previous)',
            '- Import wizard + source breakdown (Bot vs ChatKeeper/Combot)',
            '- Report archive + reward history',
            '- Owner notifications (auto report DM, mid-month progress, at-risk alerts, congrats)',
            '- Log channel + changelog updates',
            '',
            'See tiers: /pricing',
            'Upgrade (owner only): /setplan premium 30',
        ];
        $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::infoContext('Premium benefits viewed', $this->logContextForChat($chatId, ['plan' => $plan]));
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
            '- Core analytics (stats, leaderboard, reward sheets)',
            '- Live dashboard + CSV export',
            '- Auto monthly reports + mid‑month progress',
            '- Multi‑chat summary',
            '- ChatKeeper/Combot CLI imports',
            '- Log channel + changelog updates',
            'Best for: small or single communities',
            '',
            '<b>Premium</b>',
            '- Everything in Free',
            '- Fair reward engine (anti‑spam caps + day normalization)',
            '- Reward upgrades (max-share cap, stability bonus, penalty decay)',
            '- Coaching tips + team health (coverage gaps, workload balance, burnout risk)',
            '- AI performance reviews per mod (monthly feedback summaries)',
            '- Retention risk alerts (month-over-month drop detection)',
            '- Inactivity spike alerts (rolling 7‑day drop vs previous)',
            '- Executive summary + trend report + PDF export',
            '- Import wizard (browser upload) + source breakdown',
            '- Report archive + reward history',
            '- Owner notifications (DM reports, mid-month alerts, congrats)',
            'Best for: multi-group communities and growing teams',
            '',
            '<b>Enterprise</b>',
            '- Everything in Premium',
            '- White-label branding (logo/colors)',
            '- Custom onboarding + assisted setup',
            '- Scoring calibration (we tune weights to your policy)',
            '- Dedicated support + SLA',
            'Best for: large communities with multiple teams',
            '',
            'Pay with Telegram Stars or crypto (test mode supported).',
            'Upgrade (owner only): /setplan premium 30',
        ];

        $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_commands')) {
            Logger::infoContext('Pricing viewed', $this->logContextForChat($chatId, ['plan' => $plan]));
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
            Logger::infoContext(
                'Roster add/update',
                $this->logContextForChat($chatId, [
                    'actor_id' => $message['from']['id'] ?? null,
                    'actor_username' => $message['from']['username'] ?? null,
                    'actor_name' => $this->formatUserLabel($message['from'] ?? []),
                    'target_id' => $target['id'],
                    'target_username' => $target['username'] ?? null,
                    'target_name' => $this->formatUserLabel($target),
                    'role' => $role,
                    'notes' => $notes !== '' ? $notes : null,
                ])
            );
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
            Logger::infoContext(
                'Roster removed',
                $this->logContextForChat($chatId, [
                    'actor_id' => $message['from']['id'] ?? null,
                    'actor_username' => $message['from']['username'] ?? null,
                    'actor_name' => $this->formatUserLabel($message['from'] ?? []),
                    'target_id' => $target['id'],
                    'target_username' => $target['username'] ?? null,
                    'target_name' => $this->formatUserLabel($target),
                ])
            );
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
            Logger::infoContext(
                'Roster role updated',
                $this->logContextForChat($chatId, [
                    'actor_id' => $message['from']['id'] ?? null,
                    'actor_username' => $message['from']['username'] ?? null,
                    'actor_name' => $this->formatUserLabel($message['from'] ?? []),
                    'target_id' => $target['id'],
                    'target_username' => $target['username'] ?? null,
                    'target_name' => $this->formatUserLabel($target),
                    'role' => $role,
                    'notes' => $notes !== '' ? $notes : null,
                ])
            );
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
            Logger::infoContext(
                'Budget updated',
                $this->logContextForChat($chatId, [
                    'amount' => number_format($amount, 2, '.', ''),
                ])
            );
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
            Logger::infoContext('Timezone updated', $this->logContextForChat($chatId, ['timezone' => $tz]));
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
            Logger::infoContext(
                'Activity settings updated',
                $this->logContextForChat($chatId, [
                    'gap_minutes' => $gap,
                    'floor_minutes' => $floor,
                ])
            );
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
        $security = $this->config['security'] ?? [];
        $whitelist = $security['whitelist_chat_ids'] ?? [];
        $params = [$userId];
        $where = 'cm.user_id = ? AND c.type IN (\'group\', \'supergroup\')';
        if (is_array($whitelist) && !empty($whitelist)) {
            $placeholders = implode(',', array_fill(0, count($whitelist), '?'));
            $where .= ' AND c.id IN (' . $placeholders . ')';
            foreach ($whitelist as $id) {
                $params[] = (int)$id;
            }
        }
        $sql = 'SELECT c.id, c.title, c.type, cm.is_mod, cm.updated_at
                FROM chat_members cm
                JOIN chats c ON c.id = cm.chat_id
                WHERE ' . $where . '
                ORDER BY cm.updated_at DESC
                LIMIT ' . $limit;

        return $this->db->fetchAll($sql, $params);
    }

    private function userHasChat(int|string $userId, int|string $chatId): bool
    {
        if (!$this->isWhitelisted($chatId)) {
            return false;
        }
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
                Logger::infoContext(
                    'Mod added (group)',
                    $this->logContextForChat($chatId, [
                        'actor_id' => $message['from']['id'] ?? null,
                        'actor_username' => $message['from']['username'] ?? null,
                        'actor_name' => $this->formatUserLabel($message['from'] ?? []),
                        'target_id' => $target['id'],
                        'target_username' => $target['username'] ?? null,
                        'target_name' => $this->formatUserLabel($target),
                    ])
                );
            }
            return;
        }

        if ($action === 'remove') {
            $this->setModStatus($chatId, $target['id'], false);
            $this->tg->sendMessage($chatId, 'Mod removed: ' . $this->displayName($target), ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::infoContext(
                    'Mod removed (group)',
                    $this->logContextForChat($chatId, [
                        'actor_id' => $message['from']['id'] ?? null,
                        'actor_username' => $message['from']['username'] ?? null,
                        'actor_name' => $this->formatUserLabel($message['from'] ?? []),
                        'target_id' => $target['id'],
                        'target_username' => $target['username'] ?? null,
                        'target_name' => $this->formatUserLabel($target),
                    ])
                );
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
                Logger::infoContext(
                    'Auto report enabled',
                    $this->logContextForChat($chatId, [
                        'day' => $day,
                        'hour' => $hour,
                    ])
                );
            }
            return;
        }

        if ($action === 'off') {
            $this->settings->updateAutoReport($chatId, false, null, null);
            $this->tg->sendMessage($responseChatId, 'Auto report disabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::infoContext('Auto report disabled', $this->logContextForChat($chatId));
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
                Logger::infoContext(
                    'Progress report enabled',
                    $this->logContextForChat($chatId, [
                        'day' => $day,
                        'hour' => $hour,
                    ])
                );
            }
            return;
        }

        if ($action === 'off') {
            $this->settings->updateProgressReport($chatId, false, null, null);
            $this->tg->sendMessage($responseChatId, 'Progress report disabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::infoContext('Progress report disabled', $this->logContextForChat($chatId));
            }
            return;
        }

        $this->tg->sendMessage($responseChatId, 'Usage: /autoprogress on [day] [hour] [chat_id] | /autoprogress off [chat_id] | /autoprogress status [chat_id]', ['parse_mode' => 'HTML']);
    }

    private function handleAutoWeekly(int|string $responseChatId, int|string $chatId, string $args): void
    {
        $parts = preg_split('/\s+/', trim($args));
        $action = strtolower($parts[0] ?? '');

        if ($action === 'status' || $action === '') {
            $settings = $this->settings->get($chatId);
            $status = !empty($settings['weekly_summary_enabled']) ? 'ON' : 'OFF';
            $weekday = (int)($settings['weekly_summary_weekday'] ?? 1);
            $hour = (int)($settings['weekly_summary_hour'] ?? 10);
            $this->tg->sendMessage($responseChatId, 'Weekly summary: ' . $status . ' | Weekday ' . $weekday . ' at ' . $hour . ':00.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($action === 'on') {
            $weekday = isset($parts[1]) ? (int)$parts[1] : null;
            $hour = isset($parts[2]) ? (int)$parts[2] : null;
            if ($weekday !== null && ($weekday < 1 || $weekday > 7)) {
                $this->tg->sendMessage($responseChatId, 'Weekday must be 1-7 (Mon-Sun).', ['parse_mode' => 'HTML']);
                return;
            }
            if ($hour !== null && ($hour < 0 || $hour > 23)) {
                $this->tg->sendMessage($responseChatId, 'Hour must be between 0 and 23.', ['parse_mode' => 'HTML']);
                return;
            }
            $this->settings->updateWeeklySummary($chatId, true, $weekday, $hour);
            $this->tg->sendMessage($responseChatId, 'Weekly summary enabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::infoContext('Weekly summary enabled', $this->logContextForChat($chatId, [
                    'weekday' => $weekday,
                    'hour' => $hour,
                ]));
            }
            return;
        }

        if ($action === 'off') {
            $this->settings->updateWeeklySummary($chatId, false, null, null);
            $this->tg->sendMessage($responseChatId, 'Weekly summary disabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::infoContext('Weekly summary disabled', $this->logContextForChat($chatId));
            }
            return;
        }

        $this->tg->sendMessage($responseChatId, 'Usage: /autoweekly on [weekday] [hour] [chat_id] | /autoweekly off [chat_id] | /autoweekly status [chat_id]', ['parse_mode' => 'HTML']);
    }

    private function handleAutoInactive(int|string $responseChatId, int|string $chatId, string $args): void
    {
        $parts = preg_split('/\s+/', trim($args));
        $action = strtolower($parts[0] ?? '');

        if ($action === 'status' || $action === '') {
            $settings = $this->settings->get($chatId);
            $status = !empty($settings['inactivity_alert_enabled']) ? 'ON' : 'OFF';
            $days = (int)($settings['inactivity_alert_days'] ?? 7);
            $hour = (int)($settings['inactivity_alert_hour'] ?? 10);
            $this->tg->sendMessage($responseChatId, 'Inactivity alerts: ' . $status . ' | ' . $days . 'd at ' . $hour . ':00.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($action === 'on') {
            $days = isset($parts[1]) ? (int)$parts[1] : null;
            $hour = isset($parts[2]) ? (int)$parts[2] : null;
            if ($days !== null && $days < 1) {
                $this->tg->sendMessage($responseChatId, 'Days must be 1 or more.', ['parse_mode' => 'HTML']);
                return;
            }
            if ($hour !== null && ($hour < 0 || $hour > 23)) {
                $this->tg->sendMessage($responseChatId, 'Hour must be between 0 and 23.', ['parse_mode' => 'HTML']);
                return;
            }
            $this->settings->updateInactivityAlert($chatId, true, $days, $hour);
            $this->tg->sendMessage($responseChatId, 'Inactivity alerts enabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::infoContext('Inactivity alerts enabled', $this->logContextForChat($chatId, [
                    'days' => $days,
                    'hour' => $hour,
                ]));
            }
            return;
        }

        if ($action === 'off') {
            $this->settings->updateInactivityAlert($chatId, false, null, null);
            $this->tg->sendMessage($responseChatId, 'Inactivity alerts disabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::infoContext('Inactivity alerts disabled', $this->logContextForChat($chatId));
            }
            return;
        }

        $this->tg->sendMessage($responseChatId, 'Usage: /autoinactive on [days] [hour] [chat_id] | /autoinactive off [chat_id] | /autoinactive status [chat_id]', ['parse_mode' => 'HTML']);
    }

    private function handleAutoAiReview(int|string $responseChatId, int|string $chatId, string $args, int|string $userId): void
    {
        if (!$this->isManager($userId)) {
            $this->tg->sendMessage($responseChatId, 'Only bot managers or owners can change AI review automation.', ['parse_mode' => 'HTML']);
            return;
        }
        if (!$this->requirePremium($chatId, $responseChatId)) {
            return;
        }

        $parts = preg_split('/\s+/', trim($args));
        $action = strtolower($parts[0] ?? 'status');

        $settings = $this->settings->get($chatId);
        if ($action === '' || $action === 'status') {
            $status = !empty($settings['ai_review_enabled']) ? 'ON' : 'OFF';
            $day = (int)($settings['ai_review_day'] ?? 1);
            $hour = (int)($settings['ai_review_hour'] ?? 9);
            $this->tg->sendMessage($responseChatId, 'AI review: ' . $status . ' | Day ' . $day . ' at ' . $hour . ':00.', ['parse_mode' => 'HTML']);
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
            $this->settings->updateAiReview($chatId, true, $day, $hour);
            $this->tg->sendMessage($responseChatId, 'AI review automation enabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::infoContext('AI review automation enabled', $this->logContextForChat($chatId, [
                    'day' => $day,
                    'hour' => $hour,
                ]));
            }
            return;
        }

        if ($action === 'off') {
            $this->settings->updateAiReview($chatId, false, null, null);
            $this->tg->sendMessage($responseChatId, 'AI review automation disabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::infoContext('AI review automation disabled', $this->logContextForChat($chatId));
            }
            return;
        }

        $this->tg->sendMessage($responseChatId, 'Usage: /autoaireview on [day] [hour] [chat_id] | /autoaireview off [chat_id] | /autoaireview status [chat_id]', ['parse_mode' => 'HTML']);
    }

    private function handleAiReview(int|string $responseChatId, int|string $chatId, string $args, int|string $userId): void
    {
        if (!$this->requirePremium($chatId, $responseChatId)) {
            return;
        }
        if (!$this->isManager($userId)) {
            $this->sendReportMessage('Only bot managers or owners can send AI performance reviews.', ['parse_mode' => 'HTML']);
            return;
        }

        $month = null;
        $tokens = preg_split('/\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}$/', $token)) {
                $month = $token;
                break;
            }
        }

        $report = $this->performanceReview->buildReport($chatId, $month);
        if (empty($report['reviews'])) {
            $this->sendReportMessage('No mods are configured yet. Use /modadd first.', ['parse_mode' => 'HTML']);
            return;
        }

        $chatRow = $this->db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
        $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);
        $lines = $this->performanceReview->buildLines($report, $chatTitle);

        $this->sendReportMessage(implode("\n", $lines), ['parse_mode' => 'HTML']);

        if ($this->shouldLog('log_reports')) {
            Logger::infoContext(
                'AI performance review generated',
                $this->logContextForChat($chatId, [
                    'month' => $report['range']['month'] ?? null,
                    'mods' => count($report['reviews']),
                ])
            );
        }
    }

    private function handleAutoRetention(int|string $responseChatId, int|string $chatId, string $args, int|string $userId): void
    {
        if (!$this->isManager($userId)) {
            $this->tg->sendMessage($responseChatId, 'Only bot managers or owners can change retention alerts.', ['parse_mode' => 'HTML']);
            return;
        }
        if (!$this->requirePremium($chatId, $responseChatId)) {
            return;
        }

        $parts = preg_split('/\s+/', trim($args));
        $action = strtolower($parts[0] ?? 'status');
        $settings = $this->settings->get($chatId);

        if ($action === '' || $action === 'status') {
            $status = !empty($settings['retention_alert_enabled']) ? 'ON' : 'OFF';
            $day = (int)($settings['retention_alert_day'] ?? 2);
            $hour = (int)($settings['retention_alert_hour'] ?? 10);
            $threshold = (float)($settings['retention_threshold'] ?? 30);
            $this->tg->sendMessage($responseChatId, 'Retention alerts: ' . $status . ' | Day ' . $day . ' at ' . $hour . ':00 | ' . number_format($threshold, 0) . '% drop.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($action === 'on') {
            $day = isset($parts[1]) ? (int)$parts[1] : null;
            $hour = isset($parts[2]) ? (int)$parts[2] : null;
            $threshold = isset($parts[3]) ? $this->parsePercent($parts[3]) : null;
            if ($day !== null && ($day < 1 || $day > 28)) {
                $this->tg->sendMessage($responseChatId, 'Day must be between 1 and 28.', ['parse_mode' => 'HTML']);
                return;
            }
            if ($hour !== null && ($hour < 0 || $hour > 23)) {
                $this->tg->sendMessage($responseChatId, 'Hour must be between 0 and 23.', ['parse_mode' => 'HTML']);
                return;
            }
            if ($threshold !== null && ($threshold < 5 || $threshold > 90)) {
                $this->tg->sendMessage($responseChatId, 'Threshold must be between 5 and 90.', ['parse_mode' => 'HTML']);
                return;
            }
            $this->settings->updateRetentionAlert($chatId, true, $day, $hour, $threshold);
            $this->tg->sendMessage($responseChatId, 'Retention alerts enabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::infoContext('Retention alerts enabled', $this->logContextForChat($chatId, [
                    'day' => $day,
                    'hour' => $hour,
                    'threshold' => $threshold,
                ]));
            }
            return;
        }

        if ($action === 'off') {
            $this->settings->updateRetentionAlert($chatId, false, null, null, null);
            $this->tg->sendMessage($responseChatId, 'Retention alerts disabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::infoContext('Retention alerts disabled', $this->logContextForChat($chatId));
            }
            return;
        }

        $this->tg->sendMessage($responseChatId, 'Usage: /autoretention on [day] [hour] [threshold%] [chat_id] | /autoretention off [chat_id] | /autoretention status [chat_id]', ['parse_mode' => 'HTML']);
    }

    private function handleRetentionAlert(int|string $responseChatId, int|string $chatId, string $args, int|string $userId): void
    {
        if (!$this->requirePremium($chatId, $responseChatId)) {
            return;
        }
        if (!$this->isManager($userId)) {
            $this->sendReportMessage('Only bot managers or owners can send retention alerts.', ['parse_mode' => 'HTML']);
            return;
        }

        $month = null;
        $threshold = null;
        $tokens = preg_split('/\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}$/', $token)) {
                $month = $token;
                continue;
            }
            $parsed = $this->parsePercent($token);
            if ($parsed !== null) {
                $threshold = $parsed;
            }
        }

        $report = $this->retentionRisk->buildReport($chatId, $month, $threshold);
        if ($report['status'] === 'no_mods') {
            $this->sendReportMessage('No mods are configured yet. Use /modadd first.', ['parse_mode' => 'HTML']);
            return;
        }

        $chatRow = $this->db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
        $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);
        $lines = $this->retentionRisk->buildLines($report, $chatTitle);

        $this->sendReportMessage(implode("\n", $lines), ['parse_mode' => 'HTML']);

        if ($this->shouldLog('log_reports')) {
            Logger::infoContext(
                'Retention alerts generated',
                $this->logContextForChat($chatId, [
                    'month' => $report['range']['month'] ?? null,
                    'flagged' => $report['summary']['flagged'] ?? 0,
                ])
            );
        }
    }

    private function handleWeeklySummaryManual(int|string $responseChatId, int|string $chatId, string $args, int|string $userId): void
    {
        if (!$this->isManager($userId)) {
            $this->sendReportMessage('Only bot managers or owners can send weekly summaries.', ['parse_mode' => 'HTML']);
            return;
        }

        $days = 7;
        $tokens = preg_split('/\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (is_numeric($token)) {
                $days = (int)$token;
            }
        }
        $days = max(3, min(30, $days));

        $stats = $this->stats->getRollingStats($chatId, $days);
        if (empty($stats['mods'])) {
            $this->sendReportMessage('No mods are configured yet. Use /modadd first.', ['parse_mode' => 'HTML']);
            return;
        }

        $lines = $this->buildWeeklySummaryLines($chatId, $stats, $days);
        $tldr = $this->buildWeeklySummaryTldr($chatId, $stats, $days);
        if ($tldr !== '') {
            $this->sendReportMessage($tldr, ['parse_mode' => 'HTML']);
        }
        $this->sendReportMessage(implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function handleAutoSpike(int|string $responseChatId, int|string $chatId, string $args, int|string $userId): void
    {
        if (!$this->isManager($userId)) {
            $this->tg->sendMessage($responseChatId, 'Only bot managers or owners can change inactivity spike alerts.', ['parse_mode' => 'HTML']);
            return;
        }
        if (!$this->requirePremium($chatId, $responseChatId)) {
            return;
        }

        $parts = preg_split('/\s+/', trim($args));
        $action = strtolower($parts[0] ?? 'status');
        $settings = $this->settings->get($chatId);

        if ($action === '' || $action === 'status') {
            $status = !empty($settings['inactivity_spike_enabled']) ? 'ON' : 'OFF';
            $hour = (int)($settings['inactivity_spike_hour'] ?? 10);
            $threshold = (float)($settings['inactivity_spike_threshold'] ?? 35);
            $this->tg->sendMessage($responseChatId, 'Inactivity spike alerts: ' . $status . ' | Hour ' . $hour . ':00 | ' . number_format($threshold, 0) . '% drop.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($action === 'on') {
            $hour = isset($parts[1]) ? (int)$parts[1] : null;
            $threshold = isset($parts[2]) ? $this->parsePercent($parts[2]) : null;
            if ($hour !== null && ($hour < 0 || $hour > 23)) {
                $this->tg->sendMessage($responseChatId, 'Hour must be between 0 and 23.', ['parse_mode' => 'HTML']);
                return;
            }
            if ($threshold !== null && ($threshold < 5 || $threshold > 90)) {
                $this->tg->sendMessage($responseChatId, 'Threshold must be between 5 and 90.', ['parse_mode' => 'HTML']);
                return;
            }
            $this->settings->updateInactivitySpike($chatId, true, $hour, $threshold);
            $this->tg->sendMessage($responseChatId, 'Inactivity spike alerts enabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::infoContext('Inactivity spike alerts enabled', $this->logContextForChat($chatId, [
                    'hour' => $hour,
                    'threshold' => $threshold,
                ]));
            }
            return;
        }

        if ($action === 'off') {
            $this->settings->updateInactivitySpike($chatId, false, null, null);
            $this->tg->sendMessage($responseChatId, 'Inactivity spike alerts disabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::infoContext('Inactivity spike alerts disabled', $this->logContextForChat($chatId));
            }
            return;
        }

        $this->tg->sendMessage($responseChatId, 'Usage: /autospike on [hour] [threshold%] [chat_id] | /autospike off [chat_id] | /autospike status [chat_id]', ['parse_mode' => 'HTML']);
    }

    private function handleAutoFeedback(int|string $responseChatId, int|string $chatId, string $args, int|string $userId): void
    {
        if (!$this->isManager($userId)) {
            $this->tg->sendMessage($responseChatId, 'Only bot managers or owners can change daily feedback.', ['parse_mode' => 'HTML']);
            return;
        }

        $parts = preg_split('/\s+/', trim($args));
        $action = strtolower($parts[0] ?? 'status');
        $settings = $this->settings->get($chatId);
        $allowModDms = (bool)($this->config['reports']['send_to_mods'] ?? false);

        if ($action === '' || $action === 'status') {
            $status = !empty($settings['daily_feedback_enabled']) ? 'ON' : 'OFF';
            $hour = (int)($settings['daily_feedback_hour'] ?? 20);
            $note = $allowModDms ? '' : ' (mod DMs disabled)';
            $this->tg->sendMessage($responseChatId, 'Daily micro-feedback: ' . $status . ' | Hour ' . $hour . ':00' . $note . '.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($action === 'on') {
            $hour = isset($parts[1]) ? (int)$parts[1] : null;
            if ($hour !== null && ($hour < 0 || $hour > 23)) {
                $this->tg->sendMessage($responseChatId, 'Hour must be between 0 and 23.', ['parse_mode' => 'HTML']);
                return;
            }
            $this->settings->updateDailyFeedback($chatId, true, $hour);
            $this->tg->sendMessage($responseChatId, 'Daily micro-feedback enabled.', ['parse_mode' => 'HTML']);
            if (!$allowModDms) {
                $this->tg->sendMessage($responseChatId, 'Note: mod DMs are disabled. Set reports.send_to_mods=true to deliver feedback.', ['parse_mode' => 'HTML']);
            }
            if ($this->shouldLog('log_commands')) {
                Logger::infoContext('Daily micro-feedback enabled', $this->logContextForChat($chatId, [
                    'hour' => $hour,
                ]));
            }
            return;
        }

        if ($action === 'off') {
            $this->settings->updateDailyFeedback($chatId, false, null);
            $this->tg->sendMessage($responseChatId, 'Daily micro-feedback disabled.', ['parse_mode' => 'HTML']);
            if ($this->shouldLog('log_commands')) {
                Logger::infoContext('Daily micro-feedback disabled', $this->logContextForChat($chatId));
            }
            return;
        }

        $this->tg->sendMessage($responseChatId, 'Usage: /autofeedback on [hour] [chat_id] | /autofeedback off [chat_id] | /autofeedback status [chat_id]', ['parse_mode' => 'HTML']);
    }

    private function handleMyDashboard(int|string $responseChatId, int|string $chatId, string $args, int|string $userId): void
    {
        $month = null;
        $tokens = preg_split('/\s+/', trim($args));
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}$/', $token)) {
                $month = $token;
                break;
            }
        }

        $stats = $this->stats->getMonthlyStats($chatId, $month);
        if (empty($stats['mods'])) {
            $this->tg->sendMessage($responseChatId, 'No mods are configured yet. Use /modadd first.', ['parse_mode' => 'HTML']);
            return;
        }

        $target = null;
        foreach ($stats['mods'] as $mod) {
            if ((int)$mod['user_id'] === (int)$userId) {
                $target = $mod;
                break;
            }
        }
        if (!$target) {
            $this->tg->sendMessage($responseChatId, 'You are not a mod in this chat yet.', ['parse_mode' => 'HTML']);
            return;
        }

        $rank = 1;
        foreach ($stats['mods'] as $mod) {
            if ((int)$mod['user_id'] === (int)$userId) {
                break;
            }
            $rank++;
        }
        $totalMods = count($stats['mods']);
        $actions = (int)($target['warnings'] ?? 0) + (int)($target['mutes'] ?? 0) + (int)($target['bans'] ?? 0);
        $activeHours = ((float)($target['active_minutes'] ?? 0)) / 60;
        $presenceHours = ((float)($target['membership_minutes'] ?? 0)) / 60;

        $badges = [];
        foreach ($this->buildPerformanceBadges($stats['mods']) as $badge) {
            if (!empty($badge['user_id']) && (int)$badge['user_id'] === (int)$userId) {
                $badges[] = $badge['title'];
            }
        }

        $monthKey = $stats['range']['month'] ?? $month ?? (new DateTimeImmutable('now', new DateTimeZone($stats['timezone'] ?? 'UTC')))->format('Y-m');
        $goalRow = $this->goals->getGoal($chatId, $userId, $monthKey);
        $goalLines = $this->buildGoalProgressLines($goalRow, $target);
        $feedbackLines = $this->buildPersonalFeedback($target);

        $chatRow = $this->db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
        $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);

        $lines = [];
        $lines[] = '<b>Your Mod Dashboard</b>';
        $lines[] = $this->escape($chatTitle) . ' · ' . $this->escape($stats['range']['label'] ?? $monthKey);
        $lines[] = 'Rank: #' . $rank . ' of ' . $totalMods;
        $lines[] = 'Messages: ' . number_format((int)($target['messages'] ?? 0));
        $lines[] = 'Active hours: ' . number_format($activeHours, 1) . 'h';
        $lines[] = 'Presence hours: ' . number_format($presenceHours, 1) . 'h';
        $lines[] = 'Actions: ' . $actions;
        $lines[] = 'Consistency: ' . number_format((float)($target['consistency_index'] ?? 0), 1) . '%';
        $lines[] = 'Score: ' . number_format((float)($target['score'] ?? 0), 2);
        if (($target['improvement'] ?? null) !== null) {
            $lines[] = 'Trend: ' . number_format((float)$target['improvement'], 1) . '% vs last month';
        }
        $lines[] = '';
        $lines[] = '<b>Badges</b>';
        $lines[] = empty($badges) ? 'No badges yet.' : implode(', ', $badges);
        $lines[] = '';
        $lines[] = '<b>Goals</b>';
        if (empty($goalLines)) {
            $lines[] = 'No goals set. Use /goalset messages 500 or /goalset active_hours 12.';
        } else {
            foreach ($goalLines as $line) {
                $lines[] = $line;
            }
        }
        $lines[] = '';
        $lines[] = '<b>Feedback</b>';
        foreach ($feedbackLines as $line) {
            $lines[] = '• ' . $line;
        }

        $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function handleGoalSet(int|string $responseChatId, int|string $chatId, string $args, array $message): void
    {
        $tokens = preg_split('/\s+/', trim($args));
        if (count($tokens) < 2) {
            $this->tg->sendMessage($responseChatId, 'Usage: /goalset <messages|active_hours|actions|days_active|score> <value> [YYYY-MM] [@user]', ['parse_mode' => 'HTML']);
            return;
        }

        $metric = strtolower($tokens[0] ?? '');
        $valueToken = $tokens[1] ?? null;
        if ($valueToken === null || !is_numeric($valueToken)) {
            $this->tg->sendMessage($responseChatId, 'Goal value must be a number.', ['parse_mode' => 'HTML']);
            return;
        }

        $metric = $this->normalizeGoalMetric($metric);
        if ($metric === null) {
            $this->tg->sendMessage($responseChatId, 'Unknown goal type. Use messages, active_hours, actions, days_active, or score.', ['parse_mode' => 'HTML']);
            return;
        }

        $month = null;
        $targetUserId = $message['from']['id'];
        $targetUsername = null;

        if (isset($message['reply_to_message']['from'])) {
            $targetUserId = $message['reply_to_message']['from']['id'];
            $targetUsername = $message['reply_to_message']['from']['username'] ?? null;
        }

        foreach (array_slice($tokens, 2) as $token) {
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
            } elseif (is_numeric($token)) {
                $targetUserId = (int)$token;
                $targetUsername = null;
            }
        }

        $actorId = $message['from']['id'];
        if ($targetUserId !== $actorId || $targetUsername !== null) {
            if (!$this->isManager($actorId)) {
                $this->tg->sendMessage($responseChatId, 'You can only set your own goals.', ['parse_mode' => 'HTML']);
                return;
            }
            $target = $this->findChatUser($chatId, $targetUserId ? (int)$targetUserId : null, $targetUsername);
            if (!$target) {
                $this->tg->sendMessage($responseChatId, 'User not found in this chat.', ['parse_mode' => 'HTML']);
                return;
            }
            $targetUserId = (int)$target['id'];
        }

        if (!$this->isMod($chatId, $targetUserId)) {
            $this->tg->sendMessage($responseChatId, 'Goals can only be set for mods.', ['parse_mode' => 'HTML']);
            return;
        }

        if ($month === null) {
            $month = $this->currentMonthKey($chatId);
        }

        $value = (float)$valueToken;
        $this->goals->setGoal($chatId, $targetUserId, $month, $metric, $value);
        $this->tg->sendMessage($responseChatId, 'Goal saved for ' . $month . '.', ['parse_mode' => 'HTML']);
    }

    private function handleGoalStatus(int|string $responseChatId, int|string $chatId, string $args, array $message): void
    {
        $month = null;
        $targetUserId = $message['from']['id'];
        $targetUsername = null;

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
            } elseif (is_numeric($token)) {
                $targetUserId = (int)$token;
                $targetUsername = null;
            }
        }

        $actorId = $message['from']['id'];
        if ($targetUserId !== $actorId || $targetUsername !== null) {
            if (!$this->isManager($actorId)) {
                $this->tg->sendMessage($responseChatId, 'You can only view your own goals.', ['parse_mode' => 'HTML']);
                return;
            }
            $target = $this->findChatUser($chatId, $targetUserId ? (int)$targetUserId : null, $targetUsername);
            if (!$target) {
                $this->tg->sendMessage($responseChatId, 'User not found in this chat.', ['parse_mode' => 'HTML']);
                return;
            }
            $targetUserId = (int)$target['id'];
        }

        if ($month === null) {
            $month = $this->currentMonthKey($chatId);
        }

        $stats = $this->stats->getMonthlyStats($chatId, $month);
        $target = null;
        foreach ($stats['mods'] as $mod) {
            if ((int)$mod['user_id'] === (int)$targetUserId) {
                $target = $mod;
                break;
            }
        }
        if (!$target) {
            $this->tg->sendMessage($responseChatId, 'Mod not found in this chat.', ['parse_mode' => 'HTML']);
            return;
        }

        $goalRow = $this->goals->getGoal($chatId, $targetUserId, $month);
        $goalLines = $this->buildGoalProgressLines($goalRow, $target);
        if (empty($goalLines)) {
            $this->tg->sendMessage($responseChatId, 'No goals set for this month.', ['parse_mode' => 'HTML']);
            return;
        }

        $lines = [];
        $lines[] = '<b>Goal Tracker</b>';
        $lines[] = $this->escape($stats['range']['label'] ?? $month);
        foreach ($goalLines as $line) {
            $lines[] = $line;
        }
        $this->tg->sendMessage($responseChatId, implode("\n", $lines), ['parse_mode' => 'HTML']);
    }

    private function handleGoalClear(int|string $responseChatId, int|string $chatId, string $args, array $message): void
    {
        $month = null;
        $targetUserId = $message['from']['id'];
        $targetUsername = null;

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
            } elseif (is_numeric($token)) {
                $targetUserId = (int)$token;
                $targetUsername = null;
            }
        }

        $actorId = $message['from']['id'];
        if ($targetUserId !== $actorId || $targetUsername !== null) {
            if (!$this->isManager($actorId)) {
                $this->tg->sendMessage($responseChatId, 'You can only clear your own goals.', ['parse_mode' => 'HTML']);
                return;
            }
            $target = $this->findChatUser($chatId, $targetUserId ? (int)$targetUserId : null, $targetUsername);
            if (!$target) {
                $this->tg->sendMessage($responseChatId, 'User not found in this chat.', ['parse_mode' => 'HTML']);
                return;
            }
            $targetUserId = (int)$target['id'];
        }

        if ($month === null) {
            $month = $this->currentMonthKey($chatId);
        }

        $this->goals->clearGoals($chatId, $targetUserId, $month);
        $this->tg->sendMessage($responseChatId, 'Goals cleared for ' . $month . '.', ['parse_mode' => 'HTML']);
    }

    private function buildGoalProgressLines(?array $goalRow, array $mod): array
    {
        if (!$goalRow) {
            return [];
        }

        $lines = [];
        $map = [
            'messages_target' => ['label' => 'Messages', 'current' => (float)($mod['messages'] ?? 0), 'decimals' => 0],
            'active_hours_target' => ['label' => 'Active hours', 'current' => ((float)($mod['active_minutes'] ?? 0)) / 60, 'decimals' => 1],
            'actions_target' => ['label' => 'Actions', 'current' => (float)((($mod['warnings'] ?? 0) + ($mod['mutes'] ?? 0) + ($mod['bans'] ?? 0))), 'decimals' => 0],
            'days_active_target' => ['label' => 'Active days', 'current' => (float)($mod['days_active'] ?? 0), 'decimals' => 0],
            'score_target' => ['label' => 'Score', 'current' => (float)($mod['score'] ?? 0), 'decimals' => 2],
        ];

        foreach ($map as $field => $meta) {
            if (!isset($goalRow[$field]) || $goalRow[$field] === null) {
                continue;
            }
            $target = (float)$goalRow[$field];
            if ($target <= 0) {
                continue;
            }
            $current = (float)$meta['current'];
            $pct = min(200, ($current / $target) * 100);
            $decimals = (int)($meta['decimals'] ?? 0);
            $lines[] = $meta['label'] . ': ' . number_format($current, $decimals) . ' / ' . number_format($target, $decimals) . ' (' . number_format($pct, 0) . '%)';
        }

        return $lines;
    }

    private function normalizeGoalMetric(string $metric): ?string
    {
        $metric = strtolower(trim($metric));
        $map = [
            'message' => 'messages',
            'messages' => 'messages',
            'msg' => 'messages',
            'msgs' => 'messages',
            'active_hours' => 'active_hours',
            'hours' => 'active_hours',
            'hour' => 'active_hours',
            'active' => 'active_hours',
            'actions' => 'actions',
            'action' => 'actions',
            'days' => 'days_active',
            'days_active' => 'days_active',
            'score' => 'score',
        ];
        return $map[$metric] ?? null;
    }

    private function currentMonthKey(int|string $chatId): string
    {
        $settings = $this->settings->get($chatId);
        $timezone = $settings['timezone'] ?? ($this->config['timezone'] ?? 'UTC');
        $tz = new DateTimeZone($timezone);
        return (new DateTimeImmutable('now', $tz))->format('Y-m');
    }

    private function buildPersonalFeedback(array $mod): array
    {
        $feedbackConfig = $this->config['micro_feedback'] ?? [];
        $minMessages = (int)($feedbackConfig['min_messages'] ?? 10);
        $minActiveHours = (float)($feedbackConfig['min_active_hours'] ?? 1.0);
        $actionsWarn = (float)($feedbackConfig['actions_per_1k_warn'] ?? 35);
        $positiveMessages = (int)($feedbackConfig['positive_messages'] ?? 50);
        $positiveHours = (float)($feedbackConfig['positive_active_hours'] ?? 2.5);

        $messages = (int)($mod['messages'] ?? 0);
        $activeHours = ((float)($mod['active_minutes'] ?? 0)) / 60;
        $actions = (int)($mod['warnings'] ?? 0) + (int)($mod['mutes'] ?? 0) + (int)($mod['bans'] ?? 0);
        $actionsPer1k = $messages > 0 ? ($actions / $messages) * 1000 : ($actions > 0 ? 999 : 0);
        $consistency = (float)($mod['consistency_index'] ?? 0);
        $improvement = $mod['improvement'] ?? null;

        $tips = [];
        if ($consistency < 40) {
            $tips[] = 'Consistency is low. Aim for more active days.';
        }
        if ($messages < ($minMessages * 2)) {
            $tips[] = 'Try to increase chat engagement (messages are low).';
        }
        if ($activeHours < ($minActiveHours * 2)) {
            $tips[] = 'Spend a bit more time in chat to build presence.';
        }
        if ($actions >= 3 && $actionsPer1k >= $actionsWarn) {
            $tips[] = 'Moderation intensity is high; balance with chat support.';
        }
        if ($improvement !== null && $improvement < 0) {
            $tips[] = 'Score is trending down vs last month. Focus on balance.';
        }
        if ($messages >= $positiveMessages && $activeHours >= $positiveHours) {
            $tips[] = 'Great presence this month — keep it up.';
        }

        if (empty($tips)) {
            $tips[] = 'Nice work. Keep a steady pace this month.';
        }

        return array_slice($tips, 0, 3);
    }

    private function buildWeeklySummaryLines(int|string $chatId, array $stats, int $days): array
    {
        $chatRow = $this->db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
        $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);
        $summary = $stats['summary'] ?? [];

        $lines = [];
        $lines[] = '<b>Weekly Summary</b>';
        $lines[] = $this->escape($chatTitle) . ' · ' . $this->escape($stats['range']['label'] ?? ('Last ' . $days . ' days'));
        $lines[] = 'Messages: ' . number_format((int)($summary['messages'] ?? 0));
        $lines[] = 'Active hours: ' . number_format(((float)($summary['active_minutes'] ?? 0)) / 60, 1) . 'h';
        $lines[] = 'Actions: ' . number_format((int)($summary['warnings'] ?? 0) + (int)($summary['mutes'] ?? 0) + (int)($summary['bans'] ?? 0));
        $lines[] = '';
        $lines[] = '<b>Top 5 Mods</b>';

        $top = array_slice($stats['mods'], 0, 5);
        $rank = 1;
        foreach ($top as $mod) {
            $activeH = ((float)($mod['active_minutes'] ?? 0)) / 60;
            $presenceH = ((float)($mod['membership_minutes'] ?? 0)) / 60;
            $actions = (int)($mod['warnings'] ?? 0) + (int)($mod['mutes'] ?? 0) + (int)($mod['bans'] ?? 0);
            $lines[] = $rank . '. ' . $mod['display_name'] . ' · ' . number_format($activeH, 1) . 'h active · ' . number_format($presenceH, 1) . 'h presence · ' . $actions . ' actions';
            $rank++;
        }

        [$chatRank, $actionRank] = $this->buildActivityRanks($stats['mods'], 5);
        $lines[] = '';
        $lines[] = '<b>Chat Work Ranking</b>';
        $rank = 1;
        foreach ($chatRank as $mod) {
            $lines[] = $rank . '. ' . $mod['display_name'] . ' · ' . number_format($mod['chat_score'], 2) . ' score · ' . number_format($mod['active_hours'], 1) . 'h active · ' . number_format((int)$mod['messages']) . ' msgs';
            $rank++;
        }
        $lines[] = '';
        $lines[] = '<b>Moderation Actions Ranking</b>';
        $rank = 1;
        foreach ($actionRank as $mod) {
            $lines[] = $rank . '. ' . $mod['display_name'] . ' · ' . number_format($mod['action_score'], 2) . ' score · ' . $mod['actions'] . ' actions';
            $rank++;
        }

        $eligibility = $this->config['eligibility'] ?? [];
        $minDays = (int)($eligibility['min_days_active'] ?? 0);
        $minMessages = (int)($eligibility['min_messages'] ?? 0);
        $alerts = [];
        foreach ($stats['mods'] as $mod) {
            if ($minDays > 0 && ($mod['days_active'] ?? 0) < $minDays) {
                $alerts[] = $mod['display_name'] . ' (low days)';
                continue;
            }
            if ($minMessages > 0 && ($mod['messages'] ?? 0) < $minMessages) {
                $alerts[] = $mod['display_name'] . ' (low msgs)';
            }
        }
        if (!empty($alerts)) {
            $lines[] = '';
            $lines[] = '<b>Alerts</b>';
            $lines[] = implode(', ', array_slice($alerts, 0, 8));
        }

        $badges = $this->buildPerformanceBadges($stats['mods'] ?? []);
        if (!empty($badges)) {
            $lines[] = '';
            $lines[] = '<b>Performance Badges</b>';
            foreach ($badges as $badge) {
                $line = $badge['title'] . ': ' . $badge['name'];
                if (!empty($badge['meta'])) {
                    $line .= ' (' . $badge['meta'] . ')';
                }
                $lines[] = $line;
            }
        }

        $burnoutRisks = $this->buildBurnoutRisks($chatId);
        if (!empty($burnoutRisks)) {
            $lines[] = '';
            $lines[] = '<b>Burnout Risk (MTD)</b>';
            foreach ($burnoutRisks as $risk) {
                $line = $risk['name'] . ' · ' . number_format((float)($risk['active_hours'] ?? 0), 1) . 'h';
                if (($risk['consistency_drop'] ?? null) !== null) {
                    $line .= ' · Consistency -' . number_format((float)$risk['consistency_drop'], 1) . '%';
                }
                if (($risk['improvement'] ?? null) !== null && $risk['improvement'] < 0) {
                    $line .= ' · Score ' . number_format((float)$risk['improvement'], 1) . '%';
                }
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function buildBurnoutRisks(int|string $chatId): array
    {
        $settings = $this->settings->get($chatId);
        $timezone = $settings['timezone'] ?? ($this->config['timezone'] ?? 'UTC');
        $tz = new DateTimeZone($timezone);
        $nowLocal = new DateTimeImmutable('now', $tz);
        $monthKey = $nowLocal->format('Y-m');
        $bundle = $this->stats->getMonthlyStats($chatId, $monthKey);
        if (empty($bundle['mods'])) {
            return [];
        }
        $prevMonthKey = $nowLocal->modify('first day of last month')->format('Y-m');
        $prevBundle = $this->stats->getMonthlyStats($chatId, $prevMonthKey);
        return $this->computeBurnoutRisks($bundle['mods'], $prevBundle['mods'] ?? []);
    }

    private function computeBurnoutRisks(array $mods, array $prevMods): array
    {
        $prevMap = [];
        foreach ($prevMods as $mod) {
            $prevMap[(int)$mod['user_id']] = $mod;
        }

        $riskConfig = $this->config['burnout_risk'] ?? [];
        $minHours = (float)($riskConfig['min_active_hours'] ?? 40);
        $consistencyDrop = (float)($riskConfig['consistency_drop'] ?? 15);
        $improvementDrop = (float)($riskConfig['improvement_drop'] ?? -10);
        $qualityPer1k = (float)($riskConfig['quality_actions_per_1k'] ?? 35);
        $qualityMinActions = (int)($riskConfig['quality_min_actions'] ?? 5);

        $risks = [];
        foreach ($mods as $mod) {
            $activeHours = ((float)($mod['active_minutes'] ?? 0)) / 60;
            if ($activeHours < $minHours) {
                continue;
            }

            $prev = $prevMap[(int)$mod['user_id']] ?? null;
            $prevConsistency = $prev ? (float)($prev['consistency_index'] ?? 0) : null;
            $consistency = (float)($mod['consistency_index'] ?? 0);
            $consistencyDropValue = $prevConsistency !== null ? ($prevConsistency - $consistency) : null;

            $improvement = $mod['improvement'] ?? null;
            $actions = (int)($mod['warnings'] ?? 0) + (int)($mod['mutes'] ?? 0) + (int)($mod['bans'] ?? 0);
            $messages = (int)($mod['messages'] ?? 0);
            $actionsPer1k = $messages > 0 ? ($actions / $messages) * 1000 : ($actions > 0 ? 999 : 0);
            $qualityFlag = $actions >= $qualityMinActions && $actionsPer1k >= $qualityPer1k;

            $consistencyFlag = $consistencyDropValue !== null && $consistencyDropValue >= $consistencyDrop;
            $improvementFlag = $improvement !== null && $improvement <= $improvementDrop;

            if (!$consistencyFlag && !$improvementFlag && !$qualityFlag) {
                continue;
            }

            $risks[] = [
                'name' => $mod['display_name'],
                'active_hours' => $activeHours,
                'consistency_drop' => $consistencyDropValue,
                'improvement' => $improvement,
            ];
        }

        usort($risks, fn($a, $b) => ($b['active_hours'] <=> $a['active_hours']));
        return array_slice($risks, 0, 6);
    }

    private function buildPerformanceBadges(array $mods): array
    {
        if (empty($mods)) {
            return [];
        }

        $weights = $this->config['score_weights'] ?? [];
        $fastMinMessages = 15;
        $fastMinHours = 1.0;
        $balancedMinMessages = 15;
        $balancedMinActions = 1;

        $topHelper = null;
        $topHelperMessages = -1;
        $consistencyKing = null;
        $consistencyMax = -1.0;
        $fastResponder = null;
        $fastRate = null;
        $fastFallback = null;
        $fastFallbackRate = null;
        $mostBalanced = null;
        $mostBalancedScore = null;
        $balancedFallback = null;
        $balancedFallbackScore = null;
        $mostBalancedMeta = '';
        $balancedFallbackMeta = '';

        foreach ($mods as $mod) {
            $messages = (int)($mod['messages'] ?? 0);
            if ($messages > $topHelperMessages) {
                $topHelperMessages = $messages;
                $topHelper = $mod;
            }
            $consistency = (float)($mod['consistency_index'] ?? 0);
            if ($consistency > $consistencyMax) {
                $consistencyMax = $consistency;
                $consistencyKing = $mod;
            }

            $activeMinutes = (float)($mod['active_minutes'] ?? 0);
            $hours = $activeMinutes / 60;
            if ($messages > 0 && $hours > 0) {
                $rate = $messages / $hours;
                if ($messages >= $fastMinMessages && $hours >= $fastMinHours) {
                    if ($fastRate === null || $rate > $fastRate) {
                        $fastRate = $rate;
                        $fastResponder = $mod;
                    }
                } else {
                    if ($fastFallbackRate === null || $rate > $fastFallbackRate) {
                        $fastFallbackRate = $rate;
                        $fastFallback = $mod;
                    }
                }
            }

            $warnings = (int)($mod['warnings'] ?? 0);
            $mutes = (int)($mod['mutes'] ?? 0);
            $bans = (int)($mod['bans'] ?? 0);
            $actions = $warnings + $mutes + $bans;
            $daysActive = (int)($mod['days_active'] ?? 0);
            $roleMultiplier = (float)($mod['role_multiplier'] ?? 1.0);

            $chatScore = 0.0;
            $chatScore += log(1 + $messages) * ($weights['message'] ?? 1.0);
            $chatScore += sqrt($activeMinutes) * ($weights['active_minute'] ?? 0.0);
            $chatScore += $daysActive * ($weights['day_active'] ?? 0.0);
            $chatScore *= $roleMultiplier;

            $actionScore = 0.0;
            $actionScore += $warnings * ($weights['warn'] ?? 1.0);
            $actionScore += $mutes * ($weights['mute'] ?? 1.0);
            $actionScore += $bans * ($weights['ban'] ?? 1.0);
            $actionScore *= $roleMultiplier;

            if ($chatScore > 0 && $actionScore > 0) {
                $balanceRatio = 1 - (abs($chatScore - $actionScore) / max($chatScore, $actionScore, 1.0));
                $score = ($chatScore + $actionScore) * $balanceRatio;
                $meta = 'Balance ' . number_format($balanceRatio * 100, 0) . '% · ' . $messages . ' msgs · ' . $actions . ' actions';

                if ($messages >= $balancedMinMessages && $actions >= $balancedMinActions) {
                    if ($mostBalancedScore === null || $score > $mostBalancedScore) {
                        $mostBalancedScore = $score;
                        $mostBalanced = $mod;
                        $mostBalancedMeta = $meta;
                    }
                } else {
                    if ($balancedFallbackScore === null || $score > $balancedFallbackScore) {
                        $balancedFallbackScore = $score;
                        $balancedFallback = $mod;
                        $balancedFallbackMeta = $meta;
                    }
                }
            }
        }

        if ($fastResponder === null && $fastFallback !== null) {
            $fastResponder = $fastFallback;
            $fastRate = $fastFallbackRate;
        }
        if ($mostBalanced === null && $balancedFallback !== null) {
            $mostBalanced = $balancedFallback;
            $mostBalancedMeta = $balancedFallbackMeta;
        }

        $badges = [];
        if ($topHelper) {
            $badges[] = [
                'title' => 'Top Helper',
                'user_id' => $topHelper['user_id'],
                'name' => $topHelper['display_name'],
                'meta' => number_format((int)($topHelper['messages'] ?? 0)) . ' msgs',
            ];
        }
        if ($mostBalanced) {
            $badges[] = [
                'title' => 'Most Balanced',
                'user_id' => $mostBalanced['user_id'],
                'name' => $mostBalanced['display_name'],
                'meta' => $mostBalancedMeta,
            ];
        }
        if ($consistencyKing) {
            $badges[] = [
                'title' => 'Consistency King',
                'user_id' => $consistencyKing['user_id'],
                'name' => $consistencyKing['display_name'],
                'meta' => number_format((float)($consistencyKing['consistency_index'] ?? 0), 1) . '%',
            ];
        }
        if ($fastResponder && $fastRate !== null) {
            $badges[] = [
                'title' => 'Fast Responder',
                'user_id' => $fastResponder['user_id'],
                'name' => $fastResponder['display_name'],
                'meta' => number_format($fastRate, 1) . ' msgs/hr',
            ];
        }

        return $badges;
    }

    private function buildWeeklySummaryTldr(int|string $chatId, array $stats, int $days): string
    {
        $summary = $stats['summary'] ?? [];
        $mods = $stats['mods'] ?? [];

        $chatRow = $this->db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
        $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);

        $messages = number_format((int)($summary['messages'] ?? 0));
        $activeHours = number_format(((float)($summary['active_minutes'] ?? 0)) / 60, 1);
        $actions = number_format((int)($summary['warnings'] ?? 0) + (int)($summary['mutes'] ?? 0) + (int)($summary['bans'] ?? 0));

        $topRewardLabel = 'n/a';
        if (!empty($mods)) {
            $top = $mods[0];
            $topName = $this->escape((string)($top['display_name'] ?? 'Unknown'));
            $topScore = number_format((float)($top['score'] ?? 0), 2);
            $topRewardLabel = $topName . ' (score ' . $topScore . ')';
        }

        $riskLabel = 'none';
        $riskReason = '';
        if (!empty($mods)) {
            $inactive = array_filter($mods, static function (array $mod): bool {
                $messages = (int)($mod['messages'] ?? 0);
                $activeMinutes = (float)($mod['active_minutes'] ?? 0);
                $actions = (int)($mod['warnings'] ?? 0) + (int)($mod['mutes'] ?? 0) + (int)($mod['bans'] ?? 0);
                return $messages === 0 && $activeMinutes <= 0 && $actions === 0;
            });

            if (!empty($inactive)) {
                usort($inactive, fn($a, $b) => ($b['membership_minutes'] ?? 0) <=> ($a['membership_minutes'] ?? 0));
                $riskMod = $inactive[0];
                $riskLabel = $this->escape((string)($riskMod['display_name'] ?? 'Unknown'));
                $riskReason = '0 msgs, 0h';
            } else {
                $byScore = $mods;
                usort($byScore, fn($a, $b) => ($a['score'] ?? 0) <=> ($b['score'] ?? 0));
                $riskMod = $byScore[0] ?? null;
                if ($riskMod) {
                    $riskLabel = $this->escape((string)($riskMod['display_name'] ?? 'Unknown'));
                    $riskMsgs = (int)($riskMod['messages'] ?? 0);
                    $riskHoursValue = ((float)($riskMod['active_minutes'] ?? 0)) / 60;
                    $riskHours = number_format($riskHoursValue, 1);
                    $riskReason = $riskMsgs > 0 || $riskHoursValue > 0
                        ? $riskMsgs . ' msgs, ' . $riskHours . 'h'
                        : 'low activity';
                }
            }
        }

        $label = $this->escape($stats['range']['label'] ?? ('Last ' . $days . ' days'));
        $line = '<b>TL;DR</b> · ' . $this->escape($chatTitle) . ' · ' . $label . ' · Quick summary: ' . $messages . ' msgs, ' . $activeHours . 'h active, ' . $actions . ' actions | Top reward: ' . $topRewardLabel . ' | Top risk: ' . $riskLabel;
        if ($riskReason !== '') {
            $line .= ' (' . $riskReason . ')';
        }

        return $line;
    }

    private function handleActivityRank(int|string $responseChatId, int|string $chatId, string $args, int|string $userId): void
    {
        if (!$this->isManager($userId)) {
            $this->sendReportMessage('Only bot managers or owners can send activity rankings.', ['parse_mode' => 'HTML']);
            return;
        }

        $month = null;
        $topN = 5;
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
                $topN = (int)$token;
            }
        }
        $topN = max(1, min(10, $topN));

        $stats = $this->stats->getMonthlyStats($chatId, $month);
        if (empty($stats['mods'])) {
            $this->sendReportMessage('No mods are configured yet. Use /modadd first.', ['parse_mode' => 'HTML']);
            return;
        }

        $chatRow = $this->db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
        $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);
        $rangeLabel = $stats['range']['label'] ?? ($month ?? 'current month');

        [$chatRank, $actionRank] = $this->buildActivityRanks($stats['mods'], $topN);

        $lines = [];
        $lines[] = '<b>Activity Rankings</b>';
        $lines[] = $this->escape($chatTitle) . ' · ' . $this->escape($rangeLabel);
        $lines[] = '';
        $lines[] = '<b>Chat Work</b>';
        $rank = 1;
        foreach ($chatRank as $mod) {
            $lines[] = $rank . '. ' . $mod['display_name'] . ' · ' . number_format($mod['chat_score'], 2) . ' score · ' . number_format($mod['active_hours'], 1) . 'h active · ' . number_format((int)$mod['messages']) . ' msgs';
            $rank++;
        }
        $lines[] = '';
        $lines[] = '<b>Moderation Actions</b>';
        $rank = 1;
        foreach ($actionRank as $mod) {
            $lines[] = $rank . '. ' . $mod['display_name'] . ' · ' . number_format($mod['action_score'], 2) . ' score · ' . $mod['actions'] . ' actions';
            $rank++;
        }

        $message = implode("\n", $lines);
        $this->sendReportMessage($message, ['parse_mode' => 'HTML']);
    }

    private function buildActivityRanks(array $mods, int $topN): array
    {
        $weights = $this->config['score_weights'] ?? [];
        $ranked = [];
        foreach ($mods as $mod) {
            $messages = (int)($mod['messages'] ?? 0);
            $activeMinutes = (float)($mod['active_minutes'] ?? 0);
            $daysActive = (int)($mod['days_active'] ?? 0);
            $warnings = (int)($mod['warnings'] ?? 0);
            $mutes = (int)($mod['mutes'] ?? 0);
            $bans = (int)($mod['bans'] ?? 0);
            $actions = $warnings + $mutes + $bans;
            $roleMultiplier = (float)($mod['role_multiplier'] ?? 1.0);

            $chatScore = 0.0;
            $chatScore += log(1 + $messages) * ($weights['message'] ?? 1.0);
            $chatScore += sqrt($activeMinutes) * ($weights['active_minute'] ?? 0.0);
            $chatScore += $daysActive * ($weights['day_active'] ?? 0.0);
            $chatScore *= $roleMultiplier;

            $actionScore = 0.0;
            $actionScore += $warnings * ($weights['warn'] ?? 1.0);
            $actionScore += $mutes * ($weights['mute'] ?? 1.0);
            $actionScore += $bans * ($weights['ban'] ?? 1.0);
            $actionScore *= $roleMultiplier;

            $ranked[] = [
                'display_name' => $mod['display_name'],
                'messages' => $messages,
                'active_hours' => $activeMinutes / 60,
                'actions' => $actions,
                'chat_score' => $chatScore,
                'action_score' => $actionScore,
            ];
        }

        $chatRank = $ranked;
        usort($chatRank, fn($a, $b) => $b['chat_score'] <=> $a['chat_score']);
        $actionRank = $ranked;
        usort($actionRank, fn($a, $b) => $b['action_score'] <=> $a['action_score']);

        return [
            array_slice($chatRank, 0, $topN),
            array_slice($actionRank, 0, $topN),
        ];
    }

    private function getManagerTargets(): array
    {
        $managerIds = $this->config['manager_user_ids'] ?? [];
        $ownerIds = $this->config['owner_user_ids'] ?? [];
        $targets = array_merge(
            is_array($managerIds) ? $managerIds : [],
            is_array($ownerIds) ? $ownerIds : []
        );
        $targets = array_values(array_unique(array_filter(array_map('intval', $targets))));
        return $targets;
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
            $this->sendReportMessage('No mods are configured yet. Use /modadd [chat_id] @username in private chat (or /usechat).', ['parse_mode' => 'HTML']);
            return;
        }

        if ($budget === null) {
            $budget = (float)($bundle['settings']['reward_budget'] ?? 0);
        }

        $suffix = ($bundle['range']['month'] ?? 'mtd') . '-mtd';
        $filePath = $this->rewardSheet->generate($chatId, null, $budget, $bundle, $suffix);
        $caption = 'Progress report (MTD) for ' . $bundle['range']['label'] . ' (budget: ' . number_format($budget, 2) . ')';
        $this->sendReportDocument($filePath, $caption);
        if ($this->shouldLog('log_reports')) {
            Logger::infoContext(
                'Progress report generated',
                $this->logContextForChat($chatId, [
                    'month' => $bundle['range']['month'] ?? null,
                    'budget' => number_format($budget, 2, '.', ''),
                    'file' => basename($filePath),
                ])
            );
        }
    }

    private function handleForecast(int|string $responseChatId, int|string $chatId, string $args): void
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

        $settings = $this->settings->get($chatId);
        if ($budget === null) {
            $budget = (float)($settings['reward_budget'] ?? 0);
        }

        $timezone = $settings['timezone'] ?? ($this->config['timezone'] ?? 'UTC');
        $tz = new DateTimeZone($timezone);
        $nowLocal = new DateTimeImmutable('now', $tz);
        $currentMonthKey = $nowLocal->format('Y-m');
        if ($month !== null && $month !== $currentMonthKey) {
            $this->sendReportMessage('Forecast is available for the current month only.', ['parse_mode' => 'HTML']);
            return;
        }

        $mtdBundle = $this->stats->getMonthToDateStats($chatId, $nowLocal);
        if (empty($mtdBundle['mods'])) {
            $this->sendReportMessage('No mods are configured yet. Use /modadd first.', ['parse_mode' => 'HTML']);
            return;
        }

        $lastMonthKey = $nowLocal->modify('first day of last month')->format('Y-m');
        $lastBundle = $this->stats->getMonthlyStats($chatId, $lastMonthKey);
        $forecast = $this->buildRewardForecast($mtdBundle, $lastBundle, $budget);
        if (($forecast['status'] ?? '') !== 'ok') {
            $this->sendReportMessage('Forecast is unavailable right now.', ['parse_mode' => 'HTML']);
            return;
        }

        $chatRow = $this->db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
        $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);
        $summary = $mtdBundle['summary'] ?? [];
        $messages = (int)($summary['messages'] ?? 0);
        $actions = (int)($summary['warnings'] ?? 0) + (int)($summary['mutes'] ?? 0) + (int)($summary['bans'] ?? 0);
        $activeHours = ((float)($summary['active_minutes'] ?? 0)) / 60;

        $deltaPct = (float)($forecast['delta_pct'] ?? 0);
        $deltaLabel = ($deltaPct >= 0 ? '+' : '') . number_format($deltaPct, 1) . '%';

        $lines = [];
        $lines[] = '<b>Reward Forecast</b>';
        $lines[] = $this->escape($chatTitle) . ' · ' . $this->escape($mtdBundle['range']['label'] ?? 'MTD');
        $lines[] = 'Days: ' . (int)$forecast['days_elapsed'] . '/' . (int)$forecast['days_total'] . ' · Pace ' . number_format((float)$forecast['pace_factor'], 2) . 'x';
        $lines[] = 'MTD: ' . number_format($messages) . ' msgs · ' . number_format($actions) . ' actions · ' . number_format($activeHours, 1) . 'h active';
        $lines[] = 'Projected: ' . number_format((float)$forecast['projected_messages']) . ' msgs · ' . number_format((float)$forecast['projected_actions']) . ' actions · ' . number_format((float)$forecast['projected_active_hours'], 1) . 'h active';
        $lines[] = 'Index: ' . number_format((float)$forecast['projected_index'], 1) . ' (baseline ' . number_format((float)$forecast['baseline_index'], 1) . ')';
        $lines[] = 'Budget: ' . number_format($budget, 2) . ' → ' . number_format((float)$forecast['forecast_budget'], 2) . ' (' . $deltaLabel . ')';

        $this->sendReportMessage(implode("\n", $lines), ['parse_mode' => 'HTML']);
        if ($this->shouldLog('log_reports')) {
            Logger::infoContext('Forecast generated', $this->logContextForChat($chatId, [
                'month' => $currentMonthKey,
                'budget' => number_format($budget, 2, '.', ''),
            ]));
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

    private function isManager(int|string $userId): bool
    {
        if ($this->isOwner($userId)) {
            return true;
        }
        $managers = $this->config['manager_user_ids'] ?? [];
        if (!is_array($managers)) {
            return false;
        }
        $userId = (int)$userId;
        foreach ($managers as $manager) {
            if ((int)$manager === $userId) {
                return true;
            }
        }
        return false;
    }

    private function isWhitelisted(int|string $chatId): bool
    {
        $security = $this->config['security'] ?? [];
        $whitelist = $security['whitelist_chat_ids'] ?? [];
        if (!is_array($whitelist) || empty($whitelist)) {
            return true;
        }
        $chatId = (int)$chatId;
        foreach ($whitelist as $id) {
            if ((int)$id === $chatId) {
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

        $chatType = $message['chat']['type'] ?? '';
        if (in_array($chatType, ['group', 'supergroup'], true)) {
            $this->ensureMembershipOpen($chatId, $userId, (int)$message['date']);
        }
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

    private function ensureMembershipOpen(int|string $chatId, int|string $userId, int $timestamp): void
    {
        $existing = $this->db->fetch(
            'SELECT id FROM memberships WHERE chat_id = ? AND user_id = ? AND left_at IS NULL ORDER BY joined_at DESC LIMIT 1',
            [$chatId, $userId]
        );
        if ($existing) {
            return;
        }
        $this->recordMembershipJoin($chatId, $userId, $timestamp);
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
                'Reports are delivered to the reports channel + manager DMs.',
                'The bot stays silent in groups and does not DM mods.',
                'Tip: set a default chat with /usechat &lt;chat_id&gt; to skip chat ids.',
                '/mychats - list your group chats',
                '/linkedchat (show default linked group)',
                '/whoami (your bot role + id)',
                '/botusers [days] [limit]',
                '/usechat &lt;chat_id&gt; | /usechat &lt;title&gt; | /usechat off',
                '/guide (full usage guide with examples)',
                '/mydashboard [YYYY-MM]',
                '/goalset &lt;metric&gt; &lt;value&gt; [YYYY-MM]',
                '/goalstatus [YYYY-MM]',
                '/goalclear [YYYY-MM]',
                '/stats [chat_id] [YYYY-MM] [@user]',
                '/finduser &lt;name|@username|user_id&gt; [chat_id]',
                '/timesheet &lt;@username|user_id&gt; [YYYY-MM-DD] [YYYY-MM-DD] [chat_id]',
                '/compare &lt;@user1|id1&gt; &lt;@user2|id2&gt; [YYYY-MM] [chat_id]',
                '/weeklysummary [chat_id] [days]',
                '/autospike on [hour] [threshold%] [chat_id]',
                '/leaderboard [chat_id] [YYYY-MM] [budget]',
                '/report [chat_id] [YYYY-MM] [budget]',
                '/reportcsv [chat_id] [YYYY-MM] [budget]',
                '/exportgsheet [chat_id] [YYYY-MM] [budget]',
                '/summary [YYYY-MM] [budget]',
                '/forecast [budget]',
                '/plan',
                '/setplan &lt;free|premium|enterprise&gt; [days] (owner only)',
                '/giftplan &lt;chat_id&gt; &lt;free|premium|enterprise&gt; [days] [note] (manager/owner)',
                '/grantplan &lt;chat_id&gt; &lt;free|premium|enterprise&gt; [days] [note] (manager/owner)',
                '/approval on|off &lt;chat_id&gt; (manager/owner)',
                '/approvereport &lt;chat_id&gt; [YYYY-MM] (manager/owner)',
                '/approvalstatus &lt;chat_id&gt; [YYYY-MM] (manager/owner)',
                '/auditlogcsv [chat_id] [limit] (manager/owner)',
                '/coach [YYYY-MM]',
                '/health [YYYY-MM]',
                '/trend [YYYY-MM] [budget]',
                '/execsummary [YYYY-MM] [budget]',
                '/archive',
                '/premium (view premium benefits)',
                '/pricing (tiers + features)',
                '/buy_stars_test &lt;amount&gt; [chat_id] (manager/owner)',
                '/buy_crypto_test &lt;amount&gt; [chat_id] (manager/owner)',
                '/paystatus (latest payment)',
                '/setbudget &lt;amount&gt; [chat_id]',
                '/settimezone &lt;Region/City&gt; [chat_id]',
                '/setactivity &lt;gap_minutes&gt; &lt;floor_minutes&gt; [chat_id]',
                '/autoreport on [day] [hour] [chat_id]',
                '/autoprogress on [day] [hour] [chat_id]',
                '/autoweekly on [weekday] [hour] [chat_id]',
                '/autoinactive on [days] [hour] [chat_id]',
                '/autoaireview on [day] [hour] [chat_id]',
                '/autoretention on [day] [hour] [threshold%] [chat_id]',
                '/autofeedback on [hour] [chat_id]',
                '/activityrank [chat_id] [YYYY-MM] [top]',
                '/aireview [chat_id] [YYYY-MM]',
                '/retention [chat_id] [YYYY-MM] [threshold%]',
                '/progress [chat_id] [budget]',
                '/modadd [chat_id] &lt;@username|user_id&gt;',
                '/modremove [chat_id] &lt;@username|user_id&gt;',
                '/modlist [chat_id]',
                '/modaudit [chat_id] [limit]',
                '/debughours [chat_id] [YYYY-MM] [@user]',
                '/debughoursall [chat_id] [YYYY-MM]',
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
            'Reports are sent to the <b>reports channel</b> and to managers.',
            'The bot stays silent in groups and does not DM mods.',
            '',
            '<b>Step 1: Add bot to groups</b>',
            '1) Add bot to the group',
            '2) Make it admin or disable privacy mode in BotFather',
            '3) Send any message in the group',
            '',
            '<b>Step 2: Pick a default chat</b>',
            '<code>/mychats</code>',
            '<code>/usechat &lt;chat_id&gt;</code>',
            '<code>/linkedchat</code>',
            '<code>/whoami</code>',
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
            '<code>/finduser alex</code>',
            '<code>/finduser @alex</code>',
            '<code>/timesheet @alex 2026-02-01 2026-02-28</code>',
            '<code>/compare @alex @maria 2026-02</code>',
            '<code>/activityrank 2026-02 5</code>',
            '<code>/aireview 2026-02</code>',
            '<code>/retention 2026-02 30%</code>',
            '<code>/weeklysummary 7</code>',
            'Weekly summary DMs include a one-line TL;DR with quick summary + top risk + top reward.',
            'Weekly summary includes performance badges (Top Helper, Most Balanced, Consistency King, Fast Responder).',
            '',
            '<b>Personal dashboard + goals</b>',
            '<code>/mydashboard</code>',
            '<code>/goalset messages 500</code>',
            '<code>/goalset active_hours 12</code>',
            '<code>/goalstatus</code>',
            '<code>/goalclear</code>',
            '',
            '<b>Leaderboards</b>',
            '<code>/leaderboard</code>',
            '<code>/leaderboard 2026-02</code>',
            '',
            '<b>Hours (Active + Presence)</b>',
            'Active hours = message-based activity sessions',
            'Presence hours = membership time in the chat',
            '<code>/debughours</code>',
            '<code>/debughours 2026-02 @alex</code>',
            '<code>/debughoursall 2026-02</code>',
            '',
            '<b>Reward sheets</b>',
            '<code>/report 2026-02 5000</code>',
            '<code>/reportcsv 2026-02 5000</code>',
            'Includes performance badges (Top Helper, Most Balanced, Consistency King, Fast Responder).',
            '',
            '<b>Mid-month progress</b>',
            '<code>/progress</code>',
            '<code>/progress 7500</code>',
            '<code>/forecast</code> (reward forecast for current month)',
            '',
            '<b>Multi-chat summary</b>',
            '<code>/summary 2026-02 12000</code>',
            '',
            '<b>Audit</b>',
            '<code>/modaudit 25</code>',
        ]);

        $parts[] = implode("\n", [
            '<b>Usage Guide (3/3) – Automation + Extras</b>',
            '<b>Automation</b>',
            '<code>/autoreport on 1 9</code>',
            '<code>/autoprogress on 15 12</code>',
            '<code>/autoweekly on 1 10</code> (Mon 10:00)',
            '<code>/autoinactive on 7 10</code> (inactive 7d, 10:00)',
            '<code>/autoaireview on 1 9</code> (monthly AI review)',
            '<code>/autoretention on 2 10 30%</code> (retention drop alerts)',
            '<code>/autospike on 10 35%</code> (inactivity spike alerts)',
            '<code>/autofeedback on 20</code> (daily micro-feedback DMs)',
            '',
            '<b>Report delivery</b>',
            'Set <code>reports.channel_id</code> in config.local.php to your reports-only channel.',
            'Reports go to that channel + manager DMs. Mod DMs are disabled by default.',
            '',
            '<b>Admin audit</b>',
            '<code>/botusers 30 20</code> (users who ran commands)',
            '',
            '<b>Approvals + audit log</b>',
            '<code>/approval on</code>',
            '<code>/approvereport 2026-02</code>',
            '<code>/approvalstatus 2026-02</code>',
            '<code>/auditlogcsv 200</code>',
            'Score audits: every reward calculation is logged as <code>score_calc</code>.',
            '',
            '<b>Test payments (Stars + Crypto)</b>',
            '<code>/buy_stars_test 500</code>',
            '<code>/buy_crypto_test 25</code>',
            '<code>/paystatus</code>',
            'Stars sandbox requires telegram.test_environment=true + a test bot token.',
            '',
            '<b>Premium insights</b>',
            '<code>/coach 2026-02</code>',
            '<code>/health 2026-02</code>',
            '<code>/trend 2026-02 5000</code>',
            '<code>/execsummary 2026-02 5000</code>',
            '',
            '<b>Dashboard</b>',
            'Open in browser:',
            '<code>https://YOUR_DOMAIN/?token=YOUR_TOKEN</code>',
            '<code>http://127.0.0.1:8000/dashboard.php?token=YOUR_TOKEN</code>',
            '<code>http://127.0.0.1:8000/manager-digest.php?token=YOUR_TOKEN</code> (manager digest)',
            '<code>http://127.0.0.1:8000/manager-digest.php?token=YOUR_TOKEN&amp;format=pdf</code> (digest PDF)',
            'Tip: add <code>&amp;chat_id=...&amp;month=YYYY-MM&amp;budget=5000</code>',
            'Dashboard highlights show badges (Top Helper, Most Balanced, Consistency King, Fast Responder).',
            'Planning cards: Reward Forecast (MTD pace), Budget Optimizer, Bonus Split Planner.',
            'Risk signals: Conflict/Spam risk + Burnout risk.',
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

    private function buildActivityIndex(array $summary): float
    {
        $messages = (float)($summary['messages'] ?? 0);
        $actions = (float)($summary['warnings'] ?? 0) + (float)($summary['mutes'] ?? 0) + (float)($summary['bans'] ?? 0);
        $activeHours = ((float)($summary['active_minutes'] ?? 0)) / 60;

        $weights = $this->config['forecast_weights'] ?? [];
        $defaults = [
            'messages' => 1.0,
            'actions' => 3.0,
            'active_hours' => 0.5,
        ];
        $weights = array_merge($defaults, is_array($weights) ? $weights : []);

        return ($messages * (float)$weights['messages'])
            + ($actions * (float)$weights['actions'])
            + ($activeHours * (float)$weights['active_hours']);
    }

    private function buildRewardForecast(array $mtdBundle, array $lastBundle, float $budget): array
    {
        $mtdSummary = $mtdBundle['summary'] ?? [];
        $lastSummary = $lastBundle['summary'] ?? [];

        $currentIndex = $this->buildActivityIndex($mtdSummary);
        $baselineIndex = $this->buildActivityIndex($lastSummary);

        $range = $mtdBundle['range'] ?? [];
        $startLocal = $range['start_local'] ?? null;
        $endLocal = $range['end_local'] ?? null;
        if (!$startLocal instanceof DateTimeImmutable || !$endLocal instanceof DateTimeImmutable) {
            return [
                'status' => 'invalid_range',
            ];
        }

        $daysElapsed = max(1, (int)$startLocal->diff($endLocal)->days + 1);
        $daysTotal = max(1, (int)$startLocal->diff($startLocal->modify('first day of next month'))->days);
        $paceFactor = $daysTotal / $daysElapsed;

        $projectedMessages = (float)($mtdSummary['messages'] ?? 0) * $paceFactor;
        $projectedActions = ((float)($mtdSummary['warnings'] ?? 0) + (float)($mtdSummary['mutes'] ?? 0) + (float)($mtdSummary['bans'] ?? 0)) * $paceFactor;
        $projectedActiveHours = (((float)($mtdSummary['active_minutes'] ?? 0)) / 60) * $paceFactor;
        $projectedIndex = $currentIndex * $paceFactor;

        $baselineIndex = $baselineIndex > 0 ? $baselineIndex : ($currentIndex > 0 ? $currentIndex : 1.0);
        $forecastBudget = $budget > 0 ? $budget * ($projectedIndex / $baselineIndex) : 0.0;
        $deltaPct = $budget > 0 ? (($forecastBudget - $budget) / $budget) * 100 : 0.0;

        return [
            'status' => 'ok',
            'days_elapsed' => $daysElapsed,
            'days_total' => $daysTotal,
            'pace_factor' => $paceFactor,
            'projected_messages' => $projectedMessages,
            'projected_actions' => $projectedActions,
            'projected_active_hours' => $projectedActiveHours,
            'projected_index' => $projectedIndex,
            'baseline_index' => $baselineIndex,
            'forecast_budget' => $forecastBudget,
            'delta_pct' => $deltaPct,
        ];
    }

    private function findChatUser(int|string $chatId, ?int $userId, ?string $username): ?array
    {
        if ($userId !== null) {
            $row = $this->db->fetch(
                'SELECT u.id, u.username, u.first_name, u.last_name, cm.is_mod
                 FROM chat_members cm
                 JOIN users u ON u.id = cm.user_id
                 WHERE cm.chat_id = ? AND u.id = ? LIMIT 1',
                [$chatId, (int)$userId]
            );
            if ($row) {
                return $row;
            }
        }
        if ($username !== null && $username !== '') {
            $name = ltrim($username, '@');
            $row = $this->db->fetch(
                'SELECT u.id, u.username, u.first_name, u.last_name, cm.is_mod
                 FROM chat_members cm
                 JOIN users u ON u.id = cm.user_id
                 WHERE cm.chat_id = ? AND LOWER(u.username) = LOWER(?) LIMIT 1',
                [$chatId, $name]
            );
            if ($row) {
                return $row;
            }
        }
        return null;
    }

    private function findModInStats(array $mods, ?int $userId, ?string $username): ?array
    {
        foreach ($mods as $mod) {
            if ($userId !== null && (int)$mod['user_id'] === (int)$userId) {
                return $mod;
            }
            if ($username !== null && !empty($mod['username']) && strcasecmp($mod['username'], ltrim($username, '@')) === 0) {
                return $mod;
            }
        }
        return null;
    }

    private function parsePercent(string $token): ?float
    {
        $value = rtrim(trim($token), '%');
        if ($value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (float)$value;
    }

    private function formatStatsMessage(array $stat, array $range): string
    {
        $name = $stat['display_name'];
        $lines = [];
        $lines[] = '<b>Stats for ' . $this->escape($name) . '</b>';
        $lines[] = 'Month: ' . $range['label'];
        $lines[] = 'Score: ' . number_format($stat['score'], 2);
        $lines[] = 'Impact score: ' . number_format((float)($stat['impact_score'] ?? 0), 2);
        $lines[] = 'Consistency: ' . number_format((float)($stat['consistency_index'] ?? 0), 1) . '%';
        $lines[] = 'Messages: ' . $stat['messages'];
        $lines[] = 'Warnings issued: ' . $stat['warnings'];
        $lines[] = 'Mutes issued: ' . $stat['mutes'];
        $lines[] = 'Bans issued: ' . $stat['bans'];
        $activeMinutes = (float)($stat['active_minutes'] ?? 0);
        $membershipMinutes = (float)($stat['membership_minutes'] ?? 0);
        $lines[] = 'Active hours: ' . number_format($activeMinutes / 60, 2) . 'h (' . number_format($activeMinutes, 1) . ' min)';
        $lines[] = 'Presence hours: ' . number_format($membershipMinutes / 60, 2) . 'h (' . number_format($membershipMinutes, 1) . ' min)';
        $lines[] = 'Days active: ' . $stat['days_active'];
        $lines[] = 'Peak hour: ' . $stat['peak_hour'];
        if ($stat['improvement'] !== null) {
            $lines[] = 'Improvement vs last month: ' . number_format($stat['improvement'], 1) . '%';
        }
        if (isset($stat['trend_3m']) && $stat['trend_3m'] !== null) {
            $lines[] = '3-month trend: ' . ($stat['trend_3m'] >= 0 ? '+' : '') . number_format($stat['trend_3m'], 1) . '%';
        }
        return implode("\n", $lines);
    }

    private function formatLeaderboardMessage(array $ranked, array $range, float $budget): string
    {
        $lines = [];
        $lines[] = '<b>Leaderboard - ' . $range['label'] . '</b>';
        $lines[] = 'Budget: ' . number_format($budget, 2);
        $lines[] = '';

        $rankBy = $this->config['reward']['rank_by'] ?? 'score';
        $rank = 1;
        foreach ($ranked as $mod) {
            $line = $rank . '. ' . $this->escape($mod['display_name']);
            if ($rankBy === 'reward_score' && isset($mod['reward_score'])) {
                $line .= ' | Reward Score ' . number_format((float)$mod['reward_score'], 3);
            }
            $line .= ' | Score ' . number_format($mod['score'], 2) .
                ' | Reward ' . number_format($mod['reward'], 2);
            if (!empty($mod['bonus'])) {
                $line .= ' | Bonus ' . number_format((float)$mod['bonus'], 2);
            }
            $lines[] = $line;
            $rank++;
        }

        return implode("\n", $lines);
    }

    private function logContextFromMessage(int|string $chatId, array $message, array $extra = []): array
    {
        $chat = $message['chat'] ?? [];
        $from = $message['from'] ?? [];
        $chatTitle = $chat['title'] ?? ($chat['username'] ?? null);
        if (!$chatTitle) {
            $chatTitle = trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
        }
        $userName = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        $context = [
            'chat_id' => $chatId,
            'chat_title' => $chatTitle ?: null,
            'chat_type' => $chat['type'] ?? null,
            'user_id' => $from['id'] ?? null,
            'username' => $from['username'] ?? null,
            'user_name' => $userName !== '' ? $userName : null,
            'message_id' => $message['message_id'] ?? null,
        ];
        return array_merge($context, $extra);
    }

    private function logContextForChat(int|string $chatId, array $extra = []): array
    {
        return array_merge($this->getChatMeta($chatId), $extra);
    }

    private function logContextForChatUser(int|string $chatId, int|string $userId, array $extra = []): array
    {
        return array_merge($this->getChatMeta($chatId), $this->getUserMeta($userId), $extra);
    }

    private function logContextForUser(int|string $userId, array $extra = []): array
    {
        return array_merge($this->getUserMeta($userId), $extra);
    }

    private function getChatMeta(int|string $chatId): array
    {
        static $cache = [];
        $key = (string)$chatId;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $row = $this->db->fetch('SELECT id, title, type FROM chats WHERE id = ? LIMIT 1', [$chatId]);
        $cache[$key] = [
            'chat_id' => $chatId,
            'chat_title' => $row['title'] ?? null,
            'chat_type' => $row['type'] ?? null,
        ];
        return $cache[$key];
    }

    private function getUserMeta(int|string $userId): array
    {
        static $cache = [];
        $key = (string)$userId;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $row = $this->db->fetch('SELECT id, username, first_name, last_name FROM users WHERE id = ? LIMIT 1', [$userId]);
        $name = '';
        if ($row) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        }
        $cache[$key] = [
            'user_id' => $row['id'] ?? $userId,
            'username' => $row['username'] ?? null,
            'user_name' => $name !== '' ? $name : null,
        ];
        return $cache[$key];
    }

    private function logCommandAudit(array $message, array $parsed, string $chatType): void
    {
        $userId = $message['from']['id'] ?? null;
        if (!$userId) {
            return;
        }
        if ($chatType !== 'private') {
            return;
        }
        $chatId = $message['chat']['id'] ?? null;
        $command = $parsed['command'] ?? '';
        if ($command === '') {
            return;
        }
        $this->audit->log('command_use', $userId, $chatId ? (int)$chatId : null, [
            'command' => $command,
        ]);
        if ($command === 'start') {
            $this->audit->log('start', $userId, $chatId ? (int)$chatId : null, []);
        }
    }

    private function formatTimestamp(string $utcTimestamp, string $timezone): string
    {
        try {
            $dt = new DateTimeImmutable($utcTimestamp, new DateTimeZone('UTC'));
            $local = $dt->setTimezone(new DateTimeZone($timezone));
            return $local->format('Y-m-d H:i');
        } catch (Throwable $e) {
            return $utcTimestamp;
        }
    }

    private function formatUserLabel(array $user): string
    {
        if (!empty($user['username'])) {
            return '@' . $user['username'];
        }
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        if (!empty($user['id'])) {
            return 'User ' . $user['id'];
        }
        return 'Unknown';
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

    private function sendReportMessage(string $text, array $options = [], int $limit = 3500): void
    {
        $this->reporter->sendMessage($text, $options, $limit);
    }

    private function sendReportDocument(string $filePath, string $caption = '', array $options = []): void
    {
        $this->reporter->sendDocument($filePath, $caption, $options);
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
