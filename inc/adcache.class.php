<?php
/**
 * inc/adcache.class.php — AD 数据库缓存层
 *
 * 设计：
 *   - AD 全量数据持久化到 DB，所有搜索从 DB 走，不实时连 AD
 *   - 手动触发 or 定时自动同步（可配置间隔，默认 6 小时）
 *   - 同步是增量 upsert，按 dn 去重，不整表删除
 *   - 同步过程加 DB 锁防并发
 */
if (!defined('GLPI_ROOT')) { die('禁止直接访问'); }

class PluginAdmanagerAdCache
{
    const TABLE      = 'glpi_plugin_admanager_ad_cache';
    const LOG_TABLE  = 'glpi_plugin_admanager_ad_sync_log';
    const LOCK_KEY   = 'plugin_admanager_ad_sync_lock';

    // 默认自动同步间隔（秒）
    const DEFAULT_AUTO_SYNC_INTERVAL = 21600; // 6 小时

    // ── 查询接口（供 aduser.php / computer_query.php / dashboard 用）────────

    public static function searchUsers(string $keyword = '', string $ou = ''): array {
        global $DB;
        $where = ['cache_type' => 'user'];
        if ($keyword) {
            $esc = $DB->escape($keyword);
            $where[] = new \QueryExpression(
                "(sam LIKE '%{$esc}%' OR display_name LIKE '%{$esc}%' OR mail LIKE '%{$esc}%' OR department LIKE '%{$esc}%')"
            );
        }
        if ($ou) {
            $esc = $DB->escape($ou);
            $where[] = new \QueryExpression("dn LIKE '%{$esc}%'");
        }
        $rows = $DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'LIMIT' => 2000]);
        return array_map(fn($r) => json_decode($r['raw_json'], true) ?: [], iterator_to_array($rows));
    }

    public static function searchComputers(string $keyword = '', string $ou = ''): array {
        global $DB;
        $where = ['cache_type' => 'computer'];
        if ($keyword) {
            $esc = $DB->escape($keyword);
            $where[] = new \QueryExpression(
                "(sam LIKE '%{$esc}%' OR dns_hostname LIKE '%{$esc}%' OR os LIKE '%{$esc}%')"
            );
        }
        if ($ou) {
            $esc = $DB->escape($ou);
            $where[] = new \QueryExpression("dn LIKE '%{$esc}%'");
        }
        $rows = $DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'LIMIT' => 3000]);
        return array_map(fn($r) => json_decode($r['raw_json'], true) ?: [], iterator_to_array($rows));
    }

    public static function getUserCount(): int {
        global $DB;
        $r = $DB->request(['COUNT' => 'id', 'FROM' => self::TABLE, 'WHERE' => ['cache_type' => 'user']]);
        return (int)(array_values((array)$r->current())[0] ?? 0);
    }

    public static function getComputerCount(): int {
        global $DB;
        $r = $DB->request(['COUNT' => 'id', 'FROM' => self::TABLE, 'WHERE' => ['cache_type' => 'computer']]);
        return (int)(array_values((array)$r->current())[0] ?? 0);
    }

    // ── 缓存状态 ──────────────────────────────────────────────────────────────

    public static function getLastSyncInfo(): array {
        global $DB;
        $r = $DB->request([
            'FROM'    => self::LOG_TABLE,
            'WHERE'   => ['status' => 'ok'],
            'ORDER'   => ['synced_at DESC'],
            'LIMIT'   => 1,
        ]);
        $row = $r->current();
        if (!$row) return ['synced_at' => null, 'total' => 0, 'triggered_by' => ''];
        return [
            'synced_at'    => $row['synced_at'],
            'total'        => $row['total_count'],
            'triggered_by' => $row['triggered_by'],
            'duration_sec' => $row['duration_sec'],
            'sync_type'    => $row['sync_type'],
        ];
    }

    public static function isSyncing(): bool {
        global $DB;
        $r = $DB->request([
            'FROM'  => 'glpi_configs',
            'WHERE' => ['context' => 'plugin:admanager', 'name' => self::LOCK_KEY],
            'LIMIT' => 1,
        ]);
        $row = $r->current();
        if (!$row) return false;
        // 锁超过 30 分钟视为僵尸，自动释放
        return (time() - (int)$row['value']) < 1800;
    }

    public static function needsAutoSync(): bool {
        $info = self::getLastSyncInfo();
        if (!$info['synced_at']) return true;
        $interval = (int)(PluginAdmanagerConfig::get('ad_sync_interval') ?: self::DEFAULT_AUTO_SYNC_INTERVAL);
        return (time() - strtotime($info['synced_at'])) > $interval;
    }

    // ── 同步核心 ──────────────────────────────────────────────────────────────

    /**
     * 执行全量同步（用户 + 计算机）
     * $triggeredBy: 'manual'|'auto'|'cron'
     */
    public static function syncAll(string $triggeredBy = 'manual'): array {
        if (self::isSyncing()) {
            return ['ok' => false, 'message' => '同步正在进行中，请稍候'];
        }

        // 加锁
        Config::setConfigurationValues('plugin:admanager', [self::LOCK_KEY => (string)time()]);
        $t0 = microtime(true);

        try {
            $ldap = PluginAdmanagerAdLdap::getInstance(true);
            $ldap->connect();

            // 同步用户
            $users = $ldap->searchUsersInConfiguredOUs('');
            self::upsertBatch('user', $users);
            // 删除 AD 已不存在的（以 dn 为准）
            self::pruneDeleted('user', array_column($users, 'dn'));

            // 同步计算机
            $computers = $ldap->searchComputersInConfiguredOUs('');
            self::upsertBatch('computer', $computers);
            self::pruneDeleted('computer', array_column($computers, 'dn'));

            $ldap->disconnect();

            $duration = round(microtime(true) - $t0, 1);
            $total    = count($users) + count($computers);

            // 写同步日志
            self::writeLog('full', $total, $duration, $triggeredBy, 'ok');

            // 释放锁
            Config::setConfigurationValues('plugin:admanager', [self::LOCK_KEY => '0']);

            return [
                'ok'           => true,
                'message'      => "同步完成：用户 " . count($users) . " 个，计算机 " . count($computers) . " 台，耗时 {$duration}s",
                'user_count'   => count($users),
                'computer_count'=> count($computers),
                'duration_sec' => $duration,
            ];
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $t0, 1);
            self::writeLog('full', 0, $duration, $triggeredBy, 'error', $e->getMessage());
            Config::setConfigurationValues('plugin:admanager', [self::LOCK_KEY => '0']);
            return ['ok' => false, 'message' => '同步失败：' . $e->getMessage()];
        }
    }

    // ── 单条缓存刷新（AD 写操作后调用）────────────────────────────────────

    public static function refreshUserByDn(string $dn): void {
        $ldap = PluginAdmanagerAdLdap::getInstance();
        $user = $ldap->getUserDetail($dn);
        if ($user) {
            self::upsertBatch('user', [$user]);
        }
    }

    // ── 私有辅助 ──────────────────────────────────────────────────────────────

    private static function upsertBatch(string $type, array $items): void {
        global $DB;
        $now = date('Y-m-d H:i:s');
        foreach ($items as $item) {
            $dn = $item['dn'] ?? $item['distinguishedname'] ?? '';
            if (!$dn) continue;

            $row = [
                'cache_type'     => $type,
                'sam'            => $item['samaccountname'] ?? $item['name'] ?? '',
                'dn'             => $dn,
                'display_name'   => $item['displayname']    ?? $item['name'] ?? '',
                'department'     => $item['department']     ?? '',
                'mail'           => $item['mail']           ?? '',
                'title'          => $item['title']          ?? '',
                'is_disabled'    => (int)($item['is_disabled'] ?? 0),
                'is_locked'      => (int)($item['is_locked']   ?? 0),
                'last_logon_unix'=> (int)($item['last_logon_unix'] ?? 0),
                'os'             => $item['operatingsystem'] ?? '',
                'dns_hostname'   => $item['dnshostname']    ?? '',
                'raw_json'       => json_encode($item, JSON_UNESCAPED_UNICODE),
                'synced_at'      => $now,
            ];

            // ON DUPLICATE KEY UPDATE（用 dn 唯一索引）
            $existing = $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['cache_type' => $type, 'dn' => $dn],
                'LIMIT' => 1,
            ])->current();

            if ($existing) {
                $DB->update(self::TABLE, $row, ['id' => $existing['id']]);
            } else {
                $DB->insert(self::TABLE, $row);
            }
        }
    }

    private static function pruneDeleted(string $type, array $activeDns): void {
        global $DB;
        if (empty($activeDns)) return;
        // 找 DB 里有但 AD 里没有的（已删除）
        $all = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['cache_type' => $type], 'FIELDS' => ['id','dn']]);
        $toDelete = [];
        foreach ($all as $row) {
            if (!in_array($row['dn'], $activeDns)) $toDelete[] = $row['id'];
        }
        if ($toDelete) {
            $DB->delete(self::TABLE, ['id' => $toDelete]);
        }
    }

    private static function writeLog(string $type, int $total, float $duration,
                                      string $by, string $status, string $err = ''): void {
        global $DB;
        // error_msg 用 addslashes 处理单引号，防止 SQL 注入
        $errSafe = addslashes(mb_substr($err, 0, 500));
        $bySafe  = addslashes(mb_substr($by, 0, 128));
        $DB->query(
            "INSERT INTO `" . self::LOG_TABLE . "`
             (`sync_type`,`total_count`,`duration_sec`,`triggered_by`,`synced_at`,`status`,`error_msg`)
             VALUES ('{$type}', {$total}, {$duration}, '{$bySafe}', '" . date('Y-m-d H:i:s') . "', '{$status}', '{$errSafe}')"
        );
    }
}

