<?php
/**
 * 17 物品管理系统 (17 Item Manager)
 * 参考 Snipe-IT / Homebox / Grocy 设计
 * 单文件 PHP 应用，SQLite 数据库，零配置部署
 * Version: 1.0.0
 */

// ============================================================
// 🔧 配置与初始化
// ============================================================
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

define('DB_PATH', __DIR__ . '/data/items_db.sqlite');
define('AUTH_DB_PATH', __DIR__ . '/data/auth_db.sqlite');
define('UPLOAD_DIR', __DIR__ . '/data/uploads/');
define('TRASH_DIR', __DIR__ . '/data/uploads/trash/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOW_PUBLIC_REGISTRATION', true);
define('DEFAULT_ADMIN_USERNAME', 'admin');
define('DEFAULT_ADMIN_PASSWORD', 'Admin@123456');
define('DEFAULT_DEMO_USERNAME', 'test');
define('DEFAULT_DEMO_PASSWORD', 'test123456');
define('SECURITY_QUESTIONS', [
    'birth_city' => '你出生的城市是？',
    'primary_school' => '你小学的名字是？',
    'first_pet' => '你的第一只宠物名字是？',
    'favorite_teacher' => '你最喜欢的老师姓名是？',
    'favorite_food' => '你最喜欢的食物是？'
]);

// 确保数据目录存在
if (!is_dir(__DIR__ . '/data'))
    mkdir(__DIR__ . '/data', 0755, true);
if (!is_dir(UPLOAD_DIR))
    mkdir(UPLOAD_DIR, 0755, true);
if (!is_dir(TRASH_DIR))
    mkdir(TRASH_DIR, 0755, true);

// ============================================================
// 🗄️ 数据库初始化
// ============================================================
function getDB()
{
    return getUserDB(getCurrentUserId());
}

function getCurrentUserId()
{
    return intval($_SESSION['user_id'] ?? 0);
}

function getUserDbPath($userId)
{
    $uid = intval($userId);
    if ($uid <= 1) {
        return DB_PATH;
    }
    return __DIR__ . '/data/items_db_u' . $uid . '.sqlite';
}

function getUserDB($userId)
{
    static $dbPool = [];
    $uid = intval($userId);
    if ($uid <= 0) {
        throw new Exception('未登录用户无法访问数据');
    }
    $path = getUserDbPath($uid);
    if (!isset($dbPool[$path])) {
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("PRAGMA journal_mode=WAL");
        $db->exec("PRAGMA foreign_keys=ON");
        initSchema($db);
        $dbPool[$path] = $db;
    }
    return $dbPool[$path];
}

function getAuthDB()
{
    static $authDb = null;
    if ($authDb === null) {
        $authDb = new PDO('sqlite:' . AUTH_DB_PATH);
        $authDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $authDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $authDb->exec("PRAGMA journal_mode=WAL");
        $authDb->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            display_name TEXT DEFAULT '',
            role TEXT DEFAULT 'user',
            security_question_key TEXT DEFAULT '',
            security_question_label TEXT DEFAULT '',
            security_answer_hash TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login_at DATETIME DEFAULT NULL
        )");
        $authDb->exec("CREATE TABLE IF NOT EXISTS public_shared_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_user_id INTEGER NOT NULL,
            owner_item_id INTEGER NOT NULL,
            item_name TEXT NOT NULL,
            category_name TEXT DEFAULT '',
            purchase_price REAL DEFAULT 0,
            purchase_from TEXT DEFAULT '',
            recommend_reason TEXT DEFAULT '',
            owner_item_updated_at TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(owner_user_id, owner_item_id)
        )");
        $authDb->exec("CREATE INDEX IF NOT EXISTS idx_public_shared_items_updated_at ON public_shared_items(updated_at)");
        $authDb->exec("CREATE TABLE IF NOT EXISTS public_shared_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            shared_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $authDb->exec("CREATE INDEX IF NOT EXISTS idx_public_shared_comments_shared_id ON public_shared_comments(shared_id)");
        $authDb->exec("CREATE INDEX IF NOT EXISTS idx_public_shared_comments_created_at ON public_shared_comments(created_at)");
        $authDb->exec("CREATE TABLE IF NOT EXISTS message_board_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            is_demo_scope INTEGER DEFAULT 0,
            is_completed INTEGER DEFAULT 0,
            completed_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $authDb->exec("CREATE INDEX IF NOT EXISTS idx_message_board_posts_scope_created ON message_board_posts(is_demo_scope, created_at DESC)");
        $authDb->exec("CREATE INDEX IF NOT EXISTS idx_message_board_posts_user ON message_board_posts(user_id)");
        $authDb->exec("CREATE TABLE IF NOT EXISTS admin_operation_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_user_id INTEGER NOT NULL,
            actor_username TEXT DEFAULT '',
            actor_display_name TEXT DEFAULT '',
            actor_role TEXT DEFAULT 'user',
            action_key TEXT NOT NULL,
            action_label TEXT NOT NULL,
            api TEXT DEFAULT '',
            method TEXT DEFAULT 'POST',
            details TEXT DEFAULT '',
            ip TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $authDb->exec("CREATE INDEX IF NOT EXISTS idx_admin_operation_logs_created_at ON admin_operation_logs(created_at DESC)");
        $authDb->exec("CREATE INDEX IF NOT EXISTS idx_admin_operation_logs_actor ON admin_operation_logs(actor_user_id)");
        $authDb->exec("CREATE TABLE IF NOT EXISTS platform_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $initRegistrationStmt = $authDb->prepare("INSERT OR IGNORE INTO platform_settings (setting_key, setting_value, updated_at)
            VALUES ('allow_public_registration', ?, datetime('now','localtime'))");
        $initRegistrationStmt->execute([ALLOW_PUBLIC_REGISTRATION ? '1' : '0']);
        try {
            $authDb->exec("ALTER TABLE public_shared_items ADD COLUMN recommend_reason TEXT DEFAULT ''");
        } catch (Exception $e) {
        }
        try {
            $authDb->exec("ALTER TABLE users ADD COLUMN role TEXT DEFAULT 'user'");
        } catch (Exception $e) {
        }
        try {
            $authDb->exec("ALTER TABLE users ADD COLUMN security_question_key TEXT DEFAULT ''");
        } catch (Exception $e) {
        }
        try {
            $authDb->exec("ALTER TABLE users ADD COLUMN security_answer_hash TEXT DEFAULT ''");
        } catch (Exception $e) {
        }
        try {
            $authDb->exec("ALTER TABLE users ADD COLUMN security_question_label TEXT DEFAULT ''");
        } catch (Exception $e) {
        }
        try {
            $authDb->exec("ALTER TABLE message_board_posts ADD COLUMN is_completed INTEGER DEFAULT 0");
        } catch (Exception $e) {
        }
        try {
            $authDb->exec("ALTER TABLE message_board_posts ADD COLUMN completed_at DATETIME DEFAULT NULL");
        } catch (Exception $e) {
        }

        // 历史兼容：若存在用户名 admin 的用户，默认升级为管理员
        try {
            $upAdmin = $authDb->prepare("UPDATE users SET role='admin' WHERE lower(username)=?");
            $upAdmin->execute([strtolower(DEFAULT_ADMIN_USERNAME)]);
        } catch (Exception $e) {
        }

        // 保底创建默认管理员（仅当当前无管理员账号时）
        $adminCount = intval($authDb->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn());
        if ($adminCount <= 0) {
            $qKeys = array_keys(SECURITY_QUESTIONS);
            $defaultQuestionKey = count($qKeys) > 0 ? $qKeys[0] : '';
            $defaultQuestionLabel = ($defaultQuestionKey !== '' && isset(SECURITY_QUESTIONS[$defaultQuestionKey])) ? strval(SECURITY_QUESTIONS[$defaultQuestionKey]) : '';
            $defaultAnswerHash = $defaultQuestionKey !== '' ? password_hash(normalizeSecurityAnswer('admin'), PASSWORD_DEFAULT) : '';
            $insAdmin = $authDb->prepare("INSERT INTO users (username, password_hash, display_name, role, security_question_key, security_question_label, security_answer_hash, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,datetime('now','localtime'),datetime('now','localtime'))");
            $insAdmin->execute([
                strtolower(DEFAULT_ADMIN_USERNAME),
                password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT),
                '系统管理员',
                'admin',
                $defaultQuestionKey,
                $defaultQuestionLabel,
                $defaultAnswerHash
            ]);
        }
    }
    return $authDb;
}

function getCurrentAuthUser($authDb)
{
    $uid = getCurrentUserId();
    if ($uid <= 0) {
        return null;
    }
    $stmt = $authDb->prepare("SELECT id, username, display_name, role, security_question_key, created_at, last_login_at FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function isAdminUser($user)
{
    return is_array($user) && (($user['role'] ?? 'user') === 'admin');
}

function normalizeSecurityAnswer($answer)
{
    $v = trim((string) $answer);
    $v = preg_replace('/\s+/u', '', $v);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($v, 'UTF-8');
    }
    return strtolower($v);
}

function getSecurityQuestions()
{
    return SECURITY_QUESTIONS;
}

function getPlatformSetting($authDb, $key, $defaultValue = '')
{
    if (!$authDb instanceof PDO) {
        return $defaultValue;
    }
    $k = trim((string) $key);
    if ($k === '') {
        return $defaultValue;
    }
    try {
        $stmt = $authDb->prepare("SELECT setting_value FROM platform_settings WHERE setting_key=? LIMIT 1");
        $stmt->execute([$k]);
        $row = $stmt->fetch();
        if (!$row) {
            return $defaultValue;
        }
        return strval($row['setting_value'] ?? $defaultValue);
    } catch (Exception $e) {
        return $defaultValue;
    }
}

function setPlatformSetting($authDb, $key, $value)
{
    if (!$authDb instanceof PDO) {
        return false;
    }
    $k = trim((string) $key);
    if ($k === '') {
        return false;
    }
    try {
        $stmt = $authDb->prepare("INSERT INTO platform_settings (setting_key, setting_value, updated_at)
            VALUES (?,?,datetime('now','localtime'))
            ON CONFLICT(setting_key) DO UPDATE SET
                setting_value=excluded.setting_value,
                updated_at=datetime('now','localtime')");
        return $stmt->execute([$k, strval($value)]);
    } catch (Exception $e) {
        return false;
    }
}

function isPublicRegistrationEnabled($authDb)
{
    $raw = strtolower(trim((string) getPlatformSetting($authDb, 'allow_public_registration', ALLOW_PUBLIC_REGISTRATION ? '1' : '0')));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function isDemoUsername($username)
{
    $u = strtolower(trim((string) $username));
    if ($u === '') {
        return false;
    }
    if ($u === strtolower(DEFAULT_DEMO_USERNAME)) {
        return true;
    }
    return preg_match('/^demo_peer_\d+_channel$/', $u) === 1;
}

function isDemoUser($user)
{
    return is_array($user) && isDemoUsername($user['username'] ?? '');
}

function getUserItemStats($userId)
{
    $uid = intval($userId);
    if ($uid <= 0) {
        return ['item_kinds' => 0, 'item_qty' => 0, 'last_item_at' => null];
    }
    try {
        $db = getUserDB($uid);
        $kinds = intval($db->query("SELECT COUNT(*) FROM items WHERE deleted_at IS NULL")->fetchColumn());
        $qty = intval($db->query("SELECT COALESCE(SUM(quantity),0) FROM items WHERE deleted_at IS NULL")->fetchColumn());
        $lastAt = $db->query("SELECT MAX(updated_at) FROM items WHERE deleted_at IS NULL")->fetchColumn();
        return ['item_kinds' => $kinds, 'item_qty' => $qty, 'last_item_at' => $lastAt ?: null];
    } catch (Exception $e) {
        return ['item_kinds' => 0, 'item_qty' => 0, 'last_item_at' => null];
    }
}

function getUserOperationLogCount($userId)
{
    $uid = intval($userId);
    if ($uid <= 0) {
        return 0;
    }
    try {
        $db = getUserDB($uid);
        return intval($db->query("SELECT COUNT(*) FROM operation_logs")->fetchColumn());
    } catch (Exception $e) {
        return 0;
    }
}

function getItemShareSnapshot($db, $itemId)
{
    $id = intval($itemId);
    if ($id <= 0) {
        return null;
    }
    $stmt = $db->prepare("SELECT
            i.id,
            i.category_id,
            i.name,
            i.is_public_shared,
            i.purchase_price,
            i.purchase_from,
            COALESCE(i.public_recommend_reason, '') AS recommend_reason,
            i.updated_at,
            COALESCE(c.name, '') AS category_name
        FROM items i
        LEFT JOIN categories c ON i.category_id=c.id
        WHERE i.id=? AND i.deleted_at IS NULL
        LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function upsertPublicSharedItem($authDb, $ownerUserId, $snapshot)
{
    if (!is_array($snapshot)) {
        return;
    }
    $stmt = $authDb->prepare("INSERT INTO public_shared_items
        (owner_user_id, owner_item_id, item_name, category_name, purchase_price, purchase_from, recommend_reason, owner_item_updated_at, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?, ?, datetime('now','localtime'), datetime('now','localtime'))
        ON CONFLICT(owner_user_id, owner_item_id) DO UPDATE SET
            item_name=excluded.item_name,
            category_name=excluded.category_name,
            purchase_price=excluded.purchase_price,
            purchase_from=excluded.purchase_from,
            recommend_reason=excluded.recommend_reason,
            owner_item_updated_at=excluded.owner_item_updated_at,
            updated_at=datetime('now','localtime')");
    $stmt->execute([
        intval($ownerUserId),
        intval($snapshot['id'] ?? 0),
        trim((string) ($snapshot['name'] ?? '')),
        trim((string) ($snapshot['category_name'] ?? '')),
        max(0, floatval($snapshot['purchase_price'] ?? 0)),
        trim((string) ($snapshot['purchase_from'] ?? '')),
        trim((string) ($snapshot['recommend_reason'] ?? '')),
        trim((string) ($snapshot['updated_at'] ?? ''))
    ]);
}

function removePublicSharedCommentsByShareIds($authDb, $shareIds = [])
{
    $ids = array_values(array_filter(array_map('intval', is_array($shareIds) ? $shareIds : []), function ($v) {
        return $v > 0;
    }));
    if (count($ids) === 0) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $authDb->prepare("DELETE FROM public_shared_comments WHERE shared_id IN ($placeholders)");
    $stmt->execute($ids);
}

function removePublicSharedItem($authDb, $ownerUserId, $ownerItemId)
{
    $uid = intval($ownerUserId);
    $itemId = intval($ownerItemId);
    if ($uid <= 0 || $itemId <= 0) {
        return;
    }
    $idStmt = $authDb->prepare("SELECT id FROM public_shared_items WHERE owner_user_id=? AND owner_item_id=?");
    $idStmt->execute([$uid, $itemId]);
    $shareIds = array_map('intval', $idStmt->fetchAll(PDO::FETCH_COLUMN));
    $stmt = $authDb->prepare("DELETE FROM public_shared_items WHERE owner_user_id=? AND owner_item_id=?");
    $stmt->execute([$uid, $itemId]);
    removePublicSharedCommentsByShareIds($authDb, $shareIds);
}

function removePublicSharedItemsByOwner($authDb, $ownerUserId, $itemIds = [])
{
    $uid = intval($ownerUserId);
    if ($uid <= 0) {
        return;
    }
    $ids = array_values(array_filter(array_map('intval', is_array($itemIds) ? $itemIds : []), function ($v) {
        return $v > 0;
    }));
    $shareIds = [];
    if (count($ids) === 0) {
        $idStmt = $authDb->prepare("SELECT id FROM public_shared_items WHERE owner_user_id=?");
        $idStmt->execute([$uid]);
        $shareIds = array_map('intval', $idStmt->fetchAll(PDO::FETCH_COLUMN));
        $stmt = $authDb->prepare("DELETE FROM public_shared_items WHERE owner_user_id=?");
        $stmt->execute([$uid]);
        removePublicSharedCommentsByShareIds($authDb, $shareIds);
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$uid], $ids);
    $idStmt = $authDb->prepare("SELECT id FROM public_shared_items WHERE owner_user_id=? AND owner_item_id IN ($placeholders)");
    $idStmt->execute($params);
    $shareIds = array_map('intval', $idStmt->fetchAll(PDO::FETCH_COLUMN));
    $stmt = $authDb->prepare("DELETE FROM public_shared_items WHERE owner_user_id=? AND owner_item_id IN ($placeholders)");
    $stmt->execute($params);
    removePublicSharedCommentsByShareIds($authDb, $shareIds);
}

function syncPublicSharedItem($authDb, $db, $ownerUserId, $itemId, $isShared)
{
    $uid = intval($ownerUserId);
    $id = intval($itemId);
    if ($uid <= 0 || $id <= 0) {
        return;
    }
    if (intval($isShared) !== 1) {
        removePublicSharedItem($authDb, $uid, $id);
        return;
    }
    $snapshot = getItemShareSnapshot($db, $id);
    if (!$snapshot || intval($snapshot['is_public_shared'] ?? 0) !== 1) {
        removePublicSharedItem($authDb, $uid, $id);
        return;
    }
    upsertPublicSharedItem($authDb, $uid, $snapshot);
}

function getClientIp()
{
    $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        $v = trim((string) ($_SERVER[$k] ?? ''));
        if ($v === '') {
            continue;
        }
        if ($k === 'HTTP_X_FORWARDED_FOR') {
            $parts = explode(',', $v);
            $v = trim((string) ($parts[0] ?? ''));
        }
        if ($v !== '') {
            return $v;
        }
    }
    return '';
}

function summarizeOperationResult($result)
{
    if (!is_array($result)) {
        return '';
    }
    $parts = [];
    $message = trim((string) ($result['message'] ?? ''));
    if ($message !== '') {
        $parts[] = $message;
    }
    $metricLabels = [
        'id' => 'ID',
        'created' => '新增',
        'deleted' => '删除',
        'imported' => '导入',
        'uploaded' => '上传',
        'skipped' => '跳过',
        'moved_images' => '图片转移'
    ];
    foreach ($metricLabels as $k => $label) {
        if (!isset($result[$k])) {
            continue;
        }
        $value = $result[$k];
        if (!is_numeric($value)) {
            continue;
        }
        $num = intval($value);
        if ($num <= 0) {
            continue;
        }
        $parts[] = $label . ':' . $num;
    }
    return trim(implode('；', $parts));
}

function composeOperationLogDetail($customDetail, $result)
{
    $parts = [];
    $custom = trim((string) $customDetail);
    if ($custom !== '') {
        $parts[] = $custom;
    }
    $summary = summarizeOperationResult($result);
    if ($summary !== '') {
        $parts[] = $summary;
    }
    $parts = array_values(array_filter($parts, function ($v) {
        return trim((string) $v) !== '';
    }));
    return trim(implode('；', $parts));
}

function logUserOperation($db, $actionKey, $actionLabel, $details = '', $api = '', $method = 'POST')
{
    if (!$db instanceof PDO) {
        return;
    }
    $key = trim((string) $actionKey);
    $label = trim((string) $actionLabel);
    if ($key === '' || $label === '') {
        return;
    }
    $detailText = trim((string) $details);
    if (function_exists('mb_substr')) {
        $detailText = mb_substr($detailText, 0, 500, 'UTF-8');
    } else {
        $detailText = substr($detailText, 0, 500);
    }
    try {
        $stmt = $db->prepare("INSERT INTO operation_logs (action_key, action_label, api, method, details, ip, created_at)
            VALUES (?,?,?,?,?,?,datetime('now','localtime'))");
        $stmt->execute([
            $key,
            $label,
            trim((string) $api),
            strtoupper(trim((string) $method)) ?: 'POST',
            $detailText,
            getClientIp()
        ]);
    } catch (Exception $e) {
    }
}

function resolveLogActorMeta($authDb, $actorUser)
{
    $meta = [
        'id' => intval(is_array($actorUser) ? ($actorUser['id'] ?? 0) : 0),
        'username' => trim((string) (is_array($actorUser) ? ($actorUser['username'] ?? '') : '')),
        'display_name' => trim((string) (is_array($actorUser) ? ($actorUser['display_name'] ?? '') : '')),
        'role' => trim((string) (is_array($actorUser) ? ($actorUser['role'] ?? 'user') : 'user')),
    ];
    if ($meta['id'] <= 0) {
        return $meta;
    }
    if ($meta['username'] !== '' && $meta['display_name'] !== '' && $meta['role'] !== '') {
        return $meta;
    }
    try {
        if (!$authDb instanceof PDO) {
            return $meta;
        }
        $stmt = $authDb->prepare("SELECT id, username, display_name, role FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$meta['id']]);
        $row = $stmt->fetch();
        if ($row) {
            $meta['username'] = trim((string) ($row['username'] ?? $meta['username']));
            $displayName = trim((string) ($row['display_name'] ?? ''));
            $meta['display_name'] = $displayName !== '' ? $displayName : $meta['username'];
            $meta['role'] = trim((string) ($row['role'] ?? $meta['role']));
        }
    } catch (Exception $e) {
    }
    if ($meta['display_name'] === '' && $meta['username'] !== '') {
        $meta['display_name'] = $meta['username'];
    }
    if ($meta['role'] === '') {
        $meta['role'] = 'user';
    }
    return $meta;
}

function logAdminOperation($authDb, $actorUser, $actionKey, $actionLabel, $details = '', $api = '', $method = 'POST')
{
    if (!$authDb instanceof PDO) {
        return;
    }
    $key = trim((string) $actionKey);
    $label = trim((string) $actionLabel);
    if ($key === '' || $label === '') {
        return;
    }
    $actor = resolveLogActorMeta($authDb, $actorUser);
    if (intval($actor['id']) <= 0) {
        return;
    }
    $detailText = trim((string) $details);
    if (function_exists('mb_substr')) {
        $detailText = mb_substr($detailText, 0, 500, 'UTF-8');
    } else {
        $detailText = substr($detailText, 0, 500);
    }
    try {
        $stmt = $authDb->prepare("INSERT INTO admin_operation_logs
            (actor_user_id, actor_username, actor_display_name, actor_role, action_key, action_label, api, method, details, ip, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,datetime('now','localtime'))");
        $stmt->execute([
            intval($actor['id']),
            trim((string) ($actor['username'] ?? '')),
            trim((string) ($actor['display_name'] ?? '')),
            trim((string) ($actor['role'] ?? 'user')) ?: 'user',
            $key,
            $label,
            trim((string) $api),
            strtoupper(trim((string) $method)) ?: 'POST',
            $detailText,
            getClientIp()
        ]);
    } catch (Exception $e) {
    }
}

function textContains($haystack, $needle)
{
    $h = strval($haystack);
    $n = strval($needle);
    if ($n === '') {
        return true;
    }
    if (function_exists('mb_strpos')) {
        return mb_strpos($h, $n, 0, 'UTF-8') !== false;
    }
    return strpos($h, $n) !== false;
}

function normalizeUserSortSettingLogDetails($details)
{
    $raw = trim((string) $details);
    if ($raw === '') {
        return '';
    }
    $labelToField = [
        '仪表盘分类排序' => 'dashboard_categories',
        '仪表盘分类统计排序' => 'dashboard_categories',
        '物品默认排序' => 'items_default',
        '物品管理默认排序' => 'items_default',
        '分类列表排序' => 'categories_list',
        '分类管理列表排序' => 'categories_list',
        '位置列表排序' => 'locations_list',
        '位置管理列表排序' => 'locations_list',
    ];
    $fieldDisplayLabels = [
        'dashboard_categories' => '仪表盘分类排序',
        'items_default' => '物品默认排序',
        'categories_list' => '分类列表排序',
        'locations_list' => '位置列表排序',
    ];
    $valueLabelMaps = [
        'dashboard_categories' => [
            'count_desc' => '按物品种类数 多→少',
            'total_qty_desc' => '按物品总件数 多→少',
            'name_asc' => '按名称首字母 A→Z',
        ],
        'items_default' => [
            'updated_at:DESC' => '最近更新',
            'created_at:DESC' => '最近添加',
            'name:ASC' => '名称 A→Z',
            'purchase_price:DESC' => '价格 高→低',
            'quantity:DESC' => '数量 多→少',
        ],
        'categories_list' => [
            'custom' => '系统默认顺序',
            'count_desc' => '按物品数量 多→少',
            'name_asc' => '按名称首字母 A→Z',
        ],
        'locations_list' => [
            'custom' => '系统默认顺序',
            'count_desc' => '按物品数量 多→少',
            'name_asc' => '按名称首字母 A→Z',
        ],
    ];

    $segments = preg_split('/[；;]/u', $raw);
    $rows = [];
    $fallbacks = [];
    foreach ($segments as $segmentRaw) {
        $segment = trim((string) $segmentRaw);
        if ($segment === '') {
            continue;
        }

        $label = '';
        $payload = $segment;
        if (preg_match('/^([^:：]+)\s*[：:]\s*(.+)$/u', $segment, $matches)) {
            $label = trim((string) $matches[1]);
            $payload = trim((string) $matches[2]);
        }

        if (preg_match('/^(.+?)\s*(?:->|→)\s*(.+)$/u', $payload, $arrowMatches)) {
            $beforeRaw = trim((string) $arrowMatches[1]);
            $afterRaw = trim((string) $arrowMatches[2]);
            if ($beforeRaw === $afterRaw) {
                continue;
            }
            $fieldKey = '';
            if ($label !== '' && isset($labelToField[$label])) {
                $fieldKey = $labelToField[$label];
            } else {
                foreach ($labelToField as $candidateLabel => $candidateField) {
                    if (textContains($segment, $candidateLabel)) {
                        $fieldKey = $candidateField;
                        break;
                    }
                }
            }
            $displayLabel = $label !== '' ? $label : '排序设置';
            if ($fieldKey !== '') {
                $displayLabel = $fieldDisplayLabels[$fieldKey] ?? $displayLabel;
            }
            $beforeText = $beforeRaw;
            $afterText = $afterRaw;
            if ($fieldKey !== '') {
                $beforeText = $valueLabelMaps[$fieldKey][$beforeRaw] ?? $beforeRaw;
                $afterText = $valueLabelMaps[$fieldKey][$afterRaw] ?? $afterRaw;
            }
            $rows[$displayLabel] = $displayLabel . '：“' . $beforeText . '” → “' . $afterText . '”';
            continue;
        }
        if (textContains($segment, '调整') && textContains($segment, '排序')) {
            $fallbacks[$segment] = true;
        }
    }
    if (count($rows) > 0) {
        return implode('；', array_values($rows));
    }
    if (count($fallbacks) > 0) {
        return implode('；', array_keys($fallbacks));
    }
    return '';
}

function normalizeUserOperationLogDetails($actionKey, $details)
{
    $key = trim((string) $actionKey);
    $detailText = trim((string) $details);
    if ($detailText === '') {
        return '';
    }
    if ($key === 'settings_sort') {
        return normalizeUserSortSettingLogDetails($detailText);
    }
    return $detailText;
}

function initSchema($db)
{
    $db->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        parent_id INTEGER DEFAULT 0,
        icon TEXT DEFAULT '📦',
        color TEXT DEFAULT '#3b82f6',
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS locations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        parent_id INTEGER DEFAULT 0,
        icon TEXT DEFAULT '📍',
        description TEXT DEFAULT '',
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS operation_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        action_key TEXT NOT NULL,
        action_label TEXT NOT NULL,
        api TEXT DEFAULT '',
        method TEXT DEFAULT 'POST',
        details TEXT DEFAULT '',
        ip TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_operation_logs_created_at ON operation_logs(created_at DESC)");

    $db->exec("CREATE TABLE IF NOT EXISTS shopping_list (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        quantity INTEGER DEFAULT 1,
        status TEXT DEFAULT 'pending_purchase',
        category_id INTEGER DEFAULT 0,
        priority TEXT DEFAULT 'normal',
        planned_price REAL DEFAULT 0,
        source_shared_id INTEGER DEFAULT 0,
        notes TEXT DEFAULT '',
        reminder_date TEXT DEFAULT '',
        reminder_note TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        category_id INTEGER DEFAULT 0,
        subcategory_id INTEGER DEFAULT 0,
        location_id INTEGER DEFAULT 0,
        quantity INTEGER DEFAULT 1,
        remaining_current INTEGER DEFAULT 0,
        remaining_total INTEGER DEFAULT 0,
        description TEXT DEFAULT '',
        image TEXT DEFAULT '',
        barcode TEXT DEFAULT '',
        purchase_date TEXT DEFAULT '',
        purchase_price REAL DEFAULT 0,
        tags TEXT DEFAULT '',
        status TEXT DEFAULT 'active',
        reminder_date TEXT DEFAULT '',
        reminder_next_date TEXT DEFAULT '',
        reminder_cycle_value INTEGER DEFAULT 0,
        reminder_cycle_unit TEXT DEFAULT '',
        reminder_note TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS item_reminder_instances (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        item_id INTEGER NOT NULL,
        due_date TEXT NOT NULL,
        is_completed INTEGER DEFAULT 0,
        completed_at DATETIME DEFAULT NULL,
        generated_by_complete_id INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_item_reminder_instances_item ON item_reminder_instances(item_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_item_reminder_instances_due ON item_reminder_instances(due_date, is_completed)");

    // 数据库迁移：为旧数据库添加 expiry_date 字段
    try {
        $db->exec("ALTER TABLE items ADD COLUMN expiry_date TEXT DEFAULT ''");
    } catch (Exception $e) { /* 字段已存在则忽略 */
    }

    // 数据库迁移：为旧数据库添加 deleted_at 字段（回收站软删除）
    try {
        $db->exec("ALTER TABLE items ADD COLUMN deleted_at DATETIME DEFAULT NULL");
    } catch (Exception $e) { /* 字段已存在则忽略 */
    }

    // 数据库迁移：购入渠道、备注
    try {
        $db->exec("ALTER TABLE items ADD COLUMN purchase_from TEXT DEFAULT ''");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE items ADD COLUMN notes TEXT DEFAULT ''");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE items ADD COLUMN subcategory_id INTEGER DEFAULT 0");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE items ADD COLUMN is_public_shared INTEGER DEFAULT 0");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE items ADD COLUMN public_recommend_reason TEXT DEFAULT ''");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE items ADD COLUMN reminder_date TEXT DEFAULT ''");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE items ADD COLUMN reminder_next_date TEXT DEFAULT ''");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE items ADD COLUMN reminder_cycle_value INTEGER DEFAULT 0");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE items ADD COLUMN reminder_cycle_unit TEXT DEFAULT ''");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE items ADD COLUMN reminder_note TEXT DEFAULT ''");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE items ADD COLUMN remaining_current INTEGER DEFAULT 0");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE items ADD COLUMN remaining_total INTEGER DEFAULT 0");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE categories ADD COLUMN parent_id INTEGER DEFAULT 0");
    } catch (Exception $e) {
    }
    try {
        $db->exec("UPDATE categories SET parent_id=0 WHERE parent_id IS NULL");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE shopping_list ADD COLUMN quantity INTEGER DEFAULT 1");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE shopping_list ADD COLUMN status TEXT DEFAULT 'pending_purchase'");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE shopping_list ADD COLUMN category_id INTEGER DEFAULT 0");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE shopping_list ADD COLUMN priority TEXT DEFAULT 'normal'");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE shopping_list ADD COLUMN planned_price REAL DEFAULT 0");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE shopping_list ADD COLUMN source_shared_id INTEGER DEFAULT 0");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE shopping_list ADD COLUMN notes TEXT DEFAULT ''");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE shopping_list ADD COLUMN reminder_date TEXT DEFAULT ''");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE shopping_list ADD COLUMN reminder_note TEXT DEFAULT ''");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE shopping_list ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE shopping_list ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP");
    } catch (Exception $e) {
    }
    try {
        $db->exec("CREATE INDEX IF NOT EXISTS idx_shopping_list_source_shared_id ON shopping_list(source_shared_id)");
    } catch (Exception $e) {
    }
    try {
        $legacyRows = $db->query("SELECT id, notes FROM shopping_list WHERE source_shared_id=0 AND notes LIKE '%[public-share:%'")->fetchAll();
        if (is_array($legacyRows) && count($legacyRows) > 0) {
            $legacyUpdate = $db->prepare("UPDATE shopping_list SET source_shared_id=?, notes=?, updated_at=datetime('now','localtime') WHERE id=?");
            foreach ($legacyRows as $legacy) {
                $notes = strval($legacy['notes'] ?? '');
                if (!preg_match('/\[public-share:(\d+)\]/', $notes, $m)) {
                    continue;
                }
                $sharedId = intval($m[1] ?? 0);
                if ($sharedId <= 0) {
                    continue;
                }
                $clean = preg_replace('/\s*\[public-share:\d+\]\s*/', '', $notes);
                $clean = preg_replace('/[；;]{2,}/u', '；', strval($clean));
                $clean = str_replace('数量: 1件', '1件', $clean);
                $clean = trim(strval($clean), " \t\n\r\0\x0B；;");
                if (strpos($clean, '来自公共频道') !== false && strpos($clean, '1件') === false) {
                    $clean .= ($clean === '' ? '' : '；') . '1件';
                }
                $legacyUpdate->execute([$sharedId, $clean, intval($legacy['id'] ?? 0)]);
            }
        }
    } catch (Exception $e) {
    }
    try {
        $db->exec("UPDATE items SET reminder_next_date = reminder_date WHERE (reminder_next_date IS NULL OR reminder_next_date='') AND reminder_date IS NOT NULL AND reminder_date != ''");
    } catch (Exception $e) {
    }
    try {
        $db->exec("UPDATE shopping_list SET status='pending_purchase' WHERE status IS NULL OR status=''");
        $db->exec("UPDATE shopping_list SET status='pending_purchase' WHERE status='待购买'");
        $db->exec("UPDATE shopping_list SET status='pending_receipt' WHERE status='待收货'");
    } catch (Exception $e) {
    }

    // 数据库迁移：位置层级已取消，统一扁平化
    try {
        $db->exec("UPDATE locations SET parent_id=0 WHERE parent_id IS NOT NULL AND parent_id!=0");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE locations ADD COLUMN icon TEXT DEFAULT '📍'");
    } catch (Exception $e) {
    }
    try {
        $db->exec("UPDATE locations SET icon='📍' WHERE icon IS NULL OR TRIM(icon)=''");
    } catch (Exception $e) {
    }
    try {
        $db->exec("UPDATE locations SET icon='🛋️' WHERE name='客厅' AND icon='📍'");
        $db->exec("UPDATE locations SET icon='🛏️' WHERE name='卧室' AND icon='📍'");
        $db->exec("UPDATE locations SET icon='🍳' WHERE name='厨房' AND icon='📍'");
        $db->exec("UPDATE locations SET icon='📚' WHERE name='书房' AND icon='📍'");
        $db->exec("UPDATE locations SET icon='📦' WHERE name='储物间' AND icon='📍'");
        $db->exec("UPDATE locations SET icon='🌤️' WHERE name='阳台' AND icon='📍'");
        $db->exec("UPDATE locations SET icon='📺' WHERE name='电视柜' AND icon='📍'");
        $db->exec("UPDATE locations SET icon='🗄️' WHERE name='书桌抽屉' AND icon='📍'");
        $db->exec("UPDATE locations SET icon='🚪' WHERE name='玄关' AND icon='📍'");
    } catch (Exception $e) {
    }

    // 数据库迁移：中文状态值 -> 英文标识
    try {
        $db->exec("UPDATE items SET status='active' WHERE status='使用中' OR status IS NULL OR status=''");
        $db->exec("UPDATE items SET status='archived' WHERE status='已归档'");
        $db->exec("UPDATE items SET status='sold' WHERE status='已转卖'");
        $db->exec("UPDATE items SET status='used_up' WHERE status='已用完'");
    } catch (Exception $e) {
    }

    // 默认分类（一级）与预设二级分类
    $defaultTopCategories = [
        ['电子设备', '💻', '#3b82f6'],
        ['家具家居', '🛋️', '#8b5cf6'],
        ['厨房用品', '🍳', '#f59e0b'],
        ['衣物鞋帽', '👔', '#ec4899'],
        ['书籍文档', '📚', '#10b981'],
        ['工具五金', '🔧', '#6366f1'],
        ['运动户外', '⚽', '#14b8a6'],
        ['虚拟产品', '🧩', '#06b6d4'],
        ['食物', '🍱', '#f97316'],
        ['一次性用品', '🧻', '#0ea5e9'],
        ['其他', '📦', '#64748b'],
    ];
    $defaultSubCategories = [
        '电子设备' => [['手机平板', '📱'], ['电脑外设', '🖥️'], ['音频设备', '🎧']],
        '家具家居' => [['清洁收纳', '🧹'], ['家纺寝具', '🛏️'], ['家居装饰', '🪴']],
        '厨房用品' => [['炊具锅具', '🍲'], ['餐具器皿', '🍽️'], ['厨房小电', '🔌']],
        '衣物鞋帽' => [['上装', '👕'], ['下装', '👖'], ['鞋靴配饰', '👟']],
        '书籍文档' => [['纸质书', '📖'], ['电子资料', '💾'], ['证件合同', '🧾']],
        '工具五金' => [['手动工具', '🪛'], ['电动工具', '🧰'], ['紧固耗材', '🪙']],
        '运动户外' => [['球类器材', '🏀'], ['健身训练', '🏋️'], ['露营徒步', '⛺']],
        '虚拟产品' => [['软件订阅', '💻'], ['会员服务', '🎟️'], ['数字资产', '🧠']],
        '食物' => [['主食粮油', '🍚'], ['生鲜冷藏', '🥬'], ['零食饮料', '🥤']],
        '其他' => [['日用杂项', '🧺'], ['礼品收藏', '🎁'], ['临时分类', '🗂️']],
    ];
    $findCategoryStmt = $db->prepare("SELECT id FROM categories WHERE name=? LIMIT 1");
    $insertCategoryStmt = $db->prepare("INSERT INTO categories (name, parent_id, icon, color) VALUES (?,?,?,?)");
    $countCategories = intval($db->query("SELECT COUNT(*) FROM categories")->fetchColumn() ?: 0);
    $hasAnySubCategory = intval($db->query("SELECT COUNT(*) FROM categories WHERE parent_id>0")->fetchColumn() ?: 0) > 0;
    $seedAllTop = ($countCategories === 0);
    $foodInserted = false;
    $topCategoryIds = [];
    if ($seedAllTop) {
        foreach ($defaultTopCategories as $cat) {
            [$name, $icon, $color] = $cat;
            $insertCategoryStmt->execute([$name, 0, $icon, $color]);
            $cid = intval($db->lastInsertId());
            if ($cid > 0) {
                $topCategoryIds[$name] = $cid;
            }
        }
    } else {
        // 兼容历史版本：保底补充“虚拟产品”“食物”“一次性用品”一级分类
        foreach ($defaultTopCategories as $cat) {
            [$name, $icon, $color] = $cat;
            if (!in_array($name, ['虚拟产品', '食物', '一次性用品'], true)) {
                continue;
            }
            $findCategoryStmt->execute([$name]);
            $cid = intval($findCategoryStmt->fetchColumn() ?: 0);
            if ($cid <= 0) {
                $insertCategoryStmt->execute([$name, 0, $icon, $color]);
                $cid = intval($db->lastInsertId());
                if ($name === '食物') {
                    $foodInserted = true;
                }
            }
            if ($cid > 0) {
                $topCategoryIds[$name] = $cid;
            }
        }
        // 读取已存在的一级分类 ID（用于后续二级分类补充）
        foreach ($defaultTopCategories as $cat) {
            [$name] = $cat;
            if (isset($topCategoryIds[$name])) {
                continue;
            }
            $stmtTop = $db->prepare("SELECT id FROM categories WHERE name=? AND parent_id=0 LIMIT 1");
            $stmtTop->execute([$name]);
            $cid = intval($stmtTop->fetchColumn() ?: 0);
            if ($cid > 0) {
                $topCategoryIds[$name] = $cid;
            }
        }
    }

    // 补充二级分类：新库初始化 / 历史库首次升级 / 新增“食物”时自动补齐
    $needSeedSubCategories = $seedAllTop || !$hasAnySubCategory || $foodInserted;
    if ($needSeedSubCategories) {
        foreach ($defaultSubCategories as $parentName => $subs) {
            $parentId = intval($topCategoryIds[$parentName] ?? 0);
            if ($parentId <= 0) {
                continue;
            }
            foreach ($subs as $subMeta) {
                [$subName, $subIcon] = $subMeta;
                $findCategoryStmt->execute([$subName]);
                $sid = intval($findCategoryStmt->fetchColumn() ?: 0);
                if ($sid <= 0) {
                    $insertCategoryStmt->execute([$subName, $parentId, $subIcon, '#64748b']);
                }
            }
        }
    }

    // 历史兼容：旧版本把二级分类写在 category_id 中，迁移到 subcategory_id
    try {
        $db->exec("UPDATE items
            SET subcategory_id = category_id,
                category_id = (SELECT parent_id FROM categories WHERE categories.id = items.category_id LIMIT 1)
            WHERE category_id IN (SELECT id FROM categories WHERE parent_id > 0)
              AND COALESCE(subcategory_id, 0) = 0");
    } catch (Exception $e) {
    }
    // 保底清理：二级分类与一级分类不匹配时清空二级分类
    try {
        $db->exec("UPDATE items
            SET subcategory_id = 0
            WHERE subcategory_id > 0
              AND (
                category_id <= 0
                OR NOT EXISTS (
                    SELECT 1 FROM categories sc
                    WHERE sc.id = items.subcategory_id
                      AND sc.parent_id = items.category_id
                )
              )");
    } catch (Exception $e) {
    }

    $count = $db->query("SELECT COUNT(*) FROM locations")->fetchColumn();
    if ($count == 0) {
        $defaults = [
            ['客厅', 0, '🛋️'],
            ['卧室', 0, '🛏️'],
            ['厨房', 0, '🍳'],
            ['书房', 0, '📚'],
        ];
        $stmt = $db->prepare("INSERT INTO locations (name, parent_id, icon) VALUES (?, ?, ?)");
        foreach ($defaults as $loc)
            $stmt->execute($loc);
    }
}

function removeAllFilesInDir($dir)
{
    if (!is_dir($dir))
        return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $fileInfo) {
        if ($fileInfo->isFile())
            @unlink($fileInfo->getPathname());
    }
}

function moveUploadFilesToTrash($db = null)
{
    if (!is_dir(UPLOAD_DIR))
        return 0;
    if (!is_dir(TRASH_DIR))
        mkdir(TRASH_DIR, 0755, true);

    $moved = 0;
    if ($db instanceof PDO) {
        $images = $db->query("SELECT DISTINCT image FROM items WHERE image IS NOT NULL AND image != ''")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($images as $img) {
            $img = basename((string) $img);
            if ($img === '')
                continue;
            $src = UPLOAD_DIR . $img;
            if (!file_exists($src))
                continue;
            $targetName = $img;
            if (file_exists(TRASH_DIR . $targetName)) {
                $targetName = uniqid('trash_') . '_' . $targetName;
            }
            if (@rename($src, TRASH_DIR . $targetName)) {
                $moved++;
            }
        }
    } else {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(UPLOAD_DIR, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $fileInfo) {
            if (!$fileInfo->isFile())
                continue;
            $src = $fileInfo->getPathname();
            if (strpos($src, TRASH_DIR) === 0)
                continue;
            $targetName = basename($src);
            if (file_exists(TRASH_DIR . $targetName)) {
                $targetName = uniqid('trash_') . '_' . $targetName;
            }
            if (@rename($src, TRASH_DIR . $targetName)) {
                $moved++;
            }
        }
    }
    return $moved;
}

function makeUniqueImportImageFilename($originalName)
{
    $originalName = basename((string) $originalName);
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $base = preg_replace('/[^\p{L}\p{N}_\-]/u', '', $base);
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    if ($base === '')
        $base = 'img_import';
    if ($ext === '')
        $ext = 'jpg';

    $candidate = $base . '.' . $ext;
    $idx = 1;
    while (file_exists(UPLOAD_DIR . $candidate)) {
        $candidate = $base . '_' . $idx . '.' . $ext;
        $idx++;
    }
    return $candidate;
}

function getUploadErrorMessage($errCode)
{
    switch (intval($errCode)) {
        case UPLOAD_ERR_OK:
            return '';
        case UPLOAD_ERR_INI_SIZE:
            return '上传失败：文件超过服务器上传上限（php.ini）';
        case UPLOAD_ERR_FORM_SIZE:
            return '上传失败：文件超过表单限制';
        case UPLOAD_ERR_PARTIAL:
            return '上传失败：文件仅部分上传，请重试';
        case UPLOAD_ERR_NO_FILE:
            return '上传失败：未选择文件';
        case UPLOAD_ERR_NO_TMP_DIR:
            return '上传失败：服务器临时目录不可用';
        case UPLOAD_ERR_CANT_WRITE:
            return '上传失败：服务器写入文件失败';
        case UPLOAD_ERR_EXTENSION:
            return '上传失败：被服务器扩展拦截';
        default:
            return '上传失败：未知错误';
    }
}

function normalizeDateYmd($dateStr)
{
    $dateStr = trim((string) $dateStr);
    if ($dateStr === '')
        return '';
    $normalized = str_replace('/', '-', $dateStr);
    if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $normalized, $m))
        return null;
    $y = intval($m[1]);
    $mon = intval($m[2]);
    $day = intval($m[3]);
    if (!checkdate($mon, $day, $y))
        return null;
    return sprintf('%04d-%02d-%02d', $y, $mon, $day);
}

function isValidDateYmd($dateStr)
{
    return normalizeDateYmd($dateStr) !== null;
}

function normalizeStatusValue($status)
{
    $v = trim((string) $status);
    if ($v === '')
        return 'active';
    $lv = strtolower($v);
    if ($lv === 'active' || $v === '使用中')
        return 'active';
    if ($lv === 'archived' || $v === '已归档')
        return 'archived';
    if ($lv === 'sold' || $v === '已转卖')
        return 'sold';
    if ($lv === 'used_up' || $v === '已用完')
        return 'used_up';
    return $v;
}

function normalizeRemainingPair($currentRaw, $totalRaw)
{
    $currentRaw = trim((string) ($currentRaw ?? '0'));
    $totalRaw = trim((string) ($totalRaw ?? '0'));
    if ($currentRaw === '')
        $currentRaw = '0';
    if ($totalRaw === '')
        $totalRaw = '0';
    if (!preg_match('/^\d+$/', $currentRaw) || !preg_match('/^\d+$/', $totalRaw)) {
        return [0, 0, '余量格式无效：只能输入数字'];
    }
    $current = max(0, intval($currentRaw));
    $total = max(0, intval($totalRaw));
    if ($current > $total) {
        return [$current, $total, '余量格式无效：左侧数值不能大于右侧'];
    }
    return [$current, $total, null];
}

function normalizeItemCategorySelection($db, $categoryId, $subcategoryId)
{
    $categoryId = max(0, intval($categoryId));
    $subcategoryId = max(0, intval($subcategoryId));
    if ($categoryId <= 0) {
        return [0, 0, null];
    }
    $stmt = $db->prepare("SELECT id, parent_id FROM categories WHERE id=? LIMIT 1");
    $stmt->execute([$categoryId]);
    $catRow = $stmt->fetch();
    if (!$catRow) {
        return [0, 0, '一级分类不存在'];
    }
    $catParentId = intval($catRow['parent_id'] ?? 0);
    if ($catParentId > 0) {
        if ($subcategoryId <= 0) {
            $subcategoryId = $categoryId;
        }
        $categoryId = $catParentId;
    }

    $topStmt = $db->prepare("SELECT id, parent_id FROM categories WHERE id=? LIMIT 1");
    $topStmt->execute([$categoryId]);
    $topRow = $topStmt->fetch();
    if (!$topRow) {
        return [0, 0, '一级分类不存在'];
    }
    if (intval($topRow['parent_id'] ?? 0) > 0) {
        return [0, 0, '一级分类选择无效'];
    }

    if ($subcategoryId > 0) {
        $subStmt = $db->prepare("SELECT id, parent_id FROM categories WHERE id=? LIMIT 1");
        $subStmt->execute([$subcategoryId]);
        $subRow = $subStmt->fetch();
        if (!$subRow) {
            return [$categoryId, 0, '二级分类不存在'];
        }
        if (intval($subRow['parent_id'] ?? 0) !== $categoryId) {
            return [$categoryId, 0, '二级分类只可选择当前一级分类下的选项'];
        }
    }
    return [$categoryId, $subcategoryId, null];
}

function normalizeShoppingPriority($priority)
{
    $p = strtolower(trim((string) $priority));
    if ($p === 'high' || $p === 'h' || $p === '高')
        return 'high';
    if ($p === 'low' || $p === 'l' || $p === '低')
        return 'low';
    return 'normal';
}

function normalizeShoppingStatus($status)
{
    $s = strtolower(trim((string) $status));
    if ($s === 'pending_receipt' || $s === 'receipt' || $s === 'receiving' || $s === '待收货')
        return 'pending_receipt';
    if ($s === 'pending_purchase' || $s === 'purchase' || $s === 'buy' || $s === '待购买' || $s === '')
        return 'pending_purchase';
    return 'pending_purchase';
}

function normalizeReminderCycleUnit($unit)
{
    $u = strtolower(trim((string) $unit));
    if ($u === 'day' || $u === 'days' || $u === 'd' || $u === '天')
        return 'day';
    if ($u === 'week' || $u === 'weeks' || $u === 'w' || $u === '周')
        return 'week';
    if ($u === 'year' || $u === 'years' || $u === 'y' || $u === '年')
        return 'year';
    return '';
}

function normalizeReminderCycleValue($value, $unit)
{
    $u = normalizeReminderCycleUnit($unit);
    if ($u === '')
        return 0;
    $v = intval($value);
    if ($v < 1)
        $v = 1;
    if ($v > 36500)
        $v = 36500;
    return $v;
}

function normalizeReminderDateValue($dateStr)
{
    $v = normalizeDateYmd($dateStr);
    return $v === null ? '' : $v;
}

function calcNextReminderDate($dateStr, $cycleValue, $cycleUnit)
{
    $baseDate = normalizeDateYmd($dateStr);
    $unit = normalizeReminderCycleUnit($cycleUnit);
    $value = normalizeReminderCycleValue($cycleValue, $unit);
    if ($baseDate === null || $baseDate === '' || $unit === '' || $value < 1)
        return null;

    $dt = DateTime::createFromFormat('Y-m-d', $baseDate);
    if (!$dt)
        return null;
    if ($unit === 'day')
        $dt->modify('+' . $value . ' day');
    elseif ($unit === 'week')
        $dt->modify('+' . $value . ' week');
    else
        $dt->modify('+' . $value . ' year');
    return $dt->format('Y-m-d');
}

function isReminderConfigValid($reminderDate, $reminderNextDate, $reminderValue, $reminderUnit)
{
    $date = normalizeReminderDateValue($reminderDate);
    $nextDate = normalizeReminderDateValue($reminderNextDate);
    $unit = normalizeReminderCycleUnit($reminderUnit);
    $value = normalizeReminderCycleValue($reminderValue, $unit);
    if ($date === '' || $unit === '' || $value <= 0)
        return [false, '', 0, ''];
    if ($nextDate === '')
        $nextDate = $date;
    return [true, $nextDate, $value, $unit];
}

function syncItemReminderInstances($db, $itemId, $reminderDate, $reminderNextDate, $reminderValue, $reminderUnit)
{
    $itemId = intval($itemId);
    if ($itemId <= 0)
        return;

    [$valid, $dueDate] = isReminderConfigValid($reminderDate, $reminderNextDate, $reminderValue, $reminderUnit);
    if (!$valid || $dueDate === '') {
        $del = $db->prepare("DELETE FROM item_reminder_instances WHERE item_id=?");
        $del->execute([$itemId]);
        return;
    }

    $pendingStmt = $db->prepare("SELECT id, due_date FROM item_reminder_instances WHERE item_id=? AND is_completed=0 ORDER BY id ASC");
    $pendingStmt->execute([$itemId]);
    $pendingRows = $pendingStmt->fetchAll();

    if (count($pendingRows) === 0) {
        $ins = $db->prepare("INSERT INTO item_reminder_instances (item_id, due_date, is_completed, completed_at, generated_by_complete_id, created_at, updated_at) VALUES (?,?,0,NULL,0,datetime('now','localtime'),datetime('now','localtime'))");
        $ins->execute([$itemId, $dueDate]);
        return;
    }

    $primary = $pendingRows[0];
    if (normalizeReminderDateValue($primary['due_date'] ?? '') !== $dueDate) {
        $upd = $db->prepare("UPDATE item_reminder_instances SET due_date=?, generated_by_complete_id=0, updated_at=datetime('now','localtime') WHERE id=? AND item_id=?");
        $upd->execute([$dueDate, intval($primary['id']), $itemId]);
    }

    if (count($pendingRows) > 1) {
        $extraIds = array_map(function ($row) {
            return intval($row['id']);
        }, array_slice($pendingRows, 1));
        if (!empty($extraIds)) {
            $placeholders = implode(',', array_fill(0, count($extraIds), '?'));
            $params = array_merge([$itemId], $extraIds);
            $delExtra = $db->prepare("DELETE FROM item_reminder_instances WHERE item_id=? AND id IN ($placeholders)");
            $delExtra->execute($params);
        }
    }
}

function seedReminderInstancesFromItems($db)
{
    $db->exec("INSERT INTO item_reminder_instances (item_id, due_date, is_completed, completed_at, generated_by_complete_id, created_at, updated_at)
        SELECT i.id, COALESCE(NULLIF(i.reminder_next_date,''), i.reminder_date), 0, NULL, 0, datetime('now','localtime'), datetime('now','localtime')
        FROM items i
        WHERE i.deleted_at IS NULL
          AND COALESCE(NULLIF(i.reminder_next_date,''), i.reminder_date) != ''
          AND i.reminder_cycle_unit IN ('day','week','year')
          AND COALESCE(i.reminder_cycle_value,0) > 0
          AND NOT EXISTS (
            SELECT 1 FROM item_reminder_instances r WHERE r.item_id=i.id AND r.is_completed=0
          )");
}

function seedDemoPeerPublicShare($authDb, $viewerUserId)
{
    $viewerId = intval($viewerUserId);
    if (!($authDb instanceof PDO) || $viewerId <= 0) {
        return ['shared_created' => 0, 'comment_created' => 0];
    }

    $viewerStmt = $authDb->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
    $viewerStmt->execute([$viewerId]);
    $viewerUsername = strtolower(trim((string) $viewerStmt->fetchColumn()));
    if ($viewerUsername !== strtolower(DEFAULT_DEMO_USERNAME)) {
        return ['shared_created' => 0, 'comment_created' => 0];
    }

    $peerUsername = 'demo_peer_' . $viewerId . '_channel';
    $peerDisplayName = '演示成员（公共频道）';
    $questions = getSecurityQuestions();
    $qKeys = array_keys($questions);
    $defaultQuestionKey = count($qKeys) > 0 ? $qKeys[0] : '';
    $defaultQuestionLabel = ($defaultQuestionKey !== '' && isset($questions[$defaultQuestionKey])) ? strval($questions[$defaultQuestionKey]) : '';

    $peerStmt = $authDb->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
    $peerStmt->execute([$peerUsername]);
    $peerId = intval($peerStmt->fetchColumn() ?: 0);
    if ($peerId <= 0) {
        $insertPeer = $authDb->prepare("INSERT INTO users (username, password_hash, display_name, role, security_question_key, security_question_label, security_answer_hash, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,datetime('now','localtime'),datetime('now','localtime'))");
        $insertPeer->execute([
            $peerUsername,
            password_hash('demo_peer_123456', PASSWORD_DEFAULT),
            $peerDisplayName,
            'user',
            $defaultQuestionKey,
            $defaultQuestionLabel,
            $defaultQuestionKey !== '' ? password_hash(normalizeSecurityAnswer('demo_peer'), PASSWORD_DEFAULT) : ''
        ]);
        $peerId = intval($authDb->lastInsertId());
    }
    if ($peerId <= 0 || $peerId === $viewerId) {
        return ['shared_created' => 0, 'comment_created' => 0];
    }

    $peerDb = getUserDB($peerId);
    $demoPeerBarcode = 'DEMO-PEER-SHARE-01';

    $oldItemStmt = $peerDb->prepare("SELECT id FROM items WHERE barcode=?");
    $oldItemStmt->execute([$demoPeerBarcode]);
    $oldItemIds = array_map('intval', $oldItemStmt->fetchAll(PDO::FETCH_COLUMN));
    $oldItemIds = array_values(array_filter($oldItemIds, function ($v) {
        return $v > 0;
    }));
    if (count($oldItemIds) > 0) {
        removePublicSharedItemsByOwner($authDb, $peerId, $oldItemIds);
        $placeholders = implode(',', array_fill(0, count($oldItemIds), '?'));
        $delStmt = $peerDb->prepare("DELETE FROM items WHERE id IN ($placeholders)");
        $delStmt->execute($oldItemIds);
    }

    $catStmt = $peerDb->prepare("SELECT id FROM categories WHERE name=? LIMIT 1");
    $catStmt->execute(['电子设备']);
    $categoryId = intval($catStmt->fetchColumn() ?: 0);
    $subCatStmt = $peerDb->prepare("SELECT id FROM categories WHERE name=? AND parent_id=? LIMIT 1");
    $subCatStmt->execute(['音频设备', $categoryId]);
    $subcategoryId = intval($subCatStmt->fetchColumn() ?: 0);
    $locStmt = $peerDb->prepare("SELECT id FROM locations WHERE name=? LIMIT 1");
    $locStmt->execute(['客厅']);
    $locationId = intval($locStmt->fetchColumn() ?: 0);

    $insertPeerItem = $peerDb->prepare("INSERT INTO items
        (name, category_id, subcategory_id, location_id, quantity, remaining_current, remaining_total, description, image, barcode, purchase_date, purchase_price, tags, status, expiry_date, purchase_from, notes, is_public_shared, public_recommend_reason, reminder_date, reminder_next_date, reminder_cycle_value, reminder_cycle_unit, reminder_note)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $insertPeerItem->execute([
        '降噪蓝牙耳机（演示成员）',
        $categoryId,
        $subcategoryId,
        $locationId,
        1,
        1,
        1,
        '公共频道权限演示：由其他成员发布',
        '',
        $demoPeerBarcode,
        date('Y-m-d', strtotime('-45 days')),
        699,
        '耳机,降噪,演示',
        'active',
        '',
        '京东',
        '用于演示：测试用户可查看并加入购物清单，但不可编辑',
        1,
        '我自己长期通勤使用，降噪稳定，佩戴也比较舒适',
        '',
        '',
        0,
        '',
        ''
    ]);
    $peerItemId = intval($peerDb->lastInsertId());
    if ($peerItemId <= 0) {
        return ['shared_created' => 0, 'comment_created' => 0];
    }

    syncPublicSharedItem($authDb, $peerDb, $peerId, $peerItemId, 1);

    $shareIdStmt = $authDb->prepare("SELECT id FROM public_shared_items WHERE owner_user_id=? AND owner_item_id=? LIMIT 1");
    $shareIdStmt->execute([$peerId, $peerItemId]);
    $shareId = intval($shareIdStmt->fetchColumn() ?: 0);
    if ($shareId > 0) {
        removePublicSharedCommentsByShareIds($authDb, [$shareId]);
        $insertCommentStmt = $authDb->prepare("INSERT INTO public_shared_comments (shared_id, user_id, content, created_at, updated_at)
            VALUES (?,?,?,datetime('now','localtime'),datetime('now','localtime'))");
        $insertCommentStmt->execute([$shareId, $peerId, '这是我最近复购的一款耳机，通勤和居家都很实用。']);
        return ['shared_created' => 1, 'comment_created' => 1];
    }

    return ['shared_created' => 1, 'comment_created' => 0];
}

function loadDemoDataIntoDb($db, $options = [])
{
    $moveImages = !empty($options['move_images']);
    $authDb = (isset($options['auth_db']) && $options['auth_db'] instanceof PDO) ? $options['auth_db'] : null;
    $ownerUserId = intval($options['owner_user_id'] ?? 0);
    $moved = $moveImages ? moveUploadFilesToTrash($db) : 0;

    $db->beginTransaction();
    try {
        $db->exec("DELETE FROM items");
        $db->exec("DELETE FROM item_reminder_instances");
        $db->exec("DELETE FROM shopping_list");
        $db->exec("DELETE FROM categories");
        $db->exec("DELETE FROM locations");
        $db->exec("DELETE FROM operation_logs");
        try {
            $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('items','item_reminder_instances','shopping_list','categories','locations','operation_logs')");
        } catch (Exception $e) {
        }

        // 重建默认分类/位置
        initSchema($db);

        $categoryRows = $db->query("SELECT id, name FROM categories")->fetchAll();
        $catIdByName = [];
        foreach ($categoryRows as $row) {
            $catIdByName[$row['name']] = intval($row['id']);
        }

        $loadLocationMap = function () use ($db) {
            $rows = $db->query("SELECT id, name FROM locations")->fetchAll();
            $map = [];
            foreach ($rows as $row) {
                $map[$row['name']] = intval($row['id']);
            }
            return $map;
        };
        $insertLocation = $db->prepare("INSERT INTO locations (name, parent_id, icon, description) VALUES (?,?,?,?)");
        $locMap = $loadLocationMap();
        $requiredLocations = [
            ['储物间', '📦', '集中存放不常用物品'],
            ['阳台', '🌤️', '户外和工具相关物品'],
            ['电视柜', '📺', '客厅电子设备与配件'],
            ['书桌抽屉', '🗄️', '文具和常用小配件'],
            ['玄关', '🚪', '出门随手物品存放']
        ];
        foreach ($requiredLocations as $locMeta) {
            [$name, $icon, $desc] = $locMeta;
            if (!isset($locMap[$name])) {
                $insertLocation->execute([$name, 0, $icon, $desc]);
                $locMap = $loadLocationMap();
            }
        }

        $today = date('Y-m-d');
        $demoItems = [
            ['name' => 'MacBook Air M2', 'category' => '电子设备', 'subcategory' => '电脑外设', 'location' => '书房', 'quantity' => 1, 'description' => '日常办公主力设备', 'barcode' => 'SN-MBA-2026', 'purchase_date' => date('Y-m-d', strtotime('-420 days')), 'purchase_price' => 7999, 'tags' => '电脑,办公', 'status' => 'active', 'expiry_date' => '', 'purchase_from' => '京东', 'notes' => '附带保护壳与扩展坞'],
            ['name' => 'AirPods Pro', 'category' => '电子设备', 'subcategory' => '音频设备', 'location' => '卧室', 'quantity' => 1, 'description' => '蓝牙耳机', 'barcode' => 'SN-AIRPODS-02', 'purchase_date' => date('Y-m-d', strtotime('-260 days')), 'purchase_price' => 1499, 'tags' => '耳机,音频', 'status' => 'active', 'expiry_date' => '', 'purchase_from' => '淘宝', 'notes' => '配件齐全'],
            ['name' => '机械键盘', 'category' => '电子设备', 'subcategory' => '电脑外设', 'location' => '书桌抽屉', 'quantity' => 1, 'description' => '备用键盘', 'barcode' => 'KB-RED-87', 'purchase_date' => date('Y-m-d', strtotime('-540 days')), 'purchase_price' => 399, 'tags' => '键盘,外设', 'status' => 'archived', 'expiry_date' => '', 'purchase_from' => '拼多多', 'notes' => '近期未使用，已归档保存'],
            ['name' => '二手显示器', 'category' => '电子设备', 'subcategory' => '电脑外设', 'location' => '储物间', 'quantity' => 1, 'description' => '已转卖物品', 'barcode' => 'MON-USED-24', 'purchase_date' => date('Y-m-d', strtotime('-800 days')), 'purchase_price' => 1200, 'tags' => '显示器,转卖', 'status' => 'sold', 'expiry_date' => '', 'purchase_from' => '闲鱼', 'notes' => '已完成交易，保留记录'],
            ['name' => '胶囊咖啡机', 'category' => '厨房用品', 'subcategory' => '厨房小电', 'location' => '厨房', 'quantity' => 1, 'description' => '家用咖啡机', 'barcode' => 'COFFEE-01', 'purchase_date' => date('Y-m-d', strtotime('-320 days')), 'purchase_price' => 899, 'tags' => '咖啡,厨房', 'status' => 'active', 'expiry_date' => '', 'purchase_from' => '线下', 'notes' => '常用设备', 'is_public_shared' => 1, 'public_recommend_reason' => '稳定耐用，家用入门友好，维护成本低', 'reminder_date' => date('Y-m-d', strtotime('-28 days')), 'reminder_next_date' => date('Y-m-d', strtotime('+2 days')), 'reminder_cycle_value' => 30, 'reminder_cycle_unit' => 'day', 'reminder_note' => '需要清洗水箱并补充咖啡胶囊'],
            ['name' => '维生素 D3', 'category' => '其他', 'subcategory' => '日用杂项', 'location' => '厨房', 'quantity' => 2, 'remaining_current' => 1, 'description' => '保健品', 'barcode' => 'HEALTH-D3-01', 'purchase_date' => date('Y-m-d', strtotime('-60 days')), 'purchase_price' => 128, 'tags' => '保健,补剂', 'status' => 'active', 'expiry_date' => date('Y-m-d', strtotime('+5 days')), 'purchase_from' => '线下', 'notes' => '还有约一周到期，优先使用'],
            ['name' => '车载灭火器', 'category' => '工具五金', 'location' => '阳台', 'quantity' => 1, 'remaining_current' => 0, 'description' => '安全应急用品', 'barcode' => 'SAFE-FIRE-01', 'purchase_date' => date('Y-m-d', strtotime('-480 days')), 'purchase_price' => 89, 'tags' => '安全,应急', 'status' => 'active', 'expiry_date' => date('Y-m-d', strtotime('-12 days')), 'purchase_from' => '京东', 'notes' => '已超过有效期，需尽快更换'],
            ['name' => '沐浴露补充装', 'category' => '其他', 'subcategory' => '日用杂项', 'location' => '储物间', 'quantity' => 3, 'description' => '家庭日用品', 'barcode' => 'HOME-BATH-03', 'purchase_date' => date('Y-m-d', strtotime('-30 days')), 'purchase_price' => 75, 'tags' => '日用品,家居', 'status' => 'active', 'expiry_date' => date('Y-m-d', strtotime('+25 days')), 'purchase_from' => '拼多多', 'notes' => '本月内到期，先用旧库存'],
            ['name' => '训练足球', 'category' => '运动户外', 'subcategory' => '球类器材', 'location' => '阳台', 'quantity' => 1, 'description' => '周末运动使用', 'barcode' => 'SPORT-BALL-01', 'purchase_date' => date('Y-m-d', strtotime('-210 days')), 'purchase_price' => 199, 'tags' => '运动,户外', 'status' => 'active', 'expiry_date' => '', 'purchase_from' => '淘宝', 'notes' => '周末固定训练用球', 'reminder_date' => date('Y-m-d', strtotime('-20 days')), 'reminder_next_date' => date('Y-m-d', strtotime('+1 day')), 'reminder_cycle_value' => 1, 'reminder_cycle_unit' => 'week', 'reminder_note' => '按首次训练日期每周提醒一次，出门前检查气压'],
            ['name' => '空气净化器滤芯', 'category' => '家具家居', 'subcategory' => '清洁收纳', 'location' => '客厅', 'quantity' => 1, 'remaining_current' => 0, 'description' => '客厅净化器维护项目', 'barcode' => 'AIR-FILTER-01', 'purchase_date' => date('Y-m-d', strtotime('-200 days')), 'purchase_price' => 169, 'tags' => '家居,维护', 'status' => 'active', 'expiry_date' => '', 'purchase_from' => '京东', 'notes' => '上次维护后需持续追踪更换周期', 'is_public_shared' => 1, 'public_recommend_reason' => '价格和性能平衡，适合作为常备耗材', 'reminder_date' => date('Y-m-d', strtotime('-87 days')), 'reminder_next_date' => date('Y-m-d', strtotime('+3 days')), 'reminder_cycle_value' => 90, 'reminder_cycle_unit' => 'day', 'reminder_note' => '按初始维护日期每 90 天提醒一次，临近提醒时准备更换滤芯'],
            ['name' => '空气净化器滤芯（原厂）', 'category' => '家具家居', 'subcategory' => '清洁收纳', 'location' => '储物间', 'quantity' => 1, 'description' => '上一批次原厂滤芯采购记录', 'barcode' => 'AIR-FILTER-OEM-02', 'purchase_date' => date('Y-m-d', strtotime('-35 days')), 'purchase_price' => 199, 'tags' => '滤芯,原厂', 'status' => 'active', 'expiry_date' => '', 'purchase_from' => '京东', 'notes' => '价格较高但安装更稳', 'is_public_shared' => 1, 'public_recommend_reason' => '安装契合度高，追求稳定可优先考虑'],
            ['name' => '空气净化器滤芯（兼容款）', 'category' => '家具家居', 'subcategory' => '清洁收纳', 'location' => '储物间', 'quantity' => 2, 'description' => '兼容款滤芯采购记录', 'barcode' => 'AIR-FILTER-COMP-03', 'purchase_date' => date('Y-m-d', strtotime('-120 days')), 'purchase_price' => 129, 'tags' => '滤芯,兼容', 'status' => 'active', 'expiry_date' => '', 'purchase_from' => '拼多多', 'notes' => '单价更低，适合备货'],
            ['name' => '维生素D3滴剂', 'category' => '其他', 'subcategory' => '日用杂项', 'location' => '厨房', 'quantity' => 1, 'description' => '儿童可用滴剂版本', 'barcode' => 'HEALTH-D3-DROP-02', 'purchase_date' => date('Y-m-d', strtotime('-22 days')), 'purchase_price' => 139, 'tags' => '保健,滴剂', 'status' => 'active', 'expiry_date' => date('Y-m-d', strtotime('+320 days')), 'purchase_from' => '淘宝', 'notes' => '最近一次补货'],
            ['name' => '维生素 D3 软胶囊', 'category' => '其他', 'location' => '厨房', 'quantity' => 1, 'description' => '成人常规补充版本', 'barcode' => 'HEALTH-D3-CAPS-03', 'purchase_date' => date('Y-m-d', strtotime('-180 days')), 'purchase_price' => 109, 'tags' => '保健,胶囊', 'status' => 'archived', 'expiry_date' => date('Y-m-d', strtotime('+120 days')), 'purchase_from' => '京东', 'notes' => '旧批次价格较低'],
            ['name' => '车载灭火器（标准版）', 'category' => '工具五金', 'location' => '阳台', 'quantity' => 1, 'description' => '上一代标准版灭火器', 'barcode' => 'SAFE-FIRE-STD-02', 'purchase_date' => date('Y-m-d', strtotime('-90 days')), 'purchase_price' => 109, 'tags' => '安全,应急', 'status' => 'archived', 'expiry_date' => date('Y-m-d', strtotime('+280 days')), 'purchase_from' => '线下', 'notes' => '作为价格对比记录'],
            ['name' => '车载灭火器（便携款）', 'category' => '工具五金', 'location' => '储物间', 'quantity' => 1, 'description' => '便携款采购记录', 'barcode' => 'SAFE-FIRE-MINI-03', 'purchase_date' => date('Y-m-d', strtotime('-300 days')), 'purchase_price' => 79, 'tags' => '安全,便携', 'status' => 'archived', 'expiry_date' => date('Y-m-d', strtotime('+60 days')), 'purchase_from' => '淘宝', 'notes' => '历史最低购入价记录'],
            ['name' => '设计模式（第2版）', 'category' => '书籍文档', 'subcategory' => '纸质书', 'location' => '书房', 'quantity' => 1, 'description' => '技术书籍', 'barcode' => 'BOOK-DESIGN-02', 'purchase_date' => date('Y-m-d', strtotime('-700 days')), 'purchase_price' => 88, 'tags' => '书籍,学习', 'status' => 'archived', 'expiry_date' => '', 'purchase_from' => '京东', 'notes' => '已读完，暂存书架'],
            ['name' => '纪念手表', 'category' => '电子设备', 'location' => '卧室', 'quantity' => 1, 'description' => '礼品来源物品', 'barcode' => 'GIFT-WATCH-01', 'purchase_date' => date('Y-m-d', strtotime('-95 days')), 'purchase_price' => 0, 'tags' => '礼物,收藏', 'status' => 'active', 'expiry_date' => '', 'purchase_from' => '礼品', 'notes' => '生日礼物，定期保养'],
            ['name' => '在线课程年度会员', 'category' => '虚拟产品', 'subcategory' => '会员服务', 'location' => '书房', 'quantity' => 1, 'description' => '在线学习会员服务', 'barcode' => 'VIP-COURSE-2026', 'purchase_date' => date('Y-m-d', strtotime('-20 days')), 'purchase_price' => 399, 'tags' => '会员,学习', 'status' => 'active', 'expiry_date' => date('Y-m-d', strtotime('+340 days')), 'purchase_from' => '线下', 'notes' => '到期前一个月提醒续费', 'is_public_shared' => 1, 'public_recommend_reason' => '内容更新频率高，长期学习性价比高', 'reminder_date' => date('Y-m-d', strtotime('-20 days')), 'reminder_next_date' => date('Y-m-d', strtotime('+345 days')), 'reminder_cycle_value' => 1, 'reminder_cycle_unit' => 'year', 'reminder_note' => '按开通日期每年提醒一次，建议到期前 30 天处理续费'],
            ['name' => '有机燕麦片', 'category' => '食物', 'subcategory' => '主食粮油', 'location' => '厨房', 'quantity' => 2, 'remaining_current' => 0, 'description' => '早餐常备食材', 'barcode' => 'FOOD-OAT-01', 'purchase_date' => date('Y-m-d', strtotime('-18 days')), 'purchase_price' => 45, 'tags' => '食物,早餐', 'status' => 'used_up', 'expiry_date' => date('Y-m-d', strtotime('+120 days')), 'purchase_from' => '京东', 'notes' => '已用完状态示例，用于覆盖状态筛选与余量提醒联动'],
            ['name' => '便携湿巾（家庭装）', 'category' => '其他', 'subcategory' => '日用杂项', 'location' => '玄关', 'quantity' => 6, 'remaining_total' => 0, 'description' => '常备清洁用品', 'barcode' => 'HOME-WIPE-06', 'purchase_date' => date('Y-m-d', strtotime('-8 days')), 'purchase_price' => 29, 'tags' => '清洁,日用品', 'status' => 'active', 'expiry_date' => '', 'purchase_from' => '线下', 'notes' => '用于演示“清空余量后不触发余量提醒”'],
            ['name' => '未分类收纳箱', 'category' => '', 'location' => '', 'quantity' => 2, 'description' => '暂未归类，等待整理', 'barcode' => 'BOX-UNCAT-01', 'purchase_date' => date('Y-m-d', strtotime('-15 days')), 'purchase_price' => 59, 'tags' => '收纳,未分类', 'status' => 'active', 'expiry_date' => '', 'purchase_from' => '线下', 'notes' => '暂放玄关，待统一收纳'],
        ];

        $insertItem = $db->prepare("INSERT INTO items (name, category_id, subcategory_id, location_id, quantity, remaining_current, remaining_total, description, image, barcode, purchase_date, purchase_price, tags, status, expiry_date, purchase_from, notes, is_public_shared, public_recommend_reason, reminder_date, reminder_next_date, reminder_cycle_value, reminder_cycle_unit, reminder_note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $created = 0;
        $subcategoryBoundCount = 0;
        $sharedCount = 0;
        $publicCommentCreated = 0;
        $usedUpCount = 0;
        $remainingUnsetCount = 0;
        if ($authDb && $ownerUserId > 0) {
            removePublicSharedItemsByOwner($authDb, $ownerUserId);
        }
        foreach ($demoItems as $item) {
            $categoryId = isset($catIdByName[$item['category'] ?? '']) ? intval($catIdByName[$item['category']]) : 0;
            $subcategoryId = isset($catIdByName[$item['subcategory'] ?? '']) ? intval($catIdByName[$item['subcategory']]) : 0;
            [$categoryId, $subcategoryId, $categoryError] = normalizeItemCategorySelection($db, $categoryId, $subcategoryId);
            if ($categoryError) {
                $categoryId = 0;
                $subcategoryId = 0;
            }
            $locationId = isset($locMap[$item['location'] ?? '']) ? intval($locMap[$item['location']]) : 0;
            $isPublicShared = intval($item['is_public_shared'] ?? 0) === 1 ? 1 : 0;
            $itemQty = max(0, intval($item['quantity'] ?? 1));
            $remainingUnset = array_key_exists('remaining_total', $item) && intval($item['remaining_total']) <= 0;
            if ($remainingUnset) {
                $remainingCurrent = 0;
                $remainingTotal = 0;
                $remainingUnsetCount++;
            } else {
                $remainingCurrent = max(0, intval($item['remaining_current'] ?? $itemQty));
                if ($remainingCurrent > $itemQty) {
                    $remainingCurrent = $itemQty;
                }
                $remainingTotal = $itemQty;
            }
            $itemStatus = normalizeStatusValue($item['status'] ?? 'active');
            if ($itemStatus === 'used_up') {
                $usedUpCount++;
            }
            $insertItem->execute([
                $item['name'],
                $categoryId,
                $subcategoryId,
                $locationId,
                $itemQty,
                $remainingCurrent,
                $remainingTotal,
                $item['description'] ?? '',
                '',
                $item['barcode'] ?? '',
                normalizeDateYmd($item['purchase_date'] ?? '') ?? '',
                floatval($item['purchase_price'] ?? 0),
                $item['tags'] ?? '',
                $itemStatus,
                normalizeDateYmd($item['expiry_date'] ?? '') ?? '',
                $item['purchase_from'] ?? '',
                $item['notes'] ?? '',
                $isPublicShared,
                trim((string) ($item['public_recommend_reason'] ?? '')),
                normalizeReminderDateValue($item['reminder_date'] ?? ''),
                normalizeReminderDateValue($item['reminder_next_date'] ?? ''),
                normalizeReminderCycleValue($item['reminder_cycle_value'] ?? 0, $item['reminder_cycle_unit'] ?? ''),
                normalizeReminderCycleUnit($item['reminder_cycle_unit'] ?? ''),
                trim((string) ($item['reminder_note'] ?? ''))
            ]);
            $created++;
            if ($subcategoryId > 0) {
                $subcategoryBoundCount++;
            }
            if ($authDb && $ownerUserId > 0 && $isPublicShared === 1) {
                $newItemId = intval($db->lastInsertId());
                syncPublicSharedItem($authDb, $db, $ownerUserId, $newItemId, 1);
                $sharedCount++;
            }
        }
        if ($authDb && $ownerUserId > 0 && $sharedCount > 0) {
            $shareRowsStmt = $authDb->prepare("SELECT id, item_name FROM public_shared_items WHERE owner_user_id=? ORDER BY id ASC");
            $shareRowsStmt->execute([$ownerUserId]);
            $shareRows = $shareRowsStmt->fetchAll();
            if (is_array($shareRows) && count($shareRows) > 0) {
                $shareIds = array_values(array_filter(array_map(function ($row) {
                    return intval($row['id'] ?? 0);
                }, $shareRows), function ($v) {
                    return $v > 0;
                }));
                removePublicSharedCommentsByShareIds($authDb, $shareIds);

                $adminUserId = intval($authDb->query("SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
                if ($adminUserId === $ownerUserId) {
                    $adminUserId = 0;
                }
                $insertCommentStmt = $authDb->prepare("INSERT INTO public_shared_comments (shared_id, user_id, content, created_at, updated_at)
                    VALUES (?,?,?,datetime('now','localtime'),datetime('now','localtime'))");
                foreach ($shareRows as $idx => $shareRow) {
                    $shareId = intval($shareRow['id'] ?? 0);
                    if ($shareId <= 0) {
                        continue;
                    }
                    $itemName = trim((string) ($shareRow['item_name'] ?? '该物品'));
                    if ($idx === 0) {
                        $insertCommentStmt->execute([$shareId, $ownerUserId, '这款我长期在用，稳定耐用，推荐先加入购物清单。']);
                        $publicCommentCreated++;
                        if ($adminUserId > 0) {
                            $insertCommentStmt->execute([$shareId, $adminUserId, '管理员建议：可先比价再下单，通常活动期更划算。']);
                            $publicCommentCreated++;
                        }
                    } elseif ($idx === 1) {
                        $insertCommentStmt->execute([$shareId, $ownerUserId, '我最近复购过「' . $itemName . '」，整体性价比不错。']);
                        $publicCommentCreated++;
                    }
                }
            }
        }

        // 回收站预置记录（用于验证恢复与彻底删除流程）
        $insertTrash = $db->prepare("INSERT INTO items (name, category_id, subcategory_id, location_id, quantity, remaining_current, remaining_total, description, image, barcode, purchase_date, purchase_price, tags, status, expiry_date, purchase_from, notes, reminder_date, reminder_next_date, reminder_cycle_value, reminder_cycle_unit, reminder_note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $insertTrash->execute([
            '旧数据线（待清理）',
            isset($catIdByName['电子设备']) ? intval($catIdByName['电子设备']) : 0,
            0,
            isset($locMap['电视柜']) ? intval($locMap['电视柜']) : 0,
            1,
            0,
            1,
            '已损坏，待确认是否恢复',
            '',
            'TRASH-DEMO-01',
            date('Y-m-d', strtotime('-480 days')),
            29,
            '待清理,回收站',
            'archived',
            '',
            '线下',
            '删除于昨日，保留恢复窗口',
            '',
            '',
            0,
            '',
            ''
        ]);
        $trashId = intval($db->lastInsertId());
        if ($trashId > 0) {
            $markTrash = $db->prepare("UPDATE items SET deleted_at=datetime('now','-1 day','localtime'), updated_at=datetime('now','-1 day','localtime') WHERE id=?");
            $markTrash->execute([$trashId]);
        }

        // 提醒实例：预置一条已完成 + 一条待完成
        seedReminderInstancesFromItems($db);
        $completedReminderDemoPrepared = false;
        $demoReminderItemStmt = $db->prepare("SELECT id, reminder_cycle_value, reminder_cycle_unit FROM items WHERE name=? LIMIT 1");
        $demoReminderItemStmt->execute(['空气净化器滤芯']);
        $demoReminderItem = $demoReminderItemStmt->fetch();
        if ($demoReminderItem) {
            $cycleUnit = normalizeReminderCycleUnit($demoReminderItem['reminder_cycle_unit'] ?? '');
            $cycleValue = normalizeReminderCycleValue($demoReminderItem['reminder_cycle_value'] ?? 0, $cycleUnit);
            $pendingReminderStmt = $db->prepare("SELECT id, due_date FROM item_reminder_instances WHERE item_id=? AND is_completed=0 ORDER BY due_date ASC, id ASC LIMIT 1");
            $pendingReminderStmt->execute([intval($demoReminderItem['id'])]);
            $pendingReminder = $pendingReminderStmt->fetch();
            if ($pendingReminder && $cycleUnit !== '' && $cycleValue > 0) {
                $currentDueDate = normalizeReminderDateValue($pendingReminder['due_date'] ?? '');
                $nextDueDate = calcNextReminderDate($currentDueDate, $cycleValue, $cycleUnit);
                if ($currentDueDate !== '' && $nextDueDate) {
                    $completeStmt = $db->prepare("UPDATE item_reminder_instances SET is_completed=1, completed_at=datetime('now','-2 hour','localtime'), updated_at=datetime('now','localtime') WHERE id=?");
                    $completeStmt->execute([intval($pendingReminder['id'])]);
                    $nextExistsStmt = $db->prepare("SELECT id FROM item_reminder_instances WHERE item_id=? AND due_date=? AND is_completed=0 LIMIT 1");
                    $nextExistsStmt->execute([intval($demoReminderItem['id']), $nextDueDate]);
                    if (!$nextExistsStmt->fetchColumn()) {
                        $insertNextStmt = $db->prepare("INSERT INTO item_reminder_instances (item_id, due_date, is_completed, completed_at, generated_by_complete_id, created_at, updated_at) VALUES (?,?,0,NULL,?,datetime('now','localtime'),datetime('now','localtime'))");
                        $insertNextStmt->execute([intval($demoReminderItem['id']), $nextDueDate, intval($pendingReminder['id'])]);
                    }
                    $updateNextDateStmt = $db->prepare("UPDATE items SET reminder_next_date=?, updated_at=datetime('now','localtime') WHERE id=?");
                    $updateNextDateStmt->execute([$nextDueDate, intval($demoReminderItem['id'])]);
                    $completedReminderDemoPrepared = true;
                }
            }
        }

        $demoShoppingList = [
            ['name' => '空气净化器滤芯（90天周期备用）', 'quantity' => 1, 'status' => 'pending_purchase', 'category' => '家具家居', 'priority' => 'high', 'planned_price' => 169, 'notes' => '与在用滤芯同型号，提前备货', 'reminder_date' => date('Y-m-d', strtotime('+1 day')), 'reminder_note' => '和物品里的 90 天循环提醒同步，确认活动价后下单'],
            ['name' => '维生素 D3（补充装）', 'quantity' => 2, 'status' => 'pending_receipt', 'category' => '其他', 'priority' => 'high', 'planned_price' => 128, 'notes' => '已下单待收货，收货后放入厨房抽屉', 'reminder_date' => date('Y-m-d', strtotime('-1 day')), 'reminder_note' => '到货后核对保质期'],
            ['name' => '车载灭火器（新）', 'quantity' => 1, 'status' => 'pending_purchase', 'category' => '工具五金', 'priority' => 'high', 'planned_price' => 99, 'notes' => '替换已过期的旧灭火器', 'reminder_date' => date('Y-m-d', strtotime('+2 days')), 'reminder_note' => '确认生产日期在一年内'],
            ['name' => '在线课程会员续费', 'quantity' => 1, 'status' => 'pending_purchase', 'category' => '虚拟产品', 'priority' => 'normal', 'planned_price' => 399, 'notes' => '用于演示年度会员的续费提醒流程', 'reminder_date' => date('Y-m-d', strtotime('+320 days')), 'reminder_note' => '到期前 30 天处理续费，避免中断使用'],
            ['name' => '机械键盘键帽套装', 'quantity' => 1, 'status' => 'pending_purchase', 'category' => '电子设备', 'priority' => 'low', 'planned_price' => 79, 'notes' => '给备用键盘更换键帽', 'reminder_date' => '', 'reminder_note' => ''],
        ];
        $insertShopping = $db->prepare("INSERT INTO shopping_list (name, quantity, status, category_id, priority, planned_price, notes, reminder_date, reminder_note, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,datetime('now','localtime'),datetime('now','localtime'))");
        $shoppingCreated = 0;
        foreach ($demoShoppingList as $row) {
            $categoryId = isset($catIdByName[$row['category']]) ? intval($catIdByName[$row['category']]) : 0;
            $insertShopping->execute([
                trim((string) ($row['name'] ?? '')),
                max(1, intval($row['quantity'] ?? 1)),
                normalizeShoppingStatus($row['status'] ?? 'pending_purchase'),
                $categoryId,
                normalizeShoppingPriority($row['priority'] ?? 'normal'),
                max(0, floatval($row['planned_price'] ?? 0)),
                trim((string) ($row['notes'] ?? '')),
                normalizeReminderDateValue($row['reminder_date'] ?? ''),
                trim((string) ($row['reminder_note'] ?? '')),
            ]);
            $shoppingCreated++;
        }

        $peerSharedCount = 0;
        $peerCommentCreated = 0;
        if ($authDb && $ownerUserId > 0) {
            try {
                $peerShareSeed = seedDemoPeerPublicShare($authDb, $ownerUserId);
                $peerSharedCount = max(0, intval($peerShareSeed['shared_created'] ?? 0));
                $peerCommentCreated = max(0, intval($peerShareSeed['comment_created'] ?? 0));
            } catch (Exception $e) {
                $peerSharedCount = 0;
                $peerCommentCreated = 0;
            }
        }

        $taskSeeded = 0;
        $taskCompletedSeeded = 0;
        if ($authDb && $ownerUserId > 0) {
            $ownerUsernameStmt = $authDb->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
            $ownerUsernameStmt->execute([$ownerUserId]);
            $ownerUsername = trim((string) $ownerUsernameStmt->fetchColumn());
            $taskScope = isDemoUsername($ownerUsername) ? 1 : 0;

            if ($taskScope === 1) {
                // Demo 环境每次重建任务清单，避免旧演示任务累积导致结果不稳定
                $cleanTaskStmt = $authDb->prepare("DELETE FROM message_board_posts WHERE is_demo_scope=1");
                $cleanTaskStmt->execute();
            } else {
                $cleanTaskStmt = $authDb->prepare("DELETE FROM message_board_posts WHERE user_id=? AND is_demo_scope=?");
                $cleanTaskStmt->execute([$ownerUserId, $taskScope]);
            }

            $demoTasks = [
                ['content' => '整理厨房抽屉里的即将到期食材', 'is_completed' => 0],
                ['content' => '给空气净化器滤芯下单备用件（90天周期）', 'is_completed' => 0],
                ['content' => '在菜单里切换帮助模式，确认字段问号提示可用', 'is_completed' => 1],
                ['content' => '检查“循环提醒初始日期 + 循环频率”是否正确推算下次提醒日期', 'is_completed' => 0],
                ['content' => '复核备忘提醒范围设置是否符合本周计划', 'is_completed' => 1]
            ];
            $insertTaskStmt = $authDb->prepare("INSERT INTO message_board_posts
                (user_id, content, is_demo_scope, is_completed, completed_at, created_at, updated_at)
                VALUES (?,?,?,?,?,datetime('now','localtime'),datetime('now','localtime'))");
            foreach ($demoTasks as $taskRow) {
                $isCompleted = intval($taskRow['is_completed'] ?? 0) === 1 ? 1 : 0;
                $insertTaskStmt->execute([
                    $ownerUserId,
                    trim((string) ($taskRow['content'] ?? '')),
                    $taskScope,
                    $isCompleted,
                    $isCompleted === 1 ? date('Y-m-d H:i:s') : null
                ]);
                $taskSeeded++;
                if ($isCompleted === 1) {
                    $taskCompletedSeeded++;
                }
            }
        }

        $totalSharedCount = $sharedCount + $peerSharedCount;
        $totalPublicCommentCreated = $publicCommentCreated + $peerCommentCreated;
        $seedLogs = [
            [
                'action_key' => 'items',
                'action_label' => '新增物品',
                'api' => 'items',
                'method' => 'POST',
                'details' => 'Demo 数据初始化：新增物品 ' . $created . ' 件'
                    . ($subcategoryBoundCount > 0 ? ('，其中二级分类 ' . $subcategoryBoundCount . ' 件') : '')
                    . ($usedUpCount > 0 ? ('，已用完状态 ' . $usedUpCount . ' 件') : '')
                    . ($remainingUnsetCount > 0 ? ('，余量未设置 ' . $remainingUnsetCount . ' 件') : ''),
                'created_at' => "datetime('now','-120 minutes','localtime')"
            ],
            [
                'action_key' => 'categories_update',
                'action_label' => '编辑分类',
                'api' => 'categories/update',
                'method' => 'POST',
                'details' => '分类管理：已准备一级/二级分类结构，支持树状维护',
                'created_at' => "datetime('now','-95 minutes','localtime')"
            ],
            [
                'action_key' => 'shopping_list',
                'action_label' => '新增购物清单',
                'api' => 'shopping-list',
                'method' => 'POST',
                'details' => '购物清单初始化：新增 ' . $shoppingCreated . ' 条待办',
                'created_at' => "datetime('now','-80 minutes','localtime')"
            ],
            [
                'action_key' => 'settings_dashboard_ranges',
                'action_label' => '更新仪表盘管理设置',
                'api' => 'client-event/settings.dashboard_ranges',
                'method' => 'POST',
                'details' => '仪表盘管理示例：过期提醒默认“未来60天”，备忘提醒默认“未来3天”，支持按需调整范围',
                'created_at' => "datetime('now','-75 minutes','localtime')"
            ],
            [
                'action_key' => 'settings_reminder_low_stock',
                'action_label' => '更新余量提醒阈值设置',
                'api' => 'client-event/settings.reminder_low_stock',
                'method' => 'POST',
                'details' => '提醒管理示例：余量提醒阈值设为 20%，已覆盖“余量不足自动提醒”与“余量留空不提醒”场景',
                'created_at' => "datetime('now','-73 minutes','localtime')"
            ],
            [
                'action_key' => 'settings_help_mode',
                'action_label' => '切换帮助模式',
                'api' => 'client-event/settings.help_mode',
                'method' => 'POST',
                'details' => '帮助模式示例：默认开启，字段名后的问号可直接查看用途说明',
                'created_at' => "datetime('now','-72 minutes','localtime')"
            ],
            [
                'action_key' => 'message_board',
                'action_label' => '新增任务',
                'api' => 'message-board',
                'method' => 'POST',
                'details' => '任务清单初始化：新增 ' . $taskSeeded . ' 条（待完成 ' . max(0, $taskSeeded - $taskCompletedSeeded) . ' 条，已完成 ' . $taskCompletedSeeded . ' 条）',
                'created_at' => "datetime('now','-70 minutes','localtime')"
            ],
            [
                'action_key' => 'public_channel_add_to_shopping',
                'action_label' => '公共频道加入购物清单',
                'api' => 'public-channel/add-to-shopping',
                'method' => 'POST',
                'details' => '公共频道示例：可将共享物品一键加入购物清单（含推荐理由）',
                'created_at' => "datetime('now','-55 minutes','localtime')"
            ]
        ];
        if ($totalSharedCount > 0) {
            $seedLogs[] = [
                'action_key' => 'public_channel_update',
                'action_label' => '编辑公共频道共享物品',
                'api' => 'public-channel/update',
                'method' => 'POST',
                'details' => '共享物品初始化：共 ' . $totalSharedCount . ' 条共享记录',
                'created_at' => "datetime('now','-45 minutes','localtime')"
            ];
        }
        if ($totalPublicCommentCreated > 0) {
            $seedLogs[] = [
                'action_key' => 'public_channel_comment',
                'action_label' => '发表评论',
                'api' => 'public-channel/comment',
                'method' => 'POST',
                'details' => '公共频道评论初始化：共 ' . $totalPublicCommentCreated . ' 条评论',
                'created_at' => "datetime('now','-30 minutes','localtime')"
            ];
        }
        if ($completedReminderDemoPrepared) {
            $seedLogs[] = [
                'action_key' => 'items_complete_reminder',
                'action_label' => '完成提醒',
                'api' => 'items/complete-reminder',
                'method' => 'POST',
                'details' => '循环提醒示例：已包含 1 条完成提醒并自动生成下一次提醒',
                'created_at' => "datetime('now','-20 minutes','localtime')"
            ];
        }
        if ($trashId > 0) {
            $seedLogs[] = [
                'action_key' => 'items_delete',
                'action_label' => '删除物品到回收站',
                'api' => 'items/delete',
                'method' => 'POST',
                'details' => '回收站示例：已预置 1 条可恢复记录',
                'created_at' => "datetime('now','-10 minutes','localtime')"
            ];
        }
        $operationLogSeeded = count($seedLogs);
        foreach ($seedLogs as $row) {
            $insertSql = sprintf(
                "INSERT INTO operation_logs (action_key, action_label, api, method, details, ip, created_at) VALUES (?,?,?,?,?,'127.0.0.1',%s)",
                $row['created_at']
            );
            $stmt = $db->prepare($insertSql);
            $stmt->execute([
                $row['action_key'],
                $row['action_label'],
                $row['api'],
                $row['method'],
                $row['details']
            ]);
        }

        $db->commit();
        $message = "体验数据已初始化：$created 件物品、$shoppingCreated 条购物清单已就绪";
        if ($subcategoryBoundCount > 0) {
            $message .= "，其中 $subcategoryBoundCount 件已绑定二级分类";
        }
        if ($usedUpCount > 0) {
            $message .= "，含 $usedUpCount 件“已用完”状态示例";
        }
        if ($remainingUnsetCount > 0) {
            $message .= "，含 $remainingUnsetCount 件“余量未设置”示例";
        }
        if ($totalSharedCount > 0) {
            $message .= "，含 $totalSharedCount 条公共频道共享物品";
        }
        if ($totalPublicCommentCreated > 0) {
            $message .= "，含 $totalPublicCommentCreated 条公共频道评论";
        }
        if ($peerSharedCount > 0) {
            $message .= '（含 1 条其他成员共享物品，用于权限演示）';
        }
        if ($completedReminderDemoPrepared) {
            $message .= '，含 1 条已完成提醒记录';
        }
        if ($trashId > 0) {
            $message .= '，含 1 条回收站记录';
        }
        if ($operationLogSeeded > 0) {
            $message .= '，含 ' . $operationLogSeeded . ' 条操作日志样例';
        }
        if ($taskSeeded > 0) {
            $message .= '，含 ' . $taskSeeded . ' 条任务清单示例';
        }
        return [
            'message' => $message,
            'created' => $created,
            'subcategory_bound' => $subcategoryBoundCount,
            'used_up_seeded' => $usedUpCount,
            'remaining_unset_seeded' => $remainingUnsetCount,
            'shopping_created' => $shoppingCreated,
            'shared_created' => $totalSharedCount,
            'public_comment_created' => $totalPublicCommentCreated,
            'operation_log_seeded' => $operationLogSeeded,
            'task_seeded' => $taskSeeded,
            'task_completed_seeded' => $taskCompletedSeeded,
            'owner_shared_created' => $sharedCount,
            'peer_shared_created' => $peerSharedCount,
            'completed_reminder_demo' => $completedReminderDemoPrepared,
            'trash_demo' => ($trashId > 0),
            'moved_images' => $moved
        ];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

// ============================================================
// 🌐 API 路由处理
// ============================================================
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $api = $_GET['api'];
    $method = $_SERVER['REQUEST_METHOD'];

    try {
        $authDb = getAuthDB();
        $result = ['success' => false, 'message' => '未知操作'];

        if ($api === 'auth/init') {
            $userCount = intval($authDb->query("SELECT COUNT(*) FROM users")->fetchColumn());
            $currentUser = getCurrentAuthUser($authDb);
            $securityQuestions = getSecurityQuestions();
            $allowRegistration = isPublicRegistrationEnabled($authDb);
            $result = [
                'success' => true,
                'allow_registration' => $allowRegistration,
                'has_users' => $userCount > 0,
                'needs_setup' => $userCount === 0,
                'default_admin' => [
                    'username' => DEFAULT_ADMIN_USERNAME
                ],
                'default_demo' => [
                    'username' => DEFAULT_DEMO_USERNAME
                ],
                'security_questions' => $securityQuestions,
                'authenticated' => !!$currentUser,
                'user' => $currentUser ? [
                    'id' => intval($currentUser['id']),
                    'username' => $currentUser['username'],
                    'display_name' => $currentUser['display_name'] ?: $currentUser['username'],
                    'role' => $currentUser['role'] ?? 'user',
                    'is_admin' => isAdminUser($currentUser)
                ] : null
            ];
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($api === 'auth/register') {
            if ($method !== 'POST') {
                $result = ['success' => false, 'message' => '仅支持 POST'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $username = strtolower(trim((string) ($data['username'] ?? '')));
            $password = strval($data['password'] ?? '');
            $displayName = trim((string) ($data['display_name'] ?? ''));
            $questionKey = trim((string) ($data['question_key'] ?? ''));
            $questionCustom = trim((string) ($data['question_custom'] ?? ''));
            $securityAnswer = strval($data['security_answer'] ?? '');

            if (!isPublicRegistrationEnabled($authDb)) {
                $result = ['success' => false, 'message' => '感谢关注，当前暂未开放注册功能，请稍后再试。'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
                $result = ['success' => false, 'message' => '用户名需为 3-32 位字母/数字/._-'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (strlen($password) < 6) {
                $result = ['success' => false, 'message' => '密码至少 6 位'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $questions = getSecurityQuestions();
            $questionLabel = '';
            if ($questionKey === '__custom__') {
                $questionLen = function_exists('mb_strlen') ? mb_strlen($questionCustom, 'UTF-8') : strlen($questionCustom);
                if ($questionLen < 2) {
                    $result = ['success' => false, 'message' => '请填写自定义验证问题'];
                    echo json_encode($result, JSON_UNESCAPED_UNICODE);
                    exit;
                }
                if ($questionLen > 60) {
                    $result = ['success' => false, 'message' => '自定义验证问题最多 60 字'];
                    echo json_encode($result, JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $questionLabel = $questionCustom;
            } else {
                if (!isset($questions[$questionKey])) {
                    $result = ['success' => false, 'message' => '请选择有效的验证问题'];
                    echo json_encode($result, JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $questionLabel = strval($questions[$questionKey] ?? '');
            }
            $answerLen = function_exists('mb_strlen') ? mb_strlen(trim($securityAnswer), 'UTF-8') : strlen(trim($securityAnswer));
            if ($answerLen < 1) {
                $result = ['success' => false, 'message' => '请填写验证答案'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($displayName === '') {
                $displayName = $username;
            }

            $existsStmt = $authDb->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
            $existsStmt->execute([$username]);
            if ($existsStmt->fetchColumn()) {
                $result = ['success' => false, 'message' => '用户名已存在'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $answerHash = password_hash(normalizeSecurityAnswer($securityAnswer), PASSWORD_DEFAULT);
            $ins = $authDb->prepare("INSERT INTO users (username, password_hash, display_name, role, security_question_key, security_question_label, security_answer_hash, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,datetime('now','localtime'),datetime('now','localtime'))");
            $ins->execute([$username, $hash, $displayName, 'user', $questionKey, $questionLabel, $answerHash]);
            $newId = intval($authDb->lastInsertId());

            $_SESSION['user_id'] = $newId;
            session_regenerate_id(true);
            $registerDetail = '用户名: ' . $username;
            $registerActor = ['id' => $newId, 'username' => $username, 'display_name' => $displayName, 'role' => 'user'];
            try {
                $newUserDb = getUserDB($newId);
                logUserOperation($newUserDb, 'auth_register', '注册账号', $registerDetail, 'auth/register', 'POST');
            } catch (Exception $e) {
            }
            logAdminOperation($authDb, $registerActor, 'auth_register', '注册账号', $registerDetail, 'auth/register', 'POST');
            $result = [
                'success' => true,
                'message' => '注册成功',
                'user' => ['id' => $newId, 'username' => $username, 'display_name' => $displayName, 'role' => 'user', 'is_admin' => false]
            ];
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($api === 'auth/login') {
            if ($method !== 'POST') {
                $result = ['success' => false, 'message' => '仅支持 POST'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $username = strtolower(trim((string) ($data['username'] ?? '')));
            $password = strval($data['password'] ?? '');

            $stmt = $authDb->prepare("SELECT id, username, password_hash, display_name, role FROM users WHERE username=? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($password, strval($user['password_hash'] ?? ''))) {
                $result = ['success' => false, 'message' => '用户名或密码错误'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }

            $_SESSION['user_id'] = intval($user['id']);
            session_regenerate_id(true);
            $up = $authDb->prepare("UPDATE users SET last_login_at=datetime('now','localtime'), updated_at=datetime('now','localtime') WHERE id=?");
            $up->execute([intval($user['id'])]);
            try {
                $loginDb = getUserDB(intval($user['id']));
                logUserOperation($loginDb, 'auth_login', '登录系统', '', 'auth/login', 'POST');
            } catch (Exception $e) {
            }
            logAdminOperation($authDb, $user, 'auth_login', '登录系统', '', 'auth/login', 'POST');
            $result = [
                'success' => true,
                'message' => '登录成功',
                'user' => [
                    'id' => intval($user['id']),
                    'username' => $user['username'],
                    'display_name' => ($user['display_name'] ?: $user['username']),
                    'role' => ($user['role'] ?: 'user'),
                    'is_admin' => (($user['role'] ?? 'user') === 'admin')
                ]
            ];
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($api === 'auth/demo-login') {
            if ($method !== 'POST') {
                $result = ['success' => false, 'message' => '仅支持 POST'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }

            $demoUsername = strtolower(DEFAULT_DEMO_USERNAME);
            $demoPassword = DEFAULT_DEMO_PASSWORD;
            $demoDisplayName = '测试用户';
            $demoQuestionKey = '__custom__';
            $demoQuestionLabel = '你最常用的收纳位置是？';

            $findStmt = $authDb->prepare("SELECT id, username, display_name, role FROM users WHERE username=? LIMIT 1");
            $findStmt->execute([$demoUsername]);
            $demoUser = $findStmt->fetch();
            $demoId = intval($demoUser['id'] ?? 0);
            if ($demoId <= 0) {
                $ins = $authDb->prepare("INSERT INTO users (username, password_hash, display_name, role, security_question_key, security_question_label, security_answer_hash, created_at, updated_at)
                    VALUES (?,?,?,?,?,?,?,datetime('now','localtime'),datetime('now','localtime'))");
                $ins->execute([
                    $demoUsername,
                    password_hash($demoPassword, PASSWORD_DEFAULT),
                    $demoDisplayName,
                    'user',
                    $demoQuestionKey,
                    $demoQuestionLabel,
                    password_hash(normalizeSecurityAnswer('test'), PASSWORD_DEFAULT)
                ]);
                $demoId = intval($authDb->lastInsertId());
            } else {
                $syncStmt = $authDb->prepare("UPDATE users SET password_hash=?, display_name=?, role='user', security_question_key=?, security_question_label=?, security_answer_hash=?, updated_at=datetime('now','localtime') WHERE id=?");
                $syncStmt->execute([
                    password_hash($demoPassword, PASSWORD_DEFAULT),
                    $demoDisplayName,
                    $demoQuestionKey,
                    $demoQuestionLabel,
                    password_hash(normalizeSecurityAnswer('test'), PASSWORD_DEFAULT),
                    $demoId
                ]);
            }

            $demoDb = getUserDB($demoId);
            $demoLoad = loadDemoDataIntoDb($demoDb, ['move_images' => true, 'auth_db' => $authDb, 'owner_user_id' => $demoId]);

            $_SESSION['user_id'] = $demoId;
            session_regenerate_id(true);
            $up = $authDb->prepare("UPDATE users SET last_login_at=datetime('now','localtime'), updated_at=datetime('now','localtime') WHERE id=?");
            $up->execute([$demoId]);
            $demoDetail = trim((string) ($demoLoad['message'] ?? ''));
            $demoActor = ['id' => $demoId, 'username' => $demoUsername, 'display_name' => $demoDisplayName, 'role' => 'user'];
            logUserOperation($demoDb, 'auth_demo_login', '进入 Demo 环境', $demoDetail, 'auth/demo-login', 'POST');
            logAdminOperation($authDb, $demoActor, 'auth_demo_login', '进入 Demo 环境', $demoDetail, 'auth/demo-login', 'POST');

            $result = [
                'success' => true,
                'message' => '已进入 Demo 环境（数据已重置）',
                'demo' => $demoLoad,
                'user' => [
                    'id' => $demoId,
                    'username' => $demoUsername,
                    'display_name' => $demoDisplayName,
                    'role' => 'user',
                    'is_admin' => false
                ]
            ];
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($api === 'auth/logout') {
            $logoutUid = getCurrentUserId();
            $logoutActor = null;
            if ($logoutUid > 0) {
                try {
                    $stmtLogoutUser = $authDb->prepare("SELECT id, username, display_name, role FROM users WHERE id=? LIMIT 1");
                    $stmtLogoutUser->execute([$logoutUid]);
                    $logoutActor = $stmtLogoutUser->fetch();
                } catch (Exception $e) {
                    $logoutActor = null;
                }
            }
            if ($logoutUid > 0) {
                try {
                    $logoutDb = getUserDB($logoutUid);
                    logUserOperation($logoutDb, 'auth_logout', '退出登录', '', 'auth/logout', 'POST');
                } catch (Exception $e) {
                }
                logAdminOperation($authDb, $logoutActor ?: ['id' => $logoutUid], 'auth_logout', '退出登录', '', 'auth/logout', 'POST');
            }
            unset($_SESSION['user_id']);
            session_regenerate_id(true);
            $result = ['success' => true, 'message' => '已退出登录'];
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($api === 'auth/me') {
            $currentUser = getCurrentAuthUser($authDb);
            if (!$currentUser) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => '未登录', 'code' => 'AUTH_REQUIRED'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $result = [
                'success' => true,
                'user' => [
                    'id' => intval($currentUser['id']),
                    'username' => $currentUser['username'],
                    'display_name' => $currentUser['display_name'] ?: $currentUser['username'],
                    'role' => $currentUser['role'] ?? 'user',
                    'is_admin' => isAdminUser($currentUser)
                ]
            ];
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($api === 'auth/get-reset-question') {
            $username = strtolower(trim((string) ($_GET['username'] ?? '')));
            if ($username === '') {
                $result = ['success' => false, 'message' => '请输入用户名'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $stmt = $authDb->prepare("SELECT security_question_key, security_question_label FROM users WHERE username=? LIMIT 1");
            $stmt->execute([$username]);
            $row = $stmt->fetch();
            if (!$row) {
                $result = ['success' => false, 'message' => '用户不存在'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $questions = getSecurityQuestions();
            $key = trim((string) ($row['security_question_key'] ?? ''));
            $storedLabel = trim((string) ($row['security_question_label'] ?? ''));
            $label = $storedLabel;
            if ($label === '' && $key !== '' && isset($questions[$key])) {
                $label = strval($questions[$key]);
            }
            if ($key === '' || $label === '') {
                $result = ['success' => false, 'message' => '该账号未设置验证问题'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $result = ['success' => true, 'question_key' => $key, 'question_label' => $label];
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($api === 'auth/reset-password-by-question') {
            if ($method !== 'POST') {
                $result = ['success' => false, 'message' => '仅支持 POST'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $username = strtolower(trim((string) ($data['username'] ?? '')));
            $answer = strval($data['security_answer'] ?? '');
            $newPassword = strval($data['new_password'] ?? '');
            if ($username === '' || $answer === '' || $newPassword === '') {
                $result = ['success' => false, 'message' => '请完整填写重置信息'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (strlen($newPassword) < 6) {
                $result = ['success' => false, 'message' => '新密码至少 6 位'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $stmt = $authDb->prepare("SELECT id, security_answer_hash FROM users WHERE username=? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if (!$user || !password_verify(normalizeSecurityAnswer($answer), strval($user['security_answer_hash'] ?? ''))) {
                $result = ['success' => false, 'message' => '验证答案错误'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $up = $authDb->prepare("UPDATE users SET password_hash=?, updated_at=datetime('now','localtime') WHERE id=?");
            $up->execute([password_hash($newPassword, PASSWORD_DEFAULT), intval($user['id'])]);
            $result = ['success' => true, 'message' => '密码已重置，请使用新密码登录'];
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $currentUser = getCurrentAuthUser($authDb);
        if (!$currentUser) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => '请先登录', 'code' => 'AUTH_REQUIRED'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $currentUserIsDemoScope = isDemoUser($currentUser);

        if ($api === 'auth/users') {
            if (!isAdminUser($currentUser)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => '仅管理员可访问', 'code' => 'ADMIN_REQUIRED'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $rows = $authDb->query("SELECT id, username, display_name, role, created_at, updated_at, last_login_at FROM users ORDER BY CASE role WHEN 'admin' THEN 0 ELSE 1 END, id ASC")->fetchAll();
            $users = [];
            foreach ($rows as $row) {
                $stats = getUserItemStats(intval($row['id']));
                $logCount = getUserOperationLogCount(intval($row['id']));
                $users[] = [
                    'id' => intval($row['id']),
                    'username' => $row['username'],
                    'display_name' => $row['display_name'] ?: $row['username'],
                    'role' => $row['role'] ?: 'user',
                    'is_admin' => (($row['role'] ?? 'user') === 'admin'),
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                    'last_login_at' => $row['last_login_at'],
                    'item_kinds' => intval($stats['item_kinds'] ?? 0),
                    'item_qty' => intval($stats['item_qty'] ?? 0),
                    'last_item_at' => $stats['last_item_at'] ?? null,
                    'operation_log_count' => $logCount
                ];
            }
            $result = ['success' => true, 'data' => $users];
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($api === 'auth/admin-reset-password') {
            if (!isAdminUser($currentUser)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => '仅管理员可操作', 'code' => 'ADMIN_REQUIRED'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($method !== 'POST') {
                $result = ['success' => false, 'message' => '仅支持 POST'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $targetId = intval($data['user_id'] ?? 0);
            $newPassword = strval($data['new_password'] ?? '');
            if ($targetId <= 0 || strlen($newPassword) < 6) {
                $result = ['success' => false, 'message' => '参数无效（密码至少 6 位）'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $existsStmt = $authDb->prepare("SELECT id, username, role FROM users WHERE id=? LIMIT 1");
            $existsStmt->execute([$targetId]);
            $targetUser = $existsStmt->fetch();
            if (!$targetUser) {
                $result = ['success' => false, 'message' => '用户不存在'];
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $up = $authDb->prepare("UPDATE users SET password_hash=?, updated_at=datetime('now','localtime') WHERE id=?");
            $up->execute([password_hash($newPassword, PASSWORD_DEFAULT), $targetId]);
            $resetDetail = '目标用户: ' . trim((string) ($targetUser['username'] ?? ('#' . $targetId))) . '（ID:' . $targetId . '）';
            try {
                $adminDb = getUserDB(intval($currentUser['id']));
                logUserOperation($adminDb, 'auth_admin_reset_password', '管理员重置用户密码', $resetDetail, 'auth/admin-reset-password', 'POST');
            } catch (Exception $e) {
            }
            logAdminOperation($authDb, $currentUser, 'auth_admin_reset_password', '管理员重置用户密码', $resetDetail, 'auth/admin-reset-password', 'POST');
            $result = ['success' => true, 'message' => "已重置用户 {$targetUser['username']} 的密码"];
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }
        $db = getUserDB(intval($currentUser['id']));
        $operationDetails = '';

        switch ($api) {
            // ---------- 仪表盘 ----------
            case 'dashboard':
                $parseDashboardRange = function ($key, $defaultValue = null) {
                    if (!array_key_exists($key, $_GET)) {
                        return $defaultValue;
                    }
                    $raw = trim((string) ($_GET[$key] ?? ''));
                    if ($raw === '') {
                        return null;
                    }
                    if (!preg_match('/^-?\d+$/', $raw)) {
                        return $defaultValue;
                    }
                    $value = intval($raw);
                    return $value < 0 ? 0 : $value;
                };
                $expiryPastDays = $parseDashboardRange('expiry_past_days', null);
                $expiryFutureDays = $parseDashboardRange('expiry_future_days', 60);
                $reminderPastDays = $parseDashboardRange('reminder_past_days', null);
                $reminderFutureDays = $parseDashboardRange('reminder_future_days', 3);
                $parseThresholdPercent = function ($key, $defaultValue = 20) {
                    if (!array_key_exists($key, $_GET)) {
                        return $defaultValue;
                    }
                    $raw = trim((string) ($_GET[$key] ?? ''));
                    if ($raw === '') {
                        return $defaultValue;
                    }
                    if (!preg_match('/^\d+$/', $raw)) {
                        return $defaultValue;
                    }
                    $value = intval($raw);
                    if ($value < 0)
                        $value = 0;
                    if ($value > 100)
                        $value = 100;
                    return $value;
                };
                $lowStockThresholdPct = $parseThresholdPercent('low_stock_threshold_pct', 20);

                $totalItems = $db->query("SELECT COALESCE(SUM(quantity),0) FROM items WHERE deleted_at IS NULL")->fetchColumn();
                $totalKinds = $db->query("SELECT COUNT(*) FROM items WHERE deleted_at IS NULL")->fetchColumn();
                $totalCategories = $db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
                $totalLocations = $db->query("SELECT COUNT(*) FROM locations")->fetchColumn();
                $totalValue = $db->query("SELECT COALESCE(SUM(purchase_price * quantity),0) FROM items WHERE deleted_at IS NULL")->fetchColumn();
                $recentItems = $db->query("SELECT i.*, c.name as category_name, c.icon as category_icon, sc.name as subcategory_name, sc.icon as subcategory_icon, l.name as location_name FROM items i LEFT JOIN categories c ON i.category_id=c.id LEFT JOIN categories sc ON i.subcategory_id=sc.id LEFT JOIN locations l ON i.location_id=l.id WHERE i.deleted_at IS NULL ORDER BY i.updated_at DESC LIMIT 8")->fetchAll();
                $categoryStats = $db->query("SELECT c.name, c.icon, c.color, COUNT(i.id) as count, COALESCE(SUM(i.quantity),0) as total_qty FROM categories c LEFT JOIN items i ON c.id=i.category_id AND i.deleted_at IS NULL AND i.status='active' GROUP BY c.id ORDER BY count DESC")->fetchAll();
                $statusStats = $db->query("SELECT status, COUNT(*) as count, COALESCE(SUM(quantity),0) as total_qty FROM items WHERE deleted_at IS NULL GROUP BY status ORDER BY total_qty DESC")->fetchAll();
                $uncategorizedQty = $db->query("SELECT COALESCE(SUM(i.quantity),0) FROM items i LEFT JOIN categories c ON i.category_id=c.id WHERE i.deleted_at IS NULL AND i.status='active' AND (i.category_id=0 OR c.id IS NULL)")->fetchColumn();
                $expiryWhere = [
                    "i.deleted_at IS NULL",
                    "i.expiry_date != ''",
                    "i.expiry_date IS NOT NULL"
                ];
                if ($expiryPastDays !== null) {
                    $expiryWhere[] = "i.expiry_date >= date('now','-" . intval($expiryPastDays) . " day','localtime')";
                }
                if ($expiryFutureDays !== null) {
                    $expiryWhere[] = "i.expiry_date <= date('now','+" . intval($expiryFutureDays) . " day','localtime')";
                }
                $expiringItemsSql = "SELECT i.*, c.name as category_name, c.icon as category_icon, sc.name as subcategory_name, sc.icon as subcategory_icon, l.name as location_name
                    FROM items i
                    LEFT JOIN categories c ON i.category_id=c.id
                    LEFT JOIN categories sc ON i.subcategory_id=sc.id
                    LEFT JOIN locations l ON i.location_id=l.id
                    WHERE " . implode(' AND ', $expiryWhere) . "
                    ORDER BY i.expiry_date ASC
                    LIMIT 10";
                $expiringItems = $db->query($expiringItemsSql)->fetchAll();
                seedReminderInstancesFromItems($db);
                $reminderWhere = [
                    "i.deleted_at IS NULL",
                    "r.due_date != ''",
                    "r.due_date IS NOT NULL"
                ];
                if ($reminderPastDays !== null) {
                    $reminderWhere[] = "r.due_date >= date('now','-" . intval($reminderPastDays) . " day','localtime')";
                }
                if ($reminderFutureDays !== null) {
                    $reminderWhere[] = "r.due_date <= date('now','+" . intval($reminderFutureDays) . " day','localtime')";
                }
                $reminderItemsSql = "SELECT
                        r.id as reminder_instance_id,
                        r.due_date as reminder_due_date,
                        COALESCE(r.is_completed,0) as reminder_completed,
                        r.generated_by_complete_id as reminder_generated_by,
                        i.*,
                        c.name as category_name,
                        c.icon as category_icon,
                        sc.name as subcategory_name,
                        sc.icon as subcategory_icon,
                        l.name as location_name
                    FROM item_reminder_instances r
                    INNER JOIN items i ON i.id=r.item_id
                    LEFT JOIN categories c ON i.category_id=c.id
                    LEFT JOIN categories sc ON i.subcategory_id=sc.id
                    LEFT JOIN locations l ON i.location_id=l.id
                    WHERE " . implode(' AND ', $reminderWhere) . "
                    ORDER BY r.due_date ASC, r.is_completed ASC, r.id ASC
                    LIMIT 20";
                $reminderItems = $db->query($reminderItemsSql)->fetchAll();
                $shoppingReminderWhere = [
                    "s.reminder_date != ''",
                    "s.reminder_date IS NOT NULL"
                ];
                if ($reminderPastDays !== null) {
                    $shoppingReminderWhere[] = "s.reminder_date >= date('now','-" . intval($reminderPastDays) . " day','localtime')";
                }
                if ($reminderFutureDays !== null) {
                    $shoppingReminderWhere[] = "s.reminder_date <= date('now','+" . intval($reminderFutureDays) . " day','localtime')";
                }
                $shoppingReminderSql = "SELECT s.*, c.name as category_name, c.icon as category_icon, c.color as category_color
                    FROM shopping_list s
                    LEFT JOIN categories c ON s.category_id=c.id
                    WHERE " . implode(' AND ', $shoppingReminderWhere) . "
                    ORDER BY s.reminder_date ASC
                    LIMIT 10";
                $shoppingReminderItems = $db->query($shoppingReminderSql)->fetchAll();
                $lowStockReminderItems = [];
                if ($lowStockThresholdPct > 0) {
                    $stockTotalExpr = "CASE WHEN COALESCE(i.remaining_total,0) > 0 THEN i.remaining_total ELSE i.quantity END";
                    $stockCurrentExpr = "CASE WHEN COALESCE(i.remaining_total,0) > 0 THEN i.remaining_current ELSE i.quantity END";
                    $lowStockSql = "SELECT
                            i.*,
                            c.name as category_name,
                            c.icon as category_icon,
                            sc.name as subcategory_name,
                            sc.icon as subcategory_icon,
                            l.name as location_name,
                            $stockCurrentExpr as stock_current,
                            $stockTotalExpr as stock_total
                        FROM items i
                        LEFT JOIN categories c ON i.category_id=c.id
                        LEFT JOIN categories sc ON i.subcategory_id=sc.id
                        LEFT JOIN locations l ON i.location_id=l.id
                        WHERE i.deleted_at IS NULL
                          AND i.status IN ('active','used_up')
                          AND ($stockTotalExpr) > 0
                          AND ($stockCurrentExpr) >= 0
                          AND (($stockCurrentExpr) * 100) < (($stockTotalExpr) * ?)
                        ORDER BY (CAST($stockCurrentExpr AS REAL) / CAST($stockTotalExpr AS REAL)) ASC, i.updated_at DESC
                        LIMIT 20";
                    $lowStockStmt = $db->prepare($lowStockSql);
                    $lowStockStmt->execute([$lowStockThresholdPct]);
                    $today = date('Y-m-d');
                    $lowStockRows = $lowStockStmt->fetchAll();
                    foreach ($lowStockRows as $row) {
                        $stockTotal = max(0, intval($row['stock_total'] ?? 0));
                        $stockCurrent = max(0, intval($row['stock_current'] ?? 0));
                        if ($stockTotal <= 0) {
                            continue;
                        }
                        if ($stockCurrent > $stockTotal) {
                            $stockCurrent = $stockTotal;
                        }
                        $ratioPct = intval(floor(($stockCurrent * 100) / $stockTotal));
                        $row['stock_total'] = $stockTotal;
                        $row['stock_current'] = $stockCurrent;
                        $row['low_stock_ratio_pct'] = $ratioPct;
                        $row['low_stock_threshold_pct'] = $lowStockThresholdPct;
                        $row['reminder_due_date'] = $today;
                        $row['reminder_note'] = '当前余量 ' . $stockCurrent . '/' . $stockTotal . '（' . $ratioPct . '%），低于阈值 ' . $lowStockThresholdPct . '%，建议补货';
                        $lowStockReminderItems[] = $row;
                    }
                }
                $messageBoardStmt = $authDb->prepare("SELECT
                        m.id,
                        m.user_id,
                        m.content,
                        COALESCE(m.is_completed,0) as is_completed,
                        m.completed_at,
                        m.created_at,
                        m.updated_at,
                        u.username,
                        u.display_name
                    FROM message_board_posts m
                    LEFT JOIN users u ON u.id=m.user_id
                    WHERE m.is_demo_scope=?
                      AND COALESCE(m.is_completed,0)=0
                    ORDER BY m.created_at DESC, m.id DESC
                    LIMIT 6");
                $messageBoardStmt->execute([$currentUserIsDemoScope ? 1 : 0]);
                $messageBoardRows = $messageBoardStmt->fetchAll();
                $messageBoardPosts = [];
                foreach ($messageBoardRows as $row) {
                    $author = trim((string) ($row['display_name'] ?? ''));
                    if ($author === '') {
                        $author = trim((string) ($row['username'] ?? ''));
                    }
                    if ($author === '') {
                        $author = '用户#' . intval($row['user_id'] ?? 0);
                    }
                    $messageBoardPosts[] = [
                        'id' => intval($row['id'] ?? 0),
                        'user_id' => intval($row['user_id'] ?? 0),
                        'author_name' => $author,
                        'content' => trim((string) ($row['content'] ?? '')),
                        'is_completed' => intval($row['is_completed'] ?? 0) === 1 ? 1 : 0,
                        'completed_at' => trim((string) ($row['completed_at'] ?? '')),
                        'created_at' => trim((string) ($row['created_at'] ?? '')),
                        'updated_at' => trim((string) ($row['updated_at'] ?? '')),
                        'can_edit' => (
                            intval($row['user_id'] ?? 0) === intval($currentUser['id'])
                            || isAdminUser($currentUser)
                        ),
                        'can_delete' => (
                            intval($row['user_id'] ?? 0) === intval($currentUser['id'])
                            || isAdminUser($currentUser)
                        )
                    ];
                }
                $result = ['success' => true, 'data' => compact('totalItems', 'totalKinds', 'totalCategories', 'totalLocations', 'totalValue', 'recentItems', 'categoryStats', 'statusStats', 'uncategorizedQty', 'expiringItems', 'reminderItems', 'shoppingReminderItems', 'lowStockReminderItems', 'lowStockThresholdPct', 'messageBoardPosts')];
                break;

            // ---------- 物品 CRUD ----------
            case 'items':
                if ($method === 'GET') {
                    $page = max(1, intval($_GET['page'] ?? 1));
                    $limit = max(1, min(100, intval($_GET['limit'] ?? 24)));
                    $offset = ($page - 1) * $limit;
                    $search = trim($_GET['search'] ?? '');
                    $category = intval($_GET['category'] ?? 0);
                    $location = intval($_GET['location'] ?? 0);
                    $status = trim($_GET['status'] ?? '');
                    $expiryOnly = intval($_GET['expiry_only'] ?? 0);
                    $sort = $_GET['sort'] ?? 'updated_at';
                    $order = ($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

                    $where = ['i.deleted_at IS NULL'];
                    $params = [];
                    if ($search) {
                        $where[] = "(
                            i.name LIKE ?
                            OR i.description LIKE ?
                            OR i.tags LIKE ?
                            OR i.barcode LIKE ?
                            OR i.purchase_from LIKE ?
                            OR i.notes LIKE ?
                            OR i.purchase_date LIKE ?
                            OR i.expiry_date LIKE ?
                            OR i.reminder_date LIKE ?
                            OR i.reminder_next_date LIKE ?
                            OR CAST(i.reminder_cycle_value AS TEXT) LIKE ?
                            OR i.reminder_cycle_unit LIKE ?
                            OR (CASE i.reminder_cycle_unit WHEN 'day' THEN '天' WHEN 'week' THEN '周' WHEN 'year' THEN '年' ELSE '' END) LIKE ?
                            OR i.reminder_note LIKE ?
                            OR CAST(i.quantity AS TEXT) LIKE ?
                            OR CAST(i.purchase_price AS TEXT) LIKE ?
                            OR c.name LIKE ?
                            OR sc.name LIKE ?
                            OR l.name LIKE ?
                            OR i.status LIKE ?
                            OR (CASE i.status WHEN 'active' THEN '使用中' WHEN 'archived' THEN '已归档' WHEN 'sold' THEN '已转卖' WHEN 'used_up' THEN '已用完' ELSE i.status END) LIKE ?
                        )";
                        $s = "%$search%";
                        $params = array_merge($params, [$s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s]);
                    }
                    if ($category !== 0) {
                        if ($category === -1) {
                            $where[] = "(i.category_id=0 OR c.id IS NULL)";
                        } else {
                            $catTypeStmt = $db->prepare("SELECT parent_id FROM categories WHERE id=? LIMIT 1");
                            $catTypeStmt->execute([$category]);
                            $catParentId = intval($catTypeStmt->fetchColumn() ?: 0);
                            if ($catParentId > 0) {
                                $where[] = "i.subcategory_id = ?";
                                $params[] = $category;
                            } else {
                                $where[] = "i.category_id = ?";
                                $params[] = $category;
                            }
                        }
                    }
                    if ($location !== 0) {
                        if ($location === -1) {
                            $where[] = "(i.location_id=0 OR l.id IS NULL)";
                        } else {
                            $where[] = "i.location_id = ?";
                            $params[] = $location;
                        }
                    }
                    if ($status) {
                        $where[] = "i.status = ?";
                        $params[] = $status;
                    }
                    if ($expiryOnly) {
                        $where[] = "i.expiry_date IS NOT NULL AND i.expiry_date != ''";
                    }

                    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
                    $allowedSort = ['name', 'quantity', 'purchase_price', 'created_at', 'updated_at', 'expiry_date'];
                    $sortCol = in_array($sort, $allowedSort) ? $sort : 'updated_at';

                    $countStmt = $db->prepare("SELECT COUNT(*)
                        FROM items i
                        LEFT JOIN categories c ON i.category_id=c.id
                        LEFT JOIN categories sc ON i.subcategory_id=sc.id
                        LEFT JOIN locations l ON i.location_id=l.id
                        $whereSQL");
                    $countStmt->execute($params);
                    $total = $countStmt->fetchColumn();

                    $orderBy = "i.$sortCol $order";
                    if ($sortCol === 'expiry_date') {
                        // 过期日期排序时，把未设置日期的记录放到最后
                        $orderBy = "(i.expiry_date='' OR i.expiry_date IS NULL) ASC, i.expiry_date $order";
                    }

                    $stmt = $db->prepare("SELECT
                            i.*,
                            c.name as category_name,
                            c.icon as category_icon,
                            c.color as category_color,
                            sc.name as subcategory_name,
                            sc.icon as subcategory_icon,
                            l.name as location_name
                        FROM items i
                        LEFT JOIN categories c ON i.category_id=c.id
                        LEFT JOIN categories sc ON i.subcategory_id=sc.id
                        LEFT JOIN locations l ON i.location_id=l.id
                        $whereSQL
                        ORDER BY $orderBy LIMIT $limit OFFSET $offset");
                    $stmt->execute($params);
                    $items = $stmt->fetchAll();

                    $result = ['success' => true, 'data' => $items, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $limit)];
                } elseif ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    if (empty($data['name'])) {
                        $result = ['success' => false, 'message' => '物品名称不能为空'];
                        break;
                    }
                    $reminderDate = normalizeReminderDateValue($data['reminder_date'] ?? '');
                    $reminderNextDate = normalizeReminderDateValue($data['reminder_next_date'] ?? '');
                    $reminderUnit = normalizeReminderCycleUnit($data['reminder_cycle_unit'] ?? '');
                    $reminderValue = normalizeReminderCycleValue($data['reminder_cycle_value'] ?? 0, $reminderUnit);
                    if ($reminderDate === '' || $reminderUnit === '' || $reminderValue <= 0) {
                        $reminderDate = '';
                        $reminderNextDate = '';
                        $reminderUnit = '';
                        $reminderValue = 0;
                    } elseif ($reminderNextDate === '') {
                        $reminderNextDate = $reminderDate;
                    }
                    $reminderNote = trim((string) ($data['reminder_note'] ?? ''));
                    $shareFlag = intval($data['is_public_shared'] ?? 0) === 1 ? 1 : 0;
                    $itemQty = max(0, intval($data['quantity'] ?? 1));
                    $remainingFlag = max(0, intval($data['remaining_total'] ?? 0));
                    if ($remainingFlag <= 0) {
                        $remainingCurrent = 0;
                        $remainingTotal = 0;
                    } else {
                        [$remainingCurrent, $remainingTotal, $remainingError] = normalizeRemainingPair($data['remaining_current'] ?? 0, $itemQty);
                        if ($remainingError) {
                            $result = ['success' => false, 'message' => $remainingError];
                            break;
                        }
                    }
                    [$categoryId, $subcategoryId, $categoryError] = normalizeItemCategorySelection($db, intval($data['category_id'] ?? 0), intval($data['subcategory_id'] ?? 0));
                    if ($categoryError) {
                        $result = ['success' => false, 'message' => $categoryError];
                        break;
                    }
                    $stmt = $db->prepare("INSERT INTO items (name, category_id, subcategory_id, location_id, quantity, remaining_current, remaining_total, description, image, barcode, purchase_date, purchase_price, tags, status, expiry_date, purchase_from, notes, is_public_shared, reminder_date, reminder_next_date, reminder_cycle_value, reminder_cycle_unit, reminder_note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([
                        $data['name'],
                        $categoryId,
                        $subcategoryId,
                        intval($data['location_id'] ?? 0),
                        $itemQty,
                        $remainingCurrent,
                        $remainingTotal,
                        $data['description'] ?? '',
                        $data['image'] ?? '',
                        $data['barcode'] ?? '',
                        $data['purchase_date'] ?? '',
                        floatval($data['purchase_price'] ?? 0),
                        $data['tags'] ?? '',
                        normalizeStatusValue($data['status'] ?? 'active'),
                        $data['expiry_date'] ?? '',
                        $data['purchase_from'] ?? '',
                        $data['notes'] ?? '',
                        $shareFlag,
                        $reminderDate,
                        $reminderNextDate,
                        $reminderValue,
                        $reminderUnit,
                        $reminderNote
                    ]);
                    $newItemId = intval($db->lastInsertId());
                    syncItemReminderInstances($db, $newItemId, $reminderDate, $reminderNextDate, $reminderValue, $reminderUnit);
                    syncPublicSharedItem($authDb, $db, intval($currentUser['id']), $newItemId, $shareFlag);
                    $itemName = trim((string) ($data['name'] ?? ''));
                    $operationDetails = '物品: ' . $itemName . '（ID:' . $newItemId . '）' . '；件数: ' . $itemQty;
                    if ($remainingTotal > 0 || $remainingCurrent > 0) {
                        $operationDetails .= '；余量: ' . $remainingCurrent . '/' . $remainingTotal;
                    }
                    if ($categoryId > 0) {
                        $catName = trim((string) ($db->query("SELECT name FROM categories WHERE id=" . intval($categoryId) . " LIMIT 1")->fetchColumn() ?: ''));
                        if ($catName !== '') {
                            $operationDetails .= '；一级分类: ' . $catName;
                        }
                    }
                    if ($subcategoryId > 0) {
                        $subName = trim((string) ($db->query("SELECT name FROM categories WHERE id=" . intval($subcategoryId) . " LIMIT 1")->fetchColumn() ?: ''));
                        if ($subName !== '') {
                            $operationDetails .= '；二级分类: ' . $subName;
                        }
                    }
                    $locId = intval($data['location_id'] ?? 0);
                    if ($locId > 0) {
                        $locName = trim((string) ($db->query("SELECT name FROM locations WHERE id=" . $locId . " LIMIT 1")->fetchColumn() ?: ''));
                        if ($locName !== '') {
                            $operationDetails .= '；位置: ' . $locName;
                        }
                    }
                    if ($shareFlag === 1) {
                        $operationDetails .= '；已共享到公共频道';
                    }
                    $result = ['success' => true, 'message' => '添加成功', 'id' => $newItemId];
                }
                break;

            case 'items/update':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    if (empty($data['id'])) {
                        $result = ['success' => false, 'message' => '缺少物品ID'];
                        break;
                    }
                    $reminderDate = normalizeReminderDateValue($data['reminder_date'] ?? '');
                    $reminderNextDate = normalizeReminderDateValue($data['reminder_next_date'] ?? '');
                    $reminderUnit = normalizeReminderCycleUnit($data['reminder_cycle_unit'] ?? '');
                    $reminderValue = normalizeReminderCycleValue($data['reminder_cycle_value'] ?? 0, $reminderUnit);
                    if ($reminderDate === '' || $reminderUnit === '' || $reminderValue <= 0) {
                        $reminderDate = '';
                        $reminderNextDate = '';
                        $reminderUnit = '';
                        $reminderValue = 0;
                    } elseif ($reminderNextDate === '') {
                        $reminderNextDate = $reminderDate;
                    }
                    $reminderNote = trim((string) ($data['reminder_note'] ?? ''));
                    $shareFlag = intval($data['is_public_shared'] ?? 0) === 1 ? 1 : 0;
                    $itemQty = max(0, intval($data['quantity'] ?? 1));
                    $remainingFlag = max(0, intval($data['remaining_total'] ?? 0));
                    if ($remainingFlag <= 0) {
                        $remainingCurrent = 0;
                        $remainingTotal = 0;
                    } else {
                        [$remainingCurrent, $remainingTotal, $remainingError] = normalizeRemainingPair($data['remaining_current'] ?? 0, $itemQty);
                        if ($remainingError) {
                            $result = ['success' => false, 'message' => $remainingError];
                            break;
                        }
                    }
                    [$categoryId, $subcategoryId, $categoryError] = normalizeItemCategorySelection($db, intval($data['category_id'] ?? 0), intval($data['subcategory_id'] ?? 0));
                    if ($categoryError) {
                        $result = ['success' => false, 'message' => $categoryError];
                        break;
                    }
                    $stmt = $db->prepare("UPDATE items SET name=?, category_id=?, subcategory_id=?, location_id=?, quantity=?, remaining_current=?, remaining_total=?, description=?, image=?, barcode=?, purchase_date=?, purchase_price=?, tags=?, status=?, expiry_date=?, purchase_from=?, notes=?, is_public_shared=?, reminder_date=?, reminder_next_date=?, reminder_cycle_value=?, reminder_cycle_unit=?, reminder_note=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
                    $stmt->execute([
                        $data['name'],
                        $categoryId,
                        $subcategoryId,
                        intval($data['location_id'] ?? 0),
                        $itemQty,
                        $remainingCurrent,
                        $remainingTotal,
                        $data['description'] ?? '',
                        $data['image'] ?? '',
                        $data['barcode'] ?? '',
                        $data['purchase_date'] ?? '',
                        floatval($data['purchase_price'] ?? 0),
                        $data['tags'] ?? '',
                        normalizeStatusValue($data['status'] ?? 'active'),
                        $data['expiry_date'] ?? '',
                        $data['purchase_from'] ?? '',
                        $data['notes'] ?? '',
                        $shareFlag,
                        $reminderDate,
                        $reminderNextDate,
                        $reminderValue,
                        $reminderUnit,
                        $reminderNote,
                        intval($data['id'])
                    ]);
                    syncItemReminderInstances($db, intval($data['id']), $reminderDate, $reminderNextDate, $reminderValue, $reminderUnit);
                    syncPublicSharedItem($authDb, $db, intval($currentUser['id']), intval($data['id']), $shareFlag);
                    $itemId = intval($data['id']);
                    $itemName = trim((string) ($data['name'] ?? ''));
                    $operationDetails = '物品: ' . $itemName . '（ID:' . $itemId . '）' . '；件数: ' . $itemQty;
                    if ($remainingTotal > 0 || $remainingCurrent > 0) {
                        $operationDetails .= '；余量: ' . $remainingCurrent . '/' . $remainingTotal;
                    }
                    if ($categoryId > 0) {
                        $catName = trim((string) ($db->query("SELECT name FROM categories WHERE id=" . intval($categoryId) . " LIMIT 1")->fetchColumn() ?: ''));
                        if ($catName !== '') {
                            $operationDetails .= '；一级分类: ' . $catName;
                        }
                    }
                    if ($subcategoryId > 0) {
                        $subName = trim((string) ($db->query("SELECT name FROM categories WHERE id=" . intval($subcategoryId) . " LIMIT 1")->fetchColumn() ?: ''));
                        if ($subName !== '') {
                            $operationDetails .= '；二级分类: ' . $subName;
                        }
                    }
                    $locId = intval($data['location_id'] ?? 0);
                    if ($locId > 0) {
                        $locName = trim((string) ($db->query("SELECT name FROM locations WHERE id=" . $locId . " LIMIT 1")->fetchColumn() ?: ''));
                        if ($locName !== '') {
                            $operationDetails .= '；位置: ' . $locName;
                        }
                    }
                    if ($shareFlag === 1) {
                        $operationDetails .= '；共享状态: 开启';
                    } else {
                        $operationDetails .= '；共享状态: 关闭';
                    }
                    $result = ['success' => true, 'message' => '更新成功'];
                }
                break;

            case 'items/complete-reminder':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    $reminderId = intval($data['reminder_id'] ?? 0);
                    if ($id <= 0) {
                        $result = ['success' => false, 'message' => '缺少物品ID'];
                        break;
                    }
                    $stmt = $db->prepare("SELECT id, name, reminder_date, reminder_next_date, reminder_cycle_value, reminder_cycle_unit FROM items WHERE id=? AND deleted_at IS NULL");
                    $stmt->execute([$id]);
                    $item = $stmt->fetch();
                    if (!$item) {
                        $result = ['success' => false, 'message' => '物品不存在'];
                        break;
                    }

                    $reminderUnit = normalizeReminderCycleUnit($item['reminder_cycle_unit'] ?? '');
                    $reminderValue = normalizeReminderCycleValue($item['reminder_cycle_value'] ?? 0, $reminderUnit);
                    if ($reminderUnit === '' || $reminderValue <= 0) {
                        $result = ['success' => false, 'message' => '该物品未设置有效的循环提醒'];
                        break;
                    }

                    seedReminderInstancesFromItems($db);
                    if ($reminderId > 0) {
                        $instanceStmt = $db->prepare("SELECT id, due_date, is_completed FROM item_reminder_instances WHERE id=? AND item_id=? LIMIT 1");
                        $instanceStmt->execute([$reminderId, $id]);
                    } else {
                        $instanceStmt = $db->prepare("SELECT id, due_date, is_completed FROM item_reminder_instances WHERE item_id=? AND is_completed=0 ORDER BY due_date ASC, id ASC LIMIT 1");
                        $instanceStmt->execute([$id]);
                    }
                    $instance = $instanceStmt->fetch();
                    if (!$instance) {
                        $result = ['success' => false, 'message' => '提醒记录不存在'];
                        break;
                    }
                    if (intval($instance['is_completed']) === 1) {
                        $result = ['success' => true, 'message' => '该提醒已是完成状态'];
                        break;
                    }

                    $currentDueDate = normalizeReminderDateValue($instance['due_date'] ?? '');
                    if ($currentDueDate === '') {
                        $result = ['success' => false, 'message' => '提醒日期无效'];
                        break;
                    }

                    $nextDate = calcNextReminderDate($currentDueDate, $reminderValue, $reminderUnit);
                    if (!$nextDate) {
                        $result = ['success' => false, 'message' => '该物品未设置有效的循环提醒'];
                        break;
                    }

                    $db->beginTransaction();
                    try {
                        $markDone = $db->prepare("UPDATE item_reminder_instances SET is_completed=1, completed_at=datetime('now','localtime'), updated_at=datetime('now','localtime') WHERE id=? AND item_id=?");
                        $markDone->execute([intval($instance['id']), $id]);

                        $checkNext = $db->prepare("SELECT id FROM item_reminder_instances WHERE item_id=? AND due_date=? AND is_completed=0 LIMIT 1");
                        $checkNext->execute([$id, $nextDate]);
                        $existingNext = $checkNext->fetchColumn();
                        if (!$existingNext) {
                            $insertNext = $db->prepare("INSERT INTO item_reminder_instances (item_id, due_date, is_completed, completed_at, generated_by_complete_id, created_at, updated_at) VALUES (?,?,0,NULL,?,datetime('now','localtime'),datetime('now','localtime'))");
                            $insertNext->execute([$id, $nextDate, intval($instance['id'])]);
                        }

                        $up = $db->prepare("UPDATE items SET reminder_next_date=?, updated_at=datetime('now','localtime') WHERE id=?");
                        $up->execute([$nextDate, $id]);

                        $db->commit();
                        $operationDetails = '物品: ' . trim((string) ($item['name'] ?? ('#' . $id))) . '（ID:' . $id . '）'
                            . '；完成提醒ID: ' . intval($instance['id'])
                            . '；本次提醒: ' . $currentDueDate
                            . '；下次提醒: ' . $nextDate;
                        $result = ['success' => true, 'message' => '提醒已完成，已生成下一次提醒', 'next_date' => $nextDate];
                    } catch (Exception $e) {
                        if ($db->inTransaction())
                            $db->rollBack();
                        throw $e;
                    }
                }
                break;

            case 'items/undo-reminder':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    $reminderId = intval($data['reminder_id'] ?? 0);
                    if ($id <= 0 || $reminderId <= 0) {
                        $result = ['success' => false, 'message' => '缺少提醒参数'];
                        break;
                    }

                    $itemStmt = $db->prepare("SELECT id, name FROM items WHERE id=? AND deleted_at IS NULL LIMIT 1");
                    $itemStmt->execute([$id]);
                    $item = $itemStmt->fetch();
                    if (!$item) {
                        $result = ['success' => false, 'message' => '物品不存在'];
                        break;
                    }

                    $instanceStmt = $db->prepare("SELECT id, due_date, is_completed FROM item_reminder_instances WHERE id=? AND item_id=? LIMIT 1");
                    $instanceStmt->execute([$reminderId, $id]);
                    $instance = $instanceStmt->fetch();
                    if (!$instance) {
                        $result = ['success' => false, 'message' => '提醒记录不存在'];
                        break;
                    }
                    if (intval($instance['is_completed']) !== 1) {
                        $result = ['success' => false, 'message' => '该提醒尚未完成'];
                        break;
                    }

                    $dueDate = normalizeReminderDateValue($instance['due_date'] ?? '');
                    if ($dueDate === '') {
                        $result = ['success' => false, 'message' => '提醒日期无效'];
                        break;
                    }

                    $db->beginTransaction();
                    try {
                        $hasCompletedChildrenStmt = $db->prepare("SELECT COUNT(*) FROM item_reminder_instances WHERE item_id=? AND generated_by_complete_id=? AND is_completed=1");
                        $hasCompletedChildrenStmt->execute([$id, $reminderId]);
                        $hasCompletedChildren = intval($hasCompletedChildrenStmt->fetchColumn() ?: 0) > 0;
                        if ($hasCompletedChildren) {
                            $db->rollBack();
                            $result = ['success' => false, 'message' => '后续提醒已完成，无法撤销该记录'];
                            break;
                        }

                        $undo = $db->prepare("UPDATE item_reminder_instances SET is_completed=0, completed_at=NULL, updated_at=datetime('now','localtime') WHERE id=? AND item_id=?");
                        $undo->execute([$reminderId, $id]);

                        $deleteGenerated = $db->prepare("DELETE FROM item_reminder_instances WHERE item_id=? AND generated_by_complete_id=? AND is_completed=0");
                        $deleteGenerated->execute([$id, $reminderId]);

                        $up = $db->prepare("UPDATE items SET reminder_next_date=?, updated_at=datetime('now','localtime') WHERE id=?");
                        $up->execute([$dueDate, $id]);

                        $db->commit();
                        $operationDetails = '物品: ' . trim((string) ($item['name'] ?? ('#' . $id))) . '（ID:' . $id . '）'
                            . '；撤销提醒ID: ' . $reminderId
                            . '；恢复提醒日期: ' . $dueDate;
                        $result = ['success' => true, 'message' => '已撤销完成状态并移除下一次提醒'];
                    } catch (Exception $e) {
                        if ($db->inTransaction())
                            $db->rollBack();
                        throw $e;
                    }
                }
                break;

            case 'items/delete':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    $itemInfoStmt = $db->prepare("SELECT id, name, quantity, image FROM items WHERE id=? LIMIT 1");
                    $itemInfoStmt->execute([$id]);
                    $itemInfo = $itemInfoStmt->fetch();
                    // 软删除：移入回收站，图片移到 trash 目录
                    $img = trim((string) ($itemInfo['image'] ?? ''));
                    if ($img && file_exists(UPLOAD_DIR . $img))
                        @rename(UPLOAD_DIR . $img, TRASH_DIR . $img);
                    $db->exec("UPDATE items SET deleted_at=datetime('now','localtime') WHERE id=$id");
                    removePublicSharedItem($authDb, intval($currentUser['id']), $id);
                    $itemName = trim((string) ($itemInfo['name'] ?? ''));
                    $itemQty = intval($itemInfo['quantity'] ?? 0);
                    $operationDetails = '物品: ' . ($itemName !== '' ? $itemName : ('#' . $id)) . '（ID:' . $id . '）';
                    if ($itemQty > 0) {
                        $operationDetails .= '；件数: ' . $itemQty;
                    }
                    $result = ['success' => true, 'message' => '已移入回收站'];
                }
                break;

            case 'items/batch-delete':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $ids = array_map('intval', $data['ids'] ?? []);
                    $deletedCount = 0;
                    $sampleNames = [];
                    if ($ids) {
                        $placeholders = implode(',', $ids);
                        $metaRows = $db->query("SELECT name FROM items WHERE id IN ($placeholders) ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
                        $sampleNames = array_slice(array_values(array_filter(array_map(function ($v) {
                            return trim((string) $v);
                        }, $metaRows))), 0, 3);
                        $deletedCount = count($metaRows);
                        $images = $db->query("SELECT image FROM items WHERE id IN ($placeholders) AND image != ''")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($images as $img) {
                            if (file_exists(UPLOAD_DIR . $img))
                                @rename(UPLOAD_DIR . $img, TRASH_DIR . $img);
                        }
                        $db->exec("UPDATE items SET deleted_at=datetime('now','localtime') WHERE id IN ($placeholders)");
                        removePublicSharedItemsByOwner($authDb, intval($currentUser['id']), $ids);
                    }
                    $operationDetails = '删除数量: ' . $deletedCount;
                    if (count($sampleNames) > 0) {
                        $operationDetails .= '；示例物品: ' . implode('、', $sampleNames);
                    }
                    $result = ['success' => true, 'message' => '已移入回收站'];
                }
                break;

            case 'items/reset-all':
                if ($method === 'POST') {
                    $images = $db->query("SELECT image FROM items WHERE image != ''")->fetchAll(PDO::FETCH_COLUMN);
                    $images = array_unique(array_filter($images));
                    $itemKindsBefore = intval($db->query("SELECT COUNT(*) FROM items")->fetchColumn() ?: 0);
                    $itemQtyBefore = intval($db->query("SELECT COALESCE(SUM(quantity),0) FROM items")->fetchColumn() ?: 0);
                    $moved = 0;
                    foreach ($images as $img) {
                        $src = UPLOAD_DIR . $img;
                        if (!file_exists($src))
                            continue;
                        $targetName = $img;
                        if (file_exists(TRASH_DIR . $targetName)) {
                            $targetName = uniqid('trash_') . '_' . $img;
                        }
                        if (@rename($src, TRASH_DIR . $targetName)) {
                            $moved++;
                        }
                    }
                    $deleted = $db->exec("DELETE FROM items");
                    removePublicSharedItemsByOwner($authDb, intval($currentUser['id']));
                    try {
                        $db->exec("DELETE FROM sqlite_sequence WHERE name='items'");
                    } catch (Exception $e) { /* 某些 SQLite 版本可能无该表 */ }
                    $operationDetails = '重置前物品种类: ' . $itemKindsBefore . '；重置前总件数: ' . $itemQtyBefore . '；迁移图片: ' . $moved;
                    $result = ['success' => true, 'message' => '所有物品已删除，图片已移入 trash 目录', 'deleted' => intval($deleted ?: 0), 'moved_images' => $moved];
                }
                break;

            case 'items/batch-import-manual':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $rows = $data['rows'] ?? [];
                    if (!is_array($rows) || count($rows) === 0) {
                        $result = ['success' => false, 'message' => '没有可导入的数据'];
                        break;
                    }

                    $db->beginTransaction();
                    try {
                        $stmt = $db->prepare("INSERT INTO items (name, category_id, subcategory_id, location_id, quantity, description, image, barcode, purchase_date, purchase_price, tags, status, expiry_date, purchase_from, notes, reminder_date, reminder_cycle_value, reminder_cycle_unit, reminder_note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        $created = 0;
                        $skipped = 0;
                        $errors = [];

                        foreach ($rows as $idx => $row) {
                            if (!is_array($row)) {
                                $skipped++;
                                continue;
                            }

                            $name = trim((string) ($row['name'] ?? ''));
                            if ($name === '') {
                                $skipped++;
                                if (count($errors) < 20)
                                    $errors[] = '第 ' . ($idx + 2) . ' 行：物品名称为空';
                                continue;
                            }

                            $purchaseDate = normalizeDateYmd($row['purchase_date'] ?? '');
                            $expiryDate = normalizeDateYmd($row['expiry_date'] ?? '');
                            if ($purchaseDate === null || $expiryDate === null) {
                                $skipped++;
                                if (count($errors) < 20)
                                    $errors[] = '第 ' . ($idx + 2) . ' 行：日期格式错误，应为 YYYY-MM-DD 或 YYYY/MM/DD（如 2026/2/9）';
                                continue;
                            }

                            [$categoryId, $subcategoryId, $categoryError] = normalizeItemCategorySelection(
                                $db,
                                intval($row['category_id'] ?? 0),
                                intval($row['subcategory_id'] ?? 0)
                            );
                            if ($categoryError) {
                                $skipped++;
                                if (count($errors) < 20)
                                    $errors[] = '第 ' . ($idx + 2) . ' 行：' . $categoryError;
                                continue;
                            }

                            try {
                                $stmt->execute([
                                    $name,
                                    $categoryId,
                                    $subcategoryId,
                                    intval($row['location_id'] ?? 0),
                                    max(0, intval($row['quantity'] ?? 1)),
                                    trim((string) ($row['description'] ?? '')),
                                    '',
                                    trim((string) ($row['barcode'] ?? '')),
                                    $purchaseDate,
                                    floatval($row['purchase_price'] ?? 0),
                                    trim((string) ($row['tags'] ?? '')),
                                    normalizeStatusValue($row['status'] ?? 'active'),
                                    $expiryDate,
                                    trim((string) ($row['purchase_from'] ?? '')),
                                    trim((string) ($row['notes'] ?? '')),
                                    '',
                                    0,
                                    '',
                                    trim((string) ($row['reminder_note'] ?? '')),
                                ]);
                                $created++;
                            } catch (Exception $e) {
                                $skipped++;
                                if (count($errors) < 20)
                                    $errors[] = '第 ' . ($idx + 2) . ' 行导入失败';
                            }
                        }

                        $db->commit();

                        $msg = '批量导入完成：成功 ' . $created . ' 条';
                        if ($skipped > 0)
                            $msg .= '，跳过 ' . $skipped . ' 条';
                        $operationDetails = '提交行数: ' . count($rows) . '；成功: ' . $created . '；跳过: ' . $skipped;
                        if (count($errors) > 0) {
                            $operationDetails .= '；错误示例: ' . trim((string) ($errors[0] ?? ''));
                        }
                        $result = ['success' => true, 'message' => $msg, 'created' => $created, 'skipped' => $skipped, 'errors' => $errors];
                    } catch (Exception $e) {
                        if ($db->inTransaction())
                            $db->rollBack();
                        throw $e;
                    }
                }
                break;

            case 'system/reset-default':
                if ($method === 'POST') {
                    $itemKindsBefore = intval($db->query("SELECT COUNT(*) FROM items")->fetchColumn() ?: 0);
                    $shoppingBefore = intval($db->query("SELECT COUNT(*) FROM shopping_list")->fetchColumn() ?: 0);
                    $categoryBefore = intval($db->query("SELECT COUNT(*) FROM categories")->fetchColumn() ?: 0);
                    $locationBefore = intval($db->query("SELECT COUNT(*) FROM locations")->fetchColumn() ?: 0);
                    $moved = moveUploadFilesToTrash($db);

                    $db->beginTransaction();
                    try {
                        $db->exec("DELETE FROM items");
                        $db->exec("DELETE FROM categories");
                        $db->exec("DELETE FROM locations");
                        $db->exec("DELETE FROM shopping_list");
                        $db->exec("DELETE FROM operation_logs");
                        removePublicSharedItemsByOwner($authDb, intval($currentUser['id']));
                        try {
                            $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('items','categories','locations','shopping_list','operation_logs')");
                        } catch (Exception $e) { /* 某些 SQLite 版本可能无该表 */ }
                        $db->commit();
                    } catch (Exception $e) {
                        if ($db->inTransaction())
                            $db->rollBack();
                        throw $e;
                    }

                    // 重新注入默认分类和默认位置
                    initSchema($db);
                    $operationDetails = '重置前: 物品' . $itemKindsBefore . '种、购物清单' . $shoppingBefore . '条、分类' . $categoryBefore . '个、位置' . $locationBefore . '个；迁移图片: ' . $moved;
                    $result = ['success' => true, 'message' => '已恢复默认环境，上传目录文件已移入 trash 目录', 'moved_images' => $moved];
                }
                break;

            case 'system/load-demo':
                if ($method === 'POST') {
                    $demoLoad = loadDemoDataIntoDb($db, ['move_images' => true, 'auth_db' => $authDb, 'owner_user_id' => intval($currentUser['id'])]);
                    $operationDetails = '物品: ' . intval($demoLoad['created'] ?? 0)
                        . '；购物清单: ' . intval($demoLoad['shopping_created'] ?? 0)
                        . '；任务: ' . intval($demoLoad['task_seeded'] ?? 0)
                        . '；共享物品: ' . intval($demoLoad['shared_created'] ?? 0)
                        . '；评论: ' . intval($demoLoad['public_comment_created'] ?? 0)
                        . '；日志样例: ' . intval($demoLoad['operation_log_seeded'] ?? 0)
                        . '；回收站示例: ' . (!empty($demoLoad['trash_demo']) ? '有' : '无')
                        . '；完成提醒示例: ' . (!empty($demoLoad['completed_reminder_demo']) ? '有' : '无');
                    $result = array_merge(['success' => true], $demoLoad);
                }
                break;

            case 'platform-settings':
                if (!isAdminUser($currentUser)) {
                    http_response_code(403);
                    $result = ['success' => false, 'message' => '仅管理员可操作', 'code' => 'ADMIN_REQUIRED'];
                    break;
                }
                if ($method === 'GET') {
                    $result = [
                        'success' => true,
                        'data' => [
                            'allow_registration' => isPublicRegistrationEnabled($authDb)
                        ]
                    ];
                } elseif ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $allowRegistration = intval($data['allow_registration'] ?? 0) === 1;
                    $saved = setPlatformSetting($authDb, 'allow_public_registration', $allowRegistration ? '1' : '0');
                    if (!$saved) {
                        $result = ['success' => false, 'message' => '平台设置保存失败'];
                        break;
                    }
                    $operationDetails = '开放注册: ' . ($allowRegistration ? '开启' : '关闭');
                    $result = [
                        'success' => true,
                        'message' => '平台设置已保存',
                        'data' => [
                            'allow_registration' => $allowRegistration
                        ]
                    ];
                }
                break;

            case 'operation-logs/client-event':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $eventType = trim((string) ($data['event_type'] ?? ''));
                    $details = trim((string) ($data['details'] ?? ''));
                    $allowedEvents = [
                        'settings.sort' => ['key' => 'settings_sort', 'label' => '更新排序设置'],
                        'settings.dashboard_ranges' => ['key' => 'settings_dashboard_ranges', 'label' => '更新仪表盘管理设置'],
                        'settings.reminder_low_stock' => ['key' => 'settings_reminder_low_stock', 'label' => '更新余量提醒阈值设置'],
                        'settings.item_size' => ['key' => 'settings_item_size', 'label' => '调整物品显示大小'],
                        'settings.item_attrs' => ['key' => 'settings_item_attrs', 'label' => '更新物品属性显示设置'],
                        'settings.statuses' => ['key' => 'settings_statuses', 'label' => '更新状态管理设置'],
                        'settings.channels' => ['key' => 'settings_channels', 'label' => '更新购入渠道设置'],
                    ];
                    if (!isset($allowedEvents[$eventType])) {
                        $result = ['success' => false, 'message' => '不支持的设置事件'];
                        break;
                    }
                    $meta = $allowedEvents[$eventType];
                    $apiName = 'client-event/' . $eventType;
                    logUserOperation($db, $meta['key'], $meta['label'], $details, $apiName, 'POST');
                    logAdminOperation($authDb, $currentUser, $meta['key'], $meta['label'], $details, $apiName, 'POST');
                    $result = ['success' => true, 'message' => '已记录设置变更'];
                }
                break;

            // ---------- 操作日志 ----------
            case 'operation-logs':
                if ($method === 'GET') {
                    $keyword = trim((string) ($_GET['keyword'] ?? ''));
                    if (isAdminUser($currentUser)) {
                        $page = max(1, intval($_GET['page'] ?? 1));
                        $limit = max(20, min(10000, intval($_GET['limit'] ?? 1000)));
                        $offset = ($page - 1) * $limit;
                        $actorUserId = intval($_GET['actor_user_id'] ?? 0);
                        $sort = trim((string) ($_GET['sort'] ?? 'time_desc'));
                        $where = [];
                        $params = [];
                        if ($keyword !== '') {
                            $where[] = "(action_label LIKE ? OR action_key LIKE ? OR details LIKE ? OR actor_username LIKE ? OR actor_display_name LIKE ? OR api LIKE ?)";
                            $kw = '%' . $keyword . '%';
                            $params = [$kw, $kw, $kw, $kw, $kw, $kw];
                        }
                        if ($actorUserId > 0) {
                            $where[] = "actor_user_id = ?";
                            $params[] = $actorUserId;
                        }
                        $whereSql = count($where) > 0 ? ('WHERE ' . implode(' AND ', $where)) : '';
                        $countStmt = $authDb->prepare("SELECT COUNT(*) FROM admin_operation_logs $whereSql");
                        $countStmt->execute($params);
                        $total = intval($countStmt->fetchColumn() ?: 0);
                        $orderBy = 'id DESC';
                        if ($sort === 'time_asc') {
                            $orderBy = 'id ASC';
                        } elseif ($sort === 'action_asc') {
                            $orderBy = 'action_label ASC, id DESC';
                        } elseif ($sort === 'action_desc') {
                            $orderBy = 'action_label DESC, id DESC';
                        } elseif ($sort === 'user_asc') {
                            $orderBy = 'actor_display_name ASC, actor_username ASC, id DESC';
                        } elseif ($sort === 'user_desc') {
                            $orderBy = 'actor_display_name DESC, actor_username DESC, id DESC';
                        }
                        $queryParams = array_merge($params, [$limit, $offset]);
                        $listStmt = $authDb->prepare("SELECT id, actor_user_id, actor_username, actor_display_name, actor_role, action_key, action_label, api, method, details, created_at FROM admin_operation_logs $whereSql ORDER BY $orderBy LIMIT ? OFFSET ?");
                        $listStmt->execute($queryParams);
                        $rows = $listStmt->fetchAll();
                        $members = $authDb->query("SELECT id, username, display_name, role FROM users ORDER BY CASE role WHEN 'admin' THEN 0 ELSE 1 END, id ASC")->fetchAll();
                        $result = [
                            'success' => true,
                            'scope' => 'admin',
                            'data' => $rows,
                            'members' => $members,
                            'sort' => $sort,
                            'total' => $total,
                            'page' => $page,
                            'pages' => max(1, intval(ceil($total / max(1, $limit))))
                        ];
                    } else {
                        $where = [];
                        $params = [];
                        if ($keyword !== '') {
                            $where[] = "(action_label LIKE ? OR details LIKE ?)";
                            $kw = '%' . $keyword . '%';
                            $params = [$kw, $kw];
                        }
                        $whereSql = count($where) > 0 ? ('WHERE ' . implode(' AND ', $where)) : '';
                        $countStmt = $db->prepare("SELECT COUNT(*) FROM operation_logs $whereSql");
                        $countStmt->execute($params);
                        $totalAll = intval($countStmt->fetchColumn() ?: 0);
                        $listStmt = $db->prepare("SELECT id, action_key, action_label, details, created_at FROM operation_logs $whereSql ORDER BY id DESC LIMIT 30");
                        $listStmt->execute($params);
                        $rows = $listStmt->fetchAll();
                        foreach ($rows as &$row) {
                            $row['details'] = normalizeUserOperationLogDetails($row['action_key'] ?? '', $row['details'] ?? '');
                            unset($row['action_key']);
                        }
                        unset($row);
                        $result = [
                            'success' => true,
                            'scope' => 'user',
                            'data' => $rows,
                            'total' => count($rows),
                            'total_all' => $totalAll,
                            'page' => 1,
                            'pages' => 1,
                            'limited_to_recent' => true
                        ];
                    }
                }
                break;

            case 'operation-logs/clear':
                if ($method === 'POST') {
                    if (!isAdminUser($currentUser)) {
                        http_response_code(403);
                        $result = ['success' => false, 'message' => '仅管理员可清空汇总日志', 'code' => 'ADMIN_REQUIRED'];
                        break;
                    }
                    $deleted = intval($authDb->exec("DELETE FROM admin_operation_logs") ?: 0);
                    $result = ['success' => true, 'message' => '管理员汇总日志已清空（不影响成员个人日志）', 'deleted' => $deleted];
                }
                break;

            // ---------- 回收站 ----------
            case 'trash':
                if ($method === 'GET') {
                    $trashItems = $db->query("SELECT i.*, c.name as category_name, c.icon as category_icon, c.color as category_color, sc.name as subcategory_name, sc.icon as subcategory_icon, l.name as location_name FROM items i LEFT JOIN categories c ON i.category_id=c.id LEFT JOIN categories sc ON i.subcategory_id=sc.id LEFT JOIN locations l ON i.location_id=l.id WHERE i.deleted_at IS NOT NULL ORDER BY i.deleted_at DESC")->fetchAll();
                    $result = ['success' => true, 'data' => $trashItems];
                }
                break;

            case 'trash/restore':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    $infoStmt = $db->prepare("SELECT id, name, quantity, image FROM items WHERE id=? LIMIT 1");
                    $infoStmt->execute([$id]);
                    $itemInfo = $infoStmt->fetch();
                    $img = trim((string) ($itemInfo['image'] ?? ''));
                    if ($img && file_exists(TRASH_DIR . $img))
                        @rename(TRASH_DIR . $img, UPLOAD_DIR . $img);
                    $db->exec("UPDATE items SET deleted_at=NULL, updated_at=datetime('now','localtime') WHERE id=$id");
                    $shareRow = getItemShareSnapshot($db, $id);
                    if ($shareRow) {
                        syncPublicSharedItem($authDb, $db, intval($currentUser['id']), $id, intval($shareRow['is_public_shared'] ?? 0));
                    }
                    $itemName = trim((string) ($itemInfo['name'] ?? ''));
                    $itemQty = intval($itemInfo['quantity'] ?? 0);
                    $operationDetails = '恢复物品: ' . ($itemName !== '' ? $itemName : ('#' . $id)) . '（ID:' . $id . '）';
                    if ($itemQty > 0) {
                        $operationDetails .= '；件数: ' . $itemQty;
                    }
                    $result = ['success' => true, 'message' => '已恢复'];
                }
                break;

            case 'trash/batch-restore':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $ids = array_map('intval', $data['ids'] ?? []);
                    $restoredCount = 0;
                    $sampleNames = [];
                    if ($ids) {
                        $placeholders = implode(',', $ids);
                        $nameRows = $db->query("SELECT name FROM items WHERE id IN ($placeholders) ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
                        $restoredCount = count($nameRows);
                        $sampleNames = array_slice(array_values(array_filter(array_map(function ($v) {
                            return trim((string) $v);
                        }, $nameRows))), 0, 3);
                        $images = $db->query("SELECT image FROM items WHERE id IN ($placeholders) AND image != ''")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($images as $img) {
                            if (file_exists(TRASH_DIR . $img))
                                @rename(TRASH_DIR . $img, UPLOAD_DIR . $img);
                        }
                        $db->exec("UPDATE items SET deleted_at=NULL, updated_at=datetime('now','localtime') WHERE id IN ($placeholders)");
                        foreach ($ids as $rid) {
                            $shareRow = getItemShareSnapshot($db, $rid);
                            if ($shareRow) {
                                syncPublicSharedItem($authDb, $db, intval($currentUser['id']), $rid, intval($shareRow['is_public_shared'] ?? 0));
                            }
                        }
                    }
                    $operationDetails = '恢复数量: ' . $restoredCount;
                    if (count($sampleNames) > 0) {
                        $operationDetails .= '；示例物品: ' . implode('、', $sampleNames);
                    }
                    $result = ['success' => true, 'message' => '已全部恢复'];
                }
                break;

            case 'trash/permanent-delete':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    $infoStmt = $db->prepare("SELECT id, name, quantity, image FROM items WHERE id=? LIMIT 1");
                    $infoStmt->execute([$id]);
                    $itemInfo = $infoStmt->fetch();
                    $img = trim((string) ($itemInfo['image'] ?? ''));
                    if ($img && file_exists(TRASH_DIR . $img))
                        unlink(TRASH_DIR . $img);
                    $db->exec("DELETE FROM items WHERE id=$id");
                    $itemName = trim((string) ($itemInfo['name'] ?? ''));
                    $operationDetails = '彻底删除: ' . ($itemName !== '' ? $itemName : ('#' . $id)) . '（ID:' . $id . '）';
                    $result = ['success' => true, 'message' => '已彻底删除'];
                }
                break;

            case 'trash/empty':
                if ($method === 'POST') {
                    $trashCount = intval($db->query("SELECT COUNT(*) FROM items WHERE deleted_at IS NOT NULL")->fetchColumn() ?: 0);
                    $images = $db->query("SELECT image FROM items WHERE deleted_at IS NOT NULL AND image != ''")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($images as $img) {
                        if (file_exists(TRASH_DIR . $img))
                            unlink(TRASH_DIR . $img);
                    }
                    $db->exec("DELETE FROM items WHERE deleted_at IS NOT NULL");
                    $operationDetails = '清空回收站数量: ' . $trashCount;
                    $result = ['success' => true, 'message' => '回收站已清空'];
                }
                break;

            // ---------- 分类 CRUD ----------
            case 'categories':
                if ($method === 'GET') {
                    $cats = $db->query("SELECT
                            c.*,
                            COALESCE(p.name, '') AS parent_name,
                            (SELECT COUNT(*) FROM items i WHERE i.deleted_at IS NULL AND ((c.parent_id>0 AND i.subcategory_id=c.id) OR (c.parent_id=0 AND i.category_id=c.id))) AS direct_item_count,
                            (SELECT COUNT(*) FROM items i WHERE i.deleted_at IS NULL AND ((c.parent_id>0 AND i.subcategory_id=c.id) OR (c.parent_id=0 AND i.category_id=c.id))) AS item_count,
                            (SELECT COUNT(*) FROM categories sc WHERE sc.parent_id=c.id) AS child_count
                        FROM categories c
                        LEFT JOIN categories p ON p.id=c.parent_id
                        ORDER BY c.parent_id ASC, c.sort_order, c.name")->fetchAll();
                    $result = ['success' => true, 'data' => $cats];
                } elseif ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $name = trim((string) ($data['name'] ?? ''));
                    $icon = trim((string) ($data['icon'] ?? '📦'));
                    $color = trim((string) ($data['color'] ?? '#3b82f6'));
                    $parentId = max(0, intval($data['parent_id'] ?? 0));
                    if ($name === '') {
                        $result = ['success' => false, 'message' => '分类名称不能为空'];
                        break;
                    }
                    if ($parentId > 0) {
                        $parentStmt = $db->prepare("SELECT id, parent_id FROM categories WHERE id=? LIMIT 1");
                        $parentStmt->execute([$parentId]);
                        $parentRow = $parentStmt->fetch();
                        if (!$parentRow) {
                            $result = ['success' => false, 'message' => '上级分类不存在'];
                            break;
                        }
                        if (intval($parentRow['parent_id'] ?? 0) > 0) {
                            $result = ['success' => false, 'message' => '仅支持两级分类，二级分类不能再作为上级'];
                            break;
                        }
                    }
                    $dupStmt = $db->prepare("SELECT id FROM categories WHERE name=? LIMIT 1");
                    $dupStmt->execute([$name]);
                    if ($dupStmt->fetchColumn()) {
                        $result = ['success' => false, 'message' => '分类名称已存在'];
                        break;
                    }
                    $stmt = $db->prepare("INSERT INTO categories (name, parent_id, icon, color) VALUES (?,?,?,?)");
                    $stmt->execute([$name, $parentId, ($icon !== '' ? $icon : '📦'), ($color !== '' ? $color : '#3b82f6')]);
                    $newCategoryId = intval($db->lastInsertId());
                    $parentName = '一级分类';
                    if ($parentId > 0) {
                        $parentName = trim((string) ($db->query("SELECT name FROM categories WHERE id=" . $parentId . " LIMIT 1")->fetchColumn() ?: ('#' . $parentId)));
                    }
                    $operationDetails = '分类: ' . $name . '（ID:' . $newCategoryId . '）'
                        . '；层级: ' . ($parentId > 0 ? ('二级（上级:' . $parentName . '）') : '一级')
                        . '；图标: ' . ($icon !== '' ? $icon : '📦');
                    $result = ['success' => true, 'message' => '分类添加成功', 'id' => $newCategoryId];
                }
                break;

            case 'categories/update':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    $name = trim((string) ($data['name'] ?? ''));
                    $icon = trim((string) ($data['icon'] ?? '📦'));
                    $color = trim((string) ($data['color'] ?? '#3b82f6'));
                    $parentId = max(0, intval($data['parent_id'] ?? 0));
                    if ($id <= 0) {
                        $result = ['success' => false, 'message' => '缺少分类ID'];
                        break;
                    }
                    if ($name === '') {
                        $result = ['success' => false, 'message' => '分类名称不能为空'];
                        break;
                    }
                    if ($parentId === $id) {
                        $result = ['success' => false, 'message' => '分类不能设置自己为上级'];
                        break;
                    }
                    $currentStmt = $db->prepare("SELECT id, parent_id, name FROM categories WHERE id=? LIMIT 1");
                    $currentStmt->execute([$id]);
                    $currentCat = $currentStmt->fetch();
                    if (!$currentCat) {
                        $result = ['success' => false, 'message' => '分类不存在'];
                        break;
                    }
                    if ($parentId > 0) {
                        $parentStmt = $db->prepare("SELECT id, parent_id FROM categories WHERE id=? LIMIT 1");
                        $parentStmt->execute([$parentId]);
                        $parentRow = $parentStmt->fetch();
                        if (!$parentRow) {
                            $result = ['success' => false, 'message' => '上级分类不存在'];
                            break;
                        }
                        if (intval($parentRow['parent_id'] ?? 0) > 0) {
                            $result = ['success' => false, 'message' => '仅支持两级分类，二级分类不能再作为上级'];
                            break;
                        }
                        $childCntStmt = $db->prepare("SELECT COUNT(*) FROM categories WHERE parent_id=?");
                        $childCntStmt->execute([$id]);
                        if (intval($childCntStmt->fetchColumn() ?: 0) > 0) {
                            $result = ['success' => false, 'message' => '该分类下已有二级分类，无法直接设置为二级分类'];
                            break;
                        }
                    }
                    $dupStmt = $db->prepare("SELECT id FROM categories WHERE name=? AND id<>? LIMIT 1");
                    $dupStmt->execute([$name, $id]);
                    if ($dupStmt->fetchColumn()) {
                        $result = ['success' => false, 'message' => '分类名称已存在'];
                        break;
                    }
                    $stmt = $db->prepare("UPDATE categories SET name=?, parent_id=?, icon=?, color=? WHERE id=?");
                    $stmt->execute([$name, $parentId, ($icon !== '' ? $icon : '📦'), ($color !== '' ? $color : '#3b82f6'), $id]);
                    $oldName = trim((string) ($currentCat['name'] ?? ''));
                    $oldParentId = intval($currentCat['parent_id'] ?? 0);
                    $oldParentName = '';
                    $newParentName = '';
                    if ($oldParentId > 0) {
                        $oldParentName = trim((string) ($db->query("SELECT name FROM categories WHERE id=" . $oldParentId . " LIMIT 1")->fetchColumn() ?: ('#' . $oldParentId)));
                    }
                    if ($parentId > 0) {
                        $newParentName = trim((string) ($db->query("SELECT name FROM categories WHERE id=" . $parentId . " LIMIT 1")->fetchColumn() ?: ('#' . $parentId)));
                    }
                    $operationDetails = '分类ID: ' . $id
                        . '；名称: ' . ($oldName !== '' ? $oldName : ('#' . $id)) . ' -> ' . $name
                        . '；层级: ' . ($oldParentId > 0 ? ('二级(' . $oldParentName . ')') : '一级')
                        . ' -> ' . ($parentId > 0 ? ('二级(' . $newParentName . ')') : '一级')
                        . '；图标: ' . ($icon !== '' ? $icon : '📦');
                    $result = ['success' => true, 'message' => '分类更新成功'];
                }
                break;

            case 'categories/delete':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    if ($id <= 0) {
                        $result = ['success' => false, 'message' => '缺少分类ID'];
                        break;
                    }
                    $currentStmt = $db->prepare("SELECT id, parent_id, name FROM categories WHERE id=? LIMIT 1");
                    $currentStmt->execute([$id]);
                    $currentCat = $currentStmt->fetch();
                    if (!$currentCat) {
                        $result = ['success' => false, 'message' => '分类不存在'];
                        break;
                    }
                    $isTopLevel = intval($currentCat['parent_id'] ?? 0) <= 0;
                    $childStmt = $db->prepare("SELECT id FROM categories WHERE parent_id=?");
                    $childStmt->execute([$id]);
                    $childIds = array_map('intval', $childStmt->fetchAll(PDO::FETCH_COLUMN));
                    $allIds = array_merge([$id], $childIds);
                    $allIds = array_values(array_filter(array_unique($allIds), function ($v) {
                        return intval($v) > 0;
                    }));
                    if (count($allIds) > 0) {
                        if ($isTopLevel) {
                            $clearTop = $db->prepare("UPDATE items SET category_id=0, subcategory_id=0 WHERE category_id=?");
                            $clearTop->execute([$id]);
                            if (count($childIds) > 0) {
                                $childPlaceholders = implode(',', array_fill(0, count($childIds), '?'));
                                $clearSubs = $db->prepare("UPDATE items SET subcategory_id=0 WHERE subcategory_id IN ($childPlaceholders)");
                                $clearSubs->execute($childIds);
                            }
                        } else {
                            $clearSub = $db->prepare("UPDATE items SET subcategory_id=0 WHERE subcategory_id=?");
                            $clearSub->execute([$id]);
                        }
                        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
                        $deleteStmt = $db->prepare("DELETE FROM categories WHERE id IN ($placeholders)");
                        $deleteStmt->execute($allIds);
                    }
                    $mainName = trim((string) ($currentCat['name'] ?? ('#' . $id)));
                    $operationDetails = '删除分类: ' . $mainName . '（ID:' . $id . '）'
                        . '；删除节点数: ' . count($allIds)
                        . '；受影响物品分类已置空';
                    $result = ['success' => true, 'message' => '分类删除成功'];
                }
                break;

            // ---------- 位置 CRUD ----------
            case 'locations':
                if ($method === 'GET') {
                    $locs = $db->query("SELECT l.*, (SELECT COUNT(*) FROM items WHERE location_id=l.id AND deleted_at IS NULL) as item_count FROM locations l ORDER BY l.sort_order, l.name")->fetchAll();
                    $result = ['success' => true, 'data' => $locs];
                } elseif ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    if (empty($data['name'])) {
                        $result = ['success' => false, 'message' => '位置名称不能为空'];
                        break;
                    }
                    $icon = trim(strval($data['icon'] ?? ''));
                    if ($icon === '') {
                        $icon = '📍';
                    }
                    $stmt = $db->prepare("INSERT INTO locations (name, parent_id, icon, description) VALUES (?,?,?,?)");
                    $stmt->execute([$data['name'], 0, $icon, $data['description'] ?? '']);
                    $newLocationId = intval($db->lastInsertId());
                    $locName = trim((string) ($data['name'] ?? ''));
                    $operationDetails = '位置: ' . $locName . '（ID:' . $newLocationId . '）' . '；图标: ' . $icon;
                    $result = ['success' => true, 'message' => '位置添加成功', 'id' => $newLocationId];
                }
                break;

            case 'locations/update':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    $oldStmt = $db->prepare("SELECT id, name FROM locations WHERE id=? LIMIT 1");
                    $oldStmt->execute([$id]);
                    $oldLoc = $oldStmt->fetch();
                    $icon = trim(strval($data['icon'] ?? ''));
                    if ($icon === '') {
                        $icon = '📍';
                    }
                    $stmt = $db->prepare("UPDATE locations SET name=?, parent_id=?, icon=?, description=? WHERE id=?");
                    $stmt->execute([$data['name'], 0, $icon, $data['description'] ?? '', $id]);
                    $oldName = trim((string) ($oldLoc['name'] ?? ('#' . $id)));
                    $newName = trim((string) ($data['name'] ?? ''));
                    $operationDetails = '位置ID: ' . $id . '；名称: ' . $oldName . ' -> ' . $newName . '；图标: ' . $icon;
                    $result = ['success' => true, 'message' => '位置更新成功'];
                }
                break;

            case 'locations/delete':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    $locName = trim((string) ($db->query("SELECT name FROM locations WHERE id=" . $id . " LIMIT 1")->fetchColumn() ?: ''));
                    $affected = intval($db->exec("UPDATE items SET location_id=0 WHERE location_id=$id"));
                    $db->exec("DELETE FROM locations WHERE id=$id");
                    $operationDetails = '删除位置: ' . ($locName !== '' ? $locName : ('#' . $id)) . '（ID:' . $id . '）'
                        . '；受影响物品: ' . $affected . ' 件（位置已置空）';
                    $result = ['success' => true, 'message' => '位置删除成功'];
                }
                break;

            // ---------- 购物清单 CRUD ----------
            case 'shopping-list/similar-items':
                if ($method === 'GET') {
                    $rawName = trim((string) ($_GET['name'] ?? ''));
                    if ($rawName === '') {
                        $result = ['success' => true, 'data' => []];
                        break;
                    }
                    $coreName = trim(preg_replace('/[\(\（][^\)\）]*[\)\）]/u', '', $rawName));
                    if ($coreName === '') {
                        $coreName = $rawName;
                    }
                    $escapedRaw = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $rawName);
                    $escapedCore = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $coreName);
                    $compactRaw = preg_replace('/\s+/u', '', $rawName);
                    $compactCore = preg_replace('/\s+/u', '', $coreName);
                    if ($compactRaw === '') {
                        $compactRaw = $rawName;
                    }
                    if ($compactCore === '') {
                        $compactCore = $coreName;
                    }
                    $escapedCompactRaw = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $compactRaw);
                    $escapedCompactCore = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $compactCore);
                    $containsRaw = '%' . $escapedRaw . '%';
                    $containsCore = '%' . $escapedCore . '%';
                    $prefixRaw = $escapedRaw . '%';
                    $prefixCore = $escapedCore . '%';
                    $compactContainsRaw = '%' . $escapedCompactRaw . '%';
                    $compactContainsCore = '%' . $escapedCompactCore . '%';
                    $compactPrefixRaw = $escapedCompactRaw . '%';
                    $compactPrefixCore = $escapedCompactCore . '%';
                    $stmt = $db->prepare("SELECT id, name, purchase_price, purchase_from, purchase_date, updated_at
                        FROM items
                        WHERE deleted_at IS NULL
                          AND name != ''
                          AND (
                              name LIKE ? ESCAPE '\\'
                              OR name LIKE ? ESCAPE '\\'
                              OR instr(?, name) > 0
                              OR instr(?, name) > 0
                              OR replace(replace(name,' ',''),'　','') LIKE ? ESCAPE '\\'
                              OR replace(replace(name,' ',''),'　','') LIKE ? ESCAPE '\\'
                          )
                        ORDER BY CASE
                            WHEN name = ? THEN 0
                            WHEN name = ? THEN 0
                            WHEN replace(replace(name,' ',''),'　','') = ? THEN 0
                            WHEN replace(replace(name,' ',''),'　','') = ? THEN 0
                            WHEN name LIKE ? ESCAPE '\\' THEN 1
                            WHEN name LIKE ? ESCAPE '\\' THEN 2
                            WHEN replace(replace(name,' ',''),'　','') LIKE ? ESCAPE '\\' THEN 3
                            WHEN replace(replace(name,' ',''),'　','') LIKE ? ESCAPE '\\' THEN 4
                            WHEN instr(?, name) > 0 THEN 5
                            WHEN instr(?, name) > 0 THEN 6
                            WHEN name LIKE ? ESCAPE '\\' THEN 7
                            WHEN replace(replace(name,' ',''),'　','') LIKE ? ESCAPE '\\' THEN 8
                            ELSE 9
                        END, updated_at DESC, id DESC
                        LIMIT 8");
                    $stmt->execute([
                        $containsRaw,
                        $containsCore,
                        $rawName,
                        $coreName,
                        $compactContainsRaw,
                        $compactContainsCore,
                        $rawName,
                        $coreName,
                        $compactRaw,
                        $compactCore,
                        $prefixRaw,
                        $prefixCore,
                        $compactPrefixRaw,
                        $compactPrefixCore,
                        $rawName,
                        $coreName,
                        $containsCore,
                        $compactContainsCore
                    ]);
                    $rows = $stmt->fetchAll();
                    $result = ['success' => true, 'data' => $rows];
                }
                break;

            case 'shopping-list':
                if ($method === 'GET') {
                    $list = $db->query("SELECT s.*, c.name as category_name, c.icon as category_icon, c.color as category_color
                        FROM shopping_list s
                        LEFT JOIN categories c ON s.category_id=c.id
                        ORDER BY CASE s.status WHEN 'pending_purchase' THEN 0 WHEN 'pending_receipt' THEN 1 ELSE 0 END,
                                 CASE s.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 WHEN 'low' THEN 2 ELSE 1 END,
                                 s.created_at DESC, s.id DESC")->fetchAll();
                    $result = ['success' => true, 'data' => $list];
                } elseif ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $name = trim((string) ($data['name'] ?? ''));
                    if ($name === '') {
                        $result = ['success' => false, 'message' => '清单名称不能为空'];
                        break;
                    }
                    $qty = max(1, intval($data['quantity'] ?? 1));
                    $shoppingStatus = normalizeShoppingStatus($data['status'] ?? 'pending_purchase');
                    $categoryId = max(0, intval($data['category_id'] ?? 0));
                    $priority = normalizeShoppingPriority($data['priority'] ?? 'normal');
                    $plannedPrice = max(0, floatval($data['planned_price'] ?? 0));
                    $notes = trim((string) ($data['notes'] ?? ''));
                    $reminderDate = normalizeReminderDateValue($data['reminder_date'] ?? '');
                    $reminderNote = trim((string) ($data['reminder_note'] ?? ''));
                    $stmt = $db->prepare("INSERT INTO shopping_list (name, quantity, status, category_id, priority, planned_price, notes, reminder_date, reminder_note, created_at, updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,datetime('now','localtime'),datetime('now','localtime'))");
                    $stmt->execute([$name, $qty, $shoppingStatus, $categoryId, $priority, $plannedPrice, $notes, $reminderDate, $reminderNote]);
                    $newShoppingId = intval($db->lastInsertId());
                    $catName = '';
                    if ($categoryId > 0) {
                        $catName = trim((string) ($db->query("SELECT name FROM categories WHERE id=" . $categoryId . " LIMIT 1")->fetchColumn() ?: ''));
                    }
                    $operationDetails = '清单: ' . $name . '（ID:' . $newShoppingId . '）'
                        . '；数量: ' . $qty
                        . '；状态: ' . $shoppingStatus
                        . '；优先级: ' . $priority
                        . ($catName !== '' ? ('；分类: ' . $catName) : '');
                    $result = ['success' => true, 'message' => '已加入购物清单', 'id' => $newShoppingId];
                }
                break;

            case 'shopping-list/update':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    $name = trim((string) ($data['name'] ?? ''));
                    if ($id <= 0) {
                        $result = ['success' => false, 'message' => '缺少清单ID'];
                        break;
                    }
                    if ($name === '') {
                        $result = ['success' => false, 'message' => '清单名称不能为空'];
                        break;
                    }
                    $qty = max(1, intval($data['quantity'] ?? 1));
                    $shoppingStatus = normalizeShoppingStatus($data['status'] ?? 'pending_purchase');
                    $categoryId = max(0, intval($data['category_id'] ?? 0));
                    $priority = normalizeShoppingPriority($data['priority'] ?? 'normal');
                    $plannedPrice = max(0, floatval($data['planned_price'] ?? 0));
                    $notes = trim((string) ($data['notes'] ?? ''));
                    $reminderDate = normalizeReminderDateValue($data['reminder_date'] ?? '');
                    $reminderNote = trim((string) ($data['reminder_note'] ?? ''));
                    $oldStmt = $db->prepare("SELECT name, status, quantity FROM shopping_list WHERE id=? LIMIT 1");
                    $oldStmt->execute([$id]);
                    $oldRow = $oldStmt->fetch();
                    $stmt = $db->prepare("UPDATE shopping_list SET name=?, quantity=?, status=?, category_id=?, priority=?, planned_price=?, notes=?, reminder_date=?, reminder_note=?, updated_at=datetime('now','localtime') WHERE id=?");
                    $stmt->execute([$name, $qty, $shoppingStatus, $categoryId, $priority, $plannedPrice, $notes, $reminderDate, $reminderNote, $id]);
                    $catName = '';
                    if ($categoryId > 0) {
                        $catName = trim((string) ($db->query("SELECT name FROM categories WHERE id=" . $categoryId . " LIMIT 1")->fetchColumn() ?: ''));
                    }
                    $operationDetails = '清单ID: ' . $id
                        . '；名称: ' . trim((string) ($oldRow['name'] ?? ('#' . $id))) . ' -> ' . $name
                        . '；状态: ' . trim((string) ($oldRow['status'] ?? '')) . ' -> ' . $shoppingStatus
                        . '；数量: ' . intval($oldRow['quantity'] ?? 0) . ' -> ' . $qty
                        . ($catName !== '' ? ('；分类: ' . $catName) : '');
                    $result = ['success' => true, 'message' => '购物清单已更新'];
                }
                break;

            case 'shopping-list/update-status':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    if ($id <= 0) {
                        $result = ['success' => false, 'message' => '缺少清单ID'];
                        break;
                    }
                    $shoppingStatus = normalizeShoppingStatus($data['status'] ?? 'pending_purchase');
                    $oldStmt = $db->prepare("SELECT name, status FROM shopping_list WHERE id=? LIMIT 1");
                    $oldStmt->execute([$id]);
                    $oldRow = $oldStmt->fetch();
                    $stmt = $db->prepare("UPDATE shopping_list SET status=?, updated_at=datetime('now','localtime') WHERE id=?");
                    $stmt->execute([$shoppingStatus, $id]);
                    $operationDetails = '清单: ' . trim((string) ($oldRow['name'] ?? ('#' . $id))) . '（ID:' . $id . '）'
                        . '；状态: ' . trim((string) ($oldRow['status'] ?? '')) . ' -> ' . $shoppingStatus;
                    $result = ['success' => true, 'message' => '清单状态已更新', 'status' => $shoppingStatus];
                }
                break;

            case 'shopping-list/delete':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    if ($id <= 0) {
                        $result = ['success' => false, 'message' => '缺少清单ID'];
                        break;
                    }
                    $oldStmt = $db->prepare("SELECT name, quantity, status FROM shopping_list WHERE id=? LIMIT 1");
                    $oldStmt->execute([$id]);
                    $oldRow = $oldStmt->fetch();
                    $db->exec("DELETE FROM shopping_list WHERE id=$id");
                    $operationDetails = '删除清单: ' . trim((string) ($oldRow['name'] ?? ('#' . $id))) . '（ID:' . $id . '）'
                        . '；数量: ' . intval($oldRow['quantity'] ?? 0)
                        . '；状态: ' . trim((string) ($oldRow['status'] ?? ''));
                    $result = ['success' => true, 'message' => '已从购物清单删除'];
                }
                break;

            case 'shopping-list/convert':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    if ($id <= 0) {
                        $result = ['success' => false, 'message' => '缺少清单ID'];
                        break;
                    }
                    $stmt = $db->prepare("SELECT * FROM shopping_list WHERE id=? LIMIT 1");
                    $stmt->execute([$id]);
                    $row = $stmt->fetch();
                    if (!$row) {
                        $result = ['success' => false, 'message' => '购物清单项不存在'];
                        break;
                    }
                    $qty = max(1, intval($row['quantity'] ?? 1));
                    $categoryIdRaw = max(0, intval($row['category_id'] ?? 0));
                    [$categoryId, $subcategoryId, $categoryError] = normalizeItemCategorySelection($db, $categoryIdRaw, 0);
                    if ($categoryError) {
                        $categoryId = 0;
                        $subcategoryId = 0;
                    }
                    $price = max(0, floatval($row['planned_price'] ?? 0));
                    $notes = trim((string) ($row['notes'] ?? ''));

                    $db->beginTransaction();
                    try {
                        $insert = $db->prepare("INSERT INTO items (name, category_id, subcategory_id, location_id, quantity, description, image, barcode, purchase_date, purchase_price, tags, status, expiry_date, purchase_from, notes, reminder_date, reminder_next_date, reminder_cycle_value, reminder_cycle_unit, reminder_note)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        $insert->execute([
                            $row['name'],
                            $categoryId,
                            $subcategoryId,
                            0,
                            $qty,
                            '',
                            '',
                            '',
                            date('Y-m-d'),
                            $price,
                            '',
                            'active',
                            '',
                            '',
                            $notes,
                            '',
                            '',
                            0,
                            '',
                            ''
                        ]);
                        $newItemId = intval($db->lastInsertId());
                        $del = $db->prepare("DELETE FROM shopping_list WHERE id=?");
                        $del->execute([$id]);
                        $db->commit();
                        $operationDetails = '清单入库: ' . trim((string) ($row['name'] ?? ('#' . $id))) . '（清单ID:' . $id . '）'
                            . '；入库物品ID: ' . $newItemId
                            . '；件数: ' . $qty;
                        $result = ['success' => true, 'message' => '已移入物品管理', 'item_id' => $newItemId];
                    } catch (Exception $e) {
                        if ($db->inTransaction())
                            $db->rollBack();
                        throw $e;
                    }
                }
                break;

            // ---------- 公共频道 ----------
            case 'message-board':
                if ($method === 'GET') {
                    $limit = max(1, min(100, intval($_GET['limit'] ?? 40)));
                    $stmt = $authDb->prepare("SELECT
                            m.id,
                            m.user_id,
                            m.content,
                            COALESCE(m.is_completed,0) as is_completed,
                            m.completed_at,
                            m.created_at,
                            m.updated_at,
                            u.username,
                            u.display_name
                        FROM message_board_posts m
                        LEFT JOIN users u ON u.id=m.user_id
                        WHERE m.is_demo_scope=?
                        ORDER BY m.created_at DESC, m.id DESC
                        LIMIT ?");
                    $stmt->bindValue(1, $currentUserIsDemoScope ? 1 : 0, PDO::PARAM_INT);
                    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
                    $stmt->execute();
                    $rows = $stmt->fetchAll();
                    $list = [];
                    foreach ($rows as $row) {
                        $author = trim((string) ($row['display_name'] ?? ''));
                        if ($author === '') {
                            $author = trim((string) ($row['username'] ?? ''));
                        }
                        if ($author === '') {
                            $author = '用户#' . intval($row['user_id'] ?? 0);
                        }
                        $list[] = [
                            'id' => intval($row['id'] ?? 0),
                            'user_id' => intval($row['user_id'] ?? 0),
                            'author_name' => $author,
                            'content' => trim((string) ($row['content'] ?? '')),
                            'is_completed' => intval($row['is_completed'] ?? 0) === 1 ? 1 : 0,
                            'completed_at' => trim((string) ($row['completed_at'] ?? '')),
                            'created_at' => trim((string) ($row['created_at'] ?? '')),
                            'updated_at' => trim((string) ($row['updated_at'] ?? '')),
                            'can_edit' => (
                                intval($row['user_id'] ?? 0) === intval($currentUser['id'])
                                || isAdminUser($currentUser)
                            ),
                            'can_delete' => (
                                intval($row['user_id'] ?? 0) === intval($currentUser['id'])
                                || isAdminUser($currentUser)
                            )
                        ];
                    }
                    $result = ['success' => true, 'data' => $list];
                } elseif ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $content = trim((string) ($data['content'] ?? ''));
                    if ($content === '') {
                        $result = ['success' => false, 'message' => '任务内容不能为空'];
                        break;
                    }
                    if (function_exists('mb_substr')) {
                        $content = mb_substr($content, 0, 300, 'UTF-8');
                    } else {
                        $content = substr($content, 0, 300);
                    }
                    $insertStmt = $authDb->prepare("INSERT INTO message_board_posts
                        (user_id, content, is_demo_scope, is_completed, completed_at, created_at, updated_at)
                        VALUES (?,?,?,0,NULL,datetime('now','localtime'),datetime('now','localtime'))");
                    $insertStmt->execute([intval($currentUser['id']), $content, $currentUserIsDemoScope ? 1 : 0]);
                    $operationDetails = '任务内容: ' . $content;
                    $result = ['success' => true, 'message' => '任务已添加'];
                }
                break;

            case 'message-board/update':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $taskId = intval($data['id'] ?? 0);
                    if ($taskId <= 0) {
                        $result = ['success' => false, 'message' => '缺少任务ID'];
                        break;
                    }
                    $stmt = $authDb->prepare("SELECT id, user_id, content, is_demo_scope, COALESCE(is_completed,0) AS is_completed FROM message_board_posts WHERE id=? LIMIT 1");
                    $stmt->execute([$taskId]);
                    $task = $stmt->fetch();
                    if (!$task || intval($task['is_demo_scope'] ?? 0) !== ($currentUserIsDemoScope ? 1 : 0)) {
                        $result = ['success' => false, 'message' => '任务不存在或已失效'];
                        break;
                    }
                    $canEdit = intval($task['user_id'] ?? 0) === intval($currentUser['id']) || isAdminUser($currentUser);
                    if (!$canEdit) {
                        $result = ['success' => false, 'message' => '仅创建者或管理员可编辑任务'];
                        break;
                    }
                    $oldContent = trim((string) ($task['content'] ?? ''));
                    $oldCompleted = intval($task['is_completed'] ?? 0) === 1 ? 1 : 0;
                    $newContent = $oldContent;
                    if (array_key_exists('content', (array) $data)) {
                        $incomingContent = trim((string) ($data['content'] ?? ''));
                        if ($incomingContent === '') {
                            $result = ['success' => false, 'message' => '任务内容不能为空'];
                            break;
                        }
                        if (function_exists('mb_substr')) {
                            $incomingContent = mb_substr($incomingContent, 0, 300, 'UTF-8');
                        } else {
                            $incomingContent = substr($incomingContent, 0, 300);
                        }
                        $newContent = $incomingContent;
                    }
                    $newCompleted = $oldCompleted;
                    if (array_key_exists('is_completed', (array) $data)) {
                        $newCompleted = intval($data['is_completed'] ?? 0) === 1 ? 1 : 0;
                    }
                    $updateStmt = $authDb->prepare("UPDATE message_board_posts
                        SET content=?,
                            is_completed=?,
                            completed_at=(CASE WHEN ?=1 THEN datetime('now','localtime') ELSE NULL END),
                            updated_at=datetime('now','localtime')
                        WHERE id=?");
                    $updateStmt->execute([$newContent, $newCompleted, $newCompleted, $taskId]);

                    $statusLabel = $newCompleted === 1 ? '已完成' : '未完成';
                    $operationDetails = '任务ID: ' . $taskId . '；状态: ' . $statusLabel . '；内容: ' . $newContent;
                    $resultMessage = ($oldCompleted !== $newCompleted)
                        ? ($newCompleted === 1 ? '任务已标记为完成' : '任务已标记为未完成')
                        : '任务已更新';
                    $result = ['success' => true, 'message' => $resultMessage];
                }
                break;

            case 'message-board/delete':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $taskId = intval($data['id'] ?? 0);
                    if ($taskId <= 0) {
                        $result = ['success' => false, 'message' => '缺少任务ID'];
                        break;
                    }
                    $stmt = $authDb->prepare("SELECT id, user_id, content, is_demo_scope FROM message_board_posts WHERE id=? LIMIT 1");
                    $stmt->execute([$taskId]);
                    $task = $stmt->fetch();
                    if (!$task || intval($task['is_demo_scope'] ?? 0) !== ($currentUserIsDemoScope ? 1 : 0)) {
                        $result = ['success' => false, 'message' => '任务不存在或已失效'];
                        break;
                    }
                    $canDelete = intval($task['user_id'] ?? 0) === intval($currentUser['id']) || isAdminUser($currentUser);
                    if (!$canDelete) {
                        $result = ['success' => false, 'message' => '仅创建者或管理员可删除任务'];
                        break;
                    }
                    $delStmt = $authDb->prepare("DELETE FROM message_board_posts WHERE id=?");
                    $delStmt->execute([$taskId]);
                    $operationDetails = '任务ID: ' . $taskId . '；内容: ' . trim((string) ($task['content'] ?? ''));
                    $result = ['success' => true, 'message' => '任务已删除'];
                }
                break;

            // ---------- 公共频道 ----------
            case 'public-channel':
                if ($method === 'GET') {
                    $rows = $authDb->query("SELECT
                            p.id,
                            p.owner_user_id,
                            p.owner_item_id,
                            p.item_name,
                            p.category_name,
                            p.purchase_price,
                            p.purchase_from,
                            p.recommend_reason,
                            p.owner_item_updated_at,
                            p.created_at,
                            p.updated_at,
                            u.username,
                            u.display_name
                        FROM public_shared_items p
                        LEFT JOIN users u ON u.id=p.owner_user_id
                        ORDER BY p.updated_at DESC, p.id DESC")->fetchAll();
                    $staleIds = [];
                    $sharedList = [];
                    foreach ($rows as $row) {
                        $shareId = intval($row['id'] ?? 0);
                        $ownerId = intval($row['owner_user_id'] ?? 0);
                        $ownerItemId = intval($row['owner_item_id'] ?? 0);
                        $ownerUsername = trim((string) ($row['username'] ?? ''));
                        if ($shareId <= 0 || $ownerId <= 0 || $ownerItemId <= 0) {
                            if ($shareId > 0) {
                                $staleIds[] = $shareId;
                            }
                            continue;
                        }
                        if (isDemoUsername($ownerUsername) !== $currentUserIsDemoScope) {
                            continue;
                        }
                        try {
                            $ownerDb = getUserDB($ownerId);
                        } catch (Exception $e) {
                            $staleIds[] = $shareId;
                            continue;
                        }
                        $live = getItemShareSnapshot($ownerDb, $ownerItemId);
                        if (!$live || intval($live['is_public_shared'] ?? 0) !== 1) {
                            $staleIds[] = $shareId;
                            continue;
                        }
                        $isChanged = trim((string) ($row['item_name'] ?? '')) !== trim((string) ($live['name'] ?? ''))
                            || trim((string) ($row['category_name'] ?? '')) !== trim((string) ($live['category_name'] ?? ''))
                            || floatval($row['purchase_price'] ?? 0) != floatval($live['purchase_price'] ?? 0)
                            || trim((string) ($row['purchase_from'] ?? '')) !== trim((string) ($live['purchase_from'] ?? ''))
                            || trim((string) ($row['recommend_reason'] ?? '')) !== trim((string) ($live['recommend_reason'] ?? ''))
                            || trim((string) ($row['owner_item_updated_at'] ?? '')) !== trim((string) ($live['updated_at'] ?? ''));
                        if ($isChanged) {
                            upsertPublicSharedItem($authDb, $ownerId, $live);
                        }
                        $ownerName = trim((string) ($row['display_name'] ?? ''));
                        if ($ownerName === '') {
                            $ownerName = trim((string) ($row['username'] ?? ''));
                        }
                        if ($ownerName === '') {
                            $ownerName = '用户#' . $ownerId;
                        }
                        $sharedList[] = [
                            'id' => $shareId,
                            'owner_user_id' => $ownerId,
                            'owner_item_id' => $ownerItemId,
                            'category_id' => intval($live['category_id'] ?? 0),
                            'owner_name' => $ownerName,
                            'item_name' => trim((string) ($live['name'] ?? '')),
                            'category_name' => trim((string) ($live['category_name'] ?? '')),
                            'purchase_price' => max(0, floatval($live['purchase_price'] ?? 0)),
                            'purchase_from' => trim((string) ($live['purchase_from'] ?? '')),
                            'recommend_reason' => trim((string) ($live['recommend_reason'] ?? '')),
                            'owner_item_updated_at' => trim((string) ($live['updated_at'] ?? '')),
                            'created_at' => $row['created_at'] ?? '',
                            'updated_at' => $row['updated_at'] ?? '',
                            'can_edit' => ($ownerId === intval($currentUser['id']))
                        ];
                    }
                    if (count($staleIds) > 0) {
                        $staleIds = array_values(array_unique(array_map('intval', $staleIds)));
                        $staleIds = array_values(array_filter($staleIds, function ($v) {
                            return $v > 0;
                        }));
                        if (count($staleIds) > 0) {
                            $placeholders = implode(',', array_fill(0, count($staleIds), '?'));
                            $cleanStmt = $authDb->prepare("DELETE FROM public_shared_items WHERE id IN ($placeholders)");
                            $cleanStmt->execute($staleIds);
                            removePublicSharedCommentsByShareIds($authDb, $staleIds);
                        }
                    }
                    if (count($sharedList) > 0) {
                        $shareIds = array_values(array_filter(array_map(function ($v) {
                            return intval($v['id'] ?? 0);
                        }, $sharedList), function ($v) {
                            return $v > 0;
                        }));
                        if (count($shareIds) > 0) {
                            $placeholders = implode(',', array_fill(0, count($shareIds), '?'));
                            $commentStmt = $authDb->prepare("SELECT
                                    c.id,
                                    c.shared_id,
                                    c.user_id,
                                    c.content,
                                    c.created_at,
                                    u.username,
                                    u.display_name
                                FROM public_shared_comments c
                                LEFT JOIN users u ON u.id=c.user_id
                                WHERE c.shared_id IN ($placeholders)
                                ORDER BY c.created_at ASC, c.id ASC");
                            $commentStmt->execute($shareIds);
                            $commentRows = $commentStmt->fetchAll();
                            $commentMap = [];
                            foreach ($commentRows as $commentRow) {
                                $sid = intval($commentRow['shared_id'] ?? 0);
                                if ($sid <= 0) {
                                    continue;
                                }
                                $commentUserName = trim((string) ($commentRow['display_name'] ?? ''));
                                if ($commentUserName === '') {
                                    $commentUserName = trim((string) ($commentRow['username'] ?? ''));
                                }
                                if ($commentUserName === '') {
                                    $commentUserName = '用户#' . intval($commentRow['user_id'] ?? 0);
                                }
                                if (!isset($commentMap[$sid])) {
                                    $commentMap[$sid] = [];
                                }
                                $commentMap[$sid][] = [
                                    'id' => intval($commentRow['id'] ?? 0),
                                    'shared_id' => $sid,
                                    'user_id' => intval($commentRow['user_id'] ?? 0),
                                    'user_name' => $commentUserName,
                                    'content' => trim((string) ($commentRow['content'] ?? '')),
                                    'created_at' => trim((string) ($commentRow['created_at'] ?? '')),
                                    'can_delete' => (
                                        intval($commentRow['user_id'] ?? 0) === intval($currentUser['id'])
                                        || isAdminUser($currentUser)
                                    )
                                ];
                            }
                            foreach ($sharedList as &$sharedItem) {
                                $sid = intval($sharedItem['id'] ?? 0);
                                $comments = $commentMap[$sid] ?? [];
                                $sharedItem['comments'] = $comments;
                                $sharedItem['comment_count'] = count($comments);
                            }
                            unset($sharedItem);
                        }
                    }
                    $result = ['success' => true, 'data' => $sharedList];
                }
                break;

            case 'public-channel/update':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $sharedId = intval($data['shared_id'] ?? 0);
                    if ($sharedId <= 0) {
                        $result = ['success' => false, 'message' => '缺少共享物品ID'];
                        break;
                    }
                    $shareStmt = $authDb->prepare("SELECT
                            p.id,
                            p.owner_user_id,
                            p.owner_item_id,
                            u.username AS owner_username
                        FROM public_shared_items p
                        LEFT JOIN users u ON u.id=p.owner_user_id
                        WHERE p.id=?
                        LIMIT 1");
                    $shareStmt->execute([$sharedId]);
                    $shareRow = $shareStmt->fetch();
                    if (!$shareRow) {
                        $result = ['success' => false, 'message' => '共享记录不存在或已失效'];
                        break;
                    }
                    if (isDemoUsername($shareRow['owner_username'] ?? '') !== $currentUserIsDemoScope) {
                        $result = ['success' => false, 'message' => '共享记录不存在或已失效'];
                        break;
                    }
                    $ownerId = intval($shareRow['owner_user_id'] ?? 0);
                    $ownerItemId = intval($shareRow['owner_item_id'] ?? 0);
                    if ($ownerId !== intval($currentUser['id'])) {
                        $result = ['success' => false, 'message' => '仅发布者可以编辑该共享物品'];
                        break;
                    }
                    if ($ownerItemId <= 0) {
                        $result = ['success' => false, 'message' => '共享记录无效'];
                        break;
                    }
                    $itemName = trim((string) ($data['item_name'] ?? ''));
                    if ($itemName === '') {
                        $result = ['success' => false, 'message' => '物品名称不能为空'];
                        break;
                    }
                    $categoryId = max(0, intval($data['category_id'] ?? 0));
                    if ($categoryId > 0) {
                        $catExistsStmt = $db->prepare("SELECT id FROM categories WHERE id=? LIMIT 1");
                        $catExistsStmt->execute([$categoryId]);
                        if (!$catExistsStmt->fetchColumn()) {
                            $result = ['success' => false, 'message' => '分类不存在'];
                            break;
                        }
                    }
                    $purchasePrice = max(0, floatval($data['purchase_price'] ?? 0));
                    $purchaseFrom = trim((string) ($data['purchase_from'] ?? ''));
                    $recommendReason = trim((string) ($data['recommend_reason'] ?? ''));
                    if (function_exists('mb_substr')) {
                        $recommendReason = mb_substr($recommendReason, 0, 300, 'UTF-8');
                    } else {
                        $recommendReason = substr($recommendReason, 0, 300);
                    }
                    $existsStmt = $db->prepare("SELECT is_public_shared FROM items WHERE id=? AND deleted_at IS NULL LIMIT 1");
                    $existsStmt->execute([$ownerItemId]);
                    $existsRow = $existsStmt->fetch();
                    if (!$existsRow || intval($existsRow['is_public_shared'] ?? 0) !== 1) {
                        removePublicSharedItem($authDb, intval($currentUser['id']), $ownerItemId);
                        $result = ['success' => false, 'message' => '该共享物品已取消共享或不存在'];
                        break;
                    }
                    $updateStmt = $db->prepare("UPDATE items
                        SET name=?, category_id=?, purchase_price=?, purchase_from=?, public_recommend_reason=?, updated_at=datetime('now','localtime')
                        WHERE id=? AND deleted_at IS NULL");
                    $updateStmt->execute([$itemName, $categoryId, $purchasePrice, $purchaseFrom, $recommendReason, $ownerItemId]);
                    syncPublicSharedItem($authDb, $db, intval($currentUser['id']), $ownerItemId, 1);
                    $catName = '';
                    if ($categoryId > 0) {
                        $catName = trim((string) ($db->query("SELECT name FROM categories WHERE id=" . $categoryId . " LIMIT 1")->fetchColumn() ?: ''));
                    }
                    $operationDetails = '共享ID: ' . $sharedId
                        . '；物品: ' . $itemName . '（来源物品ID:' . $ownerItemId . '）'
                        . ($catName !== '' ? ('；分类: ' . $catName) : '')
                        . ($purchaseFrom !== '' ? ('；购入渠道: ' . $purchaseFrom) : '')
                        . '；价格: ' . $purchasePrice;
                    $result = ['success' => true, 'message' => '共享物品已更新'];
                }
                break;

            case 'public-channel/comment':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $sharedId = intval($data['shared_id'] ?? 0);
                    $content = trim((string) ($data['content'] ?? ''));
                    if ($sharedId <= 0) {
                        $result = ['success' => false, 'message' => '缺少共享物品ID'];
                        break;
                    }
                    if ($content === '') {
                        $result = ['success' => false, 'message' => '评论内容不能为空'];
                        break;
                    }
                    if (function_exists('mb_substr')) {
                        $content = mb_substr($content, 0, 300, 'UTF-8');
                    } else {
                        $content = substr($content, 0, 300);
                    }
                    $shareStmt = $authDb->prepare("SELECT
                            p.owner_user_id,
                            p.owner_item_id,
                            u.username AS owner_username
                        FROM public_shared_items p
                        LEFT JOIN users u ON u.id=p.owner_user_id
                        WHERE p.id=?
                        LIMIT 1");
                    $shareStmt->execute([$sharedId]);
                    $shareRow = $shareStmt->fetch();
                    if (!$shareRow) {
                        $result = ['success' => false, 'message' => '共享记录不存在或已失效'];
                        break;
                    }
                    if (isDemoUsername($shareRow['owner_username'] ?? '') !== $currentUserIsDemoScope) {
                        $result = ['success' => false, 'message' => '共享记录不存在或已失效'];
                        break;
                    }
                    $ownerId = intval($shareRow['owner_user_id'] ?? 0);
                    $ownerItemId = intval($shareRow['owner_item_id'] ?? 0);
                    try {
                        $ownerDb = getUserDB($ownerId);
                    } catch (Exception $e) {
                        removePublicSharedCommentsByShareIds($authDb, [$sharedId]);
                        $cleanStmt = $authDb->prepare("DELETE FROM public_shared_items WHERE id=?");
                        $cleanStmt->execute([$sharedId]);
                        $result = ['success' => false, 'message' => '共享记录已失效'];
                        break;
                    }
                    $live = getItemShareSnapshot($ownerDb, $ownerItemId);
                    if (!$live || intval($live['is_public_shared'] ?? 0) !== 1) {
                        removePublicSharedCommentsByShareIds($authDb, [$sharedId]);
                        $cleanStmt = $authDb->prepare("DELETE FROM public_shared_items WHERE id=?");
                        $cleanStmt->execute([$sharedId]);
                        $result = ['success' => false, 'message' => '该共享物品已取消共享或不存在'];
                        break;
                    }
                    $insertStmt = $authDb->prepare("INSERT INTO public_shared_comments (shared_id, user_id, content, created_at, updated_at)
                        VALUES (?,?,?,datetime('now','localtime'),datetime('now','localtime'))");
                    $insertStmt->execute([$sharedId, intval($currentUser['id']), $content]);
                    $operationDetails = '共享ID: ' . $sharedId . '；评论内容: ' . $content;
                    $result = ['success' => true, 'message' => '评论已发布'];
                }
                break;

            case 'public-channel/comment-delete':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $commentId = intval($data['comment_id'] ?? 0);
                    if ($commentId <= 0) {
                        $result = ['success' => false, 'message' => '缺少评论ID'];
                        break;
                    }
                    $stmt = $authDb->prepare("SELECT
                            c.id,
                            c.user_id,
                            c.shared_id,
                            u.username AS owner_username
                        FROM public_shared_comments c
                        LEFT JOIN public_shared_items p ON p.id=c.shared_id
                        LEFT JOIN users u ON u.id=p.owner_user_id
                        WHERE c.id=?
                        LIMIT 1");
                    $stmt->execute([$commentId]);
                    $comment = $stmt->fetch();
                    if (!$comment) {
                        $result = ['success' => false, 'message' => '评论不存在或已删除'];
                        break;
                    }
                    if (isDemoUsername($comment['owner_username'] ?? '') !== $currentUserIsDemoScope) {
                        $result = ['success' => false, 'message' => '评论不存在或已删除'];
                        break;
                    }
                    $commentUserId = intval($comment['user_id'] ?? 0);
                    $canDelete = ($commentUserId === intval($currentUser['id'])) || isAdminUser($currentUser);
                    if (!$canDelete) {
                        $result = ['success' => false, 'message' => '仅评论者或管理员可删除评论'];
                        break;
                    }
                    $delStmt = $authDb->prepare("DELETE FROM public_shared_comments WHERE id=?");
                    $delStmt->execute([$commentId]);
                    $operationDetails = '评论ID: ' . $commentId . '；共享ID: ' . intval($comment['shared_id'] ?? 0);
                    $result = ['success' => true, 'message' => '评论已删除'];
                }
                break;

            case 'public-channel/add-to-shopping':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $sharedId = intval($data['shared_id'] ?? 0);
                    if ($sharedId <= 0) {
                        $result = ['success' => false, 'message' => '缺少共享物品ID'];
                        break;
                    }
                    $shareStmt = $authDb->prepare("SELECT
                            p.id,
                            p.owner_user_id,
                            p.owner_item_id,
                            p.recommend_reason,
                            u.username,
                            u.display_name
                        FROM public_shared_items p
                        LEFT JOIN users u ON u.id=p.owner_user_id
                        WHERE p.id=?
                        LIMIT 1");
                    $shareStmt->execute([$sharedId]);
                    $shareRow = $shareStmt->fetch();
                    if (!$shareRow) {
                        $result = ['success' => false, 'message' => '共享记录不存在或已失效'];
                        break;
                    }
                    if (isDemoUsername($shareRow['username'] ?? '') !== $currentUserIsDemoScope) {
                        $result = ['success' => false, 'message' => '共享记录不存在或已失效'];
                        break;
                    }
                    $ownerId = intval($shareRow['owner_user_id'] ?? 0);
                    $ownerItemId = intval($shareRow['owner_item_id'] ?? 0);
                    if ($ownerId <= 0 || $ownerItemId <= 0) {
                        $result = ['success' => false, 'message' => '共享记录无效'];
                        break;
                    }
                    try {
                        $ownerDb = getUserDB($ownerId);
                    } catch (Exception $e) {
                        removePublicSharedCommentsByShareIds($authDb, [$sharedId]);
                        $cleanStmt = $authDb->prepare("DELETE FROM public_shared_items WHERE id=?");
                        $cleanStmt->execute([$sharedId]);
                        $result = ['success' => false, 'message' => '共享记录已失效'];
                        break;
                    }
                    $live = getItemShareSnapshot($ownerDb, $ownerItemId);
                    if (!$live || intval($live['is_public_shared'] ?? 0) !== 1) {
                        removePublicSharedCommentsByShareIds($authDb, [$sharedId]);
                        $cleanStmt = $authDb->prepare("DELETE FROM public_shared_items WHERE id=?");
                        $cleanStmt->execute([$sharedId]);
                        $result = ['success' => false, 'message' => '该共享物品已取消共享或不存在'];
                        break;
                    }
                    $itemName = trim((string) ($live['name'] ?? ''));
                    if ($itemName === '') {
                        $result = ['success' => false, 'message' => '共享物品名称无效'];
                        break;
                    }
                    $ownerName = trim((string) ($shareRow['display_name'] ?? ''));
                    if ($ownerName === '') {
                        $ownerName = trim((string) ($shareRow['username'] ?? ''));
                    }
                    if ($ownerName === '') {
                        $ownerName = '用户#' . $ownerId;
                    }
                    $categoryName = trim((string) ($live['category_name'] ?? ''));
                    $categoryId = 0;
                    if ($categoryName !== '') {
                        $catStmt = $db->prepare("SELECT id FROM categories WHERE name=? LIMIT 1");
                        $catStmt->execute([$categoryName]);
                        $categoryId = intval($catStmt->fetchColumn() ?: 0);
                    }
                    $plannedPrice = max(0, floatval($live['purchase_price'] ?? 0));
                    $purchaseFrom = trim((string) ($live['purchase_from'] ?? ''));
                    $dupStmt = $db->prepare("SELECT id FROM shopping_list WHERE source_shared_id=? LIMIT 1");
                    $dupStmt->execute([$sharedId]);
                    $existId = intval($dupStmt->fetchColumn() ?: 0);
                    if ($existId <= 0) {
                        // 兼容历史数据：旧版本通过 notes 中的 [public-share:id] 做去重标记
                        $legacyMarker = "[public-share:$sharedId]";
                        $legacyDupStmt = $db->prepare("SELECT id FROM shopping_list WHERE name=? AND notes LIKE ? LIMIT 1");
                        $legacyDupStmt->execute([$itemName, '%' . $legacyMarker . '%']);
                        $existId = intval($legacyDupStmt->fetchColumn() ?: 0);
                    }
                    if ($existId > 0) {
                        $operationDetails = '共享ID: ' . $sharedId . '；物品: ' . $itemName . '；已存在购物清单ID: ' . $existId;
                        $result = ['success' => true, 'message' => '该共享物品已在你的购物清单中', 'id' => $existId];
                        break;
                    }
                    $noteParts = ['来自公共频道', '1件', '发布者: ' . $ownerName];
                    if ($purchaseFrom !== '') {
                        $noteParts[] = '购入渠道: ' . $purchaseFrom;
                    }
                    if ($categoryName !== '') {
                        $noteParts[] = '分类: ' . $categoryName;
                    }
                    $recommendReason = trim((string) ($live['recommend_reason'] ?? $shareRow['recommend_reason'] ?? ''));
                    if ($recommendReason !== '') {
                        $noteParts[] = '推荐理由: ' . $recommendReason;
                    }
                    $notes = implode('；', $noteParts);
                    $insertStmt = $db->prepare("INSERT INTO shopping_list
                        (name, quantity, status, category_id, priority, planned_price, source_shared_id, notes, reminder_date, reminder_note, created_at, updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,datetime('now','localtime'),datetime('now','localtime'))");
                    $insertStmt->execute([
                        $itemName,
                        1,
                        'pending_purchase',
                        $categoryId,
                        'normal',
                        $plannedPrice,
                        $sharedId,
                        $notes,
                        '',
                        ''
                    ]);
                    $newShoppingId = intval($db->lastInsertId());
                    $operationDetails = '共享ID: ' . $sharedId
                        . '；物品: ' . $itemName
                        . '；发布者: ' . $ownerName
                        . '；已加入购物清单ID: ' . $newShoppingId;
                    $result = ['success' => true, 'message' => '已加入你的购物清单', 'id' => $newShoppingId];
                }
                break;

            // ---------- 图片上传 ----------
            case 'upload':
                if ($method === 'POST') {
                    if (!isset($_FILES['image'])) {
                        $result = ['success' => false, 'message' => '未接收到图片文件，可能超过服务器 post_max_size 限制'];
                        break;
                    }
                    $file = $_FILES['image'];
                    $uploadErr = intval($file['error'] ?? UPLOAD_ERR_NO_FILE);
                    if ($uploadErr !== UPLOAD_ERR_OK) {
                        $result = ['success' => false, 'message' => getUploadErrorMessage($uploadErr)];
                        break;
                    }

                    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $mime = $file['type'] ?? '';
                    if (function_exists('mime_content_type') && !empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
                        $detected = mime_content_type($file['tmp_name']);
                        if ($detected)
                            $mime = $detected;
                    }
                    if (!in_array($mime, $allowed, true)) {
                        $result = ['success' => false, 'message' => '不支持的图片格式'];
                        break;
                    }
                    if ($file['size'] > MAX_UPLOAD_SIZE) {
                        $result = ['success' => false, 'message' => '文件超过' . intval(MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB限制'];
                        break;
                    }
                    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                    // 获取原始文件名（去扩展名）和物品名称，过滤非法字符
                    $origName = pathinfo($file['name'], PATHINFO_FILENAME);
                    $origName = preg_replace('/[^\p{L}\p{N}_\-]/u', '', $origName); // 保留字母、数字、中文、下划线、连字符
                    $itemName = trim($_POST['item_name'] ?? '');
                    $itemName = preg_replace('/[^\p{L}\p{N}_\-]/u', '', $itemName);
                    // 截断过长的名称
                    $origName = mb_substr($origName, 0, 30);
                    $itemName = mb_substr($itemName, 0, 30);
                    $suffix = ($origName ? '_' . $origName : '') . ($itemName ? '_' . $itemName : '');
                    $filename = uniqid('img_') . $suffix . '.' . $ext;
                    if (!is_uploaded_file($file['tmp_name'])) {
                        $result = ['success' => false, 'message' => '上传失败：无效上传文件'];
                    } elseif (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $filename)) {
                        $operationDetails = '图片: ' . $filename . '；原文件: ' . trim((string) ($file['name'] ?? '')) . '；大小: ' . intval($file['size'] ?? 0) . ' 字节';
                        $result = ['success' => true, 'filename' => $filename];
                    } else {
                        $result = ['success' => false, 'message' => '上传失败'];
                    }
                }
                break;

            case 'upload/batch-import':
                if ($method === 'POST') {
                    if (!isset($_FILES['images'])) {
                        $result = ['success' => false, 'message' => '未选择图片文件'];
                        break;
                    }
                    $files = $_FILES['images'];
                    if (!is_array($files['name'] ?? null)) {
                        $result = ['success' => false, 'message' => '图片参数格式错误'];
                        break;
                    }

                    $map = [];
                    $uploaded = 0;
                    $errors = [];
                    $count = count($files['name']);
                    for ($i = 0; $i < $count; $i++) {
                        $name = $files['name'][$i] ?? '';
                        $tmpName = $files['tmp_name'][$i] ?? '';
                        $err = intval($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                        $size = intval($files['size'][$i] ?? 0);
                        if ($err !== UPLOAD_ERR_OK || !$name || !$tmpName)
                            continue;
                        if ($size > MAX_UPLOAD_SIZE) {
                            $errors[] = $name . ' 超过' . intval(MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB限制';
                            continue;
                        }
                        $storedName = makeUniqueImportImageFilename($name);
                        if (move_uploaded_file($tmpName, UPLOAD_DIR . $storedName)) {
                            $map[$name] = $storedName;
                            $uploaded++;
                        } else {
                            $errors[] = $name . ' 上传失败';
                        }
                    }

                    if ($uploaded === 0) {
                        $result = ['success' => false, 'message' => '没有成功上传任何图片', 'errors' => $errors];
                    } else {
                        $sampleNames = array_slice(array_keys($map), 0, 3);
                        $operationDetails = '上传数量: ' . $uploaded;
                        if (count($sampleNames) > 0) {
                            $operationDetails .= '；示例文件: ' . implode('、', $sampleNames);
                        }
                        $result = ['success' => true, 'message' => "成功上传 $uploaded 张图片", 'uploaded' => $uploaded, 'map' => $map, 'errors' => $errors];
                    }
                }
                break;

            // ---------- 数据导出 ----------
            case 'export':
                $items = $db->query("SELECT i.*, c.name as category_name, sc.name as subcategory_name, l.name as location_name FROM items i LEFT JOIN categories c ON i.category_id=c.id LEFT JOIN categories sc ON i.subcategory_id=sc.id LEFT JOIN locations l ON i.location_id=l.id WHERE i.deleted_at IS NULL ORDER BY i.id")->fetchAll();
                $categories = $db->query("SELECT * FROM categories ORDER BY id")->fetchAll();
                $locations = $db->query("SELECT * FROM locations ORDER BY id")->fetchAll();
                $shoppingList = $db->query("SELECT s.*, c.name as category_name FROM shopping_list s LEFT JOIN categories c ON s.category_id=c.id ORDER BY s.id")->fetchAll();
                $result = ['success' => true, 'data' => ['items' => $items, 'categories' => $categories, 'locations' => $locations, 'shopping_list' => $shoppingList, 'exported_at' => date('Y-m-d H:i:s'), 'version' => '1.6.1']];
                break;

            // ---------- 数据导入 ----------
            case 'import':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    if (!$data || !isset($data['items'])) {
                        $result = ['success' => false, 'message' => '数据格式错误'];
                        break;
                    }
                    $db->beginTransaction();
                    try {
                        $imageNameMap = [];
                        if (!empty($data['image_name_map']) && is_array($data['image_name_map'])) {
                            foreach ($data['image_name_map'] as $old => $new) {
                                $oldName = basename((string) $old);
                                $newName = basename((string) $new);
                                if ($oldName && $newName)
                                    $imageNameMap[$oldName] = $newName;
                            }
                        }
                        if (!empty($data['embedded_images']) && is_array($data['embedded_images'])) {
                            $mimeExt = [
                                'image/jpeg' => 'jpg',
                                'image/jpg' => 'jpg',
                                'image/png' => 'png',
                                'image/gif' => 'gif',
                                'image/webp' => 'webp',
                                'image/bmp' => 'bmp',
                                'image/svg+xml' => 'svg',
                            ];
                            foreach ($data['embedded_images'] as $oldName => $dataUrl) {
                                $oldName = basename((string) $oldName);
                                if (!$oldName || !is_string($dataUrl))
                                    continue;
                                if (!preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/', $dataUrl, $m))
                                    continue;
                                $mime = strtolower($m[1]);
                                $bin = base64_decode(str_replace(' ', '+', $m[2]), true);
                                if ($bin === false || strlen($bin) === 0)
                                    continue;

                                $ext = $mimeExt[$mime] ?? strtolower(pathinfo($oldName, PATHINFO_EXTENSION));
                                $seedName = pathinfo($oldName, PATHINFO_FILENAME) . '.' . ($ext ?: 'jpg');
                                $storedName = makeUniqueImportImageFilename($seedName);
                                if (@file_put_contents(UPLOAD_DIR . $storedName, $bin) !== false) {
                                    $imageNameMap[$oldName] = $storedName;
                                }
                            }
                        }

                        $imported = 0;
                        $stmtItem = $db->prepare("INSERT INTO items (name, category_id, subcategory_id, location_id, quantity, description, image, barcode, purchase_date, purchase_price, tags, status, expiry_date, purchase_from, notes, reminder_date, reminder_next_date, reminder_cycle_value, reminder_cycle_unit, reminder_note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        foreach ($data['items'] as $item) {
                            $categoryCandidate = 0;
                            $subcategoryCandidate = 0;
                            $locId = 0;
                            if (!empty($item['category_name'])) {
                                $cat = $db->query("SELECT id FROM categories WHERE name=" . $db->quote($item['category_name']))->fetchColumn();
                                $categoryCandidate = $cat ?: 0;
                            } elseif (intval($item['category_id'] ?? 0) > 0) {
                                $categoryCandidate = intval($item['category_id']);
                            }
                            if (!empty($item['subcategory_name'])) {
                                $sub = $db->query("SELECT id FROM categories WHERE name=" . $db->quote($item['subcategory_name']) . " AND parent_id>0 LIMIT 1")->fetchColumn();
                                $subcategoryCandidate = $sub ?: 0;
                            } elseif (intval($item['subcategory_id'] ?? 0) > 0) {
                                $subcategoryCandidate = intval($item['subcategory_id']);
                            }
                            [$catId, $subcatId, $catErr] = normalizeItemCategorySelection($db, $categoryCandidate, $subcategoryCandidate);
                            if ($catErr) {
                                [$catId, $subcatId, $catErrFallback] = normalizeItemCategorySelection($db, $categoryCandidate, 0);
                                if ($catErrFallback) {
                                    $catId = 0;
                                    $subcatId = 0;
                                }
                            }
                            if (!empty($item['location_name'])) {
                                $loc = $db->query("SELECT id FROM locations WHERE name=" . $db->quote($item['location_name']))->fetchColumn();
                                $locId = $loc ?: 0;
                            }
                            $imageName = '';
                            $oldImageName = basename((string) ($item['image'] ?? ''));
                            if ($oldImageName) {
                                if (!empty($imageNameMap[$oldImageName])) {
                                    $imageName = $imageNameMap[$oldImageName];
                                } elseif (file_exists(UPLOAD_DIR . $oldImageName)) {
                                    $imageName = $oldImageName;
                                }
                            }
                            $reminderDate = normalizeReminderDateValue($item['reminder_date'] ?? '');
                            $reminderNextDate = normalizeReminderDateValue($item['reminder_next_date'] ?? '');
                            $reminderUnit = normalizeReminderCycleUnit($item['reminder_cycle_unit'] ?? '');
                            $reminderValue = normalizeReminderCycleValue($item['reminder_cycle_value'] ?? 0, $reminderUnit);
                            if ($reminderDate === '' || $reminderUnit === '' || $reminderValue <= 0) {
                                $reminderDate = '';
                                $reminderNextDate = '';
                                $reminderValue = 0;
                                $reminderUnit = '';
                            } elseif ($reminderNextDate === '') {
                                $reminderNextDate = $reminderDate;
                            }
                            $reminderNote = trim((string) ($item['reminder_note'] ?? ''));
                            $stmtItem->execute([
                                $item['name'] ?? '未命名',
                                $catId,
                                $subcatId,
                                $locId,
                                intval($item['quantity'] ?? 1),
                                $item['description'] ?? '',
                                $imageName,
                                $item['barcode'] ?? '',
                                $item['purchase_date'] ?? '',
                                floatval($item['purchase_price'] ?? 0),
                                $item['tags'] ?? '',
                                normalizeStatusValue($item['status'] ?? 'active'),
                                $item['expiry_date'] ?? '',
                                $item['purchase_from'] ?? '',
                                $item['notes'] ?? '',
                                $reminderDate,
                                $reminderNextDate,
                                $reminderValue,
                                $reminderUnit,
                                $reminderNote
                            ]);
                            $imported++;
                        }

                        $importedShopping = 0;
                        if (!empty($data['shopping_list']) && is_array($data['shopping_list'])) {
                            $stmtShopping = $db->prepare("INSERT INTO shopping_list (name, quantity, status, category_id, priority, planned_price, notes, reminder_date, reminder_note, created_at, updated_at)
                                VALUES (?,?,?,?,?,?,?,?,?,datetime('now','localtime'),datetime('now','localtime'))");
                            foreach ($data['shopping_list'] as $row) {
                                if (!is_array($row))
                                    continue;
                                $name = trim((string) ($row['name'] ?? ''));
                                if ($name === '')
                                    continue;
                                $categoryId = 0;
                                if (!empty($row['category_name'])) {
                                    $cat = $db->query("SELECT id FROM categories WHERE name=" . $db->quote($row['category_name']))->fetchColumn();
                                    $categoryId = $cat ?: 0;
                                } elseif (intval($row['category_id'] ?? 0) > 0) {
                                    $candidate = intval($row['category_id']);
                                    $exists = $db->query("SELECT id FROM categories WHERE id=" . $candidate)->fetchColumn();
                                    $categoryId = $exists ? $candidate : 0;
                                }
                                $stmtShopping->execute([
                                    $name,
                                    max(1, intval($row['quantity'] ?? 1)),
                                    normalizeShoppingStatus($row['status'] ?? 'pending_purchase'),
                                    $categoryId,
                                    normalizeShoppingPriority($row['priority'] ?? 'normal'),
                                    max(0, floatval($row['planned_price'] ?? 0)),
                                    trim((string) ($row['notes'] ?? '')),
                                    normalizeReminderDateValue($row['reminder_date'] ?? ''),
                                    trim((string) ($row['reminder_note'] ?? ''))
                                ]);
                                $importedShopping++;
                            }
                        }
                        $db->commit();
                        $operationDetails = '导入物品: ' . $imported . '；导入购物清单: ' . $importedShopping;
                        $result = ['success' => true, 'message' => "成功导入 $imported 件物品" . ($importedShopping > 0 ? "，购物清单 $importedShopping 条" : '')];
                    } catch (Exception $e) {
                        $db->rollBack();
                        $result = ['success' => false, 'message' => '导入失败: ' . $e->getMessage()];
                    }
                }
                break;
        }

        $operationLogMap = [
            'items' => '新增物品',
            'items/update' => '编辑物品',
            'items/complete-reminder' => '完成提醒',
            'items/undo-reminder' => '撤销提醒',
            'items/delete' => '删除物品到回收站',
            'items/batch-delete' => '批量删除物品到回收站',
            'items/reset-all' => '重置物品数据',
            'items/batch-import-manual' => '批量导入物品',
            'system/reset-default' => '恢复默认环境',
            'system/load-demo' => '加载展示数据',
            'platform-settings' => '更新平台设置',
            'trash/restore' => '恢复回收站物品',
            'trash/batch-restore' => '批量恢复回收站物品',
            'trash/permanent-delete' => '彻底删除回收站物品',
            'trash/empty' => '清空回收站',
            'categories' => '新增分类',
            'categories/update' => '编辑分类',
            'categories/delete' => '删除分类',
            'locations' => '新增位置',
            'locations/update' => '编辑位置',
            'locations/delete' => '删除位置',
            'shopping-list' => '新增购物清单',
            'shopping-list/update' => '编辑购物清单',
            'shopping-list/update-status' => '切换购物清单状态',
            'shopping-list/delete' => '删除购物清单',
            'shopping-list/convert' => '购物清单入库',
            'message-board' => '新增任务',
            'message-board/update' => '编辑任务',
            'message-board/delete' => '删除任务',
            'public-channel/update' => '编辑公共频道共享物品',
            'public-channel/comment' => '发表评论',
            'public-channel/comment-delete' => '删除评论',
            'public-channel/add-to-shopping' => '公共频道加入购物清单',
            'upload' => '上传图片',
            'upload/batch-import' => '批量上传图片',
            'import' => '导入数据'
        ];
        if ($method !== 'GET' && !empty($result['success']) && isset($operationLogMap[$api])) {
            $detail = composeOperationLogDetail($operationDetails, $result);
            logUserOperation($db, str_replace('/', '_', $api), $operationLogMap[$api], $detail, $api, $method);
            logAdminOperation($authDb, $currentUser, str_replace('/', '_', $api), $operationLogMap[$api], $detail, $api, $method);
        }

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '服务器错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ---------- 图片访问 ----------
if (isset($_GET['img'])) {
    $authDb = getAuthDB();
    $currentUser = getCurrentAuthUser($authDb);
    if (!$currentUser) {
        http_response_code(403);
        exit;
    }
    $file = basename($_GET['img']);
    $path = UPLOAD_DIR . $file;
    if (file_exists($path)) {
        $mime = mime_content_type($path);
        header("Content-Type: $mime");
        header("Cache-Control: public, max-age=86400");
        readfile($path);
    } else {
        http_response_code(404);
    }
    exit;
}

// ============================================================
// 🎨 前端 HTML
// ============================================================
$authDb = getAuthDB();
$currentAuthUser = getCurrentAuthUser($authDb);
if (!$currentAuthUser) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>17物品管理 | 登录</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
        <style>
            body {
                margin: 0;
                min-height: 100vh;
                background: radial-gradient(ellipse at 20% 30%, rgba(56, 189, 248, 0.18), transparent 50%), radial-gradient(ellipse at 80% 20%, rgba(99, 102, 241, 0.16), transparent 50%), #0f172a;
                color: #e2e8f0;
                font-family: Inter, "Noto Sans SC", sans-serif;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
            }

            .auth-card {
                width: min(460px, 100%);
                background: rgba(30, 41, 59, 0.72);
                border: 1px solid rgba(255, 255, 255, 0.12);
                border-radius: 18px;
                backdrop-filter: blur(14px);
                -webkit-backdrop-filter: blur(14px);
                padding: 24px;
                box-shadow: 0 20px 40px rgba(2, 6, 23, 0.45);
            }

            .auth-input {
                width: 100%;
                border-radius: 10px;
                border: 1px solid rgba(148, 163, 184, 0.35);
                background: rgba(15, 23, 42, 0.64);
                color: #e2e8f0;
                padding: 10px 12px;
                outline: none;
            }

            .auth-input:focus {
                border-color: #38bdf8;
                box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.18);
            }

            select.auth-input {
                -webkit-appearance: none;
                -moz-appearance: none;
                appearance: none;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
                background-repeat: no-repeat;
                background-size: 12px 12px;
                background-position: right 10px center;
                padding-right: 32px;
            }

            select.auth-input::-ms-expand {
                display: none;
            }

            .auth-custom-select {
                position: relative;
                width: 100%;
            }

            .auth-custom-native {
                position: absolute !important;
                width: 1px !important;
                height: 1px !important;
                margin: 0 !important;
                padding: 0 !important;
                border: 0 !important;
                opacity: 0 !important;
                pointer-events: none !important;
                clip: rect(0, 0, 0, 0) !important;
                clip-path: inset(50%) !important;
                overflow: hidden !important;
            }

            .auth-custom-trigger {
                display: inline-flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                text-align: left;
                cursor: pointer;
            }

            .auth-custom-trigger .label {
                flex: 1;
                min-width: 0;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .auth-custom-select.open .auth-custom-trigger .arrow {
                transform: rotate(180deg);
                color: #7dd3fc;
            }

            .auth-custom-trigger .arrow {
                transition: transform 0.18s ease, color 0.18s ease;
            }

            .auth-custom-menu {
                position: absolute;
                left: 0;
                top: calc(100% + 6px);
                width: 100%;
                max-height: 220px;
                overflow: auto;
                padding: 6px;
                border-radius: 10px;
                border: 1px solid rgba(148, 163, 184, 0.35);
                background: rgba(15, 23, 42, 0.96);
                box-shadow: 0 12px 24px rgba(2, 6, 23, 0.45);
                z-index: 80;
            }

            .auth-custom-option {
                width: 100%;
                border: 0;
                border-radius: 8px;
                background: transparent;
                color: #cbd5e1;
                padding: 8px 10px;
                text-align: left;
                display: flex;
                align-items: center;
                justify-content: space-between;
                font-size: 13px;
                cursor: pointer;
            }

            .auth-custom-option:hover {
                background: rgba(255, 255, 255, 0.08);
            }

            .auth-custom-option.active {
                background: rgba(14, 165, 233, 0.2);
                color: #7dd3fc;
            }

            .auth-custom-option .check {
                opacity: 0;
            }

            .auth-custom-option.active .check {
                opacity: 1;
            }

            .auth-custom-menu.hidden {
                display: none;
            }

            @media (max-width: 640px) {
                .auth-custom-option {
                    font-size: 14px;
                    padding: 10px 12px;
                }
            }

            .auth-btn {
                width: 100%;
                border: none;
                border-radius: 10px;
                padding: 10px 14px;
                color: #fff;
                font-weight: 600;
                cursor: pointer;
                background: linear-gradient(135deg, #0ea5e9, #6366f1);
            }

            .auth-btn.demo {
                background: linear-gradient(135deg, #14b8a6, #0ea5e9);
            }

            .auth-btn:disabled {
                background: #64748b;
                color: rgba(255, 255, 255, 0.8);
                cursor: not-allowed;
                opacity: 0.72;
            }

            .auth-tab {
                border: 1px solid rgba(148, 163, 184, 0.35);
                background: transparent;
                color: #94a3b8;
                border-radius: 999px;
                padding: 6px 12px;
                font-size: 12px;
                cursor: pointer;
            }

            .auth-tab.active {
                color: #e2e8f0;
                border-color: rgba(56, 189, 248, 0.45);
                background: rgba(14, 165, 233, 0.2);
            }

            .auth-link {
                color: #7dd3fc;
                font-size: 12px;
                cursor: pointer;
                border: none;
                background: transparent;
                padding: 0;
            }

            .auth-panel-note {
                border: 1px solid rgba(148, 163, 184, 0.32);
                background: rgba(15, 23, 42, 0.62);
                border-radius: 12px;
                padding: 12px;
                font-size: 13px;
                color: #cbd5e1;
                line-height: 1.6;
            }
        </style>
    </head>

    <body>
        <div class="auth-card">
            <div class="flex items-center gap-3 mb-5">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-sky-400 to-violet-500 flex items-center justify-center">
                    <i class="ri-lock-password-line text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="text-lg font-bold m-0">17 物品管理</h1>
                    <p class="text-xs text-slate-400 m-0">登录后按用户隔离数据</p>
                </div>
            </div>

            <div class="flex gap-2 mb-4">
                <button type="button" id="tabLogin" class="auth-tab active" onclick="switchAuthTab('login')">登录</button>
                <button type="button" id="tabRegister" class="auth-tab" onclick="switchAuthTab('register')">注册</button>
            </div>

            <p id="authHint" class="text-xs text-slate-400 mb-4"></p>

            <form id="loginForm" class="space-y-3" onsubmit="return submitLogin(event)">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">用户名</label>
                    <input type="text" id="loginUsername" class="auth-input" required autocomplete="username">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">密码</label>
                    <input type="password" id="loginPassword" class="auth-input" required autocomplete="current-password">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button class="auth-btn" type="submit">登录</button>
                    <button class="auth-btn demo" type="button" onclick="loginAsDemo()">Demo</button>
                </div>
                <div class="flex justify-end">
                    <button type="button" class="auth-link" onclick="switchAuthTab('reset')">忘记密码？</button>
                </div>
            </form>

            <form id="registerForm" class="space-y-3 hidden" onsubmit="return submitRegister(event)">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">用户名</label>
                    <input type="text" id="registerUsername" class="auth-input" required placeholder="3-32 位字母/数字/._-">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">显示名称</label>
                    <input type="text" id="registerDisplayName" class="auth-input" placeholder="可选，不填则同用户名">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">密码</label>
                    <input type="password" id="registerPassword" class="auth-input" required placeholder="至少 6 位">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">验证问题</label>
                    <select id="registerQuestionKey" class="auth-input" required>
                        <option value="">请选择验证问题</option>
                    </select>
                </div>
                <div id="registerCustomQuestionWrap" class="hidden">
                    <label class="block text-xs text-slate-400 mb-1">自定义问题</label>
                    <input type="text" id="registerCustomQuestion" class="auth-input" placeholder="请输入你的验证问题（2-60 字）">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">验证答案</label>
                    <input type="text" id="registerSecurityAnswer" class="auth-input" required placeholder="用于找回密码">
                </div>
                <button class="auth-btn" id="registerSubmitBtn" type="submit">创建账号并登录</button>
            </form>
            <div id="registerClosedPanel" class="hidden">
                <div class="auth-panel-note">
                    感谢关注，当前暂未开放注册功能，请稍后再试。
                </div>
            </div>

            <form id="resetForm" class="space-y-3 hidden" onsubmit="return submitResetPassword(event)">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">用户名</label>
                    <div class="flex gap-2">
                        <input type="text" id="resetUsername" class="auth-input" required>
                        <button type="button" class="auth-btn" style="width:auto;white-space:nowrap" onclick="loadResetQuestion()">查询问题</button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">验证问题</label>
                    <input type="text" id="resetQuestionLabel" class="auth-input" readonly placeholder="先查询问题">
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">验证答案</label>
                    <input type="text" id="resetAnswer" class="auth-input" required>
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">新密码</label>
                    <input type="password" id="resetNewPassword" class="auth-input" required placeholder="至少 6 位">
                </div>
                <button class="auth-btn" type="submit">验证并重置密码</button>
                <div class="flex justify-end">
                    <button type="button" class="auth-link" onclick="switchAuthTab('login')">返回登录</button>
                </div>
            </form>

            <p id="authMessage" class="text-sm mt-4 text-slate-300"></p>
        </div>

        <script>
            let authState = {
                allow_registration: true,
                needs_setup: false,
                security_questions: {},
                default_admin: { username: 'admin' },
                default_demo: { username: 'test' }
            };
            let resetQuestionKey = '';
            const authCustomSelectStates = new Map();
            let authCustomSelectBound = false;

            function authSelectText(option) {
                return String(option?.textContent || '').replace(/\s+/g, ' ').trim();
            }

            function closeAuthCustomSelect(state) {
                if (!state || !state.open) return;
                state.open = false;
                state.wrapper.classList.remove('open');
                state.menu.classList.add('hidden');
                state.trigger.setAttribute('aria-expanded', 'false');
            }

            function closeAllAuthCustomSelects(except = null) {
                authCustomSelectStates.forEach((state, select) => {
                    if (except && select === except) return;
                    closeAuthCustomSelect(state);
                });
            }

            function syncAuthCustomSelect(state) {
                if (!state || !state.select || !state.select.isConnected) return;
                const select = state.select;
                const menu = state.menu;
                menu.innerHTML = '';
                Array.from(select.options || []).forEach((opt, idx) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = `auth-custom-option ${select.selectedIndex === idx ? 'active' : ''}`;
                    btn.disabled = !!opt.disabled;
                    btn.innerHTML = `<span class="truncate">${authSelectText(opt) || opt.value || ''}</span><i class="ri-check-line check"></i>`;
                    btn.addEventListener('click', event => {
                        event.preventDefault();
                        event.stopPropagation();
                        if (btn.disabled) return;
                        const changed = select.selectedIndex !== idx;
                        select.selectedIndex = idx;
                        if (changed) {
                            select.dispatchEvent(new Event('input', { bubbles: true }));
                            select.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                        syncAuthCustomSelect(state);
                        closeAuthCustomSelect(state);
                        state.trigger.focus();
                    });
                    menu.appendChild(btn);
                });
                const selected = select.options && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
                state.label.textContent = selected ? authSelectText(selected) : '请选择';
                state.trigger.disabled = !!select.disabled;
            }

            function ensureAuthCustomSelect(select) {
                if (!(select instanceof HTMLSelectElement)) return;
                if (select.dataset.authCustomReady === '1') {
                    const existing = authCustomSelectStates.get(select);
                    if (existing) syncAuthCustomSelect(existing);
                    return;
                }
                const parent = select.parentElement;
                if (!parent) return;
                const classes = String(select.className || '').trim() || 'auth-input';
                const wrapper = document.createElement('div');
                wrapper.className = 'auth-custom-select';
                parent.insertBefore(wrapper, select);
                wrapper.appendChild(select);
                select.dataset.authCustomReady = '1';
                select.classList.add('auth-custom-native');

                const trigger = document.createElement('button');
                trigger.type = 'button';
                trigger.className = `${classes} auth-custom-trigger`;
                trigger.innerHTML = `<span class="label"></span><i class="ri-arrow-down-s-line arrow"></i>`;
                trigger.setAttribute('aria-expanded', 'false');
                trigger.setAttribute('aria-haspopup', 'listbox');

                const menu = document.createElement('div');
                menu.className = 'auth-custom-menu hidden';

                wrapper.appendChild(trigger);
                wrapper.appendChild(menu);

                const state = { select, wrapper, trigger, menu, label: trigger.querySelector('.label'), open: false };
                authCustomSelectStates.set(select, state);

                trigger.addEventListener('click', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    if (select.disabled) return;
                    if (state.open) {
                        closeAuthCustomSelect(state);
                    } else {
                        closeAllAuthCustomSelects(select);
                        syncAuthCustomSelect(state);
                        state.open = true;
                        wrapper.classList.add('open');
                        menu.classList.remove('hidden');
                        trigger.setAttribute('aria-expanded', 'true');
                    }
                });

                select.addEventListener('change', () => syncAuthCustomSelect(state));
                select.addEventListener('input', () => syncAuthCustomSelect(state));
                syncAuthCustomSelect(state);
            }

            function initAuthCustomSelects(root = document) {
                if (!authCustomSelectBound) {
                    authCustomSelectBound = true;
                    document.addEventListener('click', event => {
                        if (!(event.target instanceof Element)) return;
                        if (!event.target.closest('.auth-custom-select')) {
                            closeAllAuthCustomSelects();
                        }
                    });
                    document.addEventListener('keydown', event => {
                        if (event.key === 'Escape') closeAllAuthCustomSelects();
                    });
                }
                root.querySelectorAll('select.auth-input').forEach(select => ensureAuthCustomSelect(select));
            }

            function syncAuthCustomSelectById(id) {
                const select = document.getElementById(id);
                if (!(select instanceof HTMLSelectElement)) return;
                const state = authCustomSelectStates.get(select);
                if (state) syncAuthCustomSelect(state);
            }

            function setAuthMessage(msg, isError = false) {
                const el = document.getElementById('authMessage');
                if (!el) return;
                el.textContent = msg || '';
                el.style.color = isError ? '#f87171' : '#a5b4fc';
            }

            function switchAuthTab(tab) {
                const loginTab = document.getElementById('tabLogin');
                const regTab = document.getElementById('tabRegister');
                const resetTabActive = tab === 'reset';
                const loginForm = document.getElementById('loginForm');
                const registerForm = document.getElementById('registerForm');
                const registerClosedPanel = document.getElementById('registerClosedPanel');
                const resetForm = document.getElementById('resetForm');
                const isLogin = tab === 'login';
                const showRegisterForm = tab === 'register' && (authState.allow_registration || authState.needs_setup);
                loginTab.classList.toggle('active', isLogin);
                regTab.classList.toggle('active', tab === 'register');
                loginForm.classList.toggle('hidden', !isLogin);
                registerForm.classList.toggle('hidden', !showRegisterForm);
                if (registerClosedPanel) {
                    registerClosedPanel.classList.toggle('hidden', !(tab === 'register' && !showRegisterForm));
                }
                resetForm.classList.toggle('hidden', !resetTabActive);
                updateAuthHint(tab);
            }

            function fillSecurityQuestionOptions() {
                const select = document.getElementById('registerQuestionKey');
                if (!select) return;
                const questions = authState.security_questions || {};
                select.innerHTML = '<option value="">请选择验证问题</option>' + Object.entries(questions).map(([key, label]) => `<option value="${key}">${label}</option>`).join('') + '<option value="__custom__">自定义问题</option>';
                syncAuthCustomSelectById('registerQuestionKey');
                toggleCustomQuestionInput();
            }

            function toggleCustomQuestionInput() {
                const select = document.getElementById('registerQuestionKey');
                const wrap = document.getElementById('registerCustomQuestionWrap');
                const input = document.getElementById('registerCustomQuestion');
                if (!select || !wrap || !input) return;
                const isCustom = select.value === '__custom__';
                wrap.classList.toggle('hidden', !isCustom);
                input.required = isCustom;
                if (!isCustom) {
                    input.value = '';
                }
            }

            function applyRegistrationAvailability() {
                const disabled = !authState.allow_registration && !authState.needs_setup;
                const submitBtn = document.getElementById('registerSubmitBtn');
                if (submitBtn) {
                    submitBtn.disabled = disabled;
                }
            }

            function updateAuthHint(tab) {
                const hint = document.getElementById('authHint');
                if (!hint) return;
                const activeTab = tab || (document.getElementById('tabRegister')?.classList.contains('active') ? 'register' : 'login');
                if (authState.needs_setup) {
                    hint.textContent = '首次使用，请先创建管理员账号。';
                    return;
                }
                if (activeTab === 'register') {
                    hint.textContent = authState.allow_registration
                        ? '请填写注册信息并设置验证问题，用于后续找回密码。'
                        : '感谢关注，当前暂未开放注册功能，请稍后再试。';
                    return;
                }
                if (activeTab === 'reset') {
                    hint.textContent = '请输入用户名并回答验证问题，以重置登录密码。';
                    return;
                }
                const demo = authState.default_demo || {};
                const demoUser = demo.username || 'test';
                hint.textContent = `请输入账号密码登录，或点击 Demo 按钮进入体验环境（${demoUser}）。`;
            }

            async function loadResetQuestion() {
                const username = document.getElementById('resetUsername').value.trim();
                if (!username) {
                    setAuthMessage('请先输入用户名', true);
                    return;
                }
                setAuthMessage('');
                const res = await authApi(`auth/get-reset-question&username=${encodeURIComponent(username)}`);
                if (!res.success) {
                    resetQuestionKey = '';
                    document.getElementById('resetQuestionLabel').value = '';
                    setAuthMessage(res.message || '查询失败', true);
                    return;
                }
                resetQuestionKey = res.question_key || '';
                document.getElementById('resetQuestionLabel').value = res.question_label || '';
                setAuthMessage('已获取验证问题，请填写答案并设置新密码');
            }

            async function authApi(endpoint, data) {
                const res = await fetch(`?api=${endpoint}`, {
                    method: data ? 'POST' : 'GET',
                    headers: data ? { 'Content-Type': 'application/json' } : undefined,
                    body: data ? JSON.stringify(data) : undefined
                });
                return res.json();
            }

            async function submitLogin(e) {
                e.preventDefault();
                setAuthMessage('');
                const res = await authApi('auth/login', {
                    username: document.getElementById('loginUsername').value.trim(),
                    password: document.getElementById('loginPassword').value
                });
                if (!res.success) {
                    setAuthMessage(res.message || '登录失败', true);
                    return false;
                }
                location.reload();
                return false;
            }

            async function loginAsDemo() {
                setAuthMessage('');
                const res = await authApi('auth/demo-login', {});
                if (!res.success) {
                    setAuthMessage(res.message || '进入 Demo 失败', true);
                    return;
                }
                location.reload();
            }

            async function submitRegister(e) {
                e.preventDefault();
                if (!authState.allow_registration && !authState.needs_setup) {
                    setAuthMessage('感谢关注，当前暂未开放注册功能，请稍后再试。', true);
                    return false;
                }
                setAuthMessage('');
                const res = await authApi('auth/register', {
                    username: document.getElementById('registerUsername').value.trim(),
                    display_name: document.getElementById('registerDisplayName').value.trim(),
                    password: document.getElementById('registerPassword').value,
                    question_key: document.getElementById('registerQuestionKey').value,
                    question_custom: document.getElementById('registerCustomQuestion').value.trim(),
                    security_answer: document.getElementById('registerSecurityAnswer').value
                });
                if (!res.success) {
                    setAuthMessage(res.message || '注册失败', true);
                    return false;
                }
                location.reload();
                return false;
            }

            async function submitResetPassword(e) {
                e.preventDefault();
                const username = document.getElementById('resetUsername').value.trim();
                const answer = document.getElementById('resetAnswer').value;
                const newPassword = document.getElementById('resetNewPassword').value;
                if (!username || !answer || !newPassword) {
                    setAuthMessage('请完整填写重置表单', true);
                    return false;
                }
                if (!resetQuestionKey) {
                    setAuthMessage('请先点击“查询问题”', true);
                    return false;
                }
                setAuthMessage('');
                const res = await authApi('auth/reset-password-by-question', {
                    username,
                    security_answer: answer,
                    new_password: newPassword
                });
                if (!res.success) {
                    setAuthMessage(res.message || '重置失败', true);
                    return false;
                }
                setAuthMessage(res.message || '密码重置成功，请返回登录', false);
                switchAuthTab('login');
                document.getElementById('loginUsername').value = username;
                document.getElementById('loginPassword').value = '';
                document.getElementById('resetAnswer').value = '';
                document.getElementById('resetNewPassword').value = '';
                return false;
            }

            (async function initAuthView() {
                try {
                    initAuthCustomSelects(document);
                    const init = await authApi('auth/init');
                    if (init && init.success) {
                        authState = init;
                        fillSecurityQuestionOptions();
                        applyRegistrationAvailability();
                        if (init.needs_setup) {
                            switchAuthTab('register');
                        } else {
                            switchAuthTab('login');
                        }
                    }
                } catch (e) {
                    setAuthMessage('初始化失败，请刷新重试', true);
                }
            })();

            document.getElementById('registerQuestionKey')?.addEventListener('change', toggleCustomQuestionInput);
        </script>
    </body>

    </html>
    <?php
    exit;
}

getUserDB(intval($currentAuthUser['id'])); // 确保当前用户数据库初始化
$currentUserJson = json_encode([
    'id' => intval($currentAuthUser['id']),
    'username' => $currentAuthUser['username'],
    'display_name' => ($currentAuthUser['display_name'] ?: $currentAuthUser['username']),
    'role' => ($currentAuthUser['role'] ?? 'user'),
    'is_admin' => isAdminUser($currentAuthUser)
], JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>17物品管理 | Item Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Noto+Sans+SC:wght@300;400;500;700&display=swap"
        rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Noto Sans SC', sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            margin: 0;
            min-height: 100vh;
        }

        /* 动态背景 */
        .bg-aurora {
            position: fixed;
            inset: 0;
            z-index: -1;
            background: radial-gradient(ellipse at 20% 50%, rgba(56, 189, 248, 0.15), transparent 50%), radial-gradient(ellipse at 80% 20%, rgba(139, 92, 246, 0.15), transparent 50%), radial-gradient(ellipse at 50% 80%, rgba(16, 185, 129, 0.08), transparent 50%);
        }

        /* 毛玻璃效果 */
        .glass {
            background: rgba(30, 41, 59, 0.6);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .glass-hover:hover {
            background: rgba(30, 41, 59, 0.85);
            border-color: rgba(56, 189, 248, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px -8px rgba(0, 0, 0, 0.4);
        }

        /* 侧边栏 */
        .sidebar {
            width: 240px;
            transition: width 0.3s, transform 0.3s;
        }

        .sidebar.collapsed {
            width: 64px;
        }

        .sidebar.collapsed .sidebar-text {
            display: none;
        }

        .sidebar.collapsed .sidebar-logo-text {
            display: none;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 10px 16px;
            border-radius: 10px;
            transition: all 0.2s;
            color: #94a3b8;
            cursor: pointer;
            gap: 12px;
            font-size: 14px;
        }

        .sidebar-link:hover {
            background: rgba(56, 189, 248, 0.1);
            color: #e2e8f0;
        }

        .sidebar-link.active {
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.2), rgba(139, 92, 246, 0.2));
            color: #38bdf8;
            font-weight: 500;
        }

        .sidebar-link i {
            font-size: 20px;
            width: 24px;
            text-align: center;
            flex-shrink: 0;
        }

        .sidebar-parent {
            cursor: pointer;
        }

        .sidebar-parent .sub-arrow {
            font-size: 16px;
            width: auto;
        }

        .sidebar-group.open .sub-arrow {
            transform: rotate(180deg);
        }

        .sidebar-submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.25s ease;
        }

        .sidebar-group.open .sidebar-submenu {
            max-height: 480px;
        }

        .sidebar-sub {
            padding-left: 44px !important;
            font-size: 13px;
        }

        .sidebar-sub i {
            font-size: 16px;
            width: 20px;
        }

        /* 滚动条 */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(100, 116, 139, 0.4);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(100, 116, 139, 0.7);
        }

        /* 卡片动画 */
        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(16px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .anim-up {
            animation: fadeUp 0.4s ease-out forwards;
        }

        /* 弹窗 */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s;
        }

        .modal-overlay.show {
            opacity: 1;
            pointer-events: auto;
        }

        .modal-box {
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            width: 95%;
            max-width: 720px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(20px);
            transition: transform 0.25s;
        }

        .modal-overlay.show .modal-box {
            transform: translateY(0);
        }

        /* 状态徽标 */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 10px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-active {
            background: rgba(16, 185, 129, 0.15);
            color: #34d399;
        }

        .badge-archived {
            background: rgba(100, 116, 139, 0.15);
            color: #94a3b8;
        }

        .badge-lent {
            background: rgba(56, 189, 248, 0.15);
            color: #38bdf8;
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }

        .category-progress-track {
            background: rgba(51, 65, 85, 0.5);
        }

        /* Toast */
        .toast-container {
            position: fixed;
            top: 24px;
            right: 24px;
            z-index: 200;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .toast {
            padding: 12px 20px;
            border-radius: 12px;
            font-size: 14px;
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            animation: fadeUp 0.3s ease-out;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            max-width: min(440px, calc(100vw - 32px));
            white-space: pre-wrap;
        }

        .toast-icon {
            flex-shrink: 0;
            margin-top: 1px;
        }

        .toast-message {
            flex: 1;
            line-height: 1.4;
            word-break: break-word;
        }

        .toast-close {
            border: 0;
            background: transparent;
            color: rgba(255, 255, 255, 0.85);
            width: 20px;
            height: 20px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.2s;
            flex-shrink: 0;
        }

        .toast-close:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        .toast-success {
            background: rgba(16, 185, 129, 0.9);
            color: #fff;
        }

        .toast-error {
            background: rgba(239, 68, 68, 0.9);
            color: #fff;
        }

        /* 输入框 */
        .input {
            width: 100%;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(100, 116, 139, 0.3);
            color: #e2e8f0;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s, background-color 0.2s, color 0.2s;
            outline: none;
        }

        input.input[data-date-placeholder="1"] {
            display: block;
            width: 100%;
            height: 40px;
            box-sizing: border-box;
            line-height: 1.2;
        }

        .input:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15);
        }

        .input::placeholder {
            color: #475569;
        }

        select.input {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-size: 12px 12px;
            background-position: right 12px center;
            padding-right: 32px;
        }

        select.input::-ms-expand {
            display: none;
        }

        /* 自定义下拉（统一替代原生 select） */
        .custom-select {
            position: relative;
            min-width: 0;
        }

        .custom-select.custom-select-block {
            display: block;
            width: 100%;
        }

        .custom-select.custom-select-inline {
            display: inline-block;
            width: auto;
            max-width: 100%;
        }

        .custom-select-native {
            position: absolute !important;
            width: 1px !important;
            height: 1px !important;
            margin: 0 !important;
            padding: 0 !important;
            border: 0 !important;
            opacity: 0 !important;
            pointer-events: none !important;
            clip: rect(0, 0, 0, 0) !important;
            clip-path: inset(50%) !important;
            overflow: hidden !important;
        }

        .custom-select-trigger {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            text-align: left;
            gap: 10px;
            cursor: pointer;
        }

        .custom-select-trigger:disabled {
            opacity: 0.58;
            cursor: not-allowed;
        }

        .custom-select-label {
            display: block;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
        }

        .custom-select-arrow {
            flex-shrink: 0;
            transition: transform 0.18s ease, color 0.18s ease;
        }

        .custom-select.is-open .custom-select-arrow {
            transform: rotate(180deg);
            color: #38bdf8;
        }

        .custom-select-menu {
            position: absolute;
            left: 0;
            top: calc(100% + 6px);
            min-width: 100%;
            max-width: min(420px, calc(100vw - 24px));
            max-height: 260px;
            overflow: auto;
            -webkit-overflow-scrolling: touch;
            padding: 6px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.34);
            background: rgba(15, 23, 42, 0.96);
            box-shadow: 0 12px 28px rgba(2, 6, 23, 0.52);
            z-index: 140;
        }

        .custom-select-menu.custom-select-menu-floating {
            position: fixed;
            top: 0;
            left: 0;
            z-index: 260;
        }

        .custom-select.custom-select-inline .custom-select-menu {
            width: max-content;
        }

        .custom-select-group+.custom-select-group {
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px solid rgba(148, 163, 184, 0.2);
        }

        .custom-select-group-title {
            padding: 2px 8px 6px;
            font-size: 11px;
            letter-spacing: 0.02em;
            color: #94a3b8;
        }

        .custom-select-option {
            width: 100%;
            border: 0;
            border-radius: 8px;
            padding: 8px 10px;
            background: transparent;
            color: #cbd5e1;
            font-size: 13px;
            text-align: left;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            cursor: pointer;
            transition: background-color 0.16s ease, color 0.16s ease;
        }

        .custom-select-option:hover {
            background: rgba(255, 255, 255, 0.08);
        }

        .custom-select-option.is-selected {
            background: rgba(14, 165, 233, 0.22);
            color: #7dd3fc;
        }

        .custom-select-option:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .custom-select-option-check {
            font-size: 14px;
            line-height: 1;
            opacity: 0;
        }

        .custom-select-option.is-selected .custom-select-option-check {
            opacity: 1;
        }

        .custom-select-empty {
            color: #64748b;
            font-size: 12px;
            text-align: center;
            padding: 12px 10px;
        }

        @media (max-width: 640px) {
            .custom-select-option {
                font-size: 14px;
                padding: 10px 12px;
            }
        }

        /* 状态图标选择器 */
        .status-icon-picker-menu {
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .status-icon-picker-menu .status-icon-option {
            color: #cbd5e1;
        }

        .status-icon-picker-menu .status-icon-option:hover {
            background: rgba(255, 255, 255, 0.08);
        }

        .status-icon-picker-menu .status-icon-option.is-selected {
            background: rgba(14, 165, 233, 0.2);
            color: #7dd3fc;
        }

        .emoji-picker-menu {
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .emoji-picker-grid {
            display: grid;
            grid-template-columns: repeat(8, minmax(0, 1fr));
            gap: 6px;
        }

        .emoji-picker-group+.emoji-picker-group {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        .emoji-picker-group-title {
            font-size: 11px;
            color: #94a3b8;
            margin-bottom: 6px;
            letter-spacing: 0.02em;
        }

        .emoji-picker-option {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            border: 1px solid transparent;
            min-height: 34px;
            font-size: 20px;
            line-height: 1;
            transition: background-color 0.2s, border-color 0.2s, transform 0.15s;
        }

        .emoji-picker-option:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-1px);
        }

        .emoji-picker-option.is-selected {
            border-color: rgba(56, 189, 248, 0.5);
            background: rgba(14, 165, 233, 0.2);
        }

        @media (max-width: 640px) {
            .emoji-picker-grid {
                grid-template-columns: repeat(7, minmax(0, 1fr));
            }
        }

        /* 按钮 */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 18px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0ea5e9, #6366f1);
            color: #fff;
        }

        .btn-primary:hover {
            box-shadow: 0 4px 15px rgba(14, 165, 233, 0.4);
            transform: translateY(-1px);
        }

        .btn-ghost {
            background: transparent;
            color: #94a3b8;
            border: 1px solid rgba(100, 116, 139, 0.3);
        }

        .btn-ghost:hover {
            color: #e2e8f0;
            border-color: rgba(100, 116, 139, 0.6);
            background: rgba(100, 116, 139, 0.1);
        }

        .btn-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
        }

        .btn-danger:hover {
            background: rgba(239, 68, 68, 0.3);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 8px;
        }

        .help-hint-icon {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-left: 6px;
            vertical-align: middle;
            cursor: help;
            outline: none;
        }

        .help-hint-mark {
            width: 15px;
            height: 15px;
            border-radius: 999px;
            border: 1px solid rgba(56, 189, 248, 0.45);
            background: rgba(14, 165, 233, 0.16);
            color: #7dd3fc;
            font-size: 10px;
            line-height: 1;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .help-hint-tooltip {
            position: absolute;
            left: 50%;
            right: auto;
            bottom: calc(100% + 8px);
            transform: translateX(-50%) translateY(4px);
            min-width: 220px;
            max-width: min(320px, calc(100vw - 24px));
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid rgba(56, 189, 248, 0.24);
            background: rgba(15, 23, 42, 0.98);
            color: #cbd5e1;
            font-size: 12px;
            line-height: 1.5;
            box-shadow: 0 12px 28px rgba(2, 6, 23, 0.5);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.16s ease, transform 0.16s ease;
            z-index: 280;
            white-space: normal;
            text-align: left;
        }

        .help-hint-icon.hint-align-left .help-hint-tooltip {
            left: 0;
            right: auto;
            transform: translateY(4px);
        }

        .help-hint-icon.hint-align-right .help-hint-tooltip {
            left: auto;
            right: 0;
            transform: translateY(4px);
        }

        .help-hint-icon.hint-below .help-hint-tooltip {
            top: calc(100% + 8px);
            bottom: auto;
            transform: translateX(-50%) translateY(-4px);
        }

        .help-hint-icon.hint-below.hint-align-left .help-hint-tooltip,
        .help-hint-icon.hint-below.hint-align-right .help-hint-tooltip {
            transform: translateY(-4px);
        }

        .help-hint-icon:hover .help-hint-tooltip,
        .help-hint-icon:focus .help-hint-tooltip,
        .help-hint-icon:focus-within .help-hint-tooltip {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .help-hint-icon.hint-align-left:hover .help-hint-tooltip,
        .help-hint-icon.hint-align-left:focus .help-hint-tooltip,
        .help-hint-icon.hint-align-left:focus-within .help-hint-tooltip,
        .help-hint-icon.hint-align-right:hover .help-hint-tooltip,
        .help-hint-icon.hint-align-right:focus .help-hint-tooltip,
        .help-hint-icon.hint-align-right:focus-within .help-hint-tooltip {
            transform: translateY(0);
        }

        .help-hint-icon.hint-below:hover .help-hint-tooltip,
        .help-hint-icon.hint-below:focus .help-hint-tooltip,
        .help-hint-icon.hint-below:focus-within .help-hint-tooltip {
            transform: translateX(-50%) translateY(0);
        }

        .help-hint-icon.hint-below.hint-align-left:hover .help-hint-tooltip,
        .help-hint-icon.hint-below.hint-align-left:focus .help-hint-tooltip,
        .help-hint-icon.hint-below.hint-align-left:focus-within .help-hint-tooltip,
        .help-hint-icon.hint-below.hint-align-right:hover .help-hint-tooltip,
        .help-hint-icon.hint-below.hint-align-right:focus .help-hint-tooltip,
        .help-hint-icon.hint-below.hint-align-right:focus-within .help-hint-tooltip {
            transform: translateY(0);
        }

        /* 中尺寸物品卡片底部操作区（编辑/复制/删除） */
        .item-card-medium-actions {
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }

        .item-card-medium-actions .action-btn {
            border: none;
            border-radius: 0;
            background: transparent;
            color: #94a3b8;
        }

        .item-card-medium-actions .action-btn+.action-btn {
            border-left: 1px solid rgba(255, 255, 255, 0.08);
        }

        .item-card-medium-actions .action-btn:hover {
            background: rgba(148, 163, 184, 0.12);
            color: #e2e8f0;
        }

        .item-card-medium-actions .action-copy {
            color: #38bdf8;
        }

        .item-card-medium-actions .action-copy:hover {
            background: rgba(56, 189, 248, 0.16);
            color: #7dd3fc;
        }

        .item-card-medium-actions .action-delete {
            color: #f87171;
        }

        .item-card-medium-actions .action-delete:hover {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        /* 图片上传区域 */
        .upload-zone {
            border: 2px dashed rgba(100, 116, 139, 0.3);
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .upload-zone:hover {
            border-color: #38bdf8;
            background: rgba(56, 189, 248, 0.05);
        }

        .upload-zone.has-image {
            border-style: solid;
            padding: 8px;
        }

        /* 数据卡片 */
        /* 尺寸切换按钮 */
        .size-btn {
            width: 30px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            color: #64748b;
            transition: all 0.15s;
            cursor: pointer;
            border: none;
            background: none;
            font-size: 14px;
        }

        .size-btn:hover {
            color: #e2e8f0;
        }

        .size-btn.active {
            background: rgba(56, 189, 248, 0.2);
            color: #38bdf8;
        }

        /* 物品网格卡片 */
        .item-card {
            border-radius: 14px;
            overflow: hidden;
            transition: all 0.3s;
            cursor: pointer;
        }

        .item-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px -8px rgba(0, 0, 0, 0.4);
        }

        .item-card .item-img {
            height: 160px;
            background: #1e293b;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .item-card .item-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .item-card .item-img .placeholder-icon {
            font-size: 48px;
            color: #334155;
        }

        /* 分类管理思维导图视图 */
        .category-mindmap {
            position: relative;
        }

        .category-branch {
            border: 1px solid rgba(255, 255, 255, 0.06);
        }

        .category-branch-grid {
            display: grid;
            grid-template-columns: minmax(250px, 310px) minmax(0, 1fr);
            gap: 12px;
            align-items: start;
        }

        .category-node {
            border: 1px solid rgba(148, 163, 184, 0.24);
            border-radius: 14px;
            background: rgba(15, 23, 42, 0.45);
            padding: 12px;
            min-width: 0;
        }

        .category-node-root {
            border-left: 3px solid var(--node-color, #64748b);
            position: relative;
        }

        .category-node-root::after {
            display: none;
        }

        .category-node-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .category-node-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            flex-shrink: 0;
            margin-top: 3px;
        }

        .category-node-actions {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .category-node-actions .btn {
            flex: 1;
            min-width: 78px;
        }

        .category-branch-line {
            display: none;
        }

        .category-branch-line::before {
            display: none;
        }

        .category-branch-line.is-empty::before {
            display: none;
        }

        .category-children {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
            min-width: 0;
            padding-left: 0;
            grid-column: 2;
        }

        .category-node-child {
            position: relative;
        }

        .category-node-child::before {
            display: none;
        }

        .category-children.is-empty .category-node-child::before {
            display: none;
        }

        .category-node-empty {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        /* 空状态 */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #475569;
        }

        .empty-state>i {
            font-size: 64px;
            margin-bottom: 16px;
            display: block;
        }

        .empty-state .btn-first-item {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 11px 18px;
            border-radius: 12px;
            font-weight: 600;
            letter-spacing: 0.2px;
            background: linear-gradient(135deg, #22d3ee 0%, #3b82f6 55%, #6366f1 100%);
            border: 1px solid rgba(125, 211, 252, 0.35);
            box-shadow: 0 12px 26px rgba(37, 99, 235, 0.32);
            transition: transform 0.2s, box-shadow 0.2s, filter 0.2s;
        }

        .empty-state .btn-first-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 30px rgba(37, 99, 235, 0.38);
            filter: saturate(1.08);
        }

        .empty-state .btn-first-item:active {
            transform: translateY(0);
            box-shadow: 0 8px 18px rgba(37, 99, 235, 0.3);
        }

        .btn-first-item-icon {
            width: 22px;
            height: 22px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.2);
        }

        .btn-first-item-icon i {
            font-size: 13px;
            line-height: 1;
            margin: 0;
            display: inline;
        }

        .btn-first-item-text {
            line-height: 1;
        }

        @media (max-width: 640px) {
            .empty-state .btn-first-item {
                width: min(100%, 320px);
                justify-content: center;
            }
        }

        /* 选中效果 */
        .item-card.selected {
            outline: 2px solid #38bdf8;
            outline-offset: 2px;
        }

        /* 分页 */
        .pagination {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .page-btn {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            color: #94a3b8;
            background: transparent;
            border: none;
        }

        .page-btn:hover {
            background: rgba(56, 189, 248, 0.1);
            color: #38bdf8;
        }

        .page-btn.active {
            background: rgba(56, 189, 248, 0.2);
            color: #38bdf8;
            font-weight: 600;
        }

        /* 亮色模式 */
        body.light {
            background: #f1f5f9;
            color: #334155;
        }

        body.light .bg-aurora {
            background: radial-gradient(ellipse at 20% 50%, rgba(56, 189, 248, 0.12), transparent 50%), radial-gradient(ellipse at 80% 20%, rgba(139, 92, 246, 0.12), transparent 50%);
        }

        body.light .glass {
            background: rgba(255, 255, 255, 0.75);
            border-color: rgba(0, 0, 0, 0.06);
        }

        body.light .glass-hover:hover {
            background: rgba(255, 255, 255, 0.95);
            border-color: rgba(56, 189, 248, 0.3);
        }

        body.light .sidebar-link {
            color: #64748b;
        }

        body.light .sidebar-link:hover {
            background: rgba(56, 189, 248, 0.08);
            color: #334155;
        }

        body.light .sidebar-link.active {
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.15), rgba(139, 92, 246, 0.1));
            color: #0284c7;
        }

        body.light .input {
            background: rgba(255, 255, 255, 0.8);
            border-color: rgba(0, 0, 0, 0.1);
            color: #1e293b;
        }

        body.light .input::placeholder {
            color: #94a3b8;
        }

        body.light .status-icon-picker-menu {
            border-color: rgba(15, 23, 42, 0.12);
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
        }

        body.light .status-icon-picker-menu .status-icon-option {
            color: #334155;
        }

        body.light .status-icon-picker-menu .status-icon-option:hover {
            background: rgba(14, 165, 233, 0.08);
        }

        body.light .status-icon-picker-menu .status-icon-option.is-selected {
            background: rgba(14, 165, 233, 0.12);
            color: #0369a1;
        }

        body.light .emoji-picker-menu {
            border-color: rgba(15, 23, 42, 0.12);
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
        }

        body.light .emoji-picker-option:hover {
            background: rgba(14, 165, 233, 0.08);
        }

        body.light .emoji-picker-option.is-selected {
            border-color: rgba(14, 165, 233, 0.4);
            background: rgba(14, 165, 233, 0.16);
        }

        body.light .emoji-picker-group+.emoji-picker-group {
            border-top-color: rgba(15, 23, 42, 0.08);
        }

        body.light .emoji-picker-group-title {
            color: #64748b;
        }

        body.light .custom-select-menu {
            border-color: rgba(15, 23, 42, 0.12);
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.12);
        }

        body.light .custom-select-group+.custom-select-group {
            border-top-color: rgba(15, 23, 42, 0.08);
        }

        body.light .custom-select-group-title {
            color: #64748b;
        }

        body.light .custom-select-option {
            color: #334155;
        }

        body.light .custom-select-option:hover {
            background: rgba(14, 165, 233, 0.08);
        }

        body.light .custom-select-option.is-selected {
            background: rgba(14, 165, 233, 0.15);
            color: #0369a1;
        }

        body.light .custom-select.is-open .custom-select-arrow {
            color: #0369a1;
        }

        body.light .custom-select-empty {
            color: #64748b;
        }

        body.light .modal-box {
            background: #fff;
            border-color: rgba(0, 0, 0, 0.08);
        }

        body.light .text-slate-100,
        body.light .text-slate-200,
        body.light .text-white {
            color: #1e293b;
        }

        body.light .text-slate-300 {
            color: #475569;
        }

        body.light .text-slate-400 {
            color: #64748b;
        }

        body.light .text-slate-500 {
            color: #94a3b8;
        }

        body.light .item-card .item-img {
            background: #f1f5f9;
        }

        body.light .item-card .item-img .placeholder-icon {
            color: #cbd5e1;
        }

        body.light .category-branch {
            border-color: rgba(15, 23, 42, 0.08);
        }

        body.light .category-node {
            background: rgba(255, 255, 255, 0.88);
            border-color: rgba(15, 23, 42, 0.12);
        }

        body.light .category-node-root::after,
        body.light .category-branch-line::before,
        body.light .category-node-child::before {
            background: rgba(100, 116, 139, 0.5);
        }

        body.light .item-card-medium-actions {
            border-top-color: rgba(15, 23, 42, 0.08);
            background: rgba(148, 163, 184, 0.05);
        }

        body.light .item-card-medium-actions .action-btn {
            color: #64748b;
        }

        body.light .item-card-medium-actions .action-btn+.action-btn {
            border-left-color: rgba(15, 23, 42, 0.08);
        }

        body.light .item-card-medium-actions .action-btn:hover {
            background: rgba(14, 165, 233, 0.08);
            color: #334155;
        }

        body.light .item-card-medium-actions .action-copy {
            color: #0284c7;
        }

        body.light .item-card-medium-actions .action-copy:hover {
            background: rgba(2, 132, 199, 0.12);
            color: #0369a1;
        }

        body.light .item-card-medium-actions .action-delete {
            color: #dc2626;
        }

        body.light .item-card-medium-actions .action-delete:hover {
            background: rgba(239, 68, 68, 0.12);
            color: #b91c1c;
        }

        body.light .category-progress-track {
            background: rgba(148, 163, 184, 0.24);
        }

        /* 亮色模式配色统一优化 */
        body.light {
            --lm-bg: #edf3fb;
            --lm-surface: rgba(255, 255, 255, 0.9);
            --lm-surface-strong: #ffffff;
            --lm-surface-soft: #f8fbff;
            --lm-border: rgba(15, 23, 42, 0.1);
            --lm-border-soft: rgba(15, 23, 42, 0.07);
            --lm-text: #0f172a;
            --lm-text-2: #1e293b;
            --lm-text-3: #334155;
            --lm-text-muted: #5b6b7f;
            --lm-accent: #0284c7;
            --lm-accent-strong: #0369a1;
            --lm-shadow-sm: 0 8px 20px rgba(15, 23, 42, 0.06);
            --lm-shadow-md: 0 16px 30px rgba(15, 23, 42, 0.1);
            background: var(--lm-bg);
            color: var(--lm-text-2);
        }

        body.light .bg-aurora {
            background:
                radial-gradient(ellipse at 20% 48%, rgba(14, 165, 233, 0.14), transparent 52%),
                radial-gradient(ellipse at 78% 18%, rgba(6, 182, 212, 0.1), transparent 50%),
                linear-gradient(180deg, #f8fbff 0%, #edf3fb 100%);
        }

        body.light .glass {
            background: var(--lm-surface);
            border-color: var(--lm-border);
            box-shadow: var(--lm-shadow-sm);
        }

        body.light .glass-hover:hover {
            background: var(--lm-surface-strong);
            border-color: rgba(2, 132, 199, 0.26);
            box-shadow: var(--lm-shadow-md);
        }

        body.light .sidebar {
            background: rgba(255, 255, 255, 0.92);
            border-right-color: var(--lm-border) !important;
            box-shadow: 8px 0 24px rgba(15, 23, 42, 0.08);
        }

        body.light #mobileOverlay {
            background: rgba(15, 23, 42, 0.32);
        }

        body.light .main-area>header.glass {
            border-bottom-color: var(--lm-border) !important;
        }

        body.light .sidebar-link {
            color: #516175;
        }

        body.light .sidebar-link:hover {
            background: rgba(14, 165, 233, 0.12);
            color: var(--lm-text);
        }

        body.light .sidebar-link.active {
            background: linear-gradient(135deg, rgba(14, 165, 233, 0.17), rgba(6, 182, 212, 0.14));
            color: var(--lm-accent-strong);
            box-shadow: inset 0 0 0 1px rgba(2, 132, 199, 0.2);
        }

        body.light .modal-overlay {
            background: rgba(148, 163, 184, 0.42);
        }

        body.light .modal-box {
            background: rgba(255, 255, 255, 0.98);
            border-color: var(--lm-border);
            box-shadow: 0 22px 42px rgba(15, 23, 42, 0.14);
        }

        body.light .input {
            background: var(--lm-surface-soft);
            border-color: rgba(100, 116, 139, 0.32);
            color: var(--lm-text);
        }

        body.light .input:focus {
            border-color: var(--lm-accent);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.18);
            background: #fff;
        }

        body.light .input::placeholder {
            color: #8a99ad;
        }

        body.light select.input {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364758b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
        }

        body.light .btn-primary {
            background: linear-gradient(135deg, #0284c7, #0ea5e9);
            box-shadow: 0 8px 18px rgba(2, 132, 199, 0.28);
        }

        body.light .btn-primary:hover {
            box-shadow: 0 12px 22px rgba(2, 132, 199, 0.3);
        }

        body.light .btn-ghost {
            background: rgba(255, 255, 255, 0.7);
            color: #4b5d73;
            border-color: rgba(100, 116, 139, 0.35);
        }

        body.light .btn-ghost:hover {
            background: rgba(224, 242, 254, 0.9);
            border-color: rgba(2, 132, 199, 0.35);
            color: var(--lm-text);
        }

        body.light .btn-danger {
            background: rgba(239, 68, 68, 0.12);
            color: #b91c1c;
        }

        body.light .btn-danger:hover {
            background: rgba(239, 68, 68, 0.2);
            color: #991b1b;
        }

        body.light .badge-active {
            background: rgba(16, 185, 129, 0.14);
            color: #047857;
        }

        body.light .badge-lent {
            background: rgba(14, 165, 233, 0.14);
            color: #0369a1;
        }

        body.light .badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: #b45309;
        }

        body.light .badge-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #b91c1c;
        }

        body.light .badge-archived {
            background: rgba(100, 116, 139, 0.14);
            color: #475569;
        }

        body.light .item-card:hover {
            box-shadow: 0 12px 26px -14px rgba(15, 23, 42, 0.26);
        }

        body.light .item-card .item-img {
            background: #eef3f8;
        }

        body.light .item-card .item-img .placeholder-icon {
            color: #94a3b8;
        }

        body.light .upload-zone {
            background: rgba(248, 250, 252, 0.85);
            border-color: rgba(100, 116, 139, 0.35);
        }

        body.light .upload-zone:hover {
            background: rgba(224, 242, 254, 0.7);
            border-color: rgba(2, 132, 199, 0.45);
        }

        body.light .page-btn,
        body.light .size-btn {
            color: #64748b;
        }

        body.light .page-btn:hover,
        body.light .size-btn:hover {
            background: rgba(14, 165, 233, 0.1);
            color: #0369a1;
        }

        body.light .page-btn.active,
        body.light .size-btn.active {
            background: rgba(14, 165, 233, 0.18);
            color: #0369a1;
        }

        body.light .text-slate-100,
        body.light .text-slate-200,
        body.light .text-white {
            color: var(--lm-text-2);
        }

        body.light .text-slate-300 {
            color: var(--lm-text-3);
        }

        body.light .text-slate-400 {
            color: var(--lm-text-muted);
        }

        body.light .text-slate-500 {
            color: #708196;
        }

        body.light .text-sky-300,
        body.light .text-sky-400 {
            color: #0369a1 !important;
        }

        body.light .text-cyan-300,
        body.light .text-cyan-300\/90,
        body.light .text-cyan-200\/90 {
            color: #0e7490 !important;
        }

        body.light .hover\:text-white:hover {
            color: var(--lm-text) !important;
        }

        body.light .bg-white\/5,
        body.light .bg-white\/\[0\.03\],
        body.light .bg-white\/\[0\.02\] {
            background-color: rgba(15, 23, 42, 0.04) !important;
        }

        body.light .bg-white\/10,
        body.light .bg-white\/\[0\.06\],
        body.light .bg-white\/\[0\.04\] {
            background-color: rgba(15, 23, 42, 0.06) !important;
        }

        body.light .bg-sky-500\/5 {
            background-color: rgba(14, 165, 233, 0.08) !important;
        }

        body.light .bg-sky-500\/15 {
            background-color: rgba(14, 165, 233, 0.14) !important;
        }

        body.light .border-white\/5,
        body.light .border-white\/10,
        body.light .border-white\/20,
        body.light .border-white\/\[0\.04\],
        body.light .border-white\/\[0\.06\] {
            border-color: rgba(15, 23, 42, 0.12) !important;
        }

        body.light .hover\:bg-white\/5:hover,
        body.light .hover\:bg-white\/\[0\.03\]:hover,
        body.light .hover\:bg-white\/\[0\.04\]:hover,
        body.light .hover\:bg-white\/\[0\.05\]:hover,
        body.light .hover\:bg-white\/\[0\.06\]:hover {
            background-color: rgba(14, 165, 233, 0.1) !important;
        }

        /* 仪表盘提醒卡片（深色模式统一优化） */
        .expiry-remind-item,
        .reminder-remind-item {
            border-width: 1px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
            transition: background-color 0.2s, border-color 0.2s, box-shadow 0.2s;
        }

        .expiry-remind-item:hover,
        .reminder-remind-item:hover {
            filter: brightness(1.04);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05), 0 8px 18px -14px rgba(2, 6, 23, 0.9);
        }

        .expiry-remind-item.expiry-normal {
            background: rgba(71, 85, 105, 0.22);
            border-color: rgba(148, 163, 184, 0.28);
        }

        .expiry-remind-item.expiry-warning,
        .reminder-remind-item.reminder-warning {
            background: rgba(245, 158, 11, 0.14);
            border-color: rgba(245, 158, 11, 0.34);
        }

        .expiry-remind-item.expiry-urgent,
        .reminder-remind-item.reminder-urgent {
            background: rgba(249, 115, 22, 0.16);
            border-color: rgba(249, 115, 22, 0.38);
        }

        .expiry-remind-item.expiry-expired,
        .reminder-remind-item.reminder-expired {
            background: rgba(239, 68, 68, 0.16);
            border-color: rgba(239, 68, 68, 0.36);
        }

        .dashboard-reminder-grid {
            display: grid;
            gap: 0.75rem;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        }

        .expiry-remind-item .expiry-meta,
        .reminder-remind-item .reminder-meta {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* 仪表盘提醒卡片（浅色模式统一优化） */
        body.light .expiry-remind-item,
        body.light .reminder-remind-item {
            background: rgba(255, 255, 255, 0.88);
            border-color: rgba(148, 163, 184, 0.28);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
        }

        body.light .expiry-remind-item:hover,
        body.light .reminder-remind-item:hover {
            filter: brightness(1.02);
        }

        body.light .expiry-remind-item.expiry-normal {
            background: rgba(248, 250, 252, 0.96);
            border-color: rgba(148, 163, 184, 0.3);
        }

        body.light .expiry-remind-item.expiry-warning,
        body.light .reminder-remind-item.reminder-warning {
            background: rgba(254, 243, 199, 0.72);
            border-color: rgba(217, 119, 6, 0.3);
        }

        body.light .expiry-remind-item.expiry-urgent,
        body.light .reminder-remind-item.reminder-urgent {
            background: rgba(255, 237, 213, 0.78);
            border-color: rgba(194, 65, 12, 0.32);
        }

        body.light .expiry-remind-item.expiry-expired,
        body.light .reminder-remind-item.reminder-expired {
            background: rgba(254, 226, 226, 0.8);
            border-color: rgba(220, 38, 38, 0.3);
        }

        body.light .expiry-remind-item .expiry-meta,
        body.light .reminder-remind-item .reminder-meta {
            color: #475569;
            font-weight: 600;
        }

        body.light .expiry-remind-item.expiry-warning .expiry-meta,
        body.light .reminder-remind-item.reminder-warning .reminder-meta {
            color: #9a3412;
        }

        body.light .expiry-remind-item.expiry-urgent .expiry-meta,
        body.light .reminder-remind-item.reminder-urgent .reminder-meta {
            color: #7c2d12;
        }

        body.light .expiry-remind-item.expiry-expired .expiry-meta,
        body.light .reminder-remind-item.reminder-expired .reminder-meta {
            color: #991b1b;
        }

        body.light .reminder-remind-item .reminder-action-btn {
            background: rgba(255, 255, 255, 0.78);
            border-width: 1px;
        }

        body.light .reminder-remind-item .reminder-action-pending,
        body.light .reminder-remind-item .reminder-action-view {
            color: #0369a1;
            border-color: rgba(3, 105, 161, 0.35);
        }

        body.light .reminder-remind-item .reminder-action-pending:hover,
        body.light .reminder-remind-item .reminder-action-view:hover {
            color: #0c4a6e;
            border-color: rgba(12, 74, 110, 0.42);
            background: rgba(224, 242, 254, 0.95);
        }

        body.light .reminder-remind-item .reminder-action-undo {
            color: #92400e;
            border-color: rgba(146, 64, 14, 0.35);
        }

        body.light .reminder-remind-item .reminder-action-undo:hover {
            color: #78350f;
            border-color: rgba(120, 53, 15, 0.42);
            background: rgba(254, 243, 199, 0.95);
        }

        body.light .reminder-remind-item .reminder-action-done {
            color: #166534;
            border-color: rgba(22, 101, 52, 0.3);
            background: rgba(220, 252, 231, 0.92);
        }

        /* 移动端 */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                left: 0;
                top: 0;
                height: 100vh;
                z-index: 50;
                transform: translateX(-100%);
                width: 260px;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-area {
                margin-left: 0 !important;
            }

            /* 移动端日期输入统一尺寸与宽度 */
            #itemDate,
            #itemReminderDate,
            #itemReminderNext {
                display: block;
                width: 100% !important;
                max-width: none;
                min-width: 0;
                box-sizing: border-box;
                height: 40px !important;
                padding-top: 0 !important;
                padding-bottom: 0 !important;
            }

            .categories-header {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .categories-top-actions {
                width: 100%;
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }

            .categories-top-actions>.btn,
            .categories-top-actions>.relative>.btn {
                width: 100%;
                justify-content: center;
            }

            .categories-top-actions .list-sort-menu {
                left: 0;
                right: auto;
                min-width: 100%;
            }

            .items-danger-actions {
                flex-direction: column;
                align-items: stretch;
                gap: 6px;
            }

            .items-danger-actions .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 1024px) {
            .category-branch-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .category-branch-line {
                display: none;
            }

            .category-node-root::after {
                display: none;
            }

            .category-children {
                grid-column: auto;
                padding-left: 0;
                border-top: 1px dashed rgba(148, 163, 184, 0.24);
                padding-top: 10px;
            }

            .category-node-child::before {
                display: none;
            }
        }

        @media (min-width: 769px) {
            .mobile-overlay {
                display: none !important;
            }
        }
    </style>
</head>

<body>
    <div class="bg-aurora"></div>
    <div id="toast-container" class="toast-container"></div>

    <!-- 移动端遮罩 -->
    <div id="mobileOverlay" class="mobile-overlay fixed inset-0 bg-black/50 z-40 hidden" onclick="toggleSidebar()">
    </div>

    <!-- 侧边栏 -->
    <aside id="sidebar" class="sidebar fixed left-0 top-0 h-full z-50 glass flex flex-col"
        style="border-right:1px solid rgba(255,255,255,0.06)">
        <div class="p-5 flex items-center gap-3 border-b border-white/5">
            <div
                class="w-9 h-9 rounded-xl bg-gradient-to-br from-sky-400 to-violet-500 flex items-center justify-center flex-shrink-0">
                <i class="ri-archive-2-fill text-white text-lg"></i>
            </div>
            <span class="sidebar-logo-text font-bold text-base text-white whitespace-nowrap">17 物品管理</span>
            <span id="appVersion" class="sidebar-logo-text text-[10px] text-slate-500 font-mono ml-auto"></span>
        </div>
        <nav class="flex-1 p-3 space-y-1 overflow-y-auto">
            <div class="sidebar-link active" data-view="dashboard" onclick="switchView('dashboard')">
                <i class="ri-dashboard-3-line"></i><span class="sidebar-text">仪表盘</span>
            </div>
            <div class="sidebar-link" data-view="items" onclick="switchView('items')">
                <i class="ri-archive-line"></i><span class="sidebar-text">物品管理</span>
            </div>
            <div class="sidebar-link" data-view="shopping-list" onclick="switchView('shopping-list')">
                <i class="ri-shopping-cart-2-line"></i><span class="sidebar-text">购物清单</span>
            </div>
            <div class="sidebar-link" data-view="message-board" onclick="switchView('message-board')">
                <i class="ri-chat-check-line"></i><span class="sidebar-text">任务清单</span>
            </div>
            <div class="sidebar-link" data-view="public-channel" onclick="switchView('public-channel')">
                <i class="ri-broadcast-line"></i><span class="sidebar-text">公共频道</span>
            </div>

            <div class="mt-6 mb-2 px-4">
                <div class="border-t border-white/5"></div>
            </div>
            <div class="sidebar-link" data-view="locations" onclick="switchView('locations')">
                <i class="ri-map-pin-line"></i><span class="sidebar-text">位置管理</span>
            </div>
            <div class="sidebar-link" data-view="categories" onclick="switchView('categories')">
                <i class="ri-price-tag-3-line"></i><span class="sidebar-text">分类管理</span>
            </div>
            <div class="sidebar-group">
                <div class="sidebar-link sidebar-parent" onclick="toggleSubMenu(this)">
                    <i class="ri-settings-3-line"></i><span class="sidebar-text">设置</span>
                    <i
                        class="ri-arrow-down-s-line sidebar-text ml-auto sub-arrow transition-transform duration-200"></i>
                </div>
                <div class="sidebar-submenu">
                    <div class="sidebar-link sidebar-sub" data-view="import-export"
                        onclick="switchView('import-export')">
                        <i class="ri-swap-line"></i><span class="sidebar-text">数据管理</span>
                    </div>
                    <div class="sidebar-link sidebar-sub" data-view="settings" onclick="switchView('settings')">
                        <i class="ri-sort-asc"></i><span class="sidebar-text">通用设置</span>
                    </div>
                    <div class="sidebar-link sidebar-sub" data-view="status-settings"
                        onclick="switchView('status-settings')">
                        <i class="ri-list-settings-line"></i><span class="sidebar-text">状态管理</span>
                    </div>
                    <div class="sidebar-link sidebar-sub" data-view="channel-settings"
                        onclick="switchView('channel-settings')">
                        <i class="ri-shopping-bag-line"></i><span class="sidebar-text">购入渠道管理</span>
                    </div>
                    <?php if (isAdminUser($currentAuthUser)): ?>
                    <div class="sidebar-link sidebar-sub" data-view="platform-settings"
                        onclick="switchView('platform-settings')">
                        <i class="ri-global-line"></i><span class="sidebar-text">平台设置</span>
                    </div>
                    <div class="sidebar-link sidebar-sub" data-view="user-management"
                        onclick="switchView('user-management')">
                        <i class="ri-admin-line"></i><span class="sidebar-text">用户管理</span>
                    </div>
                    <?php endif; ?>
                    <div class="sidebar-link sidebar-sub" data-view="operation-logs" onclick="switchView('operation-logs')">
                        <i class="ri-file-list-3-line"></i><span class="sidebar-text">操作日志</span>
                    </div>
                    <div class="sidebar-link sidebar-sub" data-view="help-docs" onclick="switchView('help-docs')">
                        <i class="ri-book-open-line"></i><span class="sidebar-text">帮助文档</span>
                    </div>
                    <div class="sidebar-link sidebar-sub" data-view="changelog" onclick="switchView('changelog')">
                        <i class="ri-history-line"></i><span class="sidebar-text">更新记录</span>
                    </div>
                </div>
            </div>
        </nav>
        <div class="p-3 border-t border-white/5">
            <div class="sidebar-link" onclick="toggleTheme()">
                <i id="themeIcon" class="ri-moon-line"></i><span class="sidebar-text" id="themeText">深色模式</span>
            </div>
        </div>
    </aside>

    <!-- 主内容 -->
    <div class="main-area transition-all duration-300" style="margin-left:240px">
        <!-- 顶栏 -->
        <header class="sticky top-0 z-30 glass px-6 py-3 flex items-center justify-between"
            style="border-bottom:1px solid rgba(255,255,255,0.06)">
            <div class="flex items-center gap-4">
                <button onclick="toggleSidebar()" class="md:hidden p-2 text-slate-400 hover:text-white transition"><i
                        class="ri-menu-line text-xl"></i></button>
                <h2 id="viewTitle" class="text-lg font-semibold text-white">仪表盘</h2>
            </div>
            <div class="flex items-center gap-3">
                <div class="relative hidden sm:block">
                    <i class="ri-search-line absolute left-3 top-1/2 -translate-y-1/2 text-slate-500"></i>
                    <input type="text" id="globalSearch" placeholder="全局搜索物品..." class="input pl-10 !w-64 !py-2"
                        onkeyup="handleGlobalSearch(event)">
                </div>
                <div id="headerMenuWrap" class="relative">
                    <button type="button" onclick="toggleHeaderMenu()" class="btn btn-ghost !py-2 !px-3 text-xs text-slate-300 border border-white/10">
                        <i class="ri-menu-4-line"></i><span id="headerMenuButtonName" class="max-w-[110px] truncate"><?= htmlspecialchars($currentAuthUser['username'] ?? '', ENT_QUOTES, 'UTF-8') ?></span><i id="headerMenuArrow" class="ri-arrow-down-s-line transition-transform duration-200"></i>
                    </button>
                    <div id="headerMenuPanel" class="hidden absolute right-0 mt-2 w-56 rounded-xl border border-white/10 bg-slate-900/95 shadow-2xl overflow-hidden z-50"
                        style="backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);">
                        <div class="px-3 py-2.5 border-b border-white/10">
                            <p class="text-[11px] text-slate-500">当前登录</p>
                            <p class="text-sm text-slate-200 mt-1 truncate flex items-center gap-2">
                                <i class="ri-user-3-line text-sky-400"></i>
                                <span id="currentUserLabel"><?= htmlspecialchars($currentAuthUser['username'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                            </p>
                        </div>
                        <button type="button" onclick="toggleHelpMode()" class="w-full text-left px-3 py-2.5 text-sm text-slate-200 hover:bg-white/5 transition flex items-center justify-between gap-2 border-b border-white/10">
                            <span class="inline-flex items-center gap-2"><i id="helpModeIcon" class="ri-question-line text-cyan-300"></i><span>帮助模式</span></span>
                            <span id="helpModeStatus" class="text-[11px] text-emerald-300">已开启</span>
                        </button>
                        <button type="button" onclick="logout()" class="w-full text-left px-3 py-2.5 text-sm text-red-300 hover:bg-red-500/10 transition flex items-center gap-2">
                            <i class="ri-logout-box-r-line"></i><span>退出登录</span>
                        </button>
                    </div>
                </div>
                <button onclick="openAddItem()" class="btn btn-primary"><i class="ri-add-line"></i><span
                        class="hidden sm:inline">添加物品</span></button>
            </div>
        </header>

        <!-- 视图容器 -->
        <main id="viewContainer" class="p-6">
            <!-- 由 JS 动态渲染 -->
        </main>
    </div>

    <!-- 物品表单弹窗 -->
    <div id="itemModal" class="modal-overlay" onclick="if(event.target===this)closeItemModal()">
        <div class="modal-box p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 id="itemModalTitle" class="text-xl font-bold text-white">添加物品</h3>
                <div class="flex items-center gap-4">
                    <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                        <input type="checkbox" id="itemSharePublic" class="accent-sky-500 w-4 h-4">
                        <span class="text-sm text-slate-300">共享到公共频道</span>
                    </label>
                    <button onclick="closeItemModal()" class="text-slate-400 hover:text-white transition"><i
                            class="ri-close-line text-2xl"></i></button>
                </div>
            </div>
            <form id="itemForm" onsubmit="return saveItem(event)">
                <input type="hidden" id="itemId">
                <input type="hidden" id="itemImage">
                <input type="hidden" id="itemSourceShoppingId">
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                    <div class="sm:col-span-2 md:col-span-3">
                        <label class="block text-sm text-slate-400 mb-1.5">物品名称 <span
                                class="text-red-400">*</span></label>
                        <input type="text" id="itemName" class="input" placeholder="请输入物品名称" required>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">分类</label>
                        <select id="itemCategory" class="input">
                            <option value="0">选择分类</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">二级分类</label>
                        <select id="itemSubcategory" class="input" disabled>
                            <option value="0">请先选择一级分类</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">位置</label>
                        <select id="itemLocation" class="input">
                            <option value="0">选择位置</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">状态</label>
                        <select id="itemStatus" class="input"></select>
                    </div>
                    <div>
                        <div class="flex items-end gap-2">
                            <div class="flex-1">
                                <label class="block text-sm text-slate-400 mb-1.5">余量</label>
                                <input type="number" id="itemRemainingCurrent" class="input !px-3 text-center" value="0" min="0" step="1" inputmode="numeric">
                            </div>
                            <span class="text-slate-500 text-sm font-mono pb-2 text-center">/</span>
                            <div class="flex-1">
                                <label class="block text-sm text-slate-400 mb-1.5">数量</label>
                                <input type="number" id="itemQuantity" class="input !px-3 text-center" value="1" min="0" step="1" inputmode="numeric">
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">购入价格 (¥)</label>
                        <input type="number" id="itemPrice" class="input" value="0" min="0" step="0.01">
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">购入渠道</label>
                        <select id="itemPurchaseFrom" class="input">
                            <option value="">未设置</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">购入日期</label>
                        <input type="date" id="itemDate" class="input">
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">过期日期</label>
                        <input type="date" id="itemExpiry" class="input">
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">条码/序列号</label>
                        <input type="text" id="itemBarcode" class="input" placeholder="可选">
                    </div>
                    <div class="sm:col-span-2 md:col-span-3">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm text-slate-400 mb-1.5">循环提醒初始日期</label>
                                <input type="date" id="itemReminderDate" class="input !h-10 !py-0" onchange="syncReminderFields()">
                            </div>
                            <div>
                                <label class="block text-sm text-slate-400 mb-1.5">循环频率</label>
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="text-sm text-slate-400 whitespace-nowrap px-1">每</span>
                                    <input type="number" id="itemReminderEvery" class="input !w-[88px] !h-10 !py-0" value="1" min="1" step="1" onchange="syncReminderFields()">
                                    <select id="itemReminderUnit" class="input flex-1 min-w-0 !h-10 !py-0" onchange="syncReminderFields()">
                                        <option value="day">天</option>
                                        <option value="week">周</option>
                                        <option value="year">年</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm text-slate-400 mb-1.5">下次提醒日期</label>
                                <input type="date" id="itemReminderNext" class="input !h-10 !py-0">
                            </div>
                        </div>
                    </div>
                    <div class="sm:col-span-2 md:col-span-3">
                        <label class="block text-sm text-slate-400 mb-1.5">循环提醒备注</label>
                        <input type="text" id="itemReminderNote" class="input" placeholder="例如：更换滤芯、续费订阅、补货检查">
                    </div>
                    <div class="sm:col-span-2 md:col-span-3">
                        <label class="block text-sm text-slate-400 mb-1.5">标签 (逗号分隔)</label>
                        <input type="text" id="itemTags" class="input" placeholder="例如: 重要, 易碎, 保修期内">
                    </div>
                    <div class="sm:col-span-2 md:col-span-3">
                        <label class="block text-sm text-slate-400 mb-1.5">备注</label>
                        <textarea id="itemNotes" class="input" rows="2" placeholder="内部备注，不对外显示..."></textarea>
                    </div>
                    <div class="sm:col-span-2 md:col-span-3">
                        <label class="block text-sm text-slate-400 mb-1.5">图片</label>
                        <div id="uploadZone" class="upload-zone"
                            onclick="document.getElementById('imageInput').click()">
                            <div id="uploadPlaceholder">
                                <i class="ri-image-add-line text-3xl text-slate-500 mb-2"></i>
                                <p class="text-sm text-slate-500">点击上传图片</p>
                                <p class="text-xs text-slate-600 mt-1">支持 JPG / PNG / GIF / WebP, 最大 10MB</p>
                            </div>
                            <img id="uploadPreview" class="hidden max-h-40 mx-auto rounded-lg" alt="preview">
                        </div>
                        <input type="file" id="imageInput" class="hidden" accept="image/*"
                            onchange="handleImageUpload(this)">
                    </div>
                </div>
                <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-white/5">
                    <button type="button" onclick="closeItemModal()" class="btn btn-ghost">取消</button>
                    <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i><span id="itemSubmitLabel">保存</span></button>
                </div>
            </form>
        </div>
    </div>

    <!-- 物品未保存确认弹窗 -->
    <div id="itemUnsavedModal" class="modal-overlay">
        <div class="modal-box p-6" style="max-width:420px">
            <h3 class="text-lg font-bold text-white mb-2">检测到未保存修改</h3>
            <p class="text-sm text-slate-400 mb-6">关闭前请选择操作：</p>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="discardItemChangesAndClose()" class="btn btn-ghost">忽略修改</button>
                <button type="button" onclick="saveItemChangesAndClose()" class="btn btn-primary"><i class="ri-save-line"></i>保存修改</button>
            </div>
        </div>
    </div>

    <!-- 购物清单弹窗 -->
    <div id="shoppingModal" class="modal-overlay" onclick="if(event.target===this)closeShoppingModal()">
        <div class="modal-box p-6" style="max-width:720px;min-height:50vh">
            <div class="flex items-center justify-between mb-6">
                <h3 id="shoppingModalTitle" class="text-xl font-bold text-white">添加清单</h3>
                <button onclick="closeShoppingModal()" class="text-slate-400 hover:text-white transition"><i
                        class="ri-close-line text-2xl"></i></button>
            </div>
            <form id="shoppingForm" onsubmit="return saveShoppingItem(event)">
                <input type="hidden" id="shoppingId">
                <input type="hidden" id="shoppingCategoryId" value="0">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-sm text-slate-400 mb-1.5">名称 <span class="text-red-400">*</span></label>
                        <input type="text" id="shoppingName" class="input" placeholder="例如：洗衣液、充电电池、显示器支架" oninput="scheduleRefreshShoppingSimilarItemPrices()" required>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">计划数量</label>
                        <input type="number" id="shoppingQty" class="input" value="1" min="1" step="1">
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">状态</label>
                        <select id="shoppingStatus" class="input" onchange="updateShoppingToggleStatusButton()">
                            <option value="pending_purchase" selected>待购买</option>
                            <option value="pending_receipt">待收货</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">优先级</label>
                        <select id="shoppingPriority" class="input">
                            <option value="high">高</option>
                            <option value="normal" selected>普通</option>
                            <option value="low">低</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">预算单价 (¥)</label>
                        <input type="number" id="shoppingPrice" class="input" value="0" min="0" step="0.01">
                    </div>
                    <div class="sm:col-span-2 grid grid-cols-[170px_minmax(0,1fr)] gap-4">
                        <div>
                            <label class="block text-sm text-slate-400 mb-1.5">提醒日期</label>
                            <input type="date" id="shoppingReminderDate" class="input">
                        </div>
                        <div>
                            <label class="block text-sm text-slate-400 mb-1.5">提醒备注</label>
                            <input type="text" id="shoppingReminderNote" class="input" placeholder="例如：活动截止前购买">
                        </div>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm text-slate-400 mb-1.5">备注</label>
                        <textarea id="shoppingNotes" class="input" rows="5" placeholder="例如：建议品牌、型号、店铺、价格提醒..."></textarea>
                        <div id="shoppingPriceReferenceBox" class="mt-3 p-3 rounded-xl border border-white/10 bg-white/5 hidden">
                            <div class="flex items-center justify-between gap-2 mb-2">
                                <p class="text-xs text-slate-400">相似物品购入价参考</p>
                                <button type="button" id="shoppingSimilarSortBtn" class="btn btn-ghost btn-sm" onclick="toggleShoppingSimilarSortMode()">
                                    <i class="ri-sort-desc"></i><span id="shoppingSimilarSortLabel">最新日期</span>
                                </button>
                            </div>
                            <div id="shoppingPriceReferenceList" class="space-y-1.5"></div>
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-between gap-3 mt-6 pt-4 border-t border-white/5">
                    <div class="flex items-center gap-2">
                        <button type="button" id="shoppingConvertBtn" onclick="convertCurrentShoppingItem()"
                            class="btn btn-primary hidden"><i class="ri-shopping-bag-3-line"></i>已购买入库</button>
                        <button type="button" id="shoppingToggleStatusBtn" onclick="toggleCurrentShoppingStatus()"
                            class="btn btn-ghost hidden"><i class="ri-refresh-line"></i><span
                                id="shoppingToggleStatusLabel">已购买</span></button>
                    </div>
                    <div class="flex items-center gap-3 ml-auto">
                        <button type="button" onclick="closeShoppingModal()" class="btn btn-ghost">取消</button>
                        <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i>保存</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- 分类表单弹窗 -->
    <div id="categoryModal" class="modal-overlay" onclick="if(event.target===this)closeCategoryModal()">
        <div class="modal-box p-6" style="max-width:440px">
            <div class="flex items-center justify-between mb-6">
                <h3 id="catModalTitle" class="text-xl font-bold text-white">添加分类</h3>
                <button onclick="closeCategoryModal()" class="text-slate-400 hover:text-white transition"><i
                        class="ri-close-line text-2xl"></i></button>
            </div>
            <form onsubmit="return saveCategory(event)">
                <input type="hidden" id="catId">
                <div class="space-y-4">
                    <div><label class="block text-sm text-slate-400 mb-1.5">分类名称 <span
                                class="text-red-400">*</span></label><input type="text" id="catName" class="input"
                            required></div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">上级分类</label>
                        <select id="catParentId" class="input">
                            <option value="0">无（一级分类）</option>
                        </select>
                        <p class="text-[11px] text-slate-500 mt-1">选择上级后将作为二级分类展示；仅支持两级分类。</p>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">图标 (Emoji)</label>
                        <div id="catEmojiPickerHost"></div>
                    </div>
                    <div><label class="block text-sm text-slate-400 mb-1.5">颜色</label><input type="color" id="catColor"
                            class="input !p-1 !h-10" value="#3b82f6"></div>
                </div>
                <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-white/5">
                    <button type="button" onclick="closeCategoryModal()" class="btn btn-ghost">取消</button>
                    <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i>保存</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 位置表单弹窗 -->
    <div id="locationModal" class="modal-overlay" onclick="if(event.target===this)closeLocationModal()">
        <div class="modal-box p-6" style="max-width:440px">
            <div class="flex items-center justify-between mb-6">
                <h3 id="locModalTitle" class="text-xl font-bold text-white">添加位置</h3>
                <button onclick="closeLocationModal()" class="text-slate-400 hover:text-white transition"><i
                        class="ri-close-line text-2xl"></i></button>
            </div>
            <form onsubmit="return saveLocation(event)">
                <input type="hidden" id="locId">
                <div class="space-y-4">
                    <div><label class="block text-sm text-slate-400 mb-1.5">位置名称 <span
                                class="text-red-400">*</span></label><input type="text" id="locName" class="input"
                            required></div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">图标 (Emoji)</label>
                        <div id="locEmojiPickerHost"></div>
                    </div>
                    <div><label class="block text-sm text-slate-400 mb-1.5">描述</label><textarea id="locDesc"
                            class="input" rows="2"></textarea></div>
                </div>
                <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-white/5">
                    <button type="button" onclick="closeLocationModal()" class="btn btn-ghost">取消</button>
                    <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i>保存</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 物品详情弹窗 -->
    <div id="detailModal" class="modal-overlay" onclick="if(event.target===this)closeDetailModal()">
        <div class="modal-box" style="max-width:560px">
            <div id="detailContent"></div>
        </div>
    </div>

    <!-- 公共频道编辑弹窗 -->
    <div id="publicSharedEditModal" class="modal-overlay" onclick="if(event.target===this)closePublicSharedEditModal()">
        <div class="modal-box p-6" style="max-width:560px">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-bold text-white">编辑共享物品</h3>
                <button onclick="closePublicSharedEditModal()" class="text-slate-400 hover:text-white transition"><i
                        class="ri-close-line text-2xl"></i></button>
            </div>
            <form id="publicSharedEditForm" onsubmit="return savePublicSharedEdit(event)">
                <input type="hidden" id="publicSharedEditId">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-sm text-slate-400 mb-1.5">物品名称 <span class="text-red-400">*</span></label>
                        <input type="text" id="publicSharedEditName" class="input" required>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">分类</label>
                        <select id="publicSharedEditCategory" class="input">
                            <option value="0">未分类</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">购入价格 (¥)</label>
                        <input type="number" id="publicSharedEditPrice" class="input" min="0" step="0.01" value="0">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm text-slate-400 mb-1.5">购入渠道</label>
                        <input type="text" id="publicSharedEditPurchaseFrom" class="input" placeholder="例如：京东、淘宝、线下">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm text-slate-400 mb-1.5">推荐理由</label>
                        <textarea id="publicSharedEditReason" class="input" rows="3" maxlength="300" placeholder="告诉其他用户你推荐这个物品的原因..."></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-white/5">
                    <button type="button" onclick="closePublicSharedEditModal()" class="btn btn-ghost">取消</button>
                    <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i>保存</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ============================================================
        // 🚀 应用状态与核心逻辑
        // ============================================================
        const CURRENT_USER = <?= $currentUserJson ?>;
        function userScopedStorageKey(name) {
            const uid = CURRENT_USER && CURRENT_USER.id ? String(CURRENT_USER.id) : '0';
            return `item_manager_u${uid}_${name}`;
        }
        const THEME_KEY = userScopedStorageKey('theme');
        const HELP_MODE_KEY = userScopedStorageKey('help_mode');

        const HELP_HINTS_BY_FIELD_ID = {
            itemName: '填这件物品的名字，建议用你平时最容易搜索到的叫法。',
            itemCategory: '选择物品的大类，后续查找和统计会更方便。',
            itemSubcategory: '在大类下再细分一层，不需要时可以不选。',
            itemLocation: '填写物品放在哪里，例如“厨房上柜”“书房抽屉”。',
            itemStatus: '表示当前情况，例如“使用中”“已归档”“已转卖”。',
            itemRemainingCurrent: '当前还剩多少。比如买了 10 个还剩 3 个，这里填 3。',
            itemQuantity: '总共买了多少。比如一共买了 10 个，这里填 10。',
            itemPrice: '购买价格，可用于后续比价和预算回顾。',
            itemPurchaseFrom: '在哪里买的，例如京东、淘宝、线下门店。',
            itemDate: '购买日期，不确定时可以留空。',
            itemExpiry: '到期日期。填写后会在仪表盘里自动出现到期提醒。',
            itemBarcode: '商品条码或序列号，用于盘点、对账或售后。',
            itemReminderDate: '循环提醒从哪一天开始算。留空表示不启用循环提醒。',
            itemReminderEvery: '这是提醒频率数字，会基于“循环提醒初始日期”计算下次提醒日期。',
            itemReminderUnit: '这是提醒频率单位（天/周/年），与上面的数字一起决定提醒周期。',
            itemReminderNext: '到这个日期的时候，系统会自动创建一条提醒显示在仪表盘中。日期为自动生成和更新，也可以手动更改。',
            itemReminderNote: '提醒弹出时要做什么，例如“更换滤芯”“会员续费”。',
            itemTags: '关键词标签，多个标签用逗号分隔，方便以后搜索。',
            itemNotes: '其他补充说明，想记什么都可以写这里。',
            itemSharePublic: '打开后，这件物品会显示到公共频道给其他用户参考。',
            shoppingName: '写你准备购买的商品名称。',
            shoppingQty: '计划买几件。',
            shoppingStatus: '采购进度：待购买=还没下单；待收货=已下单等待到货。',
            shoppingPriority: '紧急程度。高优先会更醒目，便于先处理。',
            shoppingPrice: '预计单价，用来估算总预算，可不填。',
            shoppingReminderDate: '到这个日期会提醒你处理这条清单。',
            shoppingReminderNote: '提醒时想看到的说明，例如“今晚活动结束”。',
            shoppingNotes: '采购补充信息，如品牌、型号、链接、比价结果。',
            catName: '分类名称，建议用你日常会搜索的词。',
            catParentId: '不选就是一级分类；选择后会变成该分类下的二级分类。',
            catColor: '分类显示颜色，只影响界面展示。',
            locName: '位置名称，建议写具体一些（如“卧室衣柜上层”）。',
            locDesc: '补充位置说明，方便自己或家人快速找到。',
            publicSharedEditName: '公开给其他用户看到的物品名称。',
            publicSharedEditCategory: '公开信息所属分类，便于别人筛选。',
            publicSharedEditPrice: '分享给他人的参考价格，不填也可以。',
            publicSharedEditPurchaseFrom: '分享给他人的购买渠道信息。',
            publicSharedEditReason: '告诉别人你为什么推荐它、适合谁买。',
            set_expiry_past_days: '定义“过期提醒”时间窗口下界（过去天数）。留空表示不限制。',
            set_expiry_future_days: '定义“过期提醒”时间窗口上界（未来天数）。留空表示不限制。',
            set_reminder_past_days: '定义“备忘提醒”时间窗口下界（过去天数）。留空表示不限制。',
            set_reminder_future_days: '定义“备忘提醒”时间窗口上界（未来天数）。留空表示不限制。',
            set_low_stock_threshold_pct: '低余量触发阈值（0-100）。余量占比低于阈值时生成补货提醒；0 表示禁用。',
            set_dashboard_categories: '仪表盘“分类统计”默认排序策略。',
            set_items_default: '物品管理页面默认排序策略。',
            set_categories_list: '分类管理页面默认排序策略。',
            set_locations_list: '位置管理页面默认排序策略。',
            platformAllowRegistration: '平台注册策略开关。启用后允许自助注册；关闭后仅既有账号可登录。'
        };

        const HELP_HINTS_BY_TEXT = {
            物品名称: '填你最容易识别和搜索到的物品名称。',
            分类: '给物品分组，后续筛选和统计会更方便。',
            二级分类: '在一级分类下继续细分，不选也可以。',
            位置: '记录这件物品放在哪里。',
            状态: '表示当前情况，如使用中、已归档。',
            余量: '当前剩余可用数量。',
            数量: '这件物品的总数量。',
            购入价格: '购买价格，可用于比价和预算回看。',
            购入渠道: '在哪里购买的，例如京东、淘宝、线下。',
            购入日期: '购买日期，不确定可以留空。',
            过期日期: '设置后会自动进入到期提醒。',
            条码序列号: '用于盘点、对账或售后。',
            循环提醒初始日期: '循环提醒从这一天开始计算。',
            循环频率: '这是基于“循环提醒初始日期”来计算下次提醒日期的频率。',
            下次提醒日期: '到这个日期的时候，系统会自动创建一条提醒显示在仪表盘中。日期为自动生成和更新，也可以手动更改。',
            循环提醒备注: '提醒触发时要做什么。',
            标签逗号分隔: '可填写多个关键词，便于搜索。',
            备注: '其他补充说明。',
            共享到公共频道: '开启后会把物品基础信息共享到公共频道。',
            开放注册: '平台注册策略开关。启用后允许自助注册；关闭后仅既有账号可登录。'
        };

        function loadHelpMode() {
            try {
                const saved = localStorage.getItem(HELP_MODE_KEY);
                if (saved === null)
                    return true; // 默认开启：仅首次无配置时生效
                return saved === '1';
            } catch {
                return true;
            }
        }

        function saveHelpMode(enabled) {
            const on = !!enabled;
            localStorage.setItem(HELP_MODE_KEY, on ? '1' : '0');
            App.helpMode = on;
        }

        function normalizeHelpLabelText(text) {
            return String(text || '')
                .replace(/\s+/g, '')
                .replace(/[：:（）()【】\[\]、，,。.!！\*\/\-]/g, '')
                .trim();
        }

        function findHelpFieldIdFromLabel(labelEl) {
            if (!labelEl)
                return '';
            if (labelEl.htmlFor)
                return String(labelEl.htmlFor);
            const innerControl = labelEl.querySelector ? labelEl.querySelector('input[id],select[id],textarea[id]') : null;
            if (innerControl && innerControl.id)
                return String(innerControl.id || '');
            const parent = labelEl.parentElement;
            if (parent) {
                const directControl = Array.from(parent.children).find(el => /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName) && el.id);
                if (directControl)
                    return String(directControl.id || '');
            }
            let sib = labelEl.nextElementSibling;
            while (sib) {
                if (/^(INPUT|SELECT|TEXTAREA)$/.test(sib.tagName) && sib.id)
                    return String(sib.id || '');
                const nested = sib.querySelector ? sib.querySelector('input[id],select[id],textarea[id]') : null;
                if (nested && nested.id)
                    return String(nested.id || '');
                if (sib.tagName === 'LABEL')
                    break;
                sib = sib.nextElementSibling;
            }
            return '';
        }

        function resolveHelpHintForLabel(labelEl) {
            const fieldId = findHelpFieldIdFromLabel(labelEl);
            if (fieldId && HELP_HINTS_BY_FIELD_ID[fieldId]) {
                return HELP_HINTS_BY_FIELD_ID[fieldId];
            }
            const normalizedText = normalizeHelpLabelText(labelEl?.textContent || '');
            if (!normalizedText)
                return '';
            if (HELP_HINTS_BY_TEXT[normalizedText]) {
                return HELP_HINTS_BY_TEXT[normalizedText];
            }
            const keys = Object.keys(HELP_HINTS_BY_TEXT);
            const matched = keys.find(k => normalizedText.includes(k) || k.includes(normalizedText));
            return matched ? HELP_HINTS_BY_TEXT[matched] : '';
        }

        function buildHelpHintNode(helpText) {
            const wrap = document.createElement('span');
            wrap.className = 'help-hint-icon';
            wrap.setAttribute('tabindex', '0');
            wrap.setAttribute('aria-label', '字段说明');

            const mark = document.createElement('span');
            mark.className = 'help-hint-mark';
            mark.textContent = '?';

            const tip = document.createElement('span');
            tip.className = 'help-hint-tooltip';
            tip.textContent = String(helpText || '');

            wrap.appendChild(mark);
            wrap.appendChild(tip);
            return wrap;
        }

        function clearHelpHints(root = document) {
            const scope = root && root.querySelectorAll ? root : document;
            scope.querySelectorAll('.help-hint-icon').forEach(el => el.remove());
        }

        function applyHelpModeHints(root = document) {
            if (!(App && App.helpMode))
                return;
            const scope = root && root.querySelectorAll ? root : document;
            const labels = scope.querySelectorAll('label');
            labels.forEach(labelEl => {
                if (labelEl.querySelector('.help-hint-icon'))
                    return;
                const hint = resolveHelpHintForLabel(labelEl);
                if (!hint)
                    return;
                labelEl.appendChild(buildHelpHintNode(hint));
            });
            updateHelpHintPlacements(scope);
        }

        function updateHelpHintPlacements(root = document) {
            const scope = root && root.querySelectorAll ? root : document;
            scope.querySelectorAll('.help-hint-icon').forEach(icon => {
                icon.classList.remove('hint-align-left', 'hint-align-right', 'hint-below');
                const tip = icon.querySelector('.help-hint-tooltip');
                if (!tip)
                    return;

                const clipHost = icon.closest('.modal-box');
                const hostRect = clipHost ? clipHost.getBoundingClientRect() : { left: 0, right: window.innerWidth };
                const iconRect = icon.getBoundingClientRect();
                const tipRect = tip.getBoundingClientRect();
                const tipWidth = Math.max(220, Math.min(320, Number(tipRect.width || 280)));

                const leftSpace = iconRect.left - hostRect.left;
                const rightSpace = hostRect.right - iconRect.right;
                const halfNeed = tipWidth / 2 + 10;

                if (leftSpace < halfNeed) {
                    icon.classList.add('hint-align-left');
                } else if (rightSpace < halfNeed) {
                    icon.classList.add('hint-align-right');
                }

                const topSpace = iconRect.top - hostRect.top;
                if (topSpace < 88) {
                    icon.classList.add('hint-below');
                }
            });
        }

        function updateHelpModeMenuUI() {
            const on = !!(App && App.helpMode);
            const statusEl = document.getElementById('helpModeStatus');
            const iconEl = document.getElementById('helpModeIcon');
            if (statusEl) {
                statusEl.textContent = on ? '已开启' : '已关闭';
                statusEl.className = on ? 'text-[11px] text-emerald-300' : 'text-[11px] text-slate-400';
            }
            if (iconEl) {
                iconEl.className = on ? 'ri-question-line text-emerald-300' : 'ri-question-line text-cyan-300';
            }
        }

        function toggleHelpMode() {
            const next = !(App && App.helpMode);
            saveHelpMode(next);
            updateHelpModeMenuUI();
            if (next) {
                applyHelpModeHints(document);
            } else {
                clearHelpHints(document);
            }
            toast(next ? '帮助模式已开启' : '帮助模式已关闭');
        }

        // ---------- 排序设置 ----------
        const SORT_SETTINGS_KEY = userScopedStorageKey('sort_settings');
        const defaultSortSettings = {
            dashboard_categories: 'count_desc',   // count_desc | name_asc | total_qty_desc
            items_default: 'updated_at:DESC',     // 同物品列表排序选项
            categories_list: 'count_desc',        // count_desc | name_asc | custom
            locations_list: 'count_desc',         // count_desc | name_asc | custom
        };

        function loadSortSettings() {
            try {
                const saved = localStorage.getItem(SORT_SETTINGS_KEY);
                return saved ? { ...defaultSortSettings, ...JSON.parse(saved) } : { ...defaultSortSettings };
            } catch { return { ...defaultSortSettings }; }
        }
        function saveSortSettings(s) {
            localStorage.setItem(SORT_SETTINGS_KEY, JSON.stringify(s));
            App.sortSettings = s;
        }

        const DASHBOARD_SETTINGS_KEY = userScopedStorageKey('dashboard_settings');
        const defaultDashboardSettings = {
            expiry_past_days: null,
            expiry_future_days: 60,
            reminder_past_days: null,
            reminder_future_days: 3,
            low_stock_threshold_pct: 20,
        };
        function normalizeDashboardSettings(input = {}) {
            const source = (input && typeof input === 'object') ? input : {};
            const parseRange = (value, defaultValue) => {
                if (value === undefined) return defaultValue;
                if (value === null) return null;
                const text = String(value).trim();
                if (text === '') return null;
                const num = Number.parseInt(text, 10);
                if (!Number.isFinite(num)) return defaultValue;
                return Math.max(0, num);
            };
            const parsePercent = (value, defaultValue) => {
                if (value === undefined || value === null) return defaultValue;
                const text = String(value).trim();
                if (text === '') return defaultValue;
                const num = Number.parseInt(text, 10);
                if (!Number.isFinite(num)) return defaultValue;
                return Math.max(0, Math.min(100, num));
            };
            return {
                expiry_past_days: parseRange(source.expiry_past_days, defaultDashboardSettings.expiry_past_days),
                expiry_future_days: parseRange(source.expiry_future_days, defaultDashboardSettings.expiry_future_days),
                reminder_past_days: parseRange(source.reminder_past_days, defaultDashboardSettings.reminder_past_days),
                reminder_future_days: parseRange(source.reminder_future_days, defaultDashboardSettings.reminder_future_days),
                low_stock_threshold_pct: parsePercent(source.low_stock_threshold_pct, defaultDashboardSettings.low_stock_threshold_pct),
            };
        }
        function loadDashboardSettings() {
            try {
                const saved = localStorage.getItem(DASHBOARD_SETTINGS_KEY);
                if (!saved) return normalizeDashboardSettings(defaultDashboardSettings);
                return normalizeDashboardSettings(JSON.parse(saved));
            } catch {
                return normalizeDashboardSettings(defaultDashboardSettings);
            }
        }
        function saveDashboardSettings(settings) {
            const normalized = normalizeDashboardSettings(settings);
            localStorage.setItem(DASHBOARD_SETTINGS_KEY, JSON.stringify(normalized));
            App.dashboardSettings = normalized;
            return normalized;
        }
        function formatRangeLimitLabel(value) {
            return value === null ? '不限制' : `${Number(value)}天`;
        }

        const ITEMS_SIZE_KEY = userScopedStorageKey('items_size');
        function loadItemsSize() { return localStorage.getItem(ITEMS_SIZE_KEY) || 'large'; }
        function saveItemsSize(s) {
            const prev = String((App && App.itemsSize) || '');
            localStorage.setItem(ITEMS_SIZE_KEY, s);
            App.itemsSize = s;
            if (prev !== String(s || '')) {
                logSettingEvent('settings.item_size', `物品显示大小: ${prev || '默认'} -> ${String(s || '')}`);
            }
        }

        // ---------- 属性显示设置 ----------
        const ITEM_ATTRS_KEY = userScopedStorageKey('item_attrs');
        const allItemAttrs = [
            { key: 'category', label: '分类' },
            { key: 'location', label: '位置' },
            { key: 'quantity', label: '件数' },
            { key: 'price', label: '价格' },
            { key: 'expiry', label: '过期日期' },
            { key: 'reminder', label: '循环提醒' },
            { key: 'purchase_from', label: '购入渠道' },
            { key: 'notes', label: '备注' },
        ];
        const defaultItemAttrs = ['location', 'expiry'];
        function loadItemAttrs() {
            try {
                const saved = localStorage.getItem(ITEM_ATTRS_KEY);
                return saved ? JSON.parse(saved) : [...defaultItemAttrs];
            } catch { return [...defaultItemAttrs]; }
        }
        function saveItemAttrs(arr) {
            localStorage.setItem(ITEM_ATTRS_KEY, JSON.stringify(arr));
            App.itemAttrs = arr;
            const labels = allItemAttrs
                .filter(x => Array.isArray(arr) && arr.includes(x.key))
                .map(x => x.label);
            logSettingEvent('settings.item_attrs', `已显示属性: ${labels.length > 0 ? labels.join('、') : '无'}`);
        }
        function toggleItemAttr(key) {
            const idx = App.itemAttrs.indexOf(key);
            if (idx > -1) App.itemAttrs.splice(idx, 1);
            else App.itemAttrs.push(key);
            saveItemAttrs(App.itemAttrs);
            renderItemsFast({ openAttrPanel: true });
        }
        function hasAttr(key) { return App.itemAttrs.includes(key); }

        const EMOJI_GROUPS = [
            { label: '常用', items: ['📦', '📍', '🏠', '🗂️', '📁', '🛒', '📝', '⭐', '✅', '❗', '🔔', '📌', '🏷️', '🎁', '💡', '🧾'] },
            { label: '家居空间', items: ['🛋️', '🛏️', '🪑', '🚪', '🪟', '🪴', '🪞', '🧹', '🧺', '🧼', '🧴', '🗑️', '📺', '🛁', '🚿', '🧯'] },
            { label: '厨房食物', items: ['🍳', '🍽️', '🥣', '🫖', '☕', '🥤', '🧂', '🍱', '🍚', '🍜', '🍞', '🥛', '🍎', '🥬', '🥚', '🍊'] },
            { label: '电子办公', items: ['💻', '🖥️', '📱', '⌚', '🎧', '📷', '🖨️', '⌨️', '🖱️', '🔋', '🔌', '📡', '📶', '💾', '🧠', '📚'] },
            { label: '工具维修', items: ['🧰', '🔧', '🪛', '🔨', '🪚', '🧪', '⚙️', '🧯', '🔦', '🧲', '📏', '🧷', '🔩', '🪙', '🧱', '🪣'] },
            { label: '服饰运动', items: ['👕', '👖', '👟', '🧥', '🧢', '🎒', '👜', '⌚', '⚽', '🏀', '🏸', '🏓', '🏋️', '🚴', '🥾', '🧤'] },
            { label: '出行健康', items: ['🚗', '🧳', '🎫', '💳', '🪪', '🗺️', '🌤️', '☔', '🩺', '💊', '🧴', '😷', '❤️', '🧘', '🚲', '🛵'] },
            { label: '文档学习', items: ['📖', '📚', '🧾', '🗂️', '📅', '🗓️', '✏️', '🖊️', '📐', '📎', '🖇️', '📌', '📍', '🧮', '📰', '📜'] }
        ];
        function normalizeEmojiValue(value, fallback = '📦') {
            const icon = String(value || '').trim();
            return icon || fallback;
        }
        function renderEmojiPicker(pickerId, inputId, selectedEmoji = '📦', fallbackEmoji = '📦') {
            const selected = normalizeEmojiValue(selectedEmoji, fallbackEmoji);
            const existsInGroups = EMOJI_GROUPS.some(group => Array.isArray(group.items) && group.items.includes(selected));
            const renderGroups = existsInGroups ? EMOJI_GROUPS : [{ label: '当前图标', items: [selected] }, ...EMOJI_GROUPS];
            return `
                <div class="relative emoji-picker" id="${pickerId}">
                    <input type="hidden" id="${inputId}" value="${selected}">
                    <button type="button" onclick="toggleEmojiPicker('${pickerId}')" class="input w-full !py-2 flex items-center justify-between gap-2">
                        <span class="inline-flex items-center gap-2 min-w-0">
                            <span id="${inputId}Preview" class="text-2xl leading-none">${selected}</span>
                            <span class="text-xs text-slate-400 truncate">点击选择图标</span>
                        </span>
                        <i class="ri-arrow-down-s-line text-slate-500"></i>
                    </button>
                    <div id="${pickerId}Menu" class="emoji-picker-menu hidden absolute z-30 mt-1 w-full max-h-64 overflow-auto rounded-xl p-2">
                        ${renderGroups.map(group => `
                            <div class="emoji-picker-group">
                                <div class="emoji-picker-group-title">${group.label}</div>
                                <div class="emoji-picker-grid">
                                    ${(Array.isArray(group.items) ? group.items : []).map(emoji => `
                                        <button type="button" data-emoji="${emoji}" onclick="pickEmoji('${pickerId}','${inputId}','${emoji}')" class="emoji-picker-option ${emoji === selected ? 'is-selected' : ''}" title="${emoji}" aria-label="${emoji}">
                                            ${emoji}
                                        </button>
                                    `).join('')}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        }
        function mountEmojiPicker(hostId, pickerId, inputId, selectedEmoji = '📦', fallbackEmoji = '📦') {
            const host = document.getElementById(hostId);
            if (!host) return;
            host.innerHTML = renderEmojiPicker(pickerId, inputId, selectedEmoji, fallbackEmoji);
        }
        function hideEmojiPickerMenus(exceptMenuId = '') {
            document.querySelectorAll('.emoji-picker-menu').forEach(menu => {
                if (!exceptMenuId || menu.id !== exceptMenuId) menu.classList.add('hidden');
            });
        }
        function toggleEmojiPicker(pickerId) {
            const menuId = pickerId + 'Menu';
            const target = document.getElementById(menuId);
            if (!target) return;
            hideEmojiPickerMenus(menuId);
            document.querySelectorAll('.status-icon-picker-menu').forEach(menu => menu.classList.add('hidden'));
            target.classList.toggle('hidden');
        }
        function pickEmoji(pickerId, inputId, emoji) {
            const input = document.getElementById(inputId);
            if (input) input.value = emoji;
            const preview = document.getElementById(inputId + 'Preview');
            if (preview) preview.textContent = emoji;
            const menu = document.getElementById(pickerId + 'Menu');
            if (menu) {
                menu.querySelectorAll('button[data-emoji]').forEach(btn => {
                    btn.classList.toggle('is-selected', btn.getAttribute('data-emoji') === emoji);
                });
                menu.classList.add('hidden');
            }
        }
        function setEmojiPickerValue(pickerId, inputId, value, fallbackEmoji = '📦') {
            const icon = normalizeEmojiValue(value, fallbackEmoji);
            const input = document.getElementById(inputId);
            if (input) input.value = icon;
            const preview = document.getElementById(inputId + 'Preview');
            if (preview) preview.textContent = icon;
            const menu = document.getElementById(pickerId + 'Menu');
            if (menu) {
                menu.querySelectorAll('button[data-emoji]').forEach(btn => {
                    btn.classList.toggle('is-selected', btn.getAttribute('data-emoji') === icon);
                });
            }
        }
        function initFormEmojiPickers() {
            mountEmojiPicker('catEmojiPickerHost', 'catEmojiPicker', 'catIcon', '📦', '📦');
            mountEmojiPicker('locEmojiPickerHost', 'locEmojiPicker', 'locIcon', '📍', '📍');
        }

        // ---------- 状态管理 ----------
        const STATUS_KEY = userScopedStorageKey('statuses');
        const STATUS_KEY_TO_LABEL_MAP = { active: '使用中', archived: '已归档', sold: '已转卖', used_up: '已用完' };
        const STATUS_LABEL_TO_KEY_MAP = { 使用中: 'active', 已归档: 'archived', 已转卖: 'sold', 已用完: 'used_up' };
        const defaultStatuses = [
            { key: 'active', label: '使用中', icon: 'ri-checkbox-circle-line', color: 'text-emerald-400', badge: 'badge-active' },
            { key: 'used_up', label: '已用完', icon: 'ri-close-circle-line', color: 'text-red-400', badge: 'badge-danger' },
            { key: 'sold', label: '已转卖', icon: 'ri-share-forward-line', color: 'text-sky-400', badge: 'badge-lent' },
            { key: 'archived', label: '已归档', icon: 'ri-archive-line', color: 'text-slate-400', badge: 'badge-archived' },
        ];
        const STATUS_ICON_OPTIONS = ['ri-checkbox-circle-line', 'ri-archive-line', 'ri-share-forward-line', 'ri-close-circle-line', 'ri-tools-line', 'ri-error-warning-line', 'ri-time-line', 'ri-shopping-bag-line', 'ri-gift-line', 'ri-heart-line', 'ri-star-line'];
        function getStatusIconLabel(icon) {
            return String(icon || '').replace('ri-', '').replace('-line', '');
        }
        function renderStatusIconPicker(pickerId, inputId, selectedIcon) {
            const selected = STATUS_ICON_OPTIONS.includes(selectedIcon) ? selectedIcon : STATUS_ICON_OPTIONS[0];
            return `
                <div class="relative status-icon-picker" id="${pickerId}">
                    <input type="hidden" id="${inputId}" value="${selected}">
                    <button type="button" onclick="toggleStatusIconPicker('${pickerId}')" class="input w-full !py-2 flex items-center justify-between gap-2">
                        <span class="inline-flex items-center gap-2 min-w-0">
                            <i id="${inputId}PreviewIcon" class="${selected} text-base"></i>
                            <span id="${inputId}PreviewText" class="text-xs text-slate-300 truncate">${getStatusIconLabel(selected)}</span>
                        </span>
                        <i class="ri-arrow-down-s-line text-slate-500"></i>
                    </button>
                    <div id="${pickerId}Menu" class="status-icon-picker-menu hidden absolute z-30 mt-1 w-full max-h-56 overflow-auto rounded-xl p-1">
                        ${STATUS_ICON_OPTIONS.map(ic => `
                            <button type="button" data-icon="${ic}" onclick="pickStatusIcon('${pickerId}','${inputId}','${ic}')" class="status-icon-option w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-left text-xs transition ${ic === selected ? 'is-selected' : ''}">
                                <i class="${ic} text-base"></i>
                                <span>${getStatusIconLabel(ic)}</span>
                            </button>
                        `).join('')}
                    </div>
                </div>
            `;
        }
        function toggleStatusIconPicker(pickerId) {
            const menuId = pickerId + 'Menu';
            const target = document.getElementById(menuId);
            if (!target) return;
            document.querySelectorAll('.status-icon-picker-menu').forEach(menu => {
                if (menu.id !== menuId) menu.classList.add('hidden');
            });
            hideEmojiPickerMenus();
            target.classList.toggle('hidden');
        }
        function pickStatusIcon(pickerId, inputId, icon) {
            const input = document.getElementById(inputId);
            if (input) input.value = icon;
            const previewIcon = document.getElementById(inputId + 'PreviewIcon');
            const previewText = document.getElementById(inputId + 'PreviewText');
            if (previewIcon) previewIcon.className = `${icon} text-base`;
            if (previewText) previewText.textContent = getStatusIconLabel(icon);
            const menu = document.getElementById(pickerId + 'Menu');
            if (menu) {
                menu.querySelectorAll('button[data-icon]').forEach(btn => {
                    const selected = btn.getAttribute('data-icon') === icon;
                    btn.classList.toggle('is-selected', selected);
                });
                menu.classList.add('hidden');
            }
        }
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.status-icon-picker')) {
                document.querySelectorAll('.status-icon-picker-menu').forEach(menu => menu.classList.add('hidden'));
            }
            if (!e.target.closest('.emoji-picker')) {
                hideEmojiPickerMenus();
            }
            if (!e.target.closest('#headerMenuWrap')) {
                closeHeaderMenu();
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape')
                closeHeaderMenu();
        });
        window.addEventListener('resize', () => {
            if (localStorage.getItem(HELP_MODE_KEY) === '1') updateHelpHintPlacements(document);
        });
        function normalizeStatuses(arr) {
            const source = Array.isArray(arr) ? arr : [];
            const normalized = [];
            const seen = new Set();
            for (const raw of source) {
                if (!raw || typeof raw !== 'object')
                    continue;
                let key = String(raw.key || '').trim();
                let label = String(raw.label || '').trim();
                if (STATUS_LABEL_TO_KEY_MAP[key])
                    key = STATUS_LABEL_TO_KEY_MAP[key];
                if (!label && STATUS_KEY_TO_LABEL_MAP[key])
                    label = STATUS_KEY_TO_LABEL_MAP[key];
                if (!label && key)
                    label = key;
                if (!key && label)
                    key = STATUS_LABEL_TO_KEY_MAP[label] || label;
                if (!key || !label || seen.has(key))
                    continue;
                seen.add(key);
                normalized.push({
                    key,
                    label,
                    icon: raw.icon || 'ri-checkbox-circle-line',
                    color: raw.color || 'text-slate-400',
                    badge: raw.badge || 'badge-archived'
                });
            }
            return normalized;
        }
        function upgradeLegacyDefaultStatuses(statuses) {
            const normalized = Array.isArray(statuses) ? statuses : [];
            const keys = normalized.map(s => String(s && s.key ? s.key : '').trim());
            const isLegacyDefault = keys.length === 3 && keys[0] === 'active' && keys[1] === 'archived' && keys[2] === 'sold';
            const isLegacyWithUsedUp = keys.length === 4 && keys[0] === 'active' && keys[1] === 'archived' && keys[2] === 'sold' && keys[3] === 'used_up';
            if (isLegacyWithUsedUp) {
                const byKey = {};
                normalized.forEach(s => { if (s && s.key) byKey[s.key] = s; });
                return ['active', 'used_up', 'sold', 'archived']
                    .map(k => byKey[k])
                    .filter(Boolean)
                    .map(s => ({ ...s }));
            }
            if (!isLegacyDefault) {
                return normalized;
            }
            const usedUpDefault = defaultStatuses.find(s => s.key === 'used_up') || { key: 'used_up', label: '已用完', icon: 'ri-close-circle-line', color: 'text-red-400', badge: 'badge-danger' };
            const byKey = {};
            normalized.forEach(s => { if (s && s.key) byKey[s.key] = s; });
            byKey.used_up = { ...usedUpDefault };
            return ['active', 'used_up', 'sold', 'archived']
                .map(k => byKey[k])
                .filter(Boolean)
                .map(s => ({ ...s }));
        }
        function loadStatuses() {
            try {
                const saved = localStorage.getItem(STATUS_KEY);
                const parsed = saved ? JSON.parse(saved) : defaultStatuses.map(s => ({ ...s }));
                const normalized = normalizeStatuses(parsed);
                const upgraded = upgradeLegacyDefaultStatuses(normalized);
                if (saved && JSON.stringify(upgraded) !== JSON.stringify(normalized)) {
                    localStorage.setItem(STATUS_KEY, JSON.stringify(upgraded));
                }
                return upgraded.length > 0 ? upgraded : defaultStatuses.map(s => ({ ...s }));
            } catch {
                return defaultStatuses.map(s => ({ ...s }));
            }
        }
        function saveStatuses(arr) {
            const normalized = normalizeStatuses(arr);
            const next = normalized.length > 0 ? normalized : defaultStatuses.map(s => ({ ...s }));
            localStorage.setItem(STATUS_KEY, JSON.stringify(next));
            App.statuses = next;
            logSettingEvent('settings.statuses', `状态数量: ${next.length}；当前状态: ${next.map(s => s.label).join('、')}`);
        }
        function getDefaultStatusKey() {
            return (App.statuses[0] && App.statuses[0].key) ? App.statuses[0].key : 'active';
        }
        function getStatusMap() {
            const m = {};
            App.statuses.forEach(s => { m[s.key] = [s.label, s.badge, s.icon]; });
            return m;
        }
        function getStatusGroups() {
            return App.statuses.map(s => ({ key: s.key, label: s.label, icon: s.icon, color: s.color }));
        }

        // ---------- 购入渠道管理 ----------
        const CHANNEL_KEY = userScopedStorageKey('purchase_channels');
        const defaultPurchaseChannels = ['淘宝', '京东', '拼多多', '闲鱼', '官方渠道', '线下', '礼品'];
        function normalizeChannels(arr) {
            const seen = new Set();
            const normalized = [];
            const source = Array.isArray(arr) ? arr : [];
            for (const value of source) {
                const channel = String(value || '').trim();
                if (!channel || seen.has(channel)) continue;
                seen.add(channel);
                normalized.push(channel);
            }
            return normalized;
        }
        function loadPurchaseChannels() {
            try {
                const saved = localStorage.getItem(CHANNEL_KEY);
                if (!saved) return [...defaultPurchaseChannels];
                return normalizeChannels(JSON.parse(saved));
            } catch {
                return [...defaultPurchaseChannels];
            }
        }
        function savePurchaseChannels(arr) {
            const normalized = normalizeChannels(arr);
            localStorage.setItem(CHANNEL_KEY, JSON.stringify(normalized));
            App.purchaseChannels = normalized;
            logSettingEvent('settings.channels', `渠道数量: ${normalized.length}；渠道: ${normalized.join('、')}`);
        }

        let itemFormInitialState = '';
        function getItemFormState() {
            const ids = ['itemId', 'itemName', 'itemCategory', 'itemSubcategory', 'itemLocation', 'itemStatus', 'itemQuantity', 'itemRemainingCurrent', 'itemPrice', 'itemPurchaseFrom', 'itemSharePublic', 'itemDate', 'itemExpiry', 'itemBarcode', 'itemReminderDate', 'itemReminderEvery', 'itemReminderUnit', 'itemReminderNext', 'itemReminderNote', 'itemTags', 'itemNotes', 'itemImage', 'itemSourceShoppingId'];
            const state = {};
            ids.forEach(id => {
                const el = document.getElementById(id);
                if (!el) {
                    state[id] = '';
                } else if (el.type === 'checkbox') {
                    state[id] = !!el.checked;
                } else {
                    state[id] = el.value;
                }
            });
            return JSON.stringify(state);
        }
        function setItemSubmitLabel(text = '保存') {
            const label = document.getElementById('itemSubmitLabel');
            if (label) label.textContent = text;
        }
        function markItemFormClean() {
            itemFormInitialState = getItemFormState();
        }
        function clearItemFormTrack() {
            itemFormInitialState = '';
        }
        function hasItemFormUnsavedChanges() {
            if (!itemFormInitialState) return false;
            return getItemFormState() !== itemFormInitialState;
        }
        function openItemUnsavedConfirm() {
            const modal = document.getElementById('itemUnsavedModal');
            if (modal) modal.classList.add('show');
        }
        function closeItemUnsavedConfirm() {
            const modal = document.getElementById('itemUnsavedModal');
            if (modal) modal.classList.remove('show');
        }
        function discardItemChangesAndClose() {
            closeItemUnsavedConfirm();
            closeItemModal(true);
        }
        function saveItemChangesAndClose() {
            closeItemUnsavedConfirm();
            const form = document.getElementById('itemForm');
            if (form) form.requestSubmit();
        }

        const App = {
            statuses: loadStatuses(),
            purchaseChannels: loadPurchaseChannels(),
            currentView: 'dashboard',
            categories: [],
            publicChannelItems: [],
            messageBoardTasks: [],
            shoppingList: [],
            pendingShoppingEditId: 0,
            itemsSize: loadItemsSize(),
            itemAttrs: loadItemAttrs(),
            locations: [],
            selectedItems: new Set(),
            itemsPage: 1,
            itemsSort: 'updated_at',
            itemsOrder: 'DESC',
            itemsFilter: { search: '', category: 0, location: 0, status: '', expiryOnly: false },
            sortSettings: loadSortSettings(),
            dashboardSettings: loadDashboardSettings(),
            helpMode: loadHelpMode(),
            operationLogsFilters: { keyword: '', actorUserId: 0, sort: 'time_desc' },
            _cachedItems: null,   // 缓存物品列表数据，避免频繁 API 请求
            _cachedTotal: 0,
            _cachedPages: 0,
            _baseDataLoadedAt: 0,
            _baseDataInFlight: null,
            _baseDataVersion: 0
        };

        // ---------- API 封装 ----------
        async function api(endpoint, options = {}) {
            const url = `?api=${endpoint}`;
            try {
                const res = await fetch(url, options);
                let data = null;
                try {
                    data = await res.json();
                } catch (e) {
                    data = { success: false, message: '响应解析失败' };
                }
                if (!res.ok && data && data.code === 'AUTH_REQUIRED') {
                    location.reload();
                    return data;
                }
                if (data && data.code === 'AUTH_REQUIRED') {
                    location.reload();
                    return data;
                }
                return data;
            } catch (e) {
                toast('网络请求失败', 'error');
                return { success: false };
            }
        }

        async function apiPost(endpoint, data) {
            return api(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
        }

        async function logSettingEvent(eventType, details = '') {
            try {
                await apiPost('operation-logs/client-event', {
                    event_type: String(eventType || ''),
                    details: String(details || '')
                });
            } catch (e) {
            }
        }

        async function logout() {
            try {
                await apiPost('auth/logout', {});
            } finally {
                location.reload();
            }
        }

        function closeHeaderMenu() {
            const panel = document.getElementById('headerMenuPanel');
            const arrow = document.getElementById('headerMenuArrow');
            if (panel)
                panel.classList.add('hidden');
            if (arrow)
                arrow.classList.remove('rotate-180');
        }

        function toggleHeaderMenu() {
            const panel = document.getElementById('headerMenuPanel');
            const arrow = document.getElementById('headerMenuArrow');
            if (!panel)
                return;
            const willOpen = panel.classList.contains('hidden');
            if (willOpen) {
                panel.classList.remove('hidden');
                if (arrow)
                    arrow.classList.add('rotate-180');
            } else {
                panel.classList.add('hidden');
                if (arrow)
                    arrow.classList.remove('rotate-180');
            }
        }

        // ---------- Toast 通知 ----------
        function dismissToast(el) {
            if (!el) return;
            el.style.opacity = '0';
            el.style.transform = 'translateY(-10px)';
            setTimeout(() => el.remove(), 300);
        }

        function toast(msg, type = 'success', options = {}) {
            const opts = options && typeof options === 'object' ? options : {};
            const persistent = !!opts.persistent;
            const c = document.getElementById('toast-container');
            const el = document.createElement('div');
            el.className = `toast toast-${type}`;

            const icon = document.createElement('i');
            icon.className = `toast-icon ri-${type === 'success' ? 'check' : 'error-warning'}-line`;
            el.appendChild(icon);

            const message = document.createElement('span');
            message.className = 'toast-message';
            message.textContent = String(msg || '');
            el.appendChild(message);

            if (persistent || opts.closable) {
                const closeBtn = document.createElement('button');
                closeBtn.type = 'button';
                closeBtn.className = 'toast-close';
                closeBtn.innerHTML = '<i class="ri-close-line"></i>';
                closeBtn.onclick = () => dismissToast(el);
                el.appendChild(closeBtn);
            }

            c.appendChild(el);
            if (!persistent) {
                setTimeout(() => dismissToast(el), opts.duration || 2500);
            }
            return el;
        }

        // ---------- 主题切换 ----------
        function toggleTheme() {
            document.body.classList.toggle('light');
            const isLight = document.body.classList.contains('light');
            localStorage.setItem(THEME_KEY, isLight ? 'light' : 'dark');
            document.getElementById('themeIcon').className = isLight ? 'ri-sun-line' : 'ri-moon-line';
            document.getElementById('themeText').textContent = isLight ? '浅色模式' : '深色模式';
        }

        function initTheme() {
            if (localStorage.getItem(THEME_KEY) === 'light') {
                document.body.classList.add('light');
                document.getElementById('themeIcon').className = 'ri-sun-line';
                document.getElementById('themeText').textContent = '浅色模式';
            }
        }

        const DATE_PLACEHOLDER_TEXT = '____年/__月/__日';
        function refreshDateInputPlaceholderDisplay(root = document) {
            root.querySelectorAll('input[data-date-placeholder="1"]').forEach(input => {
                if (document.activeElement === input) return;
                input.type = input.value ? 'date' : 'text';
            });
        }
        function setupDateInputPlaceholders() {
            document.querySelectorAll('input[type="date"]').forEach(input => {
                if (input.dataset.datePlaceholderBound === '1') return;
                input.dataset.datePlaceholderBound = '1';
                input.dataset.datePlaceholder = '1';
                input.placeholder = DATE_PLACEHOLDER_TEXT;
                input.addEventListener('focus', () => { input.type = 'date'; });
                input.addEventListener('blur', () => {
                    if (!input.value) input.type = 'text';
                });
                input.addEventListener('change', () => { input.type = 'date'; });
            });
            refreshDateInputPlaceholderDisplay();
        }

        // ---------- 自定义下拉 ----------
        const customSelectStates = new Map();
        let customSelectEventsBound = false;
        let customSelectRepositionRaf = 0;
        let customSelectSyncRaf = 0;
        let customSelectSyncPendingForce = false;
        let customSelectMutationObserver = null;

        function customSelectOptionText(option) {
            return String(option?.textContent || '').replace(/\s+/g, ' ').trim();
        }

        function customSelectSignature(select) {
            const parts = [];
            Array.from(select.children || []).forEach(node => {
                const tag = String(node.tagName || '').toUpperCase();
                if (tag === 'OPTGROUP') {
                    parts.push(`g:${String(node.label || '').trim()}`);
                    Array.from(node.children || []).forEach(child => {
                        if (String(child.tagName || '').toUpperCase() !== 'OPTION') return;
                        parts.push(`o:${child.value}\u0001${customSelectOptionText(child)}\u0001${child.disabled ? 1 : 0}`);
                    });
                } else if (tag === 'OPTION') {
                    parts.push(`o:${node.value}\u0001${customSelectOptionText(node)}\u0001${node.disabled ? 1 : 0}`);
                }
            });
            return parts.join('\u0002');
        }

        function closeCustomSelect(state) {
            if (!state || !state.open) return;
            state.open = false;
            state.wrapper.classList.remove('is-open');
            state.menu.classList.add('hidden');
            state.trigger.setAttribute('aria-expanded', 'false');
            if (state.menu.parentElement !== state.wrapper) {
                state.wrapper.appendChild(state.menu);
            }
            state.menu.classList.remove('custom-select-menu-floating');
            state.menu.style.left = '';
            state.menu.style.top = '';
            state.menu.style.width = '';
            state.menu.style.minWidth = '';
            state.menu.style.maxWidth = '';
            state.menu.style.maxHeight = '';
            state.menu.style.visibility = '';
        }

        function closeAllCustomSelects(exceptSelect = null) {
            customSelectStates.forEach((state, select) => {
                if (exceptSelect && select === exceptSelect) return;
                closeCustomSelect(state);
            });
        }

        function renderCustomSelectOption(option, optionIndex, state) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'custom-select-option';
            if (state.select.selectedIndex === optionIndex) btn.classList.add('is-selected');
            btn.disabled = !!option.disabled;
            btn.dataset.optionIndex = String(optionIndex);
            btn.innerHTML = `<span class="truncate">${esc(customSelectOptionText(option) || option.value || '')}</span><i class="ri-check-line custom-select-option-check"></i>`;
            btn.addEventListener('click', event => {
                event.preventDefault();
                event.stopPropagation();
                if (btn.disabled) return;
                const targetIndex = Number.parseInt(btn.dataset.optionIndex || '-1', 10);
                if (!Number.isFinite(targetIndex) || targetIndex < 0) return;
                const changed = state.select.selectedIndex !== targetIndex;
                state.select.selectedIndex = targetIndex;
                if (changed) {
                    state.select.dispatchEvent(new Event('input', { bubbles: true }));
                    state.select.dispatchEvent(new Event('change', { bubbles: true }));
                }
                syncCustomSelectState(state, true);
                closeCustomSelect(state);
                state.trigger.focus();
            });
            return btn;
        }

        function rebuildCustomSelectMenu(state) {
            const select = state.select;
            const menu = state.menu;
            menu.innerHTML = '';
            const options = Array.from(select.options || []);
            const indexMap = new Map(options.map((opt, idx) => [opt, idx]));
            let hasAnyOption = false;

            Array.from(select.children || []).forEach(node => {
                const tag = String(node.tagName || '').toUpperCase();
                if (tag === 'OPTGROUP') {
                    const groupWrap = document.createElement('div');
                    groupWrap.className = 'custom-select-group';
                    const title = document.createElement('div');
                    title.className = 'custom-select-group-title';
                    title.textContent = String(node.label || '').trim() || '分组';
                    groupWrap.appendChild(title);
                    Array.from(node.children || []).forEach(child => {
                        if (String(child.tagName || '').toUpperCase() !== 'OPTION') return;
                        const idx = indexMap.get(child);
                        if (!Number.isFinite(idx)) return;
                        groupWrap.appendChild(renderCustomSelectOption(child, idx, state));
                        hasAnyOption = true;
                    });
                    menu.appendChild(groupWrap);
                    return;
                }
                if (tag === 'OPTION') {
                    const idx = indexMap.get(node);
                    if (!Number.isFinite(idx)) return;
                    menu.appendChild(renderCustomSelectOption(node, idx, state));
                    hasAnyOption = true;
                }
            });

            if (!hasAnyOption) {
                const empty = document.createElement('div');
                empty.className = 'custom-select-empty';
                empty.textContent = '暂无选项';
                menu.appendChild(empty);
            }
        }

        function updateCustomSelectTrigger(state) {
            const select = state.select;
            const trigger = state.trigger;
            const selectedOption = select.options && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
            const labelText = selectedOption ? customSelectOptionText(selectedOption) : '';
            state.label.textContent = labelText || '请选择';
            trigger.disabled = !!select.disabled;
            trigger.classList.toggle('opacity-60', !!select.disabled);
        }

        function positionCustomSelectMenu(state) {
            if (!state || !state.open || !state.menu || !state.trigger) return;
            const triggerRect = state.trigger.getBoundingClientRect();
            const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
            const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
            if (triggerRect.bottom < 0 || triggerRect.top > viewportHeight || triggerRect.right < 0 || triggerRect.left > viewportWidth) {
                closeCustomSelect(state);
                return;
            }
            const edge = 8;
            const gap = 6;
            const maxWidth = Math.max(180, viewportWidth - edge * 2);
            state.menu.classList.add('custom-select-menu-floating');

            if (state.inlineMode) {
                state.menu.style.width = 'auto';
                state.menu.style.minWidth = `${Math.min(Math.max(140, triggerRect.width), maxWidth)}px`;
            } else {
                const targetWidth = Math.min(Math.max(140, triggerRect.width), maxWidth);
                state.menu.style.width = `${targetWidth}px`;
                state.menu.style.minWidth = `${targetWidth}px`;
            }
            state.menu.style.maxWidth = `${maxWidth}px`;

            // 先给一个临时高度约束，拿到可靠尺寸后再二次计算
            const provisionalMaxHeight = Math.max(160, Math.min(320, Math.floor(viewportHeight * 0.5)));
            state.menu.style.maxHeight = `${provisionalMaxHeight}px`;

            let measuredRect = state.menu.getBoundingClientRect();
            let left = triggerRect.left;
            if (left + measuredRect.width > viewportWidth - edge) {
                left = viewportWidth - edge - measuredRect.width;
            }
            if (left < edge) left = edge;

            const spaceBelow = Math.max(0, viewportHeight - triggerRect.bottom - edge - gap);
            const spaceAbove = Math.max(0, triggerRect.top - edge - gap);
            const preferAbove = spaceBelow < Math.min(measuredRect.height, 180) && spaceAbove > spaceBelow;
            const availableHeight = preferAbove ? spaceAbove : spaceBelow;
            const finalMaxHeight = Math.max(140, Math.min(320, Math.floor(availableHeight)));
            state.menu.style.maxHeight = `${finalMaxHeight}px`;

            measuredRect = state.menu.getBoundingClientRect();
            let top = preferAbove ? (triggerRect.top - measuredRect.height - gap) : (triggerRect.bottom + gap);
            if (top + measuredRect.height > viewportHeight - edge) {
                top = viewportHeight - edge - measuredRect.height;
            }
            if (top < edge) top = edge;

            state.menu.style.left = `${left}px`;
            state.menu.style.top = `${top}px`;
        }

        function requestCustomSelectReposition() {
            if (customSelectRepositionRaf) return;
            customSelectRepositionRaf = window.requestAnimationFrame(() => {
                customSelectRepositionRaf = 0;
                customSelectStates.forEach(state => {
                    if (state.open) positionCustomSelectMenu(state);
                });
            });
        }

        function syncAllCustomSelectStates(force = false) {
            customSelectStates.forEach((state, select) => {
                if (!select.isConnected) {
                    closeCustomSelect(state);
                    customSelectStates.delete(select);
                    return;
                }
                syncCustomSelectState(state, force);
            });
        }

        function scheduleCustomSelectSync(force = false) {
            if (force) customSelectSyncPendingForce = true;
            if (customSelectSyncRaf) return;
            customSelectSyncRaf = window.requestAnimationFrame(() => {
                customSelectSyncRaf = 0;
                const forceSync = customSelectSyncPendingForce;
                customSelectSyncPendingForce = false;
                syncAllCustomSelectStates(forceSync);
            });
        }

        function openCustomSelect(state) {
            if (!state || state.select.disabled) return;
            closeAllCustomSelects(state.select);
            syncCustomSelectState(state, true);
            state.open = true;
            state.wrapper.classList.add('is-open');
            if (state.menu.parentElement !== document.body) {
                document.body.appendChild(state.menu);
            }
            state.menu.style.visibility = 'hidden';
            state.menu.classList.remove('hidden');
            state.trigger.setAttribute('aria-expanded', 'true');
            positionCustomSelectMenu(state);
            state.menu.style.visibility = '';
            const selected = state.menu.querySelector('.custom-select-option.is-selected');
            if (selected && typeof selected.scrollIntoView === 'function') {
                selected.scrollIntoView({ block: 'nearest' });
            }
        }

        function syncCustomSelectState(state, force = false) {
            if (!state || !state.select || !state.select.isConnected) return;
            const signature = customSelectSignature(state.select);
            const valueKey = `${state.select.selectedIndex}|${state.select.value}`;
            const disabled = !!state.select.disabled;
            const needsRebuild = force || signature !== state.lastSignature || valueKey !== state.lastValueKey;
            if (needsRebuild) {
                rebuildCustomSelectMenu(state);
                updateCustomSelectTrigger(state);
                state.lastSignature = signature;
                state.lastValueKey = valueKey;
                state.lastDisabled = disabled;
            } else if (disabled !== state.lastDisabled) {
                updateCustomSelectTrigger(state);
                state.lastDisabled = disabled;
            }
            if (disabled && state.open) {
                closeCustomSelect(state);
            }
            if (state.open) requestCustomSelectReposition();
        }

        function enhanceCustomSelect(select) {
            if (!(select instanceof HTMLSelectElement)) return;
            if (select.dataset.customSelectReady === '1') {
                const existingState = customSelectStates.get(select);
                if (existingState) syncCustomSelectState(existingState);
                return;
            }

            const parent = select.parentElement;
            if (!parent) return;
            const originalClass = String(select.className || '').trim() || 'input';
            const inlineMode = /(^|\s)!?w-auto(\s|$)/.test(originalClass);
            const wrapper = document.createElement('div');
            wrapper.className = `custom-select ${inlineMode ? 'custom-select-inline' : 'custom-select-block'}`;
            parent.insertBefore(wrapper, select);
            wrapper.appendChild(select);

            select.dataset.customSelectReady = '1';
            select.dataset.customSelectOriginalClass = originalClass;
            select.classList.add('custom-select-native');

            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = `${originalClass} custom-select-trigger`;
            trigger.setAttribute('aria-haspopup', 'listbox');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.innerHTML = `<span class="custom-select-label"></span><i class="ri-arrow-down-s-line custom-select-arrow"></i>`;

            const menu = document.createElement('div');
            menu.className = 'custom-select-menu hidden';

            wrapper.appendChild(trigger);
            wrapper.appendChild(menu);

            const state = {
                select,
                wrapper,
                trigger,
                menu,
                label: trigger.querySelector('.custom-select-label'),
                inlineMode,
                open: false,
                lastSignature: '',
                lastValueKey: '',
                lastDisabled: null
            };
            customSelectStates.set(select, state);

            trigger.addEventListener('click', event => {
                event.preventDefault();
                event.stopPropagation();
                if (state.open) closeCustomSelect(state);
                else openCustomSelect(state);
            });
            trigger.addEventListener('keydown', event => {
                if (event.key === 'Escape') {
                    if (state.open) {
                        event.preventDefault();
                        closeCustomSelect(state);
                    }
                    return;
                }
                if (event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowDown') {
                    event.preventDefault();
                    openCustomSelect(state);
                }
            });
            menu.addEventListener('keydown', event => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeCustomSelect(state);
                    trigger.focus();
                }
            });
            menu.addEventListener('click', event => event.stopPropagation());
            select.addEventListener('change', () => syncCustomSelectState(state, true));
            select.addEventListener('input', () => syncCustomSelectState(state, true));

            syncCustomSelectState(state, true);
        }

        function enhanceCustomSelects(root = document) {
            const scope = root || document;
            scope.querySelectorAll('select.input, select.auth-input').forEach(select => enhanceCustomSelect(select));
        }

        function initCustomSelects() {
            if (!customSelectEventsBound) {
                customSelectEventsBound = true;
                document.addEventListener('click', event => {
                    if (!(event.target instanceof Element)) return;
                    if (!event.target.closest('.custom-select')) closeAllCustomSelects();
                });
                document.addEventListener('keydown', event => {
                    if (event.key === 'Escape') closeAllCustomSelects();
                });
                window.addEventListener('resize', requestCustomSelectReposition);
                window.addEventListener('scroll', requestCustomSelectReposition, true);
            }
            if (!customSelectMutationObserver && document.body) {
                customSelectMutationObserver = new MutationObserver(() => {
                    scheduleCustomSelectSync();
                    requestCustomSelectReposition();
                });
                customSelectMutationObserver.observe(document.body, {
                    subtree: true,
                    childList: true,
                    characterData: true,
                    attributes: true,
                    attributeFilter: ['disabled']
                });
            }
            enhanceCustomSelects(document);
            scheduleCustomSelectSync(true);
        }

        // ---------- 侧边栏 ----------
        function toggleSubMenu(el) {
            el.closest('.sidebar-group').classList.toggle('open');
        }

        function toggleSidebar() {
            const s = document.getElementById('sidebar');
            const o = document.getElementById('mobileOverlay');
            if (window.innerWidth <= 768) {
                s.classList.toggle('open');
                o.classList.toggle('hidden');
            } else {
                s.classList.toggle('collapsed');
                document.querySelector('.main-area').style.marginLeft = s.classList.contains('collapsed') ? '64px' : '240px';
            }
        }

        // ---------- 视图切换 ----------
        const settingsSubViews = ['import-export', 'settings', 'reminder-settings', 'status-settings', 'channel-settings', 'platform-settings', 'user-management', 'operation-logs', 'help-docs', 'changelog'];

        function switchView(view) {
            App.currentView = view;
            closeHeaderMenu();
            document.querySelectorAll('.sidebar-link[data-view]').forEach(el => {
                el.classList.toggle('active', el.dataset.view === view);
            });
            const titles = { dashboard: '仪表盘', items: '物品管理', 'shopping-list': '购物清单', 'message-board': '任务清单', 'public-channel': '公共频道', categories: '分类管理', locations: '位置管理', trash: '物品管理', 'import-export': '数据管理', settings: '设置', 'reminder-settings': '设置', 'status-settings': '状态管理', 'channel-settings': '购入渠道管理', 'platform-settings': '平台设置', 'user-management': '用户管理', 'operation-logs': '操作日志', 'help-docs': '帮助文档', changelog: '更新记录' };
            document.getElementById('viewTitle').textContent = titles[view] || '';
            // 回收站视图高亮物品管理侧边栏
            if (view === 'trash') document.querySelector('.sidebar-link[data-view="items"]')?.classList.add('active');
            // 设置子视图自动展开设置菜单
            const settingsGroup = document.querySelector('.sidebar-group');
            if (settingsGroup) {
                if (settingsSubViews.includes(view)) settingsGroup.classList.add('open');
            }

            // 移动端关闭侧边栏
            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.remove('open');
                document.getElementById('mobileOverlay').classList.add('hidden');
            }

            renderView();
        }

        async function renderView() {
            const c = document.getElementById('viewContainer');
            c.innerHTML = '<div class="flex items-center justify-center py-20"><i class="ri-loader-4-line text-3xl text-sky-400 animate-spin"></i></div>';

            switch (App.currentView) {
                case 'dashboard': await renderDashboard(c); break;
                case 'items': await renderItems(c); break;
                case 'shopping-list': await renderShoppingList(c); break;
                case 'message-board': await renderMessageBoard(c); break;
                case 'public-channel': await renderPublicChannel(c); break;
                case 'categories': await renderCategories(c); break;
                case 'locations': await renderLocations(c); break;
                case 'trash': await renderTrash(c); break;
                case 'import-export': renderImportExport(c); break;
                case 'settings': renderSettings(c); break;
                case 'reminder-settings': renderSettings(c); break;
                case 'status-settings': renderStatusSettings(c); break;
                case 'channel-settings': renderChannelSettings(c); break;
                case 'platform-settings': await renderPlatformSettings(c); break;
                case 'user-management': await renderUserManagement(c); break;
                case 'operation-logs': await renderOperationLogs(c); break;
                case 'help-docs': renderHelpDocs(c); break;
                case 'changelog': renderChangelog(c); break;
            }
            enhanceCustomSelects(c);
            scheduleCustomSelectSync();
            applyHelpModeHints(c);
        }

        // ---------- 加载基础数据 ----------
        const BASE_DATA_CACHE_TTL_MS = 30000;

        function invalidateBaseDataCache() {
            App._baseDataVersion = Number(App._baseDataVersion || 0) + 1;
            App._baseDataLoadedAt = 0;
            App._baseDataInFlight = null;
        }

        function hasFreshBaseData(maxAgeMs = BASE_DATA_CACHE_TTL_MS) {
            const loadedAt = Number(App._baseDataLoadedAt || 0);
            if (loadedAt <= 0) return false;
            return (Date.now() - loadedAt) <= Math.max(0, Number(maxAgeMs || 0));
        }

        async function loadBaseData(options = {}) {
            const force = !!options.force;
            const maxAgeMsRaw = Number(options.maxAgeMs);
            const maxAgeMs = Number.isFinite(maxAgeMsRaw) ? Math.max(0, maxAgeMsRaw) : BASE_DATA_CACHE_TTL_MS;
            if (!force && hasFreshBaseData(maxAgeMs)) return;
            if (!force && App._baseDataInFlight) {
                await App._baseDataInFlight;
                return;
            }

            const requestVersion = Number(App._baseDataVersion || 0);
            const requestPromise = (async () => {
                const [catRes, locRes] = await Promise.all([api('categories'), api('locations')]);
                if (requestVersion !== Number(App._baseDataVersion || 0)) return;
                if (catRes.success) {
                    const rows = Array.isArray(catRes.data) ? catRes.data : [];
                    App.categories = rows.map(cat => ({ ...cat, icon: normalizeEmojiValue(cat.icon, '📦') }));
                }
                if (locRes.success) {
                    const rows = Array.isArray(locRes.data) ? locRes.data : [];
                    App.locations = rows.map(loc => ({ ...loc, icon: normalizeEmojiValue(loc.icon, '📍') }));
                }
                if (catRes.success || locRes.success) {
                    App._baseDataLoadedAt = Date.now();
                }
            })();
            App._baseDataInFlight = requestPromise;
            try {
                await requestPromise;
            } finally {
                if (App._baseDataInFlight === requestPromise) {
                    App._baseDataInFlight = null;
                }
            }
        }

        function getCategoryById(categoryId) {
            const id = Number(categoryId || 0);
            if (id <= 0) return null;
            return (Array.isArray(App.categories) ? App.categories : []).find(c => Number(c.id || 0) === id) || null;
        }

        function getCategoryGroups(sortMode = 'name_asc') {
            const list = Array.isArray(App.categories) ? App.categories : [];
            const idSet = new Set(list.map(c => Number(c.id || 0)));
            const roots = list.filter(c => Number(c.parent_id || 0) <= 0);
            const subs = list
                .filter(c => Number(c.parent_id || 0) > 0 && idSet.has(Number(c.parent_id || 0)))
                .map(c => ({ ...c, _parent: getCategoryById(c.parent_id) }));
            const orphans = list
                .filter(c => Number(c.parent_id || 0) > 0 && !idSet.has(Number(c.parent_id || 0)))
                .map(c => ({ ...c, _parent: null }));
            const sortedRoots = sortListData(roots, sortMode, 'item_count');
            const sortedSubs = [...subs].sort((a, b) => {
                const pa = String(a._parent?.name || '').localeCompare(String(b._parent?.name || ''), 'zh');
                if (pa !== 0) return pa;
                if (sortMode === 'count_desc') return Number(b.item_count || 0) - Number(a.item_count || 0);
                return String(a.name || '').localeCompare(String(b.name || ''), 'zh');
            });
            const sortedOrphans = sortListData(orphans, 'name_asc', 'item_count');
            return { roots: sortedRoots, subs: sortedSubs, orphans: sortedOrphans };
        }

        function getCategoryOptionLabel(cat) {
            const name = String(cat?.name || '').trim() || '未命名分类';
            const icon = String(cat?.icon || '📦').trim() || '📦';
            const parentId = Number(cat?.parent_id || 0);
            if (parentId > 0) {
                const parent = getCategoryById(parentId);
                const parentName = String(parent?.name || cat?.parent_name || '').trim();
                return `${icon} ${parentName ? `${parentName} / ` : ''}${name}`;
            }
            return `${icon} ${name}`;
        }
        function getLocationOptionLabel(loc) {
            const name = String(loc?.name || '').trim() || '未命名位置';
            const icon = String(loc?.icon || '📍').trim() || '📍';
            return `${icon} ${name}`;
        }

        function buildTopCategorySelectOptions(selectedId = 0, options = {}) {
            const selected = Number(selectedId || 0);
            const placeholder = String(options?.placeholder || '选择分类');
            const roots = getCategoryGroups('name_asc').roots;
            const optionRows = [`<option value="0" ${selected === 0 ? 'selected' : ''}>${esc(placeholder)}</option>`];
            roots.forEach(cat => {
                const id = Number(cat.id || 0);
                optionRows.push(`<option value="${id}" ${selected === id ? 'selected' : ''}>${esc(`${String(cat.icon || '📦').trim() || '📦'} ${String(cat.name || '').trim() || '未命名分类'}`)}</option>`);
            });
            return optionRows.join('');
        }

        function refreshItemSubcategorySelect(categoryId = 0, selectedSubcategoryId = 0) {
            const subSelect = document.getElementById('itemSubcategory');
            if (!subSelect) return;
            const topId = Number(categoryId || 0);
            const selected = Number(selectedSubcategoryId || 0);
            if (topId <= 0) {
                subSelect.innerHTML = '<option value="0">请先选择一级分类</option>';
                subSelect.value = '0';
                subSelect.disabled = true;
                return;
            }
            const subs = (Array.isArray(App.categories) ? App.categories : [])
                .filter(c => Number(c.parent_id || 0) === topId)
                .sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'zh'));
            if (subs.length === 0) {
                subSelect.innerHTML = '<option value="0">当前一级分类暂无二级分类</option>';
                subSelect.value = '0';
                subSelect.disabled = true;
                return;
            }
            const optionRows = ['<option value="0">不设置二级分类</option>'];
            subs.forEach(cat => {
                const id = Number(cat.id || 0);
                const icon = String(cat.icon || '📦').trim() || '📦';
                const name = String(cat.name || '').trim() || '未命名分类';
                optionRows.push(`<option value="${id}" ${selected === id ? 'selected' : ''}>${esc(`${icon} ${name}`)}</option>`);
            });
            subSelect.innerHTML = optionRows.join('');
            subSelect.value = String(subs.some(c => Number(c.id || 0) === selected) ? selected : 0);
            subSelect.disabled = false;
        }

        function buildCategorySelectOptions(selectedId = 0, options = {}) {
            const selected = Number(selectedId || 0);
            const {
                includeAll = false,
                includeUncategorized = false,
                allLabel = '所有分类',
                uncategorizedLabel = '未分类',
                placeholder = ''
            } = options || {};
            const g = getCategoryGroups('name_asc');
            const optionRows = [];
            if (includeAll) optionRows.push(`<option value="0" ${selected === 0 ? 'selected' : ''}>${allLabel}</option>`);
            if (includeUncategorized) optionRows.push(`<option value="-1" ${selected === -1 ? 'selected' : ''}>${uncategorizedLabel}</option>`);
            if (placeholder && !includeAll) optionRows.push(`<option value="0" ${selected === 0 ? 'selected' : ''}>${placeholder}</option>`);
            if (g.roots.length > 0) {
                optionRows.push('<optgroup label="一级分类">');
                g.roots.forEach(cat => {
                    const id = Number(cat.id || 0);
                    optionRows.push(`<option value="${id}" ${selected === id ? 'selected' : ''}>${esc(getCategoryOptionLabel(cat))}</option>`);
                });
                optionRows.push('</optgroup>');
            }
            if (g.subs.length > 0) {
                optionRows.push('<optgroup label="二级分类">');
                g.subs.forEach(cat => {
                    const id = Number(cat.id || 0);
                    optionRows.push(`<option value="${id}" ${selected === id ? 'selected' : ''}>${esc(getCategoryOptionLabel(cat))}</option>`);
                });
                optionRows.push('</optgroup>');
            }
            if (g.orphans.length > 0) {
                optionRows.push('<optgroup label="二级分类（待整理）">');
                g.orphans.forEach(cat => {
                    const id = Number(cat.id || 0);
                    optionRows.push(`<option value="${id}" ${selected === id ? 'selected' : ''}>${esc(getCategoryOptionLabel(cat))}</option>`);
                });
                optionRows.push('</optgroup>');
            }
            return optionRows.join('');
        }

        function formatMessageBoardDateTime(value) {
            const s = String(value || '').replace('T', ' ');
            if (!s) return '未知时间';
            return s.length >= 16 ? s.slice(0, 16) : s;
        }

        function getTaskBoardById(taskId) {
            const id = Number(taskId || 0);
            if (id <= 0) return null;
            const list = Array.isArray(App.messageBoardTasks) ? App.messageBoardTasks : [];
            return list.find(x => Number(x.id || 0) === id) || null;
        }

        function renderMessageBoardListHtml(posts, options = {}) {
            const list = Array.isArray(posts) ? posts : [];
            const {
                emptyText = '暂无任务',
                showActions = true,
                hideCompleted = false
            } = options || {};
            const rows = hideCompleted ? list.filter(x => Number(x.is_completed || 0) !== 1) : list;
            if (rows.length === 0) {
                return `<p class="text-slate-500 text-sm text-center py-6">${esc(emptyText)}</p>`;
            }
            return rows.map(post => {
                const isCompleted = Number(post.is_completed || 0) === 1;
                const canEdit = !!post.can_edit;
                const canDelete = !!post.can_delete;
                return `
                <div class="rounded-xl border border-white/10 bg-white/[0.03] px-3 py-2.5">
                    <div class="flex items-center justify-between gap-3 mb-1">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="text-xs text-sky-300 truncate">${esc(String(post.author_name || '未知用户'))}</span>
                            <span class="badge ${isCompleted ? 'badge-active' : 'badge-warning'} !text-[10px]">${isCompleted ? '已完成' : '待完成'}</span>
                        </div>
                        <span class="text-[11px] text-slate-500 flex-shrink-0">${esc(formatMessageBoardDateTime(post.created_at))}</span>
                    </div>
                    <p class="text-sm ${isCompleted ? 'text-slate-500 line-through' : 'text-slate-200'} break-words leading-6">${esc(String(post.content || ''))}</p>
                    ${showActions && (canEdit || canDelete) ? `
                        <div class="mt-2.5 flex items-center justify-end gap-2">
                            ${canEdit ? `<button onclick="toggleMessageBoardTaskStatus(${Number(post.id || 0)}, ${isCompleted ? 0 : 1})" class="btn btn-ghost btn-sm !py-1 !px-2 text-xs ${isCompleted ? 'text-amber-300 border-amber-400/25 hover:border-amber-300/40' : 'text-emerald-300 border-emerald-400/25 hover:border-emerald-300/40'}"><i class="${isCompleted ? 'ri-refresh-line' : 'ri-check-line'}"></i>${isCompleted ? '设为待办' : '标记完成'}</button>` : ''}
                            ${canEdit ? `<button onclick="editMessageBoardTask(${Number(post.id || 0)})" class="btn btn-ghost btn-sm !py-1 !px-2 text-xs text-cyan-300 border-cyan-400/25 hover:border-cyan-300/40"><i class="ri-edit-line"></i>编辑</button>` : ''}
                            ${canDelete ? `<button onclick="deleteMessageBoardTask(${Number(post.id || 0)})" class="btn btn-ghost btn-sm !py-1 !px-2 text-xs text-rose-300 border-rose-400/25 hover:border-rose-300/40"><i class="ri-delete-bin-6-line"></i>删除</button>` : ''}
                        </div>
                    ` : ''}
                </div>`;
            }).join('');
        }

        function handleMessageBoardInputKey(e, inputId) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            postMessageBoard(inputId);
        }

        async function postMessageBoard(inputId) {
            const input = document.getElementById(inputId);
            if (!input) return;
            const content = String(input.value || '').trim();
            if (!content) {
                toast('请输入任务内容', 'error');
                input.focus();
                return;
            }
            const res = await apiPost('message-board', { content });
            if (!res || !res.success) {
                toast((res && res.message) || '任务添加失败', 'error');
                return;
            }
            input.value = '';
            toast(res.message || '任务已添加');
            renderView();
        }

        async function editMessageBoardTask(taskId) {
            const task = getTaskBoardById(taskId);
            if (!task) {
                toast('任务不存在', 'error');
                return;
            }
            if (!task.can_edit) {
                toast('仅创建者或管理员可编辑任务', 'error');
                return;
            }
            const nextContent = prompt('编辑任务内容：', String(task.content || ''));
            if (nextContent === null) return;
            const content = String(nextContent || '').trim();
            if (!content) {
                toast('任务内容不能为空', 'error');
                return;
            }
            const res = await apiPost('message-board/update', { id: Number(task.id || 0), content });
            if (!res || !res.success) {
                toast((res && res.message) || '任务编辑失败', 'error');
                return;
            }
            toast(res.message || '任务已更新');
            renderView();
        }

        async function toggleMessageBoardTaskStatus(taskId, isCompleted) {
            const task = getTaskBoardById(taskId);
            if (!task) {
                toast('任务不存在', 'error');
                return;
            }
            if (!task.can_edit) {
                toast('仅创建者或管理员可修改任务', 'error');
                return;
            }
            const res = await apiPost('message-board/update', {
                id: Number(task.id || 0),
                is_completed: Number(isCompleted || 0) === 1 ? 1 : 0
            });
            if (!res || !res.success) {
                toast((res && res.message) || '任务状态更新失败', 'error');
                return;
            }
            toast(res.message || '任务状态已更新');
            renderView();
        }

        async function deleteMessageBoardTask(taskId) {
            const task = getTaskBoardById(taskId);
            if (!task) {
                toast('任务不存在', 'error');
                return;
            }
            if (!task.can_delete) {
                toast('仅创建者或管理员可删除任务', 'error');
                return;
            }
            if (!confirm('确定删除这条任务吗？')) return;
            const res = await apiPost('message-board/delete', { id: Number(task.id || 0) });
            if (!res || !res.success) {
                toast((res && res.message) || '任务删除失败', 'error');
                return;
            }
            toast(res.message || '任务已删除');
            renderView();
        }

        async function renderMessageBoard(container) {
            const res = await api('message-board&limit=120');
            if (!res || !res.success) {
                container.innerHTML = '<p class="text-red-400">任务清单加载失败</p>';
                return;
            }
            const list = Array.isArray(res.data) ? res.data : [];
            App.messageBoardTasks = list;
            const today = new Date().toISOString().slice(0, 10);
            const todayCount = list.filter(x => String(x.created_at || '').slice(0, 10) === today).length;
            const pendingTasks = list.filter(x => Number(x.is_completed || 0) !== 1);
            const completedTasks = list.filter(x => Number(x.is_completed || 0) === 1);
            container.innerHTML = `
        <div class="space-y-6">
            <div class="glass rounded-2xl p-4 anim-up">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                    <span class="text-sm text-slate-400"><i class="ri-chat-check-line mr-1 text-sky-400"></i>任务总数 ${list.length} 条</span>
                    <span class="text-sm text-slate-400"><i class="ri-time-line mr-1 text-amber-400"></i>待完成 ${pendingTasks.length} 条</span>
                    <span class="text-sm text-slate-400"><i class="ri-checkbox-circle-line mr-1 text-emerald-400"></i>已完成 ${completedTasks.length} 条</span>
                    <span class="text-sm text-slate-400"><i class="ri-calendar-check-line mr-1 text-cyan-400"></i>今日新增 ${todayCount} 条</span>
                </div>
            </div>
            <div class="glass rounded-2xl p-5 anim-up" style="animation-delay:0.03s">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-white flex items-center gap-2"><i class="ri-task-line text-cyan-400"></i>新增任务</h3>
                    <button onclick="switchView('public-channel')" class="text-sm text-sky-400 hover:text-sky-300 transition">前往公共频道 →</button>
                </div>
                <div class="flex items-center gap-2 mb-4">
                    <input id="messageBoardInputMain" type="text" maxlength="300" class="input !py-2.5 flex-1" placeholder="输入任务内容..." onkeydown="handleMessageBoardInputKey(event, 'messageBoardInputMain')">
                    <button onclick="postMessageBoard('messageBoardInputMain')" class="btn btn-primary !py-2.5 !px-4"><i class="ri-add-line"></i>添加</button>
                </div>
                <div class="space-y-5 max-h-[65vh] overflow-auto pr-1">
                    <div>
                        <p class="text-xs text-slate-500 mb-2">待完成</p>
                        <div class="space-y-2.5">
                            ${renderMessageBoardListHtml(pendingTasks, { emptyText: '暂无待完成任务', showActions: true })}
                        </div>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 mb-2">已完成</p>
                        <div class="space-y-2.5">
                            ${renderMessageBoardListHtml(completedTasks, { emptyText: '暂无已完成任务', showActions: true })}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
        }

        // ============================================================
        // 📊 仪表盘
        // ============================================================
        async function renderDashboard(container) {
            const dashboardSettings = normalizeDashboardSettings(App.dashboardSettings || defaultDashboardSettings);
            App.dashboardSettings = dashboardSettings;
            const dashboardParams = new URLSearchParams();
            dashboardParams.set('expiry_past_days', dashboardSettings.expiry_past_days === null ? '' : String(dashboardSettings.expiry_past_days));
            dashboardParams.set('expiry_future_days', dashboardSettings.expiry_future_days === null ? '' : String(dashboardSettings.expiry_future_days));
            dashboardParams.set('reminder_past_days', dashboardSettings.reminder_past_days === null ? '' : String(dashboardSettings.reminder_past_days));
            dashboardParams.set('reminder_future_days', dashboardSettings.reminder_future_days === null ? '' : String(dashboardSettings.reminder_future_days));
            dashboardParams.set('low_stock_threshold_pct', String(Number(dashboardSettings.low_stock_threshold_pct ?? defaultDashboardSettings.low_stock_threshold_pct)));
            const dashboardEndpoint = dashboardParams.toString() ? `dashboard&${dashboardParams.toString()}` : 'dashboard';
            const res = await api(dashboardEndpoint);
            if (!res.success) { container.innerHTML = '<p class="text-red-400">加载失败</p>'; return; }
            const d = res.data;
            const statusMap = getStatusMap();
            const expiringItems = Array.isArray(d.expiringItems) ? d.expiringItems : [];
            const reminderItems = Array.isArray(d.reminderItems) ? d.reminderItems : [];
            const shoppingReminderItems = Array.isArray(d.shoppingReminderItems) ? d.shoppingReminderItems : [];
            const lowStockReminderItems = Array.isArray(d.lowStockReminderItems) ? d.lowStockReminderItems : [];
            const lowStockThresholdPct = Number(d.lowStockThresholdPct ?? dashboardSettings.low_stock_threshold_pct ?? defaultDashboardSettings.low_stock_threshold_pct);
            const memoReminderItems = [
                ...reminderItems.map(item => ({ ...item, _source: 'item', _dueDate: reminderDisplayDate(item) })),
                ...shoppingReminderItems.map(item => ({ ...item, _source: 'shopping', _dueDate: item.reminder_date || '' })),
                ...lowStockReminderItems.map(item => ({ ...item, _source: 'stock', _dueDate: item.reminder_due_date || '' }))
            ]
                .filter(item => item._dueDate)
                .sort((a, b) => String(a._dueDate).localeCompare(String(b._dueDate)));
            const memoExpiredCount = memoReminderItems.filter(item => daysUntilReminder(item._dueDate) < 0).length;
            const memoCycleCount = memoReminderItems.filter(item => item._source === 'item').length;
            const memoShoppingCount = memoReminderItems.filter(item => item._source === 'shopping').length;
            const memoStockCount = memoReminderItems.filter(item => item._source === 'stock').length;
            const dashboardStatusStats = (d.statusStats || []).filter(s => Number(s.total_qty || 0) > 0);
            const taskBoardPosts = (Array.isArray(d.messageBoardPosts) ? d.messageBoardPosts : []).filter(x => Number(x.is_completed || 0) !== 1);
            App.messageBoardTasks = taskBoardPosts;

            container.innerHTML = `
        <div class="glass rounded-2xl p-4 mb-6 anim-up">
            <div class="flex flex-wrap gap-x-6 gap-y-2 items-center">
                ${statInline('ri-archive-line', '物品种类', d.totalKinds, 'text-sky-400')}
                <span class="hidden sm:block w-px h-5 bg-white/5"></span>
                ${statInline('ri-stack-line', '物品总数', d.totalItems, 'text-violet-400')}
                <span class="hidden sm:block w-px h-5 bg-white/5"></span>
                ${statInline('ri-price-tag-3-line', '分类数', d.totalCategories, 'text-emerald-400')}
                <span class="hidden sm:block w-px h-5 bg-white/5"></span>
                ${statInline('ri-map-pin-line', '位置数', d.totalLocations, 'text-amber-400')}
                <span class="hidden sm:block w-px h-5 bg-white/5"></span>
                ${statInline('ri-money-cny-circle-line', '总价值', '¥' + Number(d.totalValue).toLocaleString('zh-CN', { minimumFractionDigits: 0, maximumFractionDigits: 2 }), 'text-rose-400')}
            </div>
        </div>

        <div class="glass rounded-2xl p-5 mb-6 anim-up">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-white flex items-center gap-2"><i class="ri-alarm-warning-line text-amber-400"></i>过期提醒</h3>
                <span class="text-xs text-slate-500">${expiringItems.length} 件物品设有过期日期</span>
            </div>
            ${expiringItems.length > 0 ? `
            <div class="dashboard-reminder-grid">
                ${expiringItems.map(item => {
                const days = daysUntilExpiry(item.expiry_date);
                const urgency = days < 0 ? 'expired' : days <= 7 ? 'urgent' : days <= 30 ? 'warning' : 'normal';
                const bgMap = {
                    expired: 'bg-red-500/10 border-red-500/20 expiry-remind-item expiry-expired',
                    urgent: 'bg-amber-500/10 border-amber-500/20 expiry-remind-item expiry-urgent',
                    warning: 'bg-yellow-500/5 border-yellow-500/15 expiry-remind-item expiry-warning',
                    normal: 'bg-white/5 border-white/5 expiry-remind-item expiry-normal'
                };
                const textMap = { expired: 'text-red-400', urgent: 'text-amber-400', warning: 'text-yellow-400', normal: 'text-slate-400' };
                const labelMap = { expired: '已过期 ' + Math.abs(days) + ' 天', urgent: '剩余 ' + days + ' 天', warning: '剩余 ' + days + ' 天', normal: '剩余 ' + days + ' 天' };
                return `<div class="flex items-center gap-3 p-3 rounded-xl border ${bgMap[urgency]} cursor-pointer hover:brightness-110 transition" onclick="showDetail(${item.id})">
                        <div class="w-9 h-9 rounded-lg ${item.image ? '' : 'bg-slate-700/50 flex items-center justify-center text-base'} flex-shrink-0 overflow-hidden">
                            ${item.image ? `<img src="?img=${item.image}" class="w-full h-full object-cover rounded-lg">` : `<span>${item.category_icon || '📦'}</span>`}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-200 truncate">${esc(item.name)}</p>
                            <p class="text-xs ${textMap[urgency]} font-medium expiry-meta"><span>${item.expiry_date}</span> · <span>${labelMap[urgency]}</span></p>
                        </div>
                        ${urgency === 'expired' ? '<i class="ri-error-warning-fill text-red-400 flex-shrink-0"></i>' : urgency === 'urgent' ? '<i class="ri-alarm-warning-fill text-amber-400 flex-shrink-0"></i>' : ''}
                    </div>`;
            }).join('')}
            </div>
            ` : '<p class="text-slate-500 text-sm text-center py-8">暂无设置过期日期的物品</p>'}
        </div>

        <div class="glass rounded-2xl p-5 mb-6 anim-up" style="animation-delay:0.04s">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-white flex items-center gap-2"><i class="ri-loop-right-line text-cyan-400"></i>备忘提醒</h3>
                <span class="text-xs text-slate-500">过期 ${memoExpiredCount} 条 · 循环 ${memoCycleCount} 条 · 购物 ${memoShoppingCount} 条 · 余量 ${memoStockCount} 条（阈值 ${Number.isFinite(lowStockThresholdPct) ? lowStockThresholdPct : defaultDashboardSettings.low_stock_threshold_pct}%）</span>
            </div>
            ${memoReminderItems.length > 0 ? `
            <div class="dashboard-reminder-grid">
                ${memoReminderItems.map(item => {
                const dueDate = item._dueDate;
                const days = daysUntilReminder(dueDate);
                const urgency = days < 0 ? 'expired' : days <= 1 ? 'urgent' : 'warning';
                const bgMap = {
                    expired: 'bg-red-500/10 border-red-500/20 reminder-remind-item reminder-expired',
                    urgent: 'bg-amber-500/10 border-amber-500/20 reminder-remind-item reminder-urgent',
                    warning: 'bg-yellow-500/5 border-yellow-500/15 reminder-remind-item reminder-warning'
                };
                const textMap = { expired: 'text-red-400', urgent: 'text-amber-400', warning: 'text-yellow-400' };
                const isItemReminder = item._source === 'item';
                const isStockReminder = item._source === 'stock';
                const clickAction = (isItemReminder || isStockReminder) ? `showDetail(${item.id})` : `switchView('shopping-list')`;
                const summaryNote = String(item.reminder_note || '').trim();
                const summaryNoteHtml = summaryNote ? esc(summaryNote) : '&nbsp;';
                const isCompleted = isItemReminder && Number(item.reminder_completed || 0) === 1;
                const reminderId = Number(item.reminder_instance_id || 0);
                const stockTotal = Math.max(0, Number(item.stock_total || item.remaining_total || item.quantity || 0));
                const stockCurrent = Math.max(0, Number(item.stock_current ?? item.remaining_current ?? 0));
                const stockRatio = stockTotal > 0 ? Math.round((stockCurrent / stockTotal) * 100) : 0;
                return `<div class="p-3 rounded-xl border ${bgMap[urgency]} cursor-pointer hover:brightness-110 transition" onclick="${clickAction}">
                        <div class="flex items-start gap-3">
                            <div class="w-9 h-9 rounded-lg ${(isItemReminder || isStockReminder) && item.image ? '' : 'bg-slate-700/50 flex items-center justify-center text-base'} flex-shrink-0 overflow-hidden">
                                ${(isItemReminder || isStockReminder) && item.image ? `<img src="?img=${item.image}" class="w-full h-full object-cover rounded-lg">` : `<span>${(isItemReminder || isStockReminder) ? (item.category_icon || '📦') : '🛒'}</span>`}
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-slate-200 truncate">${esc(item.name)}</p>
                                <p class="text-xs ${textMap[urgency]} font-medium reminder-meta"><span>${dueDate}</span> · <span>${reminderDueLabel(dueDate)}</span></p>
                                <p class="text-[11px] text-slate-500 mt-0.5">${isItemReminder ? reminderCycleLabel(item.reminder_cycle_value, item.reminder_cycle_unit) : (isStockReminder ? `余量提醒 · ${stockCurrent}/${stockTotal}（${stockRatio}%）` : '购物清单提醒')}</p>
                                <p class="text-[11px] text-slate-400 mt-1 truncate h-4 leading-4">${summaryNoteHtml}</p>
                            </div>
                        </div>
                        <div class="mt-3 flex justify-end gap-2">
                            ${isItemReminder ? `
                                ${isCompleted ? `
                                    <button onclick="event.stopPropagation();undoReminder(${item.id},${reminderId})" class="btn btn-ghost btn-sm reminder-action-btn reminder-action-undo !py-1 !px-2 text-xs text-amber-300 hover:text-amber-200 border-amber-400/25 hover:border-amber-300/40">
                                        <i class="ri-arrow-go-back-line"></i>撤销
                                    </button>
                                    <button class="btn btn-ghost btn-sm reminder-action-btn reminder-action-done !py-1 !px-2 text-xs text-emerald-300 border-emerald-400/25 cursor-default pointer-events-none">
                                        <i class="ri-checkbox-circle-line"></i>已完成
                                    </button>
                                ` : `
                                    <button onclick="event.stopPropagation();completeReminder(${item.id},${reminderId})" class="btn btn-ghost btn-sm reminder-action-btn reminder-action-pending !py-1 !px-2 text-xs text-cyan-300 hover:text-cyan-200 border-cyan-400/25 hover:border-cyan-300/40">
                                        <i class="ri-time-line"></i>待完成
                                    </button>
                                `}
                            ` : `
                            ${isStockReminder ? `
                                <button onclick="event.stopPropagation();showDetail(${item.id})" class="btn btn-ghost btn-sm reminder-action-btn reminder-action-view !py-1 !px-2 text-xs text-cyan-300 hover:text-cyan-200 border-cyan-400/25 hover:border-cyan-300/40">
                                    <i class="ri-eye-line"></i>查看物品
                                </button>
                            ` : `
                                <button onclick="event.stopPropagation();openShoppingListAndEdit(${item.id})" class="btn btn-ghost btn-sm reminder-action-btn reminder-action-view !py-1 !px-2 text-xs text-cyan-300 hover:text-cyan-200 border-cyan-400/25 hover:border-cyan-300/40">
                                    <i class="ri-list-check"></i>查看清单
                                </button>
                            `}
                            `}
                        </div>
                    </div>`;
            }).join('')}
            </div>
            ` : '<p class="text-slate-500 text-sm text-center py-8">暂无临近 3 天的备忘提醒</p>'}
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <div class="glass rounded-2xl p-5 anim-up" style="animation-delay:0.08s">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-semibold text-white flex items-center gap-2"><i class="ri-task-line text-cyan-400"></i>任务清单</h3>
                        <button onclick="switchView('message-board')" class="text-sm text-sky-400 hover:text-sky-300 transition">查看全部 →</button>
                    </div>
                    <div class="flex items-center gap-2 mb-4">
                        <input id="messageBoardInputDashboard" type="text" maxlength="300" class="input !py-2.5 flex-1" placeholder="添加待办任务..." onkeydown="handleMessageBoardInputKey(event, 'messageBoardInputDashboard')">
                        <button onclick="postMessageBoard('messageBoardInputDashboard')" class="btn btn-primary btn-sm !py-2 !px-3"><i class="ri-add-line"></i>添加</button>
                    </div>
                    <div class="space-y-2.5">
                        ${renderMessageBoardListHtml(taskBoardPosts, { emptyText: '暂无待办任务', showActions: true, hideCompleted: true })}
                    </div>
                </div>

                <div class="glass rounded-2xl p-5 anim-up">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-semibold text-white flex items-center gap-2"><i class="ri-time-line text-sky-400"></i>最近更新</h3>
                        <button onclick="switchView('items')" class="text-sm text-sky-400 hover:text-sky-300 transition">查看全部 →</button>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 xl:grid-cols-3 gap-2">
                        ${d.recentItems.map(item => `
                            <div class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg hover:bg-white/5 transition cursor-pointer" onclick="showDetail(${item.id})">
                                <div class="w-8 h-8 rounded-md ${item.image ? '' : 'bg-slate-700/50 flex items-center justify-center text-sm'} flex-shrink-0 overflow-hidden">
                                    ${item.image ? `<img src="?img=${item.image}" class="w-full h-full object-cover rounded-md">` : `<span>${item.category_icon || '📦'}</span>`}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-slate-200 truncate leading-tight">${esc(item.name)}</p>
                                    <p class="text-[11px] text-slate-500 truncate">${esc(item.location_name || '未设定位置')} · x${item.quantity}</p>
                                </div>
                            </div>
                        `).join('')}
                        ${d.recentItems.length === 0 ? '<p class="text-slate-500 text-sm col-span-full text-center py-8">还没有物品，点击右上角「添加物品」开始吧</p>' : ''}
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="glass rounded-2xl p-5 anim-up" style="animation-delay:0.1s">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-semibold text-white flex items-center gap-2"><i class="ri-pie-chart-line text-violet-400"></i>分类统计</h3>
                        <span class="text-xs text-slate-500">未分类 ${Number(d.uncategorizedQty || 0)} 件</span>
                    </div>
                    <div class="space-y-3">
                        ${(() => { const total = d.categoryStats.reduce((sum, c) => sum + Number(c.total_qty || 0), 0);
                return sortCategoryStats(d.categoryStats.filter(c => c.count > 0)).map(cat => {
                    const qty = Number(cat.total_qty || 0);
                    const pct = total > 0 ? Math.round(qty / total * 100) : 0;
                    return `<div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm text-slate-300">${cat.icon} ${esc(cat.name)}</span>
                                <span class="text-xs text-slate-500">${qty} 件</span>
                            </div>
                            <div class="h-2 category-progress-track rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-500" style="width:${pct}%;background:${cat.color}"></div>
                            </div>
                        </div>`;
                }).join(''); })()}
                        ${d.categoryStats.filter(c => c.count > 0).length === 0 ? '<p class="text-slate-500 text-sm text-center py-4">暂无数据</p>' : ''}
                    </div>
                </div>

                <div class="glass rounded-2xl p-5 anim-up" style="animation-delay:0.15s">
                    <h3 class="font-semibold text-white flex items-center gap-2 mb-4"><i class="ri-pulse-line text-emerald-400"></i>状态统计</h3>
                    ${dashboardStatusStats.length > 0 ? `
                    <div class="space-y-2.5">
                        ${dashboardStatusStats.map(s => {
                const meta = statusMap[s.status] || ['未知状态', 'badge-archived', 'ri-question-line'];
                const [label, badgeClass, iconClass] = meta;
                return `<div class="flex items-center justify-between py-1.5 border-b border-white/5 last:border-b-0">
                                <span class="badge ${badgeClass}"><i class="${iconClass} mr-1"></i>${label}</span>
                                <span class="text-xs text-slate-500">${Number(s.total_qty || 0)} 件</span>
                            </div>`;
            }).join('')}
                    </div>
                    ` : '<p class="text-slate-500 text-sm text-center py-8">暂无状态数据</p>'}
                </div>
            </div>
        </div>
    `;
        }

        function statInline(icon, label, value, iconColor) {
            return `<div class="flex items-center gap-2.5 py-1">
        <i class="${icon} text-lg ${iconColor}"></i>
        <div class="flex items-baseline gap-1.5">
            <span class="text-lg font-bold text-white leading-none">${value}</span>
            <span class="text-xs text-slate-500">${label}</span>
        </div>
    </div>`;
        }

        // ============================================================
        // 📦 物品管理
        // ============================================================
        async function renderItems(container) {
            await loadBaseData();
            const f = App.itemsFilter;

            const params = new URLSearchParams({
                page: App.itemsPage, limit: 24, sort: App.itemsSort, order: App.itemsOrder,
                search: f.search, category: f.category, location: f.location, status: f.status
            });
            if (f.expiryOnly) params.set('expiry_only', '1');

            const res = await api('items&' + params.toString());
            if (!res.success) { container.innerHTML = '<p class="text-red-400">加载失败</p>'; return; }

            // 缓存数据，供快速渲染使用
            App._cachedItems = res.data;
            App._cachedTotal = res.total;
            App._cachedPages = res.pages;

            renderItemsHTML(container, res.data, res.total, res.pages);
        }

        // 纯 HTML 渲染，不发起 API 请求
        function renderItemsHTML(container, items, total, pages) {
            const f = App.itemsFilter;
            const sortValue = `${App.itemsSort}:${App.itemsOrder}`;
            const scrollY = window.scrollY;
            const isFiltering = f.search || f.category || f.location || f.status || f.expiryOnly;

            container.innerHTML = `
        <div class="glass rounded-2xl p-4 mb-6 anim-up">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-[240px] flex items-center gap-2">
                    <div class="relative flex-1 min-w-[180px]">
                        <i class="ri-search-line absolute left-3 top-1/2 -translate-y-1/2 text-slate-500"></i>
                        <input type="text" id="itemSearch" class="input pl-10 !py-2" placeholder="搜索名称、分类、位置、标签、渠道、备注..." value="${esc(f.search)}" onkeydown="handleItemSearch(event)">
                    </div>
                    <button onclick="searchItemsByInput()" class="btn btn-primary !py-2 !px-3 text-xs flex-shrink-0" title="执行搜索">
                        <i class="ri-search-line mr-1"></i>搜索
                    </button>
                </div>
                <select class="input !w-auto !py-2" onchange="App.itemsFilter.category=+this.value;App.itemsPage=1;renderView()">
                    ${buildCategorySelectOptions(f.category, { includeAll: true, includeUncategorized: true, allLabel: '所有分类', uncategorizedLabel: '未分类' })}
                </select>
                <select class="input !w-auto !py-2" onchange="App.itemsFilter.location=+this.value;App.itemsPage=1;renderView()">
                    <option value="0">所有位置</option>
                    <option value="-1" ${f.location === -1 ? 'selected' : ''}>未设定</option>
                    ${App.locations.map(l => `<option value="${l.id}" ${f.location == l.id ? 'selected' : ''}>${esc(getLocationOptionLabel(l))}</option>`).join('')}
                </select>
                <select class="input !w-auto !py-2" onchange="App.itemsFilter.status=this.value;App.itemsPage=1;renderView()">
                    <option value="">所有状态</option>
                    ${App.statuses.map(s => `<option value="${s.key}" ${f.status === s.key ? 'selected' : ''}>${s.label}</option>`).join('')}
                </select>
                <select class="input !w-auto !py-2" onchange="const [s,o]=this.value.split(':');App.itemsSort=s;App.itemsOrder=o;renderView()">
                    <option value="updated_at:DESC" ${sortValue === 'updated_at:DESC' ? 'selected' : ''}>最近更新</option>
                    <option value="updated_at:ASC" ${sortValue === 'updated_at:ASC' ? 'selected' : ''}>最早更新</option>
                    <option value="created_at:DESC" ${sortValue === 'created_at:DESC' ? 'selected' : ''}>最近添加</option>
                    <option value="created_at:ASC" ${sortValue === 'created_at:ASC' ? 'selected' : ''}>最早添加</option>
                    <option value="name:ASC" ${sortValue === 'name:ASC' ? 'selected' : ''}>名称 A-Z</option>
                    <option value="name:DESC" ${sortValue === 'name:DESC' ? 'selected' : ''}>名称 Z-A</option>
                    <option value="purchase_price:DESC" ${sortValue === 'purchase_price:DESC' ? 'selected' : ''}>价格高→低</option>
                    <option value="purchase_price:ASC" ${sortValue === 'purchase_price:ASC' ? 'selected' : ''}>价格低→高</option>
                    <option value="quantity:DESC" ${sortValue === 'quantity:DESC' ? 'selected' : ''}>数量多→少</option>
                    <option value="quantity:ASC" ${sortValue === 'quantity:ASC' ? 'selected' : ''}>数量少→多</option>
                    <option value="expiry_date:ASC" ${sortValue === 'expiry_date:ASC' ? 'selected' : ''}>过期日期近→远</option>
                    <option value="expiry_date:DESC" ${sortValue === 'expiry_date:DESC' ? 'selected' : ''}>过期日期远→近</option>
                </select>
                ${(isFiltering || sortValue !== 'updated_at:DESC') ? `
                <button onclick="resetItemsFilter()" class="btn btn-ghost !py-2 !px-3 text-xs text-slate-400 hover:text-white border border-white/10 hover:border-white/20 rounded-lg transition flex items-center gap-1.5 flex-shrink-0" title="重置所有筛选条件">
                    <i class="ri-refresh-line"></i><span class="hidden sm:inline">重置</span>
                </button>` : ''}
            </div>
            ${App.selectedItems.size > 0 ? `
                <div class="flex items-center gap-3 mt-3 pt-3 border-t border-white/5">
                    <span class="text-sm text-slate-400">已选 ${App.selectedItems.size} 项</span>
                    <button class="btn btn-danger btn-sm" onclick="batchDelete()"><i class="ri-delete-bin-line"></i>批量删除</button>
                    <button class="btn btn-ghost btn-sm" onclick="App.selectedItems.clear();renderItemsFast()">取消选择</button>
                </div>
            ` : ''}
        </div>

        <div class="flex items-center justify-between mb-4">
            <p class="text-sm text-slate-500">共 ${total} 件物品${f.expiryOnly ? '（仅显示已设置过期日期）' : ''}</p>
            <div class="flex items-center gap-2">
                <div class="relative">
                    <button onclick="toggleAttrPanel(this)" class="glass rounded-lg px-3 py-1.5 text-slate-300 hover:text-white transition flex items-center gap-1.5 text-xs border border-white/10 hover:border-sky-500/40 hover:bg-sky-500/10 active:scale-95" title="选择要显示的属性">
                        <i class="ri-eye-line text-sky-400"></i><span class="hidden sm:inline">属性</span><i class="ri-arrow-down-s-line text-[10px] text-slate-500"></i>
                    </button>
                    <div id="attrPanel" class="absolute right-0 top-full mt-1 glass rounded-xl p-3 min-w-[160px] space-y-1.5 z-50 hidden shadow-xl border border-white/[0.06]">
                        <div class="text-[10px] text-slate-500 mb-2 font-medium">选择要显示的属性</div>
                        ${allItemAttrs.map(a => `
                        <label class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-white/[0.04] cursor-pointer transition text-xs">
                            <input type="checkbox" class="accent-sky-500" ${App.itemAttrs.includes(a.key) ? 'checked' : ''} onchange="toggleItemAttr('${a.key}')">
                            <span class="text-slate-300">${a.label}</span>
                        </label>`).join('')}
                    </div>
                </div>
                <div class="flex items-center glass rounded-lg p-0.5 gap-0.5">
                    <button onclick="setItemsSize('large')" class="size-btn ${App.itemsSize === 'large' ? 'active' : ''}" title="大"><i class="ri-layout-grid-fill"></i></button>
                    <button onclick="setItemsSize('medium')" class="size-btn ${App.itemsSize === 'medium' ? 'active' : ''}" title="中"><i class="ri-grid-fill"></i></button>
                    <button onclick="setItemsSize('small')" class="size-btn ${App.itemsSize === 'small' ? 'active' : ''}" title="小"><i class="ri-list-check"></i></button>
                </div>
                <div class="items-danger-actions flex items-center gap-2">
                    <button onclick="toggleExpiryOnlyFilter()" class="btn btn-ghost btn-sm ${f.expiryOnly ? 'text-amber-400 border-amber-400/30 bg-amber-500/10' : 'text-slate-400 hover:text-amber-400'}" title="只显示带过期日期的物品">
                        <i class="ri-alarm-warning-line mr-1"></i>过期管理
                    </button>
                    <button onclick="switchView('trash')" class="btn btn-ghost btn-sm text-slate-400 hover:text-red-400 transition" title="回收站">
                        <i class="ri-delete-bin-line mr-1"></i>回收站
                    </button>
                </div>
            </div>
        </div>

        ${items.length === 0 ? `
            <div class="empty-state anim-up">
                <i class="ri-archive-line"></i>
                <h3 class="text-xl font-semibold text-slate-400 mb-2">${f.expiryOnly ? '暂无带过期日期的物品' : '暂无物品'}</h3>
                <p class="text-slate-500 mb-4">${isFiltering ? '没有找到匹配的物品，试试其他搜索条件？' : '点击「添加物品」按钮开始管理你的物品吧'}</p>
                ${!isFiltering ? '<button onclick="openAddItem()" class="btn btn-primary btn-first-item"><span class="btn-first-item-icon"><i class="ri-add-line"></i></span><span class="btn-first-item-text">添加第一件物品</span></button>' : ''}
            </div>
        ` : renderItemsByStatus(items)}

        ${pages > 1 ? `
            <div class="flex items-center justify-center mt-8">
                <div class="pagination">
                    <button class="page-btn" onclick="goPage(${Math.max(1, App.itemsPage - 1)})" ${App.itemsPage <= 1 ? 'disabled style="opacity:0.3"' : ''}><i class="ri-arrow-left-s-line"></i></button>
                    ${paginationBtns(App.itemsPage, pages)}
                    <button class="page-btn" onclick="goPage(${Math.min(pages, App.itemsPage + 1)})" ${App.itemsPage >= pages ? 'disabled style="opacity:0.3"' : ''}><i class="ri-arrow-right-s-line"></i></button>
                </div>
            </div>
        ` : ''}
    `;
            // 恢复滚动位置
            window.scrollTo(0, scrollY);
        }

        function toggleExpiryOnlyFilter() {
            App.itemsFilter.expiryOnly = !App.itemsFilter.expiryOnly;
            App.itemsPage = 1;
            renderView();
        }

        // 快速渲染：使用缓存数据渲染，不发 API 请求，不显示加载动画
        function renderItemsFast(options = {}) {
            if (App.currentView !== 'items' || !App._cachedItems) { renderView(); return; }
            const container = document.getElementById('viewContainer');
            renderItemsHTML(container, App._cachedItems, App._cachedTotal, App._cachedPages);
            // 需要时自动打开属性面板
            if (options.openAttrPanel) {
                const panel = document.getElementById('attrPanel');
                if (panel) {
                    panel.classList.remove('hidden');
                    const btn = panel.parentElement.querySelector('button');
                    const closeHandler = (e) => {
                        if (!panel.contains(e.target) && (!btn || !btn.contains(e.target))) {
                            panel.classList.add('hidden');
                            document.removeEventListener('click', closeHandler);
                        }
                    };
                    setTimeout(() => document.addEventListener('click', closeHandler), 0);
                }
            }
        }

        function itemCard(item, index) {
            const isSelected = App.selectedItems.has(item.id);
            const statusMap = getStatusMap();
            const [statusLabel, statusClass] = statusMap[item.status] || ['未知', 'badge-archived'];
            const dueDate = reminderDisplayDate(item);

            return `<div class="item-card glass glass-hover anim-up ${isSelected ? 'selected' : ''}" style="animation-delay:${index * 30}ms">
        <div class="item-img relative" onclick="showDetail(${item.id})">
            ${item.image ? `<img src="?img=${item.image}" alt="${esc(item.name)}" loading="lazy">` : `<i class="ri-archive-line placeholder-icon"></i>`}
            <div class="absolute top-2 right-2"><span class="badge ${statusClass}">${statusLabel}</span></div>
        </div>
        <div class="p-4">
            <div class="flex items-start justify-between gap-2 mb-2">
                <h3 class="font-semibold text-white text-sm truncate flex-1 cursor-pointer" onclick="showDetail(${item.id})">${esc(item.name)}</h3>
                <label class="flex-shrink-0 cursor-pointer" title="选中">
                    <input type="checkbox" class="hidden" ${isSelected ? 'checked' : ''} onchange="toggleSelect(${item.id}, this.checked)">
                    <i class="ri-checkbox-${isSelected ? 'fill text-sky-400' : 'blank-line text-slate-600'}"></i>
                </label>
            </div>
            <div class="flex items-center flex-wrap gap-x-2 gap-y-1 text-xs text-slate-500 mb-1">
                ${hasAttr('quantity') ? `<span>x${item.quantity}</span>` : ''}
                ${hasAttr('category') && item.category_icon ? `<span style="color:${item.category_color || '#64748b'}">${item.category_icon} ${esc(item.category_name || '')}${item.subcategory_name ? ` / ${esc(item.subcategory_name)}` : ''}</span>` : ''}
                ${hasAttr('location') && item.location_name ? `<span><i class="ri-map-pin-2-line"></i> ${esc(item.location_name)}</span>` : ''}
                ${hasAttr('price') && item.purchase_price > 0 ? `<span class="text-amber-400 font-medium">¥${Number(item.purchase_price).toLocaleString()}</span>` : ''}
                ${hasAttr('purchase_from') && item.purchase_from ? `<span><i class="ri-shopping-bag-line"></i> ${esc(item.purchase_from)}</span>` : ''}
            </div>
            ${hasAttr('expiry') && item.expiry_date ? `<div class="text-xs mt-1 ${expiryColor(item.expiry_date)}"><i class="ri-alarm-warning-line mr-0.5"></i>${item.expiry_date} ${expiryLabel(item.expiry_date)}</div>` : ''}
            ${hasAttr('reminder') && dueDate && item.reminder_cycle_unit ? `<div class="text-xs mt-1 text-cyan-300/90"><i class="ri-loop-right-line mr-0.5"></i>${dueDate} ${reminderCycleLabel(item.reminder_cycle_value, item.reminder_cycle_unit)}</div>` : ''}
            ${hasAttr('notes') && item.notes ? `<div class="text-xs text-slate-600 mt-1 truncate"><i class="ri-sticky-note-line mr-0.5"></i>${esc(item.notes)}</div>` : ''}
            <div class="flex items-center gap-1 mt-3 pt-3 border-t border-white/5">
                <button onclick="event.stopPropagation();editItem(${item.id})" class="btn btn-ghost btn-sm flex-1" title="编辑"><i class="ri-edit-line"></i>编辑</button>
                <button onclick="event.stopPropagation();copyItem(${item.id})" class="btn btn-ghost btn-sm flex-1" title="复制" style="color:#38bdf8"><i class="ri-file-copy-line"></i>复制</button>
                <button onclick="event.stopPropagation();deleteItem(${item.id},'${esc(item.name)}')" class="btn btn-danger btn-sm flex-1" title="删除"><i class="ri-delete-bin-line"></i>删除</button>
            </div>
        </div>
    </div>`;
        }

        function renderItemsByStatus(items) {
            const statusGroups = getStatusGroups();
            let html = '';
            let globalIdx = 0;
            for (const g of statusGroups) {
                const group = items.filter(i => i.status === g.key);
                if (group.length === 0) continue;
                html += `<div class="mb-6 anim-up">
                    <div class="flex items-center gap-2 mb-3">
                        <i class="${g.icon} ${g.color}"></i>
                        <span class="text-sm font-medium ${g.color}">${g.label}</span>
                        <span class="text-xs text-slate-600">${group.length}</span>
                    </div>`;
                if (App.itemsSize === 'small') {
                    html += `<div class="glass rounded-2xl overflow-hidden">${group.map((item) => itemRowSmall(item, globalIdx++)).join('')}</div>`;
                } else if (App.itemsSize === 'medium') {
                    html += `<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-3">${group.map((item) => itemCardMedium(item, globalIdx++)).join('')}</div>`;
                } else {
                    html += `<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">${group.map((item) => itemCard(item, globalIdx++)).join('')}</div>`;
                }
                html += `</div>`;
            }
            // 处理未知状态
            const knownKeys = statusGroups.map(g => g.key);
            const others = items.filter(i => !knownKeys.includes(i.status));
            if (others.length > 0) {
                html += `<div class="mb-6 anim-up"><div class="flex items-center gap-2 mb-3"><i class="ri-question-line text-slate-500"></i><span class="text-sm font-medium text-slate-500">其他</span><span class="text-xs text-slate-600">${others.length}</span></div>`;
                if (App.itemsSize === 'small') {
                    html += `<div class="glass rounded-2xl overflow-hidden">${others.map((item) => itemRowSmall(item, globalIdx++)).join('')}</div>`;
                } else if (App.itemsSize === 'medium') {
                    html += `<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-3">${others.map((item) => itemCardMedium(item, globalIdx++)).join('')}</div>`;
                } else {
                    html += `<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">${others.map((item) => itemCard(item, globalIdx++)).join('')}</div>`;
                }
                html += `</div>`;
            }
            return html;
        }

        function setItemsSize(size) {
            saveItemsSize(size);
            renderItemsFast();
        }

        function toggleAttrPanel(btn) {
            const panel = document.getElementById('attrPanel');
            if (!panel) return;
            panel.classList.toggle('hidden');
            if (!panel.classList.contains('hidden')) {
                const closeHandler = (e) => {
                    if (!panel.contains(e.target) && !btn.contains(e.target)) {
                        panel.classList.add('hidden');
                        document.removeEventListener('click', closeHandler);
                    }
                };
                setTimeout(() => document.addEventListener('click', closeHandler), 0);
            }
        }

        function itemCardMedium(item, index) {
            const isSelected = App.selectedItems.has(item.id);
            const statusMap = getStatusMap();
            const [statusLabel, statusClass] = statusMap[item.status] || ['未知', 'badge-archived'];
            const dueDate = reminderDisplayDate(item);

            return `<div class="glass glass-hover rounded-xl overflow-hidden anim-up ${isSelected ? 'selected' : ''}" style="animation-delay:${index * 20}ms">
        <div class="flex items-center gap-3 p-3">
            <div class="w-12 h-12 rounded-lg flex-shrink-0 overflow-hidden ${item.image ? '' : 'bg-slate-700/50 flex items-center justify-center text-lg'} cursor-pointer" onclick="showDetail(${item.id})">
                ${item.image ? `<img src="?img=${item.image}" class="w-full h-full object-cover" loading="lazy">` : `<i class="ri-archive-line text-slate-600"></i>`}
            </div>
            <div class="flex-1 min-w-0 cursor-pointer" onclick="showDetail(${item.id})">
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-medium text-white truncate">${esc(item.name)}</h3>
                    <span class="badge ${statusClass} !text-[10px] !px-1.5 !py-0 flex-shrink-0">${statusLabel}</span>
                </div>
                <div class="flex items-center flex-wrap gap-x-2 gap-y-0.5 text-[11px] text-slate-500 mt-0.5">
                    ${hasAttr('quantity') ? `<span>x${item.quantity}</span>` : ''}
                    ${hasAttr('category') && item.category_icon ? `<span style="color:${item.category_color || '#64748b'}">${item.category_icon}${esc(item.category_name || '')}${item.subcategory_name ? `/${esc(item.subcategory_name)}` : ''}</span>` : ''}
                    ${hasAttr('location') && item.location_name ? `<span class="truncate"><i class="ri-map-pin-2-line"></i>${esc(item.location_name)}</span>` : ''}
                    ${hasAttr('price') && item.purchase_price > 0 ? `<span class="text-amber-400">¥${Number(item.purchase_price).toLocaleString()}</span>` : ''}
                    ${hasAttr('expiry') && item.expiry_date ? `<span class="${expiryColor(item.expiry_date)}"><i class="ri-alarm-warning-line"></i>${expiryLabel(item.expiry_date)}</span>` : ''}
                    ${hasAttr('reminder') && dueDate && item.reminder_cycle_unit ? `<span class="text-cyan-300/90"><i class="ri-loop-right-line"></i>${dueDate}</span>` : ''}
                    ${hasAttr('purchase_from') && item.purchase_from ? `<span><i class="ri-shopping-bag-line"></i>${esc(item.purchase_from)}</span>` : ''}
                    ${hasAttr('notes') && item.notes ? `<span class="text-slate-600 truncate"><i class="ri-sticky-note-line"></i>${esc(item.notes)}</span>` : ''}
                </div>
            </div>
            <label class="flex-shrink-0 cursor-pointer" title="选中">
                <input type="checkbox" class="hidden" ${isSelected ? 'checked' : ''} onchange="toggleSelect(${item.id}, this.checked)">
                <i class="ri-checkbox-${isSelected ? 'fill text-sky-400' : 'blank-line text-slate-600'}"></i>
            </label>
        </div>
        <div class="item-card-medium-actions flex items-center">
            <button onclick="event.stopPropagation();editItem(${item.id})" class="btn action-btn action-edit btn-ghost btn-sm flex-1 rounded-none !py-1.5 text-xs"><i class="ri-edit-line"></i></button>
            <button onclick="event.stopPropagation();copyItem(${item.id})" class="btn action-btn action-copy btn-ghost btn-sm flex-1 rounded-none !py-1.5 text-xs"><i class="ri-file-copy-line"></i></button>
            <button onclick="event.stopPropagation();deleteItem(${item.id},'${esc(item.name)}')" class="btn action-btn action-delete btn-danger btn-sm flex-1 rounded-none !py-1.5 text-xs"><i class="ri-delete-bin-line"></i></button>
        </div>
    </div>`;
        }

        function itemRowSmall(item, index) {
            const isSelected = App.selectedItems.has(item.id);
            const dueDate = reminderDisplayDate(item);

            return `<div class="flex items-center gap-3 px-4 py-2.5 hover:bg-white/[0.03] transition cursor-pointer ${index > 0 ? 'border-t border-white/[0.04]' : ''} ${isSelected ? 'bg-sky-500/5' : ''}" onclick="showDetail(${item.id})">
        <label class="flex-shrink-0 cursor-pointer" onclick="event.stopPropagation()">
            <input type="checkbox" class="hidden" ${isSelected ? 'checked' : ''} onchange="toggleSelect(${item.id}, this.checked)">
            <i class="ri-checkbox-${isSelected ? 'fill text-sky-400' : 'blank-line text-slate-600'} text-base"></i>
        </label>
        <div class="w-7 h-7 rounded-md flex-shrink-0 overflow-hidden ${item.image ? '' : 'bg-slate-700/50 flex items-center justify-center'}">
            ${item.image ? `<img src="?img=${item.image}" class="w-full h-full object-cover" loading="lazy">` : `<span class="text-xs">${item.category_icon || '📦'}</span>`}
        </div>
        <div class="flex-1 min-w-0 flex items-center gap-3">
            <span class="text-sm text-white truncate flex-shrink min-w-0">${esc(item.name)}</span>
            ${hasAttr('quantity') ? `<span class="text-[11px] text-slate-500 flex-shrink-0">x${item.quantity}</span>` : ''}
            ${hasAttr('category') ? `<span class="text-[11px] text-slate-500 flex-shrink-0">${item.category_icon || '📦'}${esc(item.category_name || '')}${item.subcategory_name ? `/${esc(item.subcategory_name)}` : ''}</span>` : ''}
            ${hasAttr('location') && item.location_name ? `<span class="text-[11px] text-slate-600 truncate hidden sm:inline"><i class="ri-map-pin-2-line"></i>${esc(item.location_name)}</span>` : ''}
            ${hasAttr('purchase_from') && item.purchase_from ? `<span class="text-[11px] text-slate-600 truncate hidden md:inline"><i class="ri-shopping-bag-line"></i>${esc(item.purchase_from)}</span>` : ''}
        </div>
        <div class="flex items-center gap-3 flex-shrink-0 text-xs">
            ${hasAttr('price') && item.purchase_price > 0 ? `<span class="text-amber-400 w-16 text-right">¥${Number(item.purchase_price).toLocaleString()}</span>` : ''}
            ${hasAttr('expiry') && item.expiry_date ? `<span class="${expiryColor(item.expiry_date)} hidden md:inline text-[11px]">${expiryLabel(item.expiry_date)}</span>` : ''}
            ${hasAttr('reminder') && dueDate && item.reminder_cycle_unit ? `<span class="text-cyan-300/90 hidden lg:inline text-[11px]"><i class="ri-loop-right-line"></i>${dueDate}</span>` : ''}
            ${hasAttr('notes') && item.notes ? `<span class="text-[11px] text-slate-600 truncate hidden lg:inline max-w-[80px]"><i class="ri-sticky-note-line"></i>${esc(item.notes)}</span>` : ''}
            <div class="flex gap-0.5" onclick="event.stopPropagation()">
                <button onclick="editItem(${item.id})" class="p-1 text-slate-500 hover:text-white transition rounded" title="编辑"><i class="ri-edit-line"></i></button>
                <button onclick="copyItem(${item.id})" class="p-1 text-sky-500/60 hover:text-sky-400 transition rounded" title="复制"><i class="ri-file-copy-line"></i></button>
                <button onclick="deleteItem(${item.id},'${esc(item.name)}')" class="p-1 text-red-500/40 hover:text-red-400 transition rounded" title="删除"><i class="ri-delete-bin-line"></i></button>
            </div>
        </div>
    </div>`;
        }

        function paginationBtns(current, total) {
            let btns = '';
            const range = 2;
            for (let i = 1; i <= total; i++) {
                if (i === 1 || i === total || (i >= current - range && i <= current + range)) {
                    btns += `<button class="page-btn ${i === current ? 'active' : ''}" onclick="goPage(${i})">${i}</button>`;
                } else if (i === current - range - 1 || i === current + range + 1) {
                    btns += `<span class="text-slate-600 px-1">…</span>`;
                }
            }
            return btns;
        }

        function goPage(p) { App.itemsPage = p; renderView(); }
        function handleItemSearch(e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            searchItemsByInput();
        }
        function searchItemsByInput() {
            const input = document.getElementById('itemSearch');
            if (!input) return;
            App.itemsFilter.search = input.value.trim();
            App.itemsPage = 1;
            renderView();
        }
        function handleGlobalSearch(e) { if (e.key === 'Enter') { App.itemsFilter.search = e.target.value; switchView('items'); } }
        function resetItemsFilter() {
            App.itemsFilter = { search: '', category: 0, location: 0, status: '', expiryOnly: false };
            App.itemsSort = 'updated_at';
            App.itemsOrder = 'DESC';
            App.itemsPage = 1;
            renderView();
        }
        function toggleSelect(id, checked) {
            checked ? App.selectedItems.add(id) : App.selectedItems.delete(id);
            renderItemsFast();
        }

        async function batchDelete() {
            if (!confirm(`确定删除选中的 ${App.selectedItems.size} 件物品？物品将移入回收站。`)) return;
            const res = await apiPost('items/batch-delete', { ids: [...App.selectedItems] });
            if (res.success) { App.selectedItems.clear(); toast('已移入回收站'); renderView(); } else toast(res.message, 'error');
        }

        // ---------- 物品详情弹窗 ----------
        async function showDetail(id) {
            const allRes = await api(`items&page=1&limit=999`);
            if (!allRes.success) return;
            const item = allRes.data.find(i => i.id === id);
            if (!item) { toast('物品不存在', 'error'); return; }

            const statusMap = getStatusMap();
            const [statusLabel, statusClass, statusIcon] = statusMap[item.status] || ['未知', 'badge-archived', 'ri-question-line'];

            document.getElementById('detailContent').innerHTML = `
        ${item.image ? `<img src="?img=${item.image}" class="w-full h-56 object-cover rounded-t-2xl" alt="">` : `<div class="w-full h-40 bg-slate-800 flex items-center justify-center rounded-t-2xl"><i class="ri-archive-line text-5xl text-slate-600"></i></div>`}
        <div class="p-6">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h2 class="text-2xl font-bold text-white mb-1">${esc(item.name)}</h2>
                    <span class="badge ${statusClass}"><i class="${statusIcon} mr-1"></i>${statusLabel}</span>
                </div>
                <button onclick="closeDetailModal()" class="text-slate-400 hover:text-white transition"><i class="ri-close-line text-2xl"></i></button>
            </div>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">分类</p><p class="text-sm text-white">${item.category_icon || '📦'} ${esc(item.category_name || '未分类')}${item.subcategory_name ? ` <span class="text-slate-500">/</span> <span class="text-cyan-300">${esc(item.subcategory_name)}</span>` : ''}</p></div>
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">位置</p><p class="text-sm text-white"><i class="ri-map-pin-2-line text-xs mr-1"></i>${esc(item.location_name || '未设定')}</p></div>
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">数量</p><p class="text-sm text-white">${item.quantity}</p></div>
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">余量</p><p class="text-sm text-white">${Number(item.remaining_total || 0) > 0 ? `${Number(item.remaining_current || 0)}/${Number(item.remaining_total || 0)}` : '未设置'}</p></div>
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">价值</p><p class="text-sm text-amber-400 font-medium">¥${Number(item.purchase_price || 0).toLocaleString()}</p></div>
                ${item.purchase_date ? `<div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">购入日期</p><p class="text-sm text-white">${item.purchase_date}</p></div>` : ''}
                ${item.expiry_date ? `<div class="p-3 rounded-xl ${expiryBg(item.expiry_date)}"><p class="text-xs text-slate-500 mb-1">过期日期</p><p class="text-sm font-medium ${expiryColor(item.expiry_date)}">${item.expiry_date} ${expiryLabel(item.expiry_date)}</p></div>` : ''}
                ${reminderDisplayDate(item) && item.reminder_cycle_unit ? `<div class="p-3 rounded-xl bg-cyan-500/10 border border-cyan-500/20"><p class="text-xs text-slate-500 mb-1">循环提醒</p><p class="text-sm font-medium text-cyan-300 leading-6">初始：${item.reminder_date || '-'} <span class="text-cyan-200/90">(${reminderCycleLabel(item.reminder_cycle_value, item.reminder_cycle_unit)})</span></p><p class="text-sm font-medium text-cyan-300 leading-6">下次：${reminderDisplayDate(item)} ${reminderDueLabel(reminderDisplayDate(item))}</p>${item.reminder_note ? `<p class="text-xs text-slate-400 mt-1">${esc(item.reminder_note)}</p>` : ''}</div>` : ''}
                ${item.purchase_from ? `<div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">购入渠道</p><p class="text-sm text-white">${esc(item.purchase_from)}</p></div>` : ''}
                ${item.barcode ? `<div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">条码/序列号</p><p class="text-sm text-white font-mono">${esc(item.barcode)}</p></div>` : ''}
            </div>
            ${item.notes ? `<div class="mb-4"><p class="text-xs text-slate-500 mb-1">备注</p><p class="text-sm text-slate-400 whitespace-pre-wrap">${esc(item.notes)}</p></div>` : ''}
            ${item.tags ? `<div class="mb-4"><p class="text-xs text-slate-500 mb-2">标签</p><div class="flex flex-wrap gap-2">${item.tags.split(',').map(t => `<span class="badge bg-white/5 text-slate-300">${esc(t.trim())}</span>`).join('')}</div></div>` : ''}
            <div class="text-xs text-slate-600 mt-4 pt-4 border-t border-white/5">
                创建: ${item.created_at} &nbsp;|&nbsp; 更新: ${item.updated_at}
            </div>
            <div class="flex gap-3 mt-4">
                <button onclick="closeDetailModal();editItem(${item.id})" class="btn btn-primary flex-1"><i class="ri-edit-line"></i>编辑</button>
                <button onclick="closeDetailModal();copyItem(${item.id})" class="btn btn-ghost flex-1" style="color:#38bdf8;border-color:rgba(56,189,248,0.3)"><i class="ri-file-copy-line"></i>复制</button>
                <button onclick="closeDetailModal();deleteItem(${item.id},'${esc(item.name)}')" class="btn btn-danger flex-1"><i class="ri-delete-bin-line"></i>删除</button>
            </div>
        </div>
    `;
            document.getElementById('detailModal').classList.add('show');
        }

        function closeDetailModal() { document.getElementById('detailModal').classList.remove('show'); }

        // ---------- 添加 / 编辑物品 ----------
        async function openAddItem() {
            document.getElementById('itemModalTitle').textContent = '添加物品';
            document.getElementById('itemForm').reset();
            document.getElementById('itemId').value = '';
            document.getElementById('itemImage').value = '';
            document.getElementById('itemSourceShoppingId').value = '';
            document.getElementById('itemSharePublic').checked = false;
            document.getElementById('itemQuantity').value = '1';
            document.getElementById('itemRemainingCurrent').value = '0';
            document.getElementById('itemPrice').value = '0';
            document.getElementById('itemExpiry').value = '';
            document.getElementById('itemReminderDate').value = '';
            document.getElementById('itemReminderEvery').value = '1';
            document.getElementById('itemReminderUnit').value = 'day';
            document.getElementById('itemReminderNext').value = '';
            document.getElementById('itemReminderNote').value = '';
            document.getElementById('itemNotes').value = '';
            syncReminderFields();
            resetUploadZone();
            await populateSelects({
                status: getDefaultStatusKey(),
                purchaseFrom: App.purchaseChannels[0] || '',
                categoryId: 0,
                subcategoryId: 0
            });
            document.getElementById('itemModal').classList.add('show');
            setItemSubmitLabel('保存');
            refreshDateInputPlaceholderDisplay(document.getElementById('itemForm'));
            closeItemUnsavedConfirm();
            markItemFormClean();
        }

        async function editItem(id) {
            const res = await api(`items&page=1&limit=999`);
            if (!res.success) return;
            const item = res.data.find(i => i.id === id);
            if (!item) { toast('物品不存在', 'error'); return; }

            document.getElementById('itemModalTitle').textContent = '编辑物品';
            document.getElementById('itemId').value = item.id;
            document.getElementById('itemName').value = item.name;
            document.getElementById('itemSourceShoppingId').value = '';
            const editQty = Math.max(0, Number(item.quantity || 0), Number(item.remaining_total || 0));
            document.getElementById('itemQuantity').value = editQty;
            document.getElementById('itemRemainingCurrent').value = Number(item.remaining_total || 0) > 0
                ? String(Math.min(editQty, Math.max(0, Number(item.remaining_current || 0))))
                : '';
            document.getElementById('itemPrice').value = item.purchase_price;
            document.getElementById('itemDate').value = item.purchase_date;
            document.getElementById('itemExpiry').value = item.expiry_date || '';
            document.getElementById('itemReminderDate').value = item.reminder_date || '';
            document.getElementById('itemReminderEvery').value = item.reminder_cycle_value || 1;
            document.getElementById('itemReminderUnit').value = ['day', 'week', 'year'].includes(item.reminder_cycle_unit) ? item.reminder_cycle_unit : 'day';
            document.getElementById('itemReminderNext').value = item.reminder_next_date || item.reminder_date || '';
            document.getElementById('itemReminderNote').value = item.reminder_note || '';
            document.getElementById('itemBarcode').value = item.barcode;
            document.getElementById('itemTags').value = item.tags;
            document.getElementById('itemImage').value = item.image || '';
            document.getElementById('itemNotes').value = item.notes || '';
            document.getElementById('itemSharePublic').checked = Number(item.is_public_shared || 0) === 1;
            syncReminderFields();

            resetUploadZone();
            if (item.image) {
                document.getElementById('uploadPreview').src = `?img=${item.image}`;
                document.getElementById('uploadPreview').classList.remove('hidden');
                document.getElementById('uploadPlaceholder').classList.add('hidden');
                document.getElementById('uploadZone').classList.add('has-image');
            }

            // 关键：await 等待下拉框填充完成后再设置值
            await populateSelects({
                status: item.status,
                purchaseFrom: item.purchase_from || '',
                categoryId: Number(item.category_id || 0),
                subcategoryId: Number(item.subcategory_id || 0)
            });
            document.getElementById('itemLocation').value = item.location_id;
            document.getElementById('itemModal').classList.add('show');
            setItemSubmitLabel('保存');
            refreshDateInputPlaceholderDisplay(document.getElementById('itemForm'));
            closeItemUnsavedConfirm();
            markItemFormClean();
        }

        async function populateSelects(options = {}) {
            await loadBaseData();
            const catSelect = document.getElementById('itemCategory');
            const subSelect = document.getElementById('itemSubcategory');
            let categoryId = Number(options.categoryId || 0);
            let subcategoryId = Number(options.subcategoryId || 0);
            if (categoryId > 0) {
                const picked = getCategoryById(categoryId);
                if (picked && Number(picked.parent_id || 0) > 0) {
                    if (subcategoryId <= 0) subcategoryId = Number(picked.id || 0);
                    categoryId = Number(picked.parent_id || 0);
                }
            }
            catSelect.innerHTML = buildTopCategorySelectOptions(categoryId, { placeholder: '选择分类' });
            catSelect.value = String(categoryId > 0 ? categoryId : 0);
            if (subSelect) {
                refreshItemSubcategorySelect(categoryId, subcategoryId);
                if (!catSelect.dataset.boundSubcategoryChange) {
                    catSelect.addEventListener('change', () => {
                        refreshItemSubcategorySelect(Number(catSelect.value || 0), 0);
                    });
                    catSelect.dataset.boundSubcategoryChange = '1';
                }
            }
            const locSelect = document.getElementById('itemLocation');
            locSelect.innerHTML = '<option value="0">选择位置</option>' + App.locations.map(l => `<option value="${l.id}">${esc(getLocationOptionLabel(l))}</option>`).join('');
            const statusSelect = document.getElementById('itemStatus');
            statusSelect.innerHTML = App.statuses.map(s => `<option value="${s.key}">${s.label}</option>`).join('');
            const purchaseSelect = document.getElementById('itemPurchaseFrom');
            if (purchaseSelect) {
                let channelOptions = [...App.purchaseChannels];
                if (options.purchaseFrom && !channelOptions.includes(options.purchaseFrom)) {
                    channelOptions = [options.purchaseFrom, ...channelOptions];
                }
                purchaseSelect.innerHTML = '<option value="">未设置</option>' + channelOptions.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
            }
            if (options.status) statusSelect.value = options.status;
            if (purchaseSelect) purchaseSelect.value = options.purchaseFrom || '';
        }

        async function saveItem(e) {
            e.preventDefault();
            const id = document.getElementById('itemId').value;
            const sourceShoppingId = +document.getElementById('itemSourceShoppingId').value || 0;
            const quantityRaw = String(document.getElementById('itemQuantity').value || '').trim();
            if (quantityRaw !== '' && !/^\d+$/.test(quantityRaw)) {
                toast('数量只能输入数字', 'error');
                return false;
            }
            const quantity = quantityRaw === '' ? 0 : Number.parseInt(quantityRaw, 10);
            const remainingCurrentRaw = String(document.getElementById('itemRemainingCurrent').value || '').trim();
            const hasRemainingValue = remainingCurrentRaw !== '';
            const parseRemainingInput = (raw, label) => {
                if (raw === '') return 0;
                if (!/^\d+$/.test(raw)) {
                    toast(`${label}只能输入数字`, 'error');
                    return null;
                }
                return Number.parseInt(raw, 10);
            };
            let remainingCurrent = 0;
            if (hasRemainingValue) {
                const parsedRemaining = parseRemainingInput(remainingCurrentRaw, '余量');
                if (parsedRemaining === null) return false;
                remainingCurrent = parsedRemaining;
                if (remainingCurrent > quantity) {
                    toast('余量数值不能大于数量', 'error');
                    return false;
                }
            }
            const data = {
                id: id ? +id : undefined,
                name: document.getElementById('itemName').value.trim(),
                category_id: +document.getElementById('itemCategory').value,
                subcategory_id: +document.getElementById('itemSubcategory').value,
                location_id: +document.getElementById('itemLocation').value,
                quantity: quantity,
                remaining_current: remainingCurrent,
                remaining_total: hasRemainingValue ? quantity : 0,
                purchase_price: +document.getElementById('itemPrice').value,
                purchase_date: document.getElementById('itemDate').value,
                expiry_date: document.getElementById('itemExpiry').value,
                barcode: document.getElementById('itemBarcode').value.trim(),
                tags: document.getElementById('itemTags').value.trim(),
                status: document.getElementById('itemStatus').value,
                image: document.getElementById('itemImage').value,
                purchase_from: document.getElementById('itemPurchaseFrom').value,
                notes: document.getElementById('itemNotes').value.trim(),
                is_public_shared: document.getElementById('itemSharePublic').checked ? 1 : 0,
                reminder_note: document.getElementById('itemReminderNote').value.trim()
            };
            const reminderDate = document.getElementById('itemReminderDate').value;
            const reminderUnit = document.getElementById('itemReminderUnit').value;
            const reminderNextDate = document.getElementById('itemReminderNext').value;
            let reminderEvery = parseInt(document.getElementById('itemReminderEvery').value || '1', 10);
            if (!Number.isFinite(reminderEvery) || reminderEvery < 1) reminderEvery = 1;
            const normalizedReminderUnit = ['day', 'week', 'year'].includes(reminderUnit) ? reminderUnit : 'day';
            data.reminder_date = reminderDate || '';
            data.reminder_next_date = reminderDate ? (reminderNextDate || reminderDate) : '';
            data.reminder_cycle_value = reminderDate ? reminderEvery : 0;
            data.reminder_cycle_unit = reminderDate ? normalizedReminderUnit : '';
            if (!data.name) { toast('请输入物品名称', 'error'); return false; }

            const endpoint = id ? 'items/update' : 'items';
            const res = await apiPost(endpoint, data);
            if (res.success) {
                if (sourceShoppingId > 0) {
                    const delRes = await apiPost('shopping-list/delete', { id: sourceShoppingId });
                    if (!delRes.success) {
                        toast('物品已入库，但购物清单删除失败，请手动处理', 'error');
                    }
                }
                toast(sourceShoppingId > 0 ? '已保存入库' : (id ? '物品已更新' : '物品已添加'));
                closeItemModal(true);
                renderView();
            } else toast(res.message, 'error');
            return false;
        }

        async function deleteItem(id, name) {
            if (!confirm(`确定删除「${name}」？物品将移入回收站。`)) return;
            const res = await apiPost('items/delete', { id });
            if (res.success) { toast('已移入回收站'); renderView(); } else toast(res.message, 'error');
        }

        async function completeReminder(id, reminderId) {
            const res = await apiPost('items/complete-reminder', { id, reminder_id: reminderId });
            if (!res.success) {
                toast(res.message || '提醒操作失败', 'error');
                return;
            }
            const nextDateText = res.next_date ? `，下次提醒：${res.next_date}` : '';
            toast(`提醒已完成${nextDateText}`);
            renderView();
        }

        async function undoReminder(id, reminderId) {
            const res = await apiPost('items/undo-reminder', { id, reminder_id: reminderId });
            if (!res.success) {
                toast(res.message || '撤销失败', 'error');
                return;
            }
            toast(res.message || '已撤销提醒完成状态');
            renderView();
        }

        function closeItemModal(force = false) {
            if (!force && hasItemFormUnsavedChanges()) {
                openItemUnsavedConfirm();
                return false;
            }
            document.getElementById('itemModal').classList.remove('show');
            closeItemUnsavedConfirm();
            clearItemFormTrack();
            return true;
        }

        function syncReminderFields() {
            const dateInput = document.getElementById('itemReminderDate');
            const everyInput = document.getElementById('itemReminderEvery');
            const unitSelect = document.getElementById('itemReminderUnit');
            const nextInput = document.getElementById('itemReminderNext');
            if (!dateInput || !everyInput || !unitSelect || !nextInput) return;
            const hasDate = !!dateInput.value;
            if (!hasDate) {
                everyInput.disabled = true;
                unitSelect.disabled = true;
                nextInput.value = '';
                refreshDateInputPlaceholderDisplay(document.getElementById('itemForm'));
                return;
            }
            if (!['day', 'week', 'year'].includes(unitSelect.value)) unitSelect.value = 'day';
            const currentEvery = parseInt(everyInput.value || '1', 10);
            if (!Number.isFinite(currentEvery) || currentEvery < 1) everyInput.value = '1';
            everyInput.disabled = false;
            unitSelect.disabled = false;
            if (!nextInput.value) nextInput.value = dateInput.value;
            refreshDateInputPlaceholderDisplay(document.getElementById('itemForm'));
        }

        function resetUploadZone() {
            document.getElementById('uploadPreview').classList.add('hidden');
            document.getElementById('uploadPreview').src = '';
            document.getElementById('uploadPlaceholder').classList.remove('hidden');
            document.getElementById('uploadZone').classList.remove('has-image');
        }

        async function handleImageUpload(input) {
            const file = input.files[0];
            if (!file) return;
            const fd = new FormData();
            fd.append('image', file);
            fd.append('item_name', document.getElementById('itemName').value.trim());
            try {
                const response = await fetch('?api=upload', { method: 'POST', body: fd });
                let res = null;
                try {
                    res = await response.json();
                } catch (e) {
                    res = null;
                }

                if (!response.ok) {
                    toast((res && res.message) || '上传失败：服务器拒绝请求，可能超过服务器上传限制', 'error');
                    return;
                }

                if (res && res.success) {
                    document.getElementById('itemImage').value = res.filename;
                    document.getElementById('uploadPreview').src = `?img=${res.filename}`;
                    document.getElementById('uploadPreview').classList.remove('hidden');
                    document.getElementById('uploadPlaceholder').classList.add('hidden');
                    document.getElementById('uploadZone').classList.add('has-image');
                } else {
                    toast((res && res.message) || '上传失败', 'error');
                }
            } catch (e) {
                toast('上传失败：网络异常或服务器限制导致中断', 'error');
            }
            input.value = '';
        }

        // ============================================================
        // 🛒 购物清单
        // ============================================================
        async function addPublicSharedToShopping(sharedId, itemName = '') {
            const id = Number(sharedId || 0);
            if (id <= 0) {
                toast('共享记录无效', 'error');
                return;
            }
            const res = await apiPost('public-channel/add-to-shopping', { shared_id: id });
            if (res && res.success) {
                toast(res.message || `已将「${itemName || '该物品'}」加入购物清单`);
                return;
            }
            toast((res && res.message) || '加入购物清单失败', 'error');
        }

        function getPublicSharedItemById(sharedId) {
            const id = Number(sharedId || 0);
            if (id <= 0) return null;
            return (Array.isArray(App.publicChannelItems) ? App.publicChannelItems : []).find(x => Number(x.id || 0) === id) || null;
        }

        async function openPublicSharedEdit(sharedId) {
            const item = getPublicSharedItemById(sharedId);
            if (!item) {
                toast('共享物品不存在', 'error');
                return;
            }
            if (Number(item.owner_user_id || 0) !== Number(CURRENT_USER.id || 0)) {
                toast('仅发布者可编辑该共享物品', 'error');
                return;
            }
            await loadBaseData();
            const categorySelect = document.getElementById('publicSharedEditCategory');
            const categoryId = Number(item.category_id || 0);
            let options = buildCategorySelectOptions(categoryId, { placeholder: '未分类' });
            if (categoryId > 0 && !App.categories.find(c => Number(c.id || 0) === categoryId)) {
                const fallbackName = String(item.category_name || '').trim() || `分类#${categoryId}`;
                options += `<option value="${categoryId}" selected>${esc(fallbackName)}</option>`;
            }
            categorySelect.innerHTML = options;
            categorySelect.value = String(categoryId > 0 ? categoryId : 0);

            document.getElementById('publicSharedEditId').value = Number(item.id || 0);
            document.getElementById('publicSharedEditName').value = String(item.item_name || '');
            document.getElementById('publicSharedEditPrice').value = Number(item.purchase_price || 0);
            document.getElementById('publicSharedEditPurchaseFrom').value = String(item.purchase_from || '');
            document.getElementById('publicSharedEditReason').value = String(item.recommend_reason || '');
            document.getElementById('publicSharedEditModal').classList.add('show');
        }

        function closePublicSharedEditModal() {
            const modal = document.getElementById('publicSharedEditModal');
            if (modal) modal.classList.remove('show');
        }

        async function savePublicSharedEdit(e) {
            e.preventDefault();
            const sharedId = Number(document.getElementById('publicSharedEditId').value || 0);
            if (sharedId <= 0) {
                toast('共享记录无效', 'error');
                return false;
            }
            const payload = {
                shared_id: sharedId,
                item_name: document.getElementById('publicSharedEditName').value.trim(),
                category_id: Number(document.getElementById('publicSharedEditCategory').value || 0),
                purchase_price: Number(document.getElementById('publicSharedEditPrice').value || 0),
                purchase_from: document.getElementById('publicSharedEditPurchaseFrom').value.trim(),
                recommend_reason: document.getElementById('publicSharedEditReason').value.trim()
            };
            if (!payload.item_name) {
                toast('物品名称不能为空', 'error');
                return false;
            }
            const res = await apiPost('public-channel/update', payload);
            if (res && res.success) {
                toast(res.message || '共享物品已更新');
                closePublicSharedEditModal();
                renderView();
            } else {
                toast((res && res.message) || '更新失败', 'error');
            }
            return false;
        }

        async function addPublicSharedComment(sharedId) {
            const id = Number(sharedId || 0);
            if (id <= 0) {
                toast('共享记录无效', 'error');
                return;
            }
            const input = document.getElementById(`publicCommentInput-${id}`);
            if (!input) {
                toast('评论输入框不存在', 'error');
                return;
            }
            const content = String(input.value || '').trim();
            if (!content) {
                toast('请输入评论内容', 'error');
                input.focus();
                return;
            }
            const res = await apiPost('public-channel/comment', { shared_id: id, content });
            if (res && res.success) {
                input.value = '';
                toast(res.message || '评论已发布');
                renderView();
                return;
            }
            toast((res && res.message) || '评论发布失败', 'error');
        }

        async function deletePublicSharedComment(commentId) {
            const id = Number(commentId || 0);
            if (id <= 0) {
                toast('评论无效', 'error');
                return;
            }
            if (!confirm('确定删除这条评论吗？')) return;
            const res = await apiPost('public-channel/comment-delete', { comment_id: id });
            if (res && res.success) {
                toast(res.message || '评论已删除');
                renderView();
                return;
            }
            toast((res && res.message) || '删除评论失败', 'error');
        }

        async function renderPublicChannel(container) {
            const res = await api('public-channel');
            if (!res.success) {
                container.innerHTML = '<p class="text-red-400">公共频道加载失败</p>';
                return;
            }
            const list = Array.isArray(res.data) ? res.data : [];
            App.publicChannelItems = list;
            const withPrice = list.filter(x => Number(x.purchase_price || 0) > 0).length;
            const withFrom = list.filter(x => String(x.purchase_from || '').trim() !== '').length;
            const withReason = list.filter(x => String(x.recommend_reason || '').trim() !== '').length;

            container.innerHTML = `
        <div class="glass rounded-2xl p-4 mb-6 anim-up">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                    <span class="text-sm text-slate-400"><i class="ri-broadcast-line mr-1 text-cyan-400"></i>共享物品 ${list.length} 条</span>
                    <span class="text-sm text-slate-400"><i class="ri-money-cny-circle-line mr-1 text-amber-400"></i>含价格 ${withPrice} 条</span>
                    <span class="text-sm text-slate-400"><i class="ri-shopping-bag-line mr-1 text-emerald-400"></i>含渠道 ${withFrom} 条</span>
                    <span class="text-sm text-slate-400"><i class="ri-thumb-up-line mr-1 text-violet-400"></i>含推荐理由 ${withReason} 条</span>
                </div>
                <span class="text-xs text-slate-500">可查看基础属性并一键加入购物清单</span>
            </div>
        </div>

        ${list.length === 0 ? `
            <div class="empty-state anim-up">
                <i class="ri-broadcast-line"></i>
                <h3 class="text-xl font-semibold text-slate-400 mb-2">公共频道暂时为空</h3>
                <p class="text-slate-500 text-sm">当用户在物品编辑中勾选“共享到公共频道”后，这里会显示对应物品。</p>
            </div>
        ` : `
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                ${list.map((item, i) => {
                    const categoryName = String(item.category_name || '').trim() || '未分类';
                    const purchaseFrom = String(item.purchase_from || '').trim();
                    const recommendReason = String(item.recommend_reason || '').trim();
                    const ownerName = String(item.owner_name || '').trim() || '未知用户';
                    const updatedDate = String(item.owner_item_updated_at || item.updated_at || '').slice(0, 10);
                    const comments = Array.isArray(item.comments) ? item.comments : [];
                    const canEdit = Number(item.owner_user_id || 0) === Number(CURRENT_USER.id || 0) || !!item.can_edit;
                    const price = Number(item.purchase_price || 0);
                    const priceHtml = price > 0
                        ? `<span class="text-amber-400 font-medium">¥${price.toLocaleString('zh-CN', { maximumFractionDigits: 2 })}</span>`
                        : '<span class="text-slate-500">价格未记录</span>';
                    return `
                    <div class="glass glass-hover rounded-2xl p-4 anim-up" style="animation-delay:${i * 25}ms">
                        <div class="flex items-start justify-between gap-3 mb-2">
                            <h3 class="font-semibold text-white leading-tight">${esc(item.item_name || '未命名物品')}</h3>
                            <div class="flex items-center gap-2">
                                <span class="badge badge-lent"><i class="ri-user-3-line mr-1"></i>${esc(ownerName)}</span>
                                ${canEdit ? `<button onclick="event.stopPropagation();openPublicSharedEdit(${Number(item.id || 0)})" class="btn btn-ghost btn-sm !py-1 !px-2 text-xs" title="编辑共享信息"><i class="ri-edit-line"></i></button>` : ''}
                            </div>
                        </div>
                        <div class="space-y-1.5 text-xs text-slate-400 mb-4">
                            <p><i class="ri-price-tag-3-line mr-1 text-sky-400"></i>分类：${esc(categoryName)}</p>
                            <p><i class="ri-money-cny-circle-line mr-1 text-amber-400"></i>购入价格：${priceHtml}</p>
                            <p><i class="ri-shopping-bag-line mr-1 text-emerald-400"></i>购入渠道：${purchaseFrom ? esc(purchaseFrom) : '<span class="text-slate-600">未记录</span>'}</p>
                            <p><i class="ri-thumb-up-line mr-1 text-violet-400"></i>推荐理由：${recommendReason ? esc(recommendReason) : '<span class="text-slate-600">未填写</span>'}</p>
                            <p><i class="ri-time-line mr-1 text-slate-500"></i>最近更新：${updatedDate || '未知'}</p>
                        </div>
                        <button onclick="addPublicSharedToShopping(${Number(item.id || 0)})" class="btn btn-primary btn-sm w-full">
                            <i class="ri-add-circle-line"></i>加入我的购物清单
                        </button>
                        <div class="mt-4 pt-3 border-t border-white/10">
                            <div class="flex items-center justify-between mb-2">
                                <p class="text-xs text-slate-400"><i class="ri-chat-3-line mr-1 text-cyan-400"></i>评论</p>
                                <span class="text-[11px] text-slate-500">${comments.length} 条</span>
                            </div>
                            <div class="space-y-2 max-h-28 overflow-auto pr-1">
                                ${comments.length > 0 ? comments.map(comment => `
                                    <div class="rounded-lg bg-white/5 px-2.5 py-2">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-[11px] text-sky-300">${esc(comment.user_name || '用户')}</span>
                                            <div class="flex items-center gap-2">
                                                <span class="text-[10px] text-slate-600">${esc(String(comment.created_at || '').slice(0, 16))}</span>
                                                ${comment.can_delete ? `<button onclick="deletePublicSharedComment(${Number(comment.id || 0)})" class="text-[10px] text-rose-300 hover:text-rose-200 transition" title="删除评论"><i class="ri-delete-bin-6-line"></i></button>` : ''}
                                            </div>
                                        </div>
                                        <p class="text-xs text-slate-300 mt-1 break-words">${esc(comment.content || '')}</p>
                                    </div>
                                `).join('') : '<p class="text-[11px] text-slate-600 py-1">暂无评论，来写第一条吧</p>'}
                            </div>
                            <div class="mt-2 flex items-center gap-2">
                                <input id="publicCommentInput-${Number(item.id || 0)}" type="text" class="input !h-9 !py-1.5 !text-xs flex-1" maxlength="300" placeholder="写下你的评论...">
                                <button onclick="addPublicSharedComment(${Number(item.id || 0)})" class="btn btn-ghost btn-sm !py-1.5 !px-3">
                                    <i class="ri-send-plane-2-line"></i>发送
                                </button>
                            </div>
                        </div>
                    </div>`;
                }).join('')}
            </div>
        `}
    `;
        }

        function shoppingStatusKey(status) {
            const s = String(status || '').trim().toLowerCase();
            if (s === 'pending_receipt' || s === '待收货') return 'pending_receipt';
            return 'pending_purchase';
        }

        function shoppingStatusMeta(status) {
            const key = shoppingStatusKey(status);
            if (key === 'pending_receipt') {
                return { key, label: '待收货', badge: 'badge-lent', icon: 'ri-truck-line', section: '待收货' };
            }
            return { key: 'pending_purchase', label: '待购买', badge: 'badge-warning', icon: 'ri-shopping-cart-2-line', section: '待购买' };
        }

        function updateShoppingToggleStatusButton() {
            const btn = document.getElementById('shoppingToggleStatusBtn');
            const label = document.getElementById('shoppingToggleStatusLabel');
            const id = Number(document.getElementById('shoppingId')?.value || 0);
            const statusInput = document.getElementById('shoppingStatus');
            if (!btn || !label || !statusInput)
                return;
            if (id <= 0) {
                btn.classList.add('hidden');
                btn.dataset.targetStatus = '';
                return;
            }
            const current = shoppingStatusKey(statusInput.value);
            const target = current === 'pending_purchase' ? 'pending_receipt' : 'pending_purchase';
            btn.dataset.targetStatus = target;
            label.textContent = target === 'pending_receipt' ? '已购买' : '待购买';
            btn.classList.remove('hidden');
        }

        function shoppingPriorityMeta(priority) {
            const p = String(priority || 'normal').toLowerCase();
            if (p === 'high') return { label: '高优先', badge: 'badge-danger', icon: 'ri-flashlight-line' };
            if (p === 'low') return { label: '低优先', badge: 'badge-archived', icon: 'ri-hourglass-line' };
            return { label: '普通', badge: 'badge-warning', icon: 'ri-list-check-line' };
        }

        function openShoppingListAndEdit(id) {
            const targetId = Number(id || 0);
            if (targetId <= 0) {
                switchView('shopping-list');
                return;
            }
            App.pendingShoppingEditId = targetId;
            switchView('shopping-list');
        }

        async function renderShoppingList(container) {
            await loadBaseData();
            const res = await api('shopping-list');
            if (!res.success) { container.innerHTML = '<p class="text-red-400">购物清单加载失败</p>'; return; }

            const list = (Array.isArray(res.data) ? res.data : []).map(item => ({
                ...item,
                status: shoppingStatusKey(item.status)
            }));
            App.shoppingList = list;
            const totalQty = list.reduce((sum, x) => sum + Math.max(1, Number(x.quantity || 1)), 0);
            const highCount = list.filter(x => String(x.priority || '') === 'high').length;
            const budgetTotal = list.reduce((sum, x) => sum + (Math.max(1, Number(x.quantity || 1)) * Math.max(0, Number(x.planned_price || 0))), 0);
            const pendingPurchaseItems = list.filter(x => shoppingStatusKey(x.status) === 'pending_purchase');
            const pendingReceiptItems = list.filter(x => shoppingStatusKey(x.status) === 'pending_receipt');
            const renderShoppingCards = (items, startDelay = 0) => items.map((item, i) => {
                const p = shoppingPriorityMeta(item.priority);
                const s = shoppingStatusMeta(item.status);
                const qty = Math.max(1, Number(item.quantity || 1));
                const price = Math.max(0, Number(item.planned_price || 0));
                const reminderDate = item.reminder_date || '';
                const reminderNote = String(item.reminder_note || '').trim();
                const reminderNoteHtml = reminderNote ? `提醒：${esc(reminderNote)}` : '&nbsp;';
                return `
                <div class="glass glass-hover rounded-2xl p-4 anim-up" style="animation-delay:${(startDelay + i) * 25}ms">
                    <div class="flex items-start justify-between gap-3 mb-3">
                        <div class="min-w-0">
                            <h3 class="font-semibold text-white truncate">${esc(item.name)}</h3>
                            <div class="flex flex-wrap items-center gap-2 mt-1">
                                <span class="badge ${s.badge}"><i class="${s.icon} mr-1"></i>${s.label}</span>
                                <span class="badge ${p.badge}"><i class="${p.icon} mr-1"></i>${p.label}</span>
                                <span class="text-xs text-slate-500">x${qty}</span>
                                ${item.category_name ? `<span class="text-xs text-slate-500">${item.category_icon || '📦'} ${esc(item.category_name)}</span>` : '<span class="text-xs text-slate-600">未分类</span>'}
                                ${price > 0 ? `<span class="text-xs text-amber-400">预算 ¥${price.toLocaleString()}</span>` : ''}
                            </div>
                        </div>
                        <span class="text-[11px] text-slate-600 flex-shrink-0">${String(item.created_at || '').slice(0, 10)}</span>
                    </div>
                    ${reminderDate ? `<p class="text-xs text-cyan-300 mb-1"><i class="ri-notification-3-line mr-1"></i>${reminderDate} · ${reminderDueLabel(reminderDate)}</p>` : '<p class="text-xs text-slate-600 mb-1">未设置提醒</p>'}
                    <p class="text-xs text-slate-400 mb-2 truncate h-4 leading-4">${reminderNoteHtml}</p>
                    ${item.notes ? `<p class="text-xs text-slate-500 mb-3 truncate">${esc(item.notes)}</p>` : '<p class="text-xs text-slate-600 mb-3">暂无备注</p>'}
                    <div class="flex gap-2">
                        <button onclick="convertShoppingItem(${item.id})" class="btn btn-primary btn-sm flex-1"><i class="ri-shopping-bag-3-line"></i>已购买入库</button>
                        <button onclick="editShoppingItem(${item.id})" class="btn btn-ghost btn-sm flex-1"><i class="ri-edit-line"></i>编辑</button>
                        <button onclick="deleteShoppingItem(${item.id},'${esc(item.name)}')" class="btn btn-danger btn-sm flex-1"><i class="ri-delete-bin-line"></i>删除</button>
                    </div>
                </div>`;
            }).join('');

            container.innerHTML = `
        <div class="glass rounded-2xl p-4 mb-6 anim-up">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                    <span class="text-sm text-slate-400"><i class="ri-shopping-cart-2-line mr-1 text-sky-400"></i>共 ${list.length} 条清单</span>
                    <span class="text-sm text-slate-400"><i class="ri-shopping-basket-line mr-1 text-amber-400"></i>待购买 ${pendingPurchaseItems.length}</span>
                    <span class="text-sm text-slate-400"><i class="ri-truck-line mr-1 text-indigo-400"></i>待收货 ${pendingReceiptItems.length}</span>
                    <span class="text-sm text-slate-400"><i class="ri-stack-line mr-1 text-violet-400"></i>计划件数 ${totalQty}</span>
                    <span class="text-sm text-slate-400"><i class="ri-flashlight-line mr-1 text-red-400"></i>高优先 ${highCount}</span>
                    <span class="text-sm text-slate-400"><i class="ri-money-cny-circle-line mr-1 text-amber-400"></i>预算约 ¥${budgetTotal.toLocaleString()}</span>
                </div>
                <button onclick="openAddShoppingItem()" class="btn btn-primary btn-sm"><i class="ri-add-line"></i>添加清单</button>
            </div>
        </div>

        <div class="space-y-6">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold text-white flex items-center gap-2"><i class="ri-shopping-basket-line text-amber-400"></i>待购买</h3>
                    <span class="text-xs text-slate-500">${pendingPurchaseItems.length} 条</span>
                </div>
                ${pendingPurchaseItems.length > 0 ? `
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    ${renderShoppingCards(pendingPurchaseItems, 0)}
                </div>` : '<p class="text-slate-500 text-sm text-center py-5 glass rounded-xl border border-white/5">暂无待购买清单</p>'}
            </div>

            <div>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold text-white flex items-center gap-2"><i class="ri-truck-line text-indigo-400"></i>待收货</h3>
                    <span class="text-xs text-slate-500">${pendingReceiptItems.length} 条</span>
                </div>
                ${pendingReceiptItems.length > 0 ? `
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    ${renderShoppingCards(pendingReceiptItems, pendingPurchaseItems.length)}
                </div>` : ''}
            </div>
        </div>
        ${list.length === 0 ? `
        <div class="empty-state anim-up">
            <i class="ri-shopping-cart-line"></i>
            <h3 class="text-xl font-semibold text-slate-400 mb-2">购物清单为空</h3>
            <p class="text-slate-500 text-sm mb-5">把未来想买的东西先记在这里，购买后可一键转入物品管理。</p>
            <button onclick="openAddShoppingItem()" class="btn btn-primary"><i class="ri-add-line"></i>添加第一条清单</button>
        </div>` : ''}
    `;

            const pendingEditId = Number(App.pendingShoppingEditId || 0);
            if (pendingEditId > 0) {
                App.pendingShoppingEditId = 0;
                await editShoppingItem(pendingEditId);
            }
        }

        let shoppingSimilarSearchTimer = null;
        let shoppingSimilarSearchSeq = 0;
        let shoppingSimilarSortMode = 'date_desc';
        let shoppingSimilarLatestItems = [];
        let shoppingSimilarLatestKeyword = '';
        let shoppingSimilarLatestState = 'idle';

        function updateShoppingSimilarSortButton() {
            const label = document.getElementById('shoppingSimilarSortLabel');
            if (!label)
                return;
            label.textContent = shoppingSimilarSortMode === 'price_asc' ? '最低价' : '最新日期';
        }

        function sortShoppingSimilarItems(items) {
            const arr = Array.isArray(items) ? [...items] : [];
            if (shoppingSimilarSortMode === 'price_asc') {
                arr.sort((a, b) => {
                    const pa = Number(a.purchase_price || 0);
                    const pb = Number(b.purchase_price || 0);
                    const va = pa > 0 ? pa : Number.POSITIVE_INFINITY;
                    const vb = pb > 0 ? pb : Number.POSITIVE_INFINITY;
                    if (va !== vb)
                        return va - vb;
                    const da = String(a.purchase_date || a.updated_at || '');
                    const db = String(b.purchase_date || b.updated_at || '');
                    return db.localeCompare(da);
                });
                return arr;
            }
            arr.sort((a, b) => {
                const da = String(a.purchase_date || a.updated_at || '');
                const db = String(b.purchase_date || b.updated_at || '');
                if (da !== db)
                    return db.localeCompare(da);
                const pa = Number(a.purchase_price || 0);
                const pb = Number(b.purchase_price || 0);
                return (pa > 0 ? pa : Number.POSITIVE_INFINITY) - (pb > 0 ? pb : Number.POSITIVE_INFINITY);
            });
            return arr;
        }

        function toggleShoppingSimilarSortMode() {
            shoppingSimilarSortMode = shoppingSimilarSortMode === 'price_asc' ? 'date_desc' : 'price_asc';
            updateShoppingSimilarSortButton();
            if (shoppingSimilarLatestState === 'done') {
                renderShoppingSimilarItemPrices(shoppingSimilarLatestItems, 'done', shoppingSimilarLatestKeyword);
            }
        }

        function renderShoppingSimilarItemPrices(items = [], state = 'idle', keyword = '') {
            const box = document.getElementById('shoppingPriceReferenceBox');
            const list = document.getElementById('shoppingPriceReferenceList');
            if (!box || !list)
                return;
            const q = String(keyword || '').trim();
            shoppingSimilarLatestKeyword = q;
            shoppingSimilarLatestState = state;
            if (!q) {
                shoppingSimilarLatestItems = [];
                box.classList.add('hidden');
                list.innerHTML = '';
                return;
            }
            box.classList.remove('hidden');
            updateShoppingSimilarSortButton();
            if (state === 'loading') {
                list.innerHTML = '<p class="text-xs text-slate-500">正在匹配历史物品...</p>';
                return;
            }
            if (state === 'error') {
                list.innerHTML = '<p class="text-xs text-red-400">参考价加载失败，请稍后重试</p>';
                return;
            }
            const dataItems = Array.isArray(items) ? items : [];
            shoppingSimilarLatestItems = dataItems;
            const sortedItems = sortShoppingSimilarItems(dataItems);
            if (sortedItems.length === 0) {
                list.innerHTML = '<p class="text-xs text-slate-500">未找到相似物品，可按当前预算填写价格</p>';
                return;
            }
            list.innerHTML = sortedItems.map(item => {
                const name = String(item.name || '').trim() || '未命名物品';
                const from = String(item.purchase_from || '').trim();
                const price = Number(item.purchase_price || 0);
                const purchaseDate = String(item.purchase_date || '').slice(0, 10);
                const priceHtml = price > 0
                    ? `<span class="text-amber-300 font-medium">¥${price.toLocaleString('zh-CN', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}</span>`
                    : '<span class="text-slate-500">未记录价格</span>';
                const metaPieces = [];
                if (from)
                    metaPieces.push(esc(from));
                metaPieces.push(purchaseDate ? esc(purchaseDate) : '日期未知');
                const metaHtml = `<span class="text-[11px] text-slate-500">${metaPieces.join(' · ')}</span>`;
                return `<div class="flex items-center justify-between gap-3 text-xs">
                    <span class="min-w-0 truncate text-slate-300">${esc(name)}</span>
                    <span class="flex items-center gap-2 flex-shrink-0">${priceHtml}${metaHtml}</span>
                </div>`;
            }).join('');
        }

        function scheduleRefreshShoppingSimilarItemPrices() {
            if (shoppingSimilarSearchTimer)
                clearTimeout(shoppingSimilarSearchTimer);
            shoppingSimilarSearchTimer = setTimeout(() => {
                refreshShoppingSimilarItemPrices();
            }, 220);
        }

        async function refreshShoppingSimilarItemPrices() {
            const nameInput = document.getElementById('shoppingName');
            const keyword = String(nameInput?.value || '').trim();
            if (!keyword) {
                shoppingSimilarSearchSeq++;
                renderShoppingSimilarItemPrices([], 'idle', '');
                return;
            }
            const seq = ++shoppingSimilarSearchSeq;
            renderShoppingSimilarItemPrices([], 'loading', keyword);
            const res = await api(`shopping-list/similar-items&name=${encodeURIComponent(keyword)}`);
            if (seq !== shoppingSimilarSearchSeq)
                return;
            if (!res || !res.success) {
                renderShoppingSimilarItemPrices([], 'error', keyword);
                return;
            }
            renderShoppingSimilarItemPrices(Array.isArray(res.data) ? res.data : [], 'done', keyword);
        }

        async function openAddShoppingItem() {
            document.getElementById('shoppingModalTitle').textContent = '添加清单';
            document.getElementById('shoppingForm').reset();
            document.getElementById('shoppingId').value = '';
            document.getElementById('shoppingConvertBtn')?.classList.add('hidden');
            document.getElementById('shoppingToggleStatusBtn')?.classList.add('hidden');
            document.getElementById('shoppingCategoryId').value = '0';
            document.getElementById('shoppingQty').value = '1';
            document.getElementById('shoppingStatus').value = 'pending_purchase';
            document.getElementById('shoppingPrice').value = '0';
            document.getElementById('shoppingPriority').value = 'normal';
            document.getElementById('shoppingReminderDate').value = '';
            document.getElementById('shoppingReminderNote').value = '';
            updateShoppingToggleStatusButton();
            shoppingSimilarSortMode = 'date_desc';
            updateShoppingSimilarSortButton();
            shoppingSimilarSearchSeq++;
            shoppingSimilarLatestItems = [];
            shoppingSimilarLatestKeyword = '';
            shoppingSimilarLatestState = 'idle';
            if (shoppingSimilarSearchTimer)
                clearTimeout(shoppingSimilarSearchTimer);
            renderShoppingSimilarItemPrices([], 'idle', '');
            document.getElementById('shoppingModal').classList.add('show');
            refreshDateInputPlaceholderDisplay(document.getElementById('shoppingForm'));
        }

        async function editShoppingItem(id) {
            let item = App.shoppingList.find(x => x.id === id);
            if (!item) {
                const res = await api('shopping-list');
                if (!res.success) { toast('购物清单加载失败', 'error'); return; }
                App.shoppingList = Array.isArray(res.data) ? res.data : [];
                item = App.shoppingList.find(x => x.id === id);
            }
            if (!item) { toast('清单项不存在', 'error'); return; }

            document.getElementById('shoppingModalTitle').textContent = '编辑清单';
            document.getElementById('shoppingId').value = item.id;
            document.getElementById('shoppingConvertBtn')?.classList.remove('hidden');
            document.getElementById('shoppingCategoryId').value = String(Number(item.category_id || 0));
            document.getElementById('shoppingName').value = item.name || '';
            document.getElementById('shoppingQty').value = Math.max(1, Number(item.quantity || 1));
            document.getElementById('shoppingStatus').value = shoppingStatusKey(item.status);
            document.getElementById('shoppingPriority').value = ['high', 'normal', 'low'].includes(item.priority) ? item.priority : 'normal';
            document.getElementById('shoppingPrice').value = Number(item.planned_price || 0);
            document.getElementById('shoppingReminderDate').value = item.reminder_date || '';
            document.getElementById('shoppingReminderNote').value = item.reminder_note || '';
            document.getElementById('shoppingNotes').value = item.notes || '';
            updateShoppingToggleStatusButton();
            shoppingSimilarSortMode = 'date_desc';
            updateShoppingSimilarSortButton();
            document.getElementById('shoppingModal').classList.add('show');
            refreshDateInputPlaceholderDisplay(document.getElementById('shoppingForm'));
            await refreshShoppingSimilarItemPrices();
        }

        async function toggleCurrentShoppingStatus() {
            const id = Number(document.getElementById('shoppingId')?.value || 0);
            if (id <= 0) {
                toast('请先保存清单后再切换状态', 'error');
                return;
            }
            const btn = document.getElementById('shoppingToggleStatusBtn');
            const statusInput = document.getElementById('shoppingStatus');
            if (!btn || !statusInput)
                return;
            const target = shoppingStatusKey(btn.dataset.targetStatus || '');
            const endpoint = 'shopping-list/update-status';
            btn.disabled = true;
            try {
                const res = await apiPost(endpoint, { id, status: target });
                if (!res.success) {
                    toast(res.message || '状态切换失败', 'error');
                    return;
                }
                statusInput.value = target;
                updateShoppingToggleStatusButton();
                const localItem = App.shoppingList.find(x => x.id === id);
                if (localItem)
                    localItem.status = target;
                toast(`已切换为${target === 'pending_receipt' ? '待收货' : '待购买'}`);
                closeShoppingModal();
                renderView();
            } finally {
                btn.disabled = false;
            }
        }

        function convertCurrentShoppingItem() {
            const id = Number(document.getElementById('shoppingId')?.value || 0);
            if (id <= 0) {
                toast('请先保存清单后再入库', 'error');
                return;
            }
            closeShoppingModal();
            convertShoppingItem(id);
        }

        async function saveShoppingItem(e) {
            e.preventDefault();
            const id = document.getElementById('shoppingId').value;
            const name = document.getElementById('shoppingName').value.trim();
            if (!name) { toast('请输入清单名称', 'error'); return false; }
            const data = {
                id: id ? +id : undefined,
                name,
                quantity: Math.max(1, parseInt(document.getElementById('shoppingQty').value || '1', 10)),
                status: shoppingStatusKey(document.getElementById('shoppingStatus').value),
                category_id: +document.getElementById('shoppingCategoryId').value,
                priority: document.getElementById('shoppingPriority').value,
                planned_price: Math.max(0, Number(document.getElementById('shoppingPrice').value || 0)),
                reminder_date: document.getElementById('shoppingReminderDate').value,
                reminder_note: document.getElementById('shoppingReminderNote').value.trim(),
                notes: document.getElementById('shoppingNotes').value.trim()
            };
            const endpoint = id ? 'shopping-list/update' : 'shopping-list';
            const res = await apiPost(endpoint, data);
            if (res.success) {
                toast(id ? '购物清单已更新' : '已加入购物清单');
                closeShoppingModal();
                renderView();
            } else {
                toast(res.message || '保存失败', 'error');
            }
            return false;
        }

        async function deleteShoppingItem(id, name) {
            if (!confirm(`确定删除购物清单「${name}」？`)) return;
            const res = await apiPost('shopping-list/delete', { id });
            if (res.success) {
                toast('已删除');
                renderView();
            } else {
                toast(res.message || '删除失败', 'error');
            }
        }

        async function convertShoppingItem(id) {
            let item = App.shoppingList.find(x => x.id === id);
            if (!item) {
                const res = await api('shopping-list');
                if (!res.success) { toast('购物清单加载失败', 'error'); return; }
                App.shoppingList = Array.isArray(res.data) ? res.data : [];
                item = App.shoppingList.find(x => x.id === id);
            }
            if (!item) { toast('清单项不存在', 'error'); return; }

            const now = new Date();
            const pad = n => String(n).padStart(2, '0');
            const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;

            document.getElementById('itemModalTitle').textContent = '已购买入库';
            document.getElementById('itemForm').reset();
            document.getElementById('itemId').value = '';
            document.getElementById('itemImage').value = '';
            document.getElementById('itemSourceShoppingId').value = item.id;
            document.getElementById('itemName').value = item.name || '';
            const convertedQty = Math.max(1, Number(item.quantity || 1));
            document.getElementById('itemQuantity').value = convertedQty;
            document.getElementById('itemRemainingCurrent').value = convertedQty;
            document.getElementById('itemPrice').value = Math.max(0, Number(item.planned_price || 0));
            document.getElementById('itemDate').value = today;
            document.getElementById('itemExpiry').value = '';
            document.getElementById('itemReminderDate').value = '';
            document.getElementById('itemReminderEvery').value = '1';
            document.getElementById('itemReminderUnit').value = 'day';
            document.getElementById('itemReminderNext').value = '';
            document.getElementById('itemReminderNote').value = '';
            document.getElementById('itemBarcode').value = '';
            document.getElementById('itemTags').value = '';
            document.getElementById('itemNotes').value = item.notes || '';
            document.getElementById('itemSharePublic').checked = false;
            syncReminderFields();

            resetUploadZone();
            await populateSelects({
                status: getDefaultStatusKey(),
                purchaseFrom: '',
                categoryId: Number(item.category_id || 0),
                subcategoryId: Number(item.subcategory_id || 0)
            });
            document.getElementById('itemLocation').value = 0;
            document.getElementById('itemModal').classList.add('show');
            setItemSubmitLabel('保存入库');
            refreshDateInputPlaceholderDisplay(document.getElementById('itemForm'));
            closeItemUnsavedConfirm();
            markItemFormClean();
        }

        function closeShoppingModal() {
            shoppingSimilarSearchSeq++;
            shoppingSimilarLatestItems = [];
            shoppingSimilarLatestKeyword = '';
            shoppingSimilarLatestState = 'idle';
            if (shoppingSimilarSearchTimer)
                clearTimeout(shoppingSimilarSearchTimer);
            renderShoppingSimilarItemPrices([], 'idle', '');
            document.getElementById('shoppingModal').classList.remove('show');
        }

        // ============================================================
        // 🏷️ 分类管理
        // ============================================================
        async function renderCategories(container) {
            await loadBaseData();
            const uncRes = await api('items&page=1&limit=1&search=&category=-1&location=0&status=');
            const uncategorizedCount = uncRes.success ? Number(uncRes.total || 0) : 0;
            const catSortMode = getEffectiveListSortMode('categories');
            const g = getCategoryGroups(catSortMode);
            const rootCats = g.roots;
            const subCats = g.subs;
            const orphanSubCats = g.orphans;
            const subByParent = {};
            subCats.forEach(cat => {
                const pid = Number(cat.parent_id || 0);
                if (!subByParent[pid]) subByParent[pid] = [];
                subByParent[pid].push(cat);
            });
            Object.keys(subByParent).forEach(pid => {
                if (catSortMode === 'count_desc') {
                    subByParent[pid].sort((a, b) => Number(b.item_count || 0) - Number(a.item_count || 0));
                } else {
                    subByParent[pid].sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'zh'));
                }
            });
            const totalCount = 1 + rootCats.length + subCats.length + orphanSubCats.length;
            container.innerHTML = `
        <div class="flex items-center justify-between mb-6 anim-up categories-header" style="position:relative;z-index:40;">
            <p class="text-sm text-slate-500">共 ${totalCount} 个分类（一级 ${rootCats.length} / 二级 ${subCats.length + orphanSubCats.length}）</p>
            <div class="flex items-center gap-2 categories-top-actions">
                <div class="relative">
                    <button onclick="toggleListSortMenu('categoriesSortMenu', this)" class="btn btn-ghost btn-sm text-slate-400 hover:text-white transition">
                        <i class="ri-sort-desc mr-1"></i>排序：${getListSortLabel(catSortMode)}
                    </button>
                    <div id="categoriesSortMenu" class="list-sort-menu hidden absolute right-0 top-full mt-1 glass rounded-xl p-2 min-w-[180px] z-50 shadow-xl border border-white/[0.06] space-y-1" style="z-index:90;">
                        <button onclick="setListSort('categories','count_desc')" class="w-full text-left px-2 py-1.5 rounded-lg text-xs transition ${catSortMode === 'count_desc' ? 'bg-sky-500/15 text-sky-300' : 'text-slate-300 hover:bg-white/[0.05]'}">按物品数量 多→少</button>
                        <button onclick="setListSort('categories','name_asc')" class="w-full text-left px-2 py-1.5 rounded-lg text-xs transition ${catSortMode === 'name_asc' ? 'bg-sky-500/15 text-sky-300' : 'text-slate-300 hover:bg-white/[0.05]'}">按名称首字母 A→Z</button>
                    </div>
                </div>
                <button onclick="openAddCategory(0)" class="btn btn-ghost btn-sm text-slate-400 hover:text-sky-300 transition"><i class="ri-add-line"></i>添加一级分类</button>
                <button onclick="openAddCategory(-1)" class="btn btn-ghost btn-sm text-slate-400 hover:text-cyan-300 transition"><i class="ri-node-tree"></i>添加二级分类</button>
            </div>
        </div>
        <div class="category-mindmap space-y-4" style="position:relative;z-index:1;">
            <div class="glass rounded-2xl p-4 anim-up category-branch" style="animation-delay:0ms;">
                <div class="category-branch-grid">
                    <div class="category-node category-node-root" style="--node-color:#64748b;">
                        <div class="category-node-head">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="text-2xl">📦</span>
                                <div class="min-w-0">
                                    <h3 class="font-semibold text-white truncate">未分类</h3>
                                    <p class="text-xs text-slate-500">${uncategorizedCount} 件物品</p>
                                </div>
                            </div>
                            <span class="category-node-dot" style="background:#64748b"></span>
                        </div>
                        <div class="category-node-actions">
                            <button onclick="viewItemsByCategory(-1)" class="btn btn-ghost btn-sm" style="color:#38bdf8" title="查看物品"><i class="ri-archive-line"></i>物品</button>
                        </div>
                    </div>
                    <div class="category-branch-line is-empty"></div>
                    <div class="category-children is-empty">
                        <div class="category-node category-node-child category-node-empty">
                            <span class="text-xs text-slate-500">系统固定分组，无二级分类</span>
                        </div>
                    </div>
                </div>
            </div>
            ${rootCats.map((cat, i) => {
                const children = subByParent[Number(cat.id || 0)] || [];
                return `
                <div class="glass rounded-2xl p-4 anim-up category-branch" style="animation-delay:${(i + 1) * 35}ms;">
                    <div class="category-branch-grid">
                        <div class="category-node category-node-root" style="--node-color:${cat.color || '#64748b'};">
                            <div class="category-node-head">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="text-2xl">${cat.icon}</span>
                                    <div class="min-w-0">
                                        <h3 class="font-semibold text-white truncate">${esc(cat.name)}</h3>
                                        <p class="text-xs text-slate-500">${cat.item_count} 件物品 · ${children.length} 个二级分类</p>
                                    </div>
                                </div>
                                <span class="category-node-dot" style="background:${cat.color || '#64748b'}"></span>
                            </div>
                            <div class="category-node-actions">
                                <button onclick="viewItemsByCategory(${cat.id})" class="btn btn-ghost btn-sm" style="color:#38bdf8" title="查看物品"><i class="ri-archive-line"></i>物品</button>
                                <button onclick="openAddSubCategory(${cat.id})" class="btn btn-ghost btn-sm" title="添加二级分类"><i class="ri-node-tree"></i>添加二级分类</button>
                                <button onclick="editCategory(${cat.id})" class="btn btn-ghost btn-sm"><i class="ri-edit-line"></i>编辑</button>
                                <button onclick="deleteCategory(${cat.id},'${esc(cat.name)}',${cat.item_count},${cat.child_count || 0})" class="btn btn-danger btn-sm"><i class="ri-delete-bin-line"></i>删除</button>
                            </div>
                        </div>
                        <div class="category-branch-line ${children.length === 0 ? 'is-empty' : ''}"></div>
                        <div class="category-children ${children.length === 0 ? 'is-empty' : ''}">
                            ${children.length > 0 ? children.map(sub => `
                                <div class="category-node category-node-child" style="border-left:2px solid ${cat.color || '#64748b'}">
                                    <div class="category-node-head">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <span class="text-xl">${sub.icon}</span>
                                            <div class="min-w-0">
                                                <h4 class="text-sm font-medium text-white truncate">${esc(sub.name)}</h4>
                                                <p class="text-xs text-slate-500">${sub.item_count} 件物品</p>
                                            </div>
                                        </div>
                                        <span class="badge badge-lent">二级</span>
                                    </div>
                                    <div class="category-node-actions">
                                        <button onclick="viewItemsByCategory(${sub.id})" class="btn btn-ghost btn-sm" style="color:#38bdf8" title="查看物品"><i class="ri-archive-line"></i>物品</button>
                                        <button onclick="editCategory(${sub.id})" class="btn btn-ghost btn-sm"><i class="ri-edit-line"></i>编辑</button>
                                        <button onclick="deleteCategory(${sub.id},'${esc(sub.name)}',${sub.item_count},0)" class="btn btn-danger btn-sm"><i class="ri-delete-bin-line"></i>删除</button>
                                    </div>
                                </div>
                            `).join('') : `
                                <div class="category-node category-node-child category-node-empty">
                                    <span class="text-xs text-slate-500">暂无二级分类</span>
                                    <button onclick="openAddSubCategory(${cat.id})" class="btn btn-ghost btn-sm"><i class="ri-add-line"></i>新增</button>
                                </div>
                            `}
                        </div>
                    </div>
                </div>`;
            }).join('')}
        </div>
        ${orphanSubCats.length > 0 ? `
            <div class="flex items-center justify-between mt-6 mb-3">
                <h4 class="text-sm font-semibold text-amber-300 flex items-center gap-2"><i class="ri-error-warning-line"></i>待整理二级分类</h4>
                <span class="text-xs text-slate-500">${orphanSubCats.length} 个</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                ${orphanSubCats.map((cat, i) => `
                    <div class="glass rounded-2xl p-5 anim-up border border-amber-500/30" style="animation-delay:${i * 30}ms;border-left:3px solid #f59e0b">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="text-3xl">${cat.icon}</span>
                                <div class="min-w-0">
                                    <h3 class="font-semibold text-white truncate">${esc(cat.name)}</h3>
                                    <p class="text-xs text-amber-300">上级分类缺失（建议编辑后重新归类）</p>
                                    <p class="text-xs text-slate-500">${cat.item_count} 件物品</p>
                                </div>
                            </div>
                            <span class="badge" style="background:rgba(245,158,11,0.18);color:#f59e0b;">待整理</span>
                        </div>
                        <div class="flex gap-2">
                            <button onclick="viewItemsByCategory(${cat.id})" class="btn btn-ghost btn-sm flex-1" style="color:#38bdf8" title="查看物品"><i class="ri-archive-line"></i>物品</button>
                            <button onclick="editCategory(${cat.id})" class="btn btn-ghost btn-sm flex-1"><i class="ri-edit-line"></i>编辑</button>
                            <button onclick="deleteCategory(${cat.id},'${esc(cat.name)}',${cat.item_count},0)" class="btn btn-danger btn-sm flex-1"><i class="ri-delete-bin-line"></i>删除</button>
                        </div>
                    </div>
                `).join('')}
            </div>
        ` : ''}
        ${(rootCats.length + subCats.length + orphanSubCats.length) === 0 ? '<div class="empty-state"><i class="ri-price-tag-3-line"></i><h3 class="text-xl font-semibold text-slate-400">暂无分类</h3></div>' : ''}
    `;
        }

        function populateCategoryParentSelect(selectedParentId = 0, editingId = 0) {
            const select = document.getElementById('catParentId');
            if (!select) return;
            const roots = getCategoryGroups('name_asc').roots.filter(c => Number(c.id || 0) !== Number(editingId || 0));
            let options = `<option value="0">无（一级分类）</option>`;
            if (roots.length > 0) {
                options += '<optgroup label="选择上级分类">';
                options += roots.map(c => `<option value="${Number(c.id || 0)}">${esc(c.icon || '📦')} ${esc(c.name || '')}</option>`).join('');
                options += '</optgroup>';
            }
            select.innerHTML = options;
            const targetParent = Number(selectedParentId || 0);
            select.value = String(roots.some(c => Number(c.id || 0) === targetParent) ? targetParent : 0);
        }

        function openAddCategory(defaultParentId = 0) {
            let parentId = Number(defaultParentId || 0);
            const forceSubMode = parentId < 0;
            if (parentId < 0) parentId = 0;
            document.getElementById('catModalTitle').textContent = (forceSubMode || parentId > 0) ? '添加二级分类' : '添加一级分类';
            document.getElementById('catId').value = '';
            document.getElementById('catName').value = '';
            setEmojiPickerValue('catEmojiPicker', 'catIcon', '📦', '📦');
            document.getElementById('catColor').value = '#3b82f6';
            populateCategoryParentSelect(parentId > 0 ? parentId : 0, 0);
            document.getElementById('catParentId').disabled = false;
            document.getElementById('categoryModal').classList.add('show');
        }

        function openAddSubCategory(parentId) {
            openAddCategory(Number(parentId || 0));
        }

        function editCategory(id) {
            const cat = App.categories.find(c => c.id === id);
            if (!cat) return;
            document.getElementById('catModalTitle').textContent = '编辑分类';
            document.getElementById('catId').value = cat.id;
            document.getElementById('catName').value = cat.name;
            setEmojiPickerValue('catEmojiPicker', 'catIcon', cat.icon, '📦');
            document.getElementById('catColor').value = cat.color;
            populateCategoryParentSelect(Number(cat.parent_id || 0), Number(cat.id || 0));
            const hasChildren = Number(cat.child_count || 0) > 0;
            document.getElementById('catParentId').disabled = hasChildren;
            document.getElementById('categoryModal').classList.add('show');
        }

        async function saveCategory(e) {
            e.preventDefault();
            const id = document.getElementById('catId').value;
            const data = {
                id: id ? +id : undefined,
                name: document.getElementById('catName').value.trim(),
                icon: document.getElementById('catIcon').value.trim() || '📦',
                color: document.getElementById('catColor').value,
                parent_id: Number(document.getElementById('catParentId').value || 0)
            };
            if (!data.name) { toast('请输入分类名称', 'error'); return false; }
            const endpoint = id ? 'categories/update' : 'categories';
            const res = await apiPost(endpoint, data);
            if (res.success) {
                invalidateBaseDataCache();
                toast(id ? '分类已更新' : '分类已添加');
                closeCategoryModal();
                renderView();
            } else toast(res.message, 'error');
            return false;
        }

        async function deleteCategory(id, name, count, childCount = 0) {
            const itemTip = count > 0 ? `其下 ${count} 件物品将变为未分类。` : '';
            const childTip = Number(childCount || 0) > 0 ? `该分类下 ${childCount} 个二级分类也会被一并删除。` : '';
            if (!confirm(`确定删除分类「${name}」？${itemTip}${childTip}`)) return;
            const res = await apiPost('categories/delete', { id });
            if (res.success) {
                invalidateBaseDataCache();
                toast('分类已删除');
                renderView();
            } else toast(res.message, 'error');
        }

        function closeCategoryModal() {
            document.getElementById('categoryModal').classList.remove('show');
            hideEmojiPickerMenus();
        }

        // ============================================================
        // 📍 位置管理
        // ============================================================
        async function renderLocations(container) {
            await loadBaseData();
            const unsetRes = await api('items&page=1&limit=1&search=&category=0&location=-1&status=');
            const unsetLocationCount = unsetRes.success ? Number(unsetRes.total || 0) : 0;
            const locSortMode = getEffectiveListSortMode('locations');
            const sortedLocs = sortListData(App.locations, locSortMode);

            container.innerHTML = `
        <div class="flex items-center justify-between mb-6 anim-up" style="position:relative;z-index:40;">
            <p class="text-sm text-slate-500">共 ${App.locations.length + 1} 个位置</p>
            <div class="flex items-center gap-2">
                <div class="relative">
                    <button onclick="toggleListSortMenu('locationsSortMenu', this)" class="btn btn-ghost btn-sm text-slate-400 hover:text-white transition">
                        <i class="ri-sort-desc mr-1"></i>排序：${getListSortLabel(locSortMode)}
                    </button>
                    <div id="locationsSortMenu" class="list-sort-menu hidden absolute right-0 top-full mt-1 glass rounded-xl p-2 min-w-[180px] z-50 shadow-xl border border-white/[0.06] space-y-1" style="z-index:90;">
                        <button onclick="setListSort('locations','count_desc')" class="w-full text-left px-2 py-1.5 rounded-lg text-xs transition ${locSortMode === 'count_desc' ? 'bg-sky-500/15 text-sky-300' : 'text-slate-300 hover:bg-white/[0.05]'}">按物品数量 多→少</button>
                        <button onclick="setListSort('locations','name_asc')" class="w-full text-left px-2 py-1.5 rounded-lg text-xs transition ${locSortMode === 'name_asc' ? 'bg-sky-500/15 text-sky-300' : 'text-slate-300 hover:bg-white/[0.05]'}">按名称首字母 A→Z</button>
                    </div>
                </div>
                <button onclick="openAddLocation()" class="btn btn-primary btn-sm"><i class="ri-add-line"></i>添加位置</button>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" style="position:relative;z-index:1;">
            <div class="glass glass-hover rounded-2xl p-5 anim-up" style="animation-delay:0ms">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 rounded-xl bg-slate-500/10 flex items-center justify-center"><span class="text-2xl leading-none">📍</span></div>
                    <div>
                        <h3 class="font-semibold text-white">未设定</h3>
                        <p class="text-xs text-slate-500">${unsetLocationCount} 件物品</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button onclick="viewItemsByLocation(-1)" class="btn btn-ghost btn-sm flex-1" style="color:#38bdf8" title="查看物品"><i class="ri-archive-line"></i>物品</button>
                    <button class="btn btn-ghost btn-sm flex-1 opacity-50 cursor-not-allowed" disabled title="系统固定项"><i class="ri-edit-line"></i>编辑</button>
                    <button class="btn btn-danger btn-sm flex-1 opacity-50 cursor-not-allowed" disabled title="系统固定项"><i class="ri-delete-bin-line"></i>删除</button>
                </div>
            </div>
            ${sortedLocs.map((loc, i) => `
                <div class="glass glass-hover rounded-2xl p-5 anim-up" style="animation-delay:${(i + 1) * 40}ms">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 flex items-center justify-center"><span class="text-2xl leading-none">${esc(normalizeEmojiValue(loc.icon, '📍'))}</span></div>
                        <div class="min-w-0 flex-1 h-10 flex flex-col justify-center">
                            <div class="flex items-center gap-2 min-w-0 leading-5">
                                <h3 class="font-semibold text-white truncate max-w-[45%]">${esc(loc.name)}</h3>
                                ${loc.description ? `<p class="text-xs text-slate-500 truncate flex-1 leading-5">${esc(loc.description)}</p>` : `<p class="text-xs text-slate-600 truncate flex-1 leading-5">暂无描述</p>`}
                            </div>
                            <p class="text-xs text-slate-500 leading-5">${loc.item_count} 件物品</p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="viewItemsByLocation(${loc.id})" class="btn btn-ghost btn-sm flex-1" style="color:#38bdf8" title="查看物品"><i class="ri-archive-line"></i>物品</button>
                        <button onclick="editLocation(${loc.id})" class="btn btn-ghost btn-sm flex-1"><i class="ri-edit-line"></i>编辑</button>
                        <button onclick="deleteLocation(${loc.id},'${esc(loc.name)}',${loc.item_count})" class="btn btn-danger btn-sm flex-1"><i class="ri-delete-bin-line"></i>删除</button>
                    </div>
                </div>
            `).join('')}
        </div>
        ${App.locations.length === 0 ? '<div class="empty-state"><i class="ri-map-pin-line"></i><h3 class="text-xl font-semibold text-slate-400">暂无位置</h3></div>' : ''}
    `;
        }

        function viewItemsByCategory(catId) {
            App.itemsFilter = { search: '', category: catId, location: 0, status: '', expiryOnly: false };
            App.itemsPage = 1;
            switchView('items');
        }

        function viewItemsByLocation(locId) {
            App.itemsFilter = { search: '', category: 0, location: locId, status: '', expiryOnly: false };
            App.itemsPage = 1;
            switchView('items');
        }

        function openAddLocation() {
            document.getElementById('locModalTitle').textContent = '添加位置';
            document.getElementById('locId').value = '';
            document.getElementById('locName').value = '';
            setEmojiPickerValue('locEmojiPicker', 'locIcon', '📍', '📍');
            document.getElementById('locDesc').value = '';
            document.getElementById('locationModal').classList.add('show');
        }

        function editLocation(id) {
            const loc = App.locations.find(l => l.id === id);
            if (!loc) return;
            document.getElementById('locModalTitle').textContent = '编辑位置';
            document.getElementById('locId').value = loc.id;
            document.getElementById('locName').value = loc.name;
            setEmojiPickerValue('locEmojiPicker', 'locIcon', loc.icon, '📍');
            document.getElementById('locDesc').value = loc.description || '';
            document.getElementById('locationModal').classList.add('show');
        }

        async function saveLocation(e) {
            e.preventDefault();
            const id = document.getElementById('locId').value;
            const data = {
                id: id ? +id : undefined,
                name: document.getElementById('locName').value.trim(),
                icon: document.getElementById('locIcon').value.trim() || '📍',
                description: document.getElementById('locDesc').value.trim()
            };
            if (!data.name) { toast('请输入位置名称', 'error'); return false; }
            const endpoint = id ? 'locations/update' : 'locations';
            const res = await apiPost(endpoint, data);
            if (res.success) {
                invalidateBaseDataCache();
                toast(id ? '位置已更新' : '位置已添加');
                closeLocationModal();
                renderView();
            } else toast(res.message, 'error');
            return false;
        }

        async function deleteLocation(id, name, count) {
            if (!confirm(`确定删除位置「${name}」？${count > 0 ? `其下 ${count} 件物品将变为未设定位置。` : ''}`)) return;
            const res = await apiPost('locations/delete', { id });
            if (res.success) {
                invalidateBaseDataCache();
                toast('位置已删除');
                renderView();
            } else toast(res.message, 'error');
        }

        function closeLocationModal() {
            document.getElementById('locationModal').classList.remove('show');
            hideEmojiPickerMenus();
        }

        // ============================================================
        // 🔄 数据管理
        // ============================================================
        function renderImportExport(container) {
            container.innerHTML = `
        <div class="max-w-2xl mx-auto space-y-6">
            <div class="glass rounded-2xl p-6 anim-up">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 rounded-xl bg-sky-500/10 flex items-center justify-center"><i class="ri-download-cloud-line text-2xl text-sky-400"></i></div>
                    <div><h3 class="font-semibold text-white text-lg">导出数据</h3><p class="text-sm text-slate-500">将所有物品、分类和位置数据导出为 JSON 文件</p></div>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-400 mb-4 cursor-pointer">
                    <input type="checkbox" id="exportIncludeImages" class="accent-sky-500">
                    <span>同时导出图片数据（文件会更大）</span>
                </label>
                <button onclick="exportData()" class="btn btn-primary w-full"><i class="ri-download-line"></i>导出 JSON 文件</button>
            </div>

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.1s">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 rounded-xl bg-emerald-500/10 flex items-center justify-center"><i class="ri-upload-cloud-line text-2xl text-emerald-400"></i></div>
                    <div><h3 class="font-semibold text-white text-lg">导入数据</h3><p class="text-sm text-slate-500">从之前导出的 JSON 文件中恢复物品数据</p></div>
                </div>
                <button onclick="document.getElementById('importInput').click()" class="btn btn-primary w-full"><i class="ri-upload-line"></i>点击选择 JSON 文件</button>
                <p class="text-xs text-slate-500 mt-3">支持导入包含内置图片数据的备份文件</p>
                <input type="file" id="importInput" class="hidden" accept=".json" onchange="importData(this)">
            </div>

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.2s">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 rounded-xl bg-cyan-500/10 flex items-center justify-center"><i class="ri-file-list-3-line text-2xl text-cyan-400"></i></div>
                    <div><h3 class="font-semibold text-white text-lg">手动批量导入物品</h3><p class="text-sm text-slate-500">下载默认 Excel 模板（CSV），填写后一次性导入多条物品</p></div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <button onclick="downloadManualImportTemplate()" class="btn btn-ghost w-full"><i class="ri-file-download-line"></i>下载默认 Excel 模板</button>
                    <button onclick="document.getElementById('manualImportInput').click()" class="btn btn-primary w-full"><i class="ri-upload-2-line"></i>导入模板文件</button>
                </div>
                <p class="text-xs text-slate-500 mt-3">模板格式为 UTF-8 CSV，可直接用 Excel 打开和编辑</p>
                <input type="file" id="manualImportInput" class="hidden" accept=".csv,text/csv,application/vnd.ms-excel" onchange="importManualItems(this)">
            </div>

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.3s">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 rounded-xl bg-amber-500/10 flex items-center justify-center"><i class="ri-file-excel-line text-2xl text-amber-400"></i></div>
                    <div><h3 class="font-semibold text-white text-lg">导出 CSV</h3><p class="text-sm text-slate-500">导出物品列表为 CSV 格式，方便在 Excel 中查看</p></div>
                </div>
                <button onclick="exportCSV()" class="btn btn-ghost w-full"><i class="ri-file-download-line"></i>导出 CSV 文件</button>
            </div>

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.5s">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 rounded-xl bg-red-500/10 flex items-center justify-center"><i class="ri-delete-bin-6-line text-2xl text-red-400"></i></div>
                    <div><h3 class="font-semibold text-white text-lg">物品数据重置</h3><p class="text-sm text-slate-500">仅清空物品与回收站数据，图片会移动到 uploads/trash，不影响分类/位置和设置</p></div>
                </div>
                <button onclick="resetItemData()" class="btn btn-danger w-full"><i class="ri-delete-bin-5-line"></i>删除所有物品数据</button>
            </div>

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.6s">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 rounded-xl bg-amber-500/10 flex items-center justify-center"><i class="ri-restart-line text-2xl text-amber-400"></i></div>
                    <div><h3 class="font-semibold text-white text-lg">恢复默认</h3><p class="text-sm text-slate-500">恢复整个环境到初始状态（含分类、位置、物品与本地设置，图片将移动到uploads/trash）</p></div>
                </div>
                <button onclick="restoreDefaultEnvironment()" class="btn btn-ghost w-full" style="color:#f59e0b;border-color:rgba(245,158,11,0.35)"><i class="ri-restart-line"></i>恢复默认环境</button>
            </div>
        </div>
    `;
        }

        async function exportData() {
            const res = await api('export');
            if (!res.success) { toast('导出失败', 'error'); return; }
            const payload = { ...res.data };
            const statusMap = getStatusMap();
            const statusLabelByKey = key => (statusMap[key] ? statusMap[key][0] : (key || ''));
            if (Array.isArray(payload.items)) {
                payload.items = payload.items.map(item => ({ ...item, status: statusLabelByKey(item.status) }));
            }
            const includeImages = !!document.getElementById('exportIncludeImages')?.checked;
            if (includeImages) {
                toast('正在打包图片数据，请稍候...');
                const bundled = await buildEmbeddedImages(payload.items || []);
                payload.embedded_images = bundled.images;
                payload.images_included = true;
                payload.images_total = bundled.total;
                payload.images_failed = bundled.failed;
            }
            const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
            downloadBlob(blob, `items_backup_${dateTimeStr()}.json`);
            toast(includeImages ? '导出成功（含图片）' : '导出成功');
        }

        async function exportCSV() {
            const res = await api('export');
            if (!res.success) { toast('导出失败', 'error'); return; }
            const items = res.data.items;
            const statusMap = getStatusMap();
            const statusLabelByKey = key => (statusMap[key] ? statusMap[key][0] : (key || ''));
            const header = ['ID', '名称', '分类', '位置', '数量', '价格', '购入渠道', '购入日期', '过期日期', '条码', '标签', '状态', '备注'];
            const rows = items.map(i => [i.id, i.name, i.category_name || '', i.location_name || '', i.quantity, i.purchase_price, i.purchase_from || '', i.purchase_date, i.expiry_date || '', i.barcode, i.tags, statusLabelByKey(i.status), i.notes || ''].map(csvCell));
            const csv = '\uFEFF' + [header.join(','), ...rows.map(r => r.join(','))].join('\n');
            downloadBlob(new Blob([csv], { type: 'text/csv;charset=utf-8' }), `items_${dateStr()}.csv`);
            toast('CSV 导出成功');
        }

        async function importData(input) {
            const file = input.files[0];
            if (!file) return;
            try {
                const text = await file.text();
                const data = JSON.parse(text);
                if (!data.items && !Array.isArray(data)) { toast('无法识别的数据格式', 'error'); return; }
                const importPayload = data.items ? { ...data } : { items: data };
                const normalizeStatusText = v => String(v || '').trim().toLowerCase().replace(/[\s\-_/\\|,，.。:：;；'"`()\[\]{}（）【】]/g, '');
                const statusCandidates = [];
                App.statuses.forEach(s => {
                    const keyNorm = normalizeStatusText(s.key);
                    const labelNorm = normalizeStatusText(s.label);
                    if (keyNorm)
                        statusCandidates.push({ key: s.key, norm: keyNorm });
                    if (labelNorm)
                        statusCandidates.push({ key: s.key, norm: labelNorm });
                    const mappedKeyFromLabel = STATUS_LABEL_TO_KEY_MAP[s.key] || STATUS_LABEL_TO_KEY_MAP[s.label];
                    if (mappedKeyFromLabel)
                        statusCandidates.push({ key: s.key, norm: normalizeStatusText(mappedKeyFromLabel) });
                    const mappedLabelFromKey = STATUS_KEY_TO_LABEL_MAP[s.key];
                    if (mappedLabelFromKey)
                        statusCandidates.push({ key: s.key, norm: normalizeStatusText(mappedLabelFromKey) });
                });
                const defaultStatus = getDefaultStatusKey();
                const resolveStatusKey = raw => {
                    const key = normalizeStatusText(raw);
                    if (!key)
                        return defaultStatus;
                    for (const c of statusCandidates) {
                        if (c.norm === key)
                            return c.key;
                    }
                    let best = null;
                    let bestScore = -1;
                    for (const c of statusCandidates) {
                        if (!c.norm)
                            continue;
                        if (c.norm.includes(key) || key.includes(c.norm)) {
                            const score = Math.min(c.norm.length, key.length);
                            if (score > bestScore) {
                                bestScore = score;
                                best = c;
                            }
                        }
                    }
                    return best ? best.key : defaultStatus;
                };
                if (Array.isArray(importPayload.items)) {
                    importPayload.items = importPayload.items.map(item => ({
                        ...item,
                        status: resolveStatusKey(item?.status)
                    }));
                }

                const embeddedCount = importPayload.embedded_images ? Object.keys(importPayload.embedded_images).length : 0;
                const imageHint = embeddedCount > 0 ? `，含 ${embeddedCount} 张内置图片` : '';
                if (!confirm(`即将导入 ${importPayload.items.length} 件物品${imageHint}，确认继续？`)) return;
                const res = await apiPost('import', importPayload);
                if (res.success) {
                    invalidateBaseDataCache();
                    toast(res.message);
                    renderView();
                } else toast(res.message, 'error');
            } catch (e) { toast('文件解析失败', 'error'); }
            input.value = '';
        }

        function downloadManualImportTemplate() {
            const header = ['名称', '分类', '位置', '数量', '状态', '购入价格', '购入渠道', '购入日期', '过期日期', '条码/序列号', '标签', '备注'];
            const sample = [
                '示例物品（必填）',
                '电子设备（可选）',
                '书房（可选）',
                '1（可选，默认1）',
                '使用中（可选，默认首个状态）',
                '199.00（可选）',
                '京东（可选）',
                '2026/02/09（可选）',
                '2026/12/31（可选）',
                'SN-001（可选）',
                '示例,批量导入（可选）',
                '这里是备注（可选）'
            ];
            const csv = '\uFEFF' + [header, sample].map(r => r.map(csvCell).join(',')).join('\n');
            downloadBlob(new Blob([csv], { type: 'text/csv;charset=utf-8' }), 'items_manual_import_template.csv');
            toast('模板已下载');
        }

        function parseCSVRows(text) {
            const rows = [];
            let row = [];
            let cell = '';
            let inQuotes = false;
            for (let i = 0; i < text.length; i++) {
                const ch = text[i];
                if (inQuotes) {
                    if (ch === '"') {
                        if (text[i + 1] === '"') {
                            cell += '"';
                            i++;
                        } else {
                            inQuotes = false;
                        }
                    } else {
                        cell += ch;
                    }
                    continue;
                }
                if (ch === '"') {
                    inQuotes = true;
                } else if (ch === ',') {
                    row.push(cell);
                    cell = '';
                } else if (ch === '\n') {
                    row.push(cell);
                    rows.push(row);
                    row = [];
                    cell = '';
                } else if (ch !== '\r') {
                    cell += ch;
                }
            }
            row.push(cell);
            rows.push(row);
            return rows.filter(r => r.some(c => String(c || '').trim() !== ''));
        }

        function normalizedHeaderName(h) {
            return String(h || '').trim().replace(/\s+/g, '').toLowerCase();
        }

        function normalizeDateYMD(value) {
            const raw = String(value || '').trim();
            if (!raw) return '';
            const normalized = raw.replace(/\//g, '-');
            const match = normalized.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
            if (!match) return null;
            const y = Number(match[1]);
            const m = Number(match[2]);
            const d = Number(match[3]);
            const date = new Date(y, m - 1, d);
            if (date.getFullYear() !== y || date.getMonth() !== m - 1 || date.getDate() !== d)
                return null;
            const mm = String(m).padStart(2, '0');
            const dd = String(d).padStart(2, '0');
            return `${y}-${mm}-${dd}`;
        }

        function isValidDateYMD(value) {
            return normalizeDateYMD(value) !== null;
        }

        function showImportPersistentNotice(title, lines = []) {
            const preview = lines.slice(0, 8);
            const more = lines.length > preview.length ? `\n... 另有 ${lines.length - preview.length} 条` : '';
            const msg = `${title}\n${preview.join('\n')}${more}\n（点击右侧 × 手动关闭）`;
            toast(msg, 'error', { persistent: true });
        }

        async function importManualItems(input) {
            const file = input.files[0];
            if (!file) return;
            if (!/\.csv$/i.test(file.name)) {
                toast('请上传 CSV 模板文件', 'error');
                input.value = '';
                return;
            }
            try {
                await loadBaseData();
                const text = (await file.text()).replace(/^\uFEFF/, '');
                const rows = parseCSVRows(text);
                if (rows.length < 2) {
                    toast('模板中没有可导入的数据', 'error');
                    input.value = '';
                    return;
                }

                const headerAlias = {
                    '名称': 'name',
                    'name': 'name',
                    '分类': 'category',
                    'category': 'category',
                    '位置': 'location',
                    'location': 'location',
                    '数量': 'quantity',
                    'quantity': 'quantity',
                    '状态': 'status',
                    'status': 'status',
                    '购入价格': 'purchase_price',
                    '价格': 'purchase_price',
                    'purchaseprice': 'purchase_price',
                    '购入渠道': 'purchase_from',
                    'purchasefrom': 'purchase_from',
                    '购入日期': 'purchase_date',
                    'purchasedate': 'purchase_date',
                    '过期日期': 'expiry_date',
                    '过期时间': 'expiry_date',
                    'expirydate': 'expiry_date',
                    '条码/序列号': 'barcode',
                    '条码': 'barcode',
                    '序列号': 'barcode',
                    'barcode': 'barcode',
                    '标签': 'tags',
                    'tags': 'tags',
                    '备注': 'notes',
                    'notes': 'notes'
                };

                const idx = {};
                rows[0].forEach((raw, i) => {
                    const key = headerAlias[normalizedHeaderName(raw)];
                    if (key && idx[key] === undefined)
                        idx[key] = i;
                });
                if (idx.name === undefined) {
                    toast('模板缺少“名称”列', 'error');
                    input.value = '';
                    return;
                }

                const normalizeMatchText = v => String(v || '').trim().toLowerCase().replace(/[\s\-_/\\|,，.。:：;；'"`()\[\]{}（）【】]/g, '');
                const findFuzzyCandidate = (input, candidates) => {
                    const key = normalizeMatchText(input);
                    if (!key) return null;

                    for (const c of candidates) {
                        if (c.norm === key) return c;
                    }

                    let best = null;
                    let bestScore = -1;
                    for (const c of candidates) {
                        if (!c.norm) continue;
                        if (c.norm.includes(key) || key.includes(c.norm)) {
                            const score = Math.min(c.norm.length, key.length);
                            if (score > bestScore) {
                                bestScore = score;
                                best = c;
                            }
                        }
                    }
                    return best;
                };

                const categoryCandidates = App.categories
                    .map(c => ({ id: c.id, norm: normalizeMatchText(c.name) }))
                    .filter(c => c.norm);
                const locationCandidates = App.locations
                    .map(l => ({ id: l.id, norm: normalizeMatchText(l.name) }))
                    .filter(l => l.norm);
                const statusCandidates = [];
                App.statuses.forEach(s => {
                    const keyNorm = normalizeMatchText(s.key);
                    const labelNorm = normalizeMatchText(s.label);
                    if (keyNorm) statusCandidates.push({ key: s.key, norm: keyNorm });
                    if (labelNorm) statusCandidates.push({ key: s.key, norm: labelNorm });
                    const mappedKeyFromLabel = STATUS_LABEL_TO_KEY_MAP[s.key] || STATUS_LABEL_TO_KEY_MAP[s.label];
                    if (mappedKeyFromLabel)
                        statusCandidates.push({ key: s.key, norm: normalizeMatchText(mappedKeyFromLabel) });
                    const mappedLabelFromKey = STATUS_KEY_TO_LABEL_MAP[s.key];
                    if (mappedLabelFromKey)
                        statusCandidates.push({ key: s.key, norm: normalizeMatchText(mappedLabelFromKey) });
                });
                const purchaseChannelCandidates = App.purchaseChannels
                    .map(ch => ({ value: ch, norm: normalizeMatchText(ch) }))
                    .filter(ch => ch.norm);
                const defaultStatus = getDefaultStatusKey();
                const defaultPurchaseFrom = '';

                const getCell = (row, key) => {
                    const col = idx[key];
                    if (col === undefined)
                        return '';
                    return String(row[col] ?? '').trim();
                };

                const payloadRows = [];
                let skippedEmpty = 0;
                const skippedDateErrors = [];
                for (let i = 1; i < rows.length; i++) {
                    const row = rows[i];
                    const name = getCell(row, 'name');
                    if (!name) {
                        skippedEmpty++;
                        continue;
                    }

                    const qtyRaw = getCell(row, 'quantity');
                    const priceRaw = getCell(row, 'purchase_price').replace(/,/g, '');
                    const qtyParsed = Number.parseInt(qtyRaw, 10);
                    const priceParsed = Number.parseFloat(priceRaw);
                    const purchaseDate = normalizeDateYMD(getCell(row, 'purchase_date'));
                    const expiryDate = normalizeDateYMD(getCell(row, 'expiry_date'));

                    if (purchaseDate === null) {
                        skippedDateErrors.push(`第 ${i + 1} 行：购入日期格式错误（应为 YYYY-MM-DD 或 YYYY/MM/DD，如 2026/2/9）`);
                        continue;
                    }
                    if (expiryDate === null) {
                        skippedDateErrors.push(`第 ${i + 1} 行：过期日期格式错误（应为 YYYY-MM-DD 或 YYYY/MM/DD，如 2026/2/9）`);
                        continue;
                    }

                    const categoryMatch = findFuzzyCandidate(getCell(row, 'category'), categoryCandidates);
                    const locationMatch = findFuzzyCandidate(getCell(row, 'location'), locationCandidates);
                    const statusMatch = findFuzzyCandidate(getCell(row, 'status'), statusCandidates);
                    const purchaseFromMatch = findFuzzyCandidate(getCell(row, 'purchase_from'), purchaseChannelCandidates);

                    payloadRows.push({
                        name,
                        category_id: categoryMatch ? categoryMatch.id : 0,
                        location_id: locationMatch ? locationMatch.id : 0,
                        quantity: Number.isNaN(qtyParsed) ? 1 : Math.max(0, qtyParsed),
                        status: statusMatch ? statusMatch.key : defaultStatus,
                        purchase_price: Number.isNaN(priceParsed) ? 0 : priceParsed,
                        purchase_from: purchaseFromMatch ? purchaseFromMatch.value : defaultPurchaseFrom,
                        purchase_date: purchaseDate,
                        expiry_date: expiryDate,
                        barcode: getCell(row, 'barcode'),
                        tags: getCell(row, 'tags'),
                        notes: getCell(row, 'notes')
                    });
                }

                if (payloadRows.length === 0) {
                    if (skippedDateErrors.length > 0) {
                        showImportPersistentNotice('没有可导入的数据行，以下记录被跳过：', skippedDateErrors);
                    } else {
                        toast('没有可导入的数据行', 'error');
                    }
                    input.value = '';
                    return;
                }

                const hintParts = [];
                if (skippedEmpty > 0)
                    hintParts.push(`另有 ${skippedEmpty} 行名称为空将被忽略`);
                if (skippedDateErrors.length > 0)
                    hintParts.push(`另有 ${skippedDateErrors.length} 行日期格式错误将被跳过`);
                const hint = hintParts.length > 0 ? `（${hintParts.join('；')}）` : '';
                if (!confirm(`即将批量导入 ${payloadRows.length} 件物品${hint}，确认继续？`)) {
                    input.value = '';
                    return;
                }

                const res = await apiPost('items/batch-import-manual', { rows: payloadRows });
                if (!res.success) {
                    toast(res.message || '批量导入失败', 'error');
                } else {
                    App.selectedItems.clear();
                    App._cachedItems = null;
                    App._cachedTotal = 0;
                    App._cachedPages = 0;
                    toast(res.message || '批量导入成功');
                    const notices = [];
                    if (skippedDateErrors.length > 0)
                        notices.push(...skippedDateErrors);
                    if (Array.isArray(res.errors) && res.errors.length > 0)
                        notices.push(...res.errors);
                    if (notices.length > 0)
                        showImportPersistentNotice('以下记录已跳过，请修正后重试：', notices);
                    renderView();
                }
            } catch (e) {
                toast('批量导入失败：文件解析错误', 'error');
            }
            input.value = '';
        }

        // ---------- 工具函数 ----------
        function esc(str) { if (!str) return ''; const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
        function csvCell(v) { return `"${String(v || '').replace(/"/g, '""')}"`; }
        function dateStr() { return new Date().toISOString().slice(0, 10); }
        function dateTimeStr() {
            const d = new Date();
            const pad = n => String(n).padStart(2, '0');
            return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}_${pad(d.getHours())}-${pad(d.getMinutes())}-${pad(d.getSeconds())}`;
        }
        function downloadBlob(blob, name) { const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(a.href); }
        function blobToDataURL(blob) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result);
                reader.onerror = () => reject(new Error('read failed'));
                reader.readAsDataURL(blob);
            });
        }
        async function buildEmbeddedImages(items) {
            const names = [...new Set(items.map(i => (i.image || '').trim()).filter(Boolean))];
            const images = {};
            let failed = 0;
            for (const name of names) {
                try {
                    const resp = await fetch(`?img=${encodeURIComponent(name)}`);
                    if (!resp.ok) { failed++; continue; }
                    const blob = await resp.blob();
                    images[name] = await blobToDataURL(blob);
                } catch {
                    failed++;
                }
            }
            return { images, total: names.length, failed };
        }

        // ---------- 复制物品 ----------
        async function copyItem(id) {
            const res = await api(`items&page=1&limit=999`);
            if (!res.success) return;
            const item = res.data.find(i => i.id === id);
            if (!item) { toast('物品不存在', 'error'); return; }

            // 打开添加表单并填入被复制物品的数据（不含 ID，图片保留引用）
            document.getElementById('itemModalTitle').textContent = '复制物品';
            document.getElementById('itemId').value = '';  // 无 ID = 新建
            document.getElementById('itemSourceShoppingId').value = '';
            document.getElementById('itemName').value = item.name + ' (副本)';
            const copyQty = Math.max(0, Number(item.quantity || 0), Number(item.remaining_total || 0));
            document.getElementById('itemQuantity').value = copyQty;
            document.getElementById('itemRemainingCurrent').value = Number(item.remaining_total || 0) > 0
                ? String(Math.min(copyQty, Math.max(0, Number(item.remaining_current || 0))))
                : '';
            document.getElementById('itemPrice').value = item.purchase_price;
            document.getElementById('itemDate').value = item.purchase_date;
            document.getElementById('itemExpiry').value = item.expiry_date || '';
            document.getElementById('itemReminderDate').value = item.reminder_date || '';
            document.getElementById('itemReminderEvery').value = item.reminder_cycle_value || 1;
            document.getElementById('itemReminderUnit').value = ['day', 'week', 'year'].includes(item.reminder_cycle_unit) ? item.reminder_cycle_unit : 'day';
            document.getElementById('itemReminderNext').value = item.reminder_next_date || item.reminder_date || '';
            document.getElementById('itemReminderNote').value = item.reminder_note || '';
            document.getElementById('itemBarcode').value = item.barcode;
            document.getElementById('itemTags').value = item.tags;
            document.getElementById('itemImage').value = item.image || '';
            document.getElementById('itemNotes').value = item.notes || '';
            document.getElementById('itemSharePublic').checked = Number(item.is_public_shared || 0) === 1;
            syncReminderFields();

            resetUploadZone();
            if (item.image) {
                document.getElementById('uploadPreview').src = `?img=${item.image}`;
                document.getElementById('uploadPreview').classList.remove('hidden');
                document.getElementById('uploadPlaceholder').classList.add('hidden');
                document.getElementById('uploadZone').classList.add('has-image');
            }

            await populateSelects({
                status: item.status,
                purchaseFrom: item.purchase_from || '',
                categoryId: Number(item.category_id || 0),
                subcategoryId: Number(item.subcategory_id || 0)
            });
            document.getElementById('itemLocation').value = item.location_id;
            document.getElementById('itemModal').classList.add('show');
            setItemSubmitLabel('保存');
            refreshDateInputPlaceholderDisplay(document.getElementById('itemForm'));
            closeItemUnsavedConfirm();
            markItemFormClean();
            toast('已复制物品资料，请确认后保存');
        }

        // ---------- 排序工具 ----------
        function sortCategoryStats(arr) {
            const mode = App.sortSettings.dashboard_categories;
            const sorted = [...arr];
            if (mode === 'name_asc') sorted.sort((a, b) => a.name.localeCompare(b.name, 'zh'));
            else if (mode === 'total_qty_desc') sorted.sort((a, b) => b.total_qty - a.total_qty);
            else sorted.sort((a, b) => b.count - a.count); // count_desc (default)
            return sorted;
        }

        function sortListData(arr, mode, countField = 'item_count') {
            const sorted = [...arr];
            if (mode === 'name_asc') sorted.sort((a, b) => a.name.localeCompare(b.name, 'zh'));
            else if (mode === 'count_desc') sorted.sort((a, b) => (b[countField] || 0) - (a[countField] || 0));
            // 'custom' = 保持原排序 (sort_order)
            return sorted;
        }
        function getEffectiveListSortMode(target) {
            const key = target === 'locations' ? 'locations_list' : 'categories_list';
            const current = App.sortSettings[key];
            if (current === 'count_desc' || current === 'name_asc')
                return current;
            return defaultSortSettings[key] || 'count_desc';
        }
        function getListSortLabel(mode) {
            if (mode === 'count_desc')
                return '数量多→少';
            if (mode === 'name_asc')
                return '名称 A→Z';
            return '数量多→少';
        }
        function toggleListSortMenu(id, btn) {
            const menu = document.getElementById(id);
            if (!menu) return;
            document.querySelectorAll('.list-sort-menu').forEach(m => {
                if (m.id !== id) m.classList.add('hidden');
            });
            menu.classList.toggle('hidden');
            if (!menu.classList.contains('hidden')) {
                const closeHandler = (e) => {
                    if (!menu.contains(e.target) && (!btn || !btn.contains(e.target))) {
                        menu.classList.add('hidden');
                        document.removeEventListener('click', closeHandler);
                    }
                };
                setTimeout(() => document.addEventListener('click', closeHandler), 0);
            }
        }
        function setListSort(target, mode) {
            const next = { ...App.sortSettings };
            if (target === 'categories')
                next.categories_list = mode;
            else if (target === 'locations')
                next.locations_list = mode;
            saveSortSettings(next);
            renderView();
        }

        // ---------- 回收站 ----------
        async function renderTrash(container) {
            const res = await api('trash');
            if (!res.success) { container.innerHTML = '<p class="text-red-400 p-6">加载失败</p>'; return; }
            const items = res.data || [];
            const count = items.length;

            container.innerHTML = `
        <div class="space-y-6">
            <div class="mb-4 anim-up">
                <button onclick="switchView('items')" class="btn btn-ghost btn-sm text-slate-400 hover:text-sky-400 transition">
                    <i class="ri-arrow-left-line mr-1"></i>返回物品管理
                </button>
            </div>
            <div class="glass rounded-2xl p-6 anim-up">
                <div class="flex flex-wrap items-center justify-between gap-4 mb-5">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-red-400/20 to-orange-400/20 flex items-center justify-center">
                            <i class="ri-delete-bin-line text-red-400 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="font-semibold text-white">回收站</h3>
                            <p class="text-xs text-slate-400">共 ${count} 个物品</p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        ${count > 0 ? `
                        <button onclick="trashRestoreAll()" class="btn btn-ghost text-sm" style="color:#38bdf8">
                            <i class="ri-arrow-go-back-line mr-1"></i>全部恢复
                        </button>
                        <button onclick="trashEmptyAll()" class="btn btn-danger text-sm">
                            <i class="ri-delete-bin-7-line mr-1"></i>清空回收站
                        </button>` : ''}
                    </div>
                </div>
                ${count === 0 ? `
                <div class="text-center py-16">
                    <i class="ri-delete-bin-line text-5xl text-slate-600 mb-4 block"></i>
                    <p class="text-slate-400 text-lg mb-2">回收站是空的</p>
                    <p class="text-slate-500 text-sm">删除的物品会出现在这里</p>
                </div>` : `
                <div class="space-y-3">
                    ${items.map(item => {
                const imgSrc = item.image ? 'data/uploads/trash/' + item.image : '';
                const deletedAt = item.deleted_at || '';
                return `
                    <div class="flex items-center gap-4 p-4 rounded-xl bg-white/[0.03] hover:bg-white/[0.06] border border-white/[0.04] transition group cursor-pointer" onclick="showTrashDetail(${item.id})">
                        <div class="w-14 h-14 rounded-xl overflow-hidden flex-shrink-0 bg-white/[0.03] flex items-center justify-center">
                            ${imgSrc ? `<img src="${imgSrc}" class="w-full h-full object-cover" onerror="this.parentNode.innerHTML='<i class=\\'ri-image-line text-2xl text-slate-600\\'></i>'">` : `<i class="ri-archive-line text-2xl text-slate-600"></i>`}
                        </div>
                        <div class="flex-1 min-w-0">
                            <h4 class="font-medium text-white truncate">${esc(item.name)}</h4>
                            <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-400 mt-1">
                                ${item.category_name ? `<span><i class="ri-price-tag-3-line mr-1"></i>${esc(item.category_name)}</span>` : ''}
                                ${item.location_name ? `<span><i class="ri-map-pin-line mr-1"></i>${esc(item.location_name)}</span>` : ''}
                                <span><i class="ri-stack-line mr-1"></i>${item.quantity}件</span>
                                ${deletedAt ? `<span class="text-red-400/70"><i class="ri-time-line mr-1"></i>删除于 ${deletedAt}</span>` : ''}
                            </div>
                        </div>
                        <div class="flex gap-2 flex-shrink-0 opacity-60 group-hover:opacity-100 transition">
                            <button onclick="event.stopPropagation();trashRestore(${item.id})" class="btn btn-ghost btn-sm" style="color:#38bdf8" title="恢复">
                                <i class="ri-arrow-go-back-line"></i>恢复
                            </button>
                            <button onclick="event.stopPropagation();trashPermanentDelete(${item.id},'${esc(item.name)}')" class="btn btn-danger btn-sm" title="彻底删除">
                                <i class="ri-close-circle-line"></i>删除
                            </button>
                        </div>
                    </div>`;
            }).join('')}
                </div>`}
            </div>
        </div>`;
        }

        async function showTrashDetail(id) {
            const res = await api('trash');
            if (!res.success) return;
            const item = res.data.find(i => i.id === id);
            if (!item) { toast('物品不存在', 'error'); return; }

            const statusMap = getStatusMap();
            const [statusLabel, statusClass, statusIcon] = statusMap[item.status] || ['未知', 'badge-archived', 'ri-question-line'];
            const imgSrc = item.image ? 'data/uploads/trash/' + item.image : '';

            document.getElementById('detailContent').innerHTML = `
        ${imgSrc ? `<img src="${imgSrc}" class="w-full h-56 object-cover rounded-t-2xl" alt="" onerror="this.style.display='none'">` : `<div class="w-full h-40 bg-slate-800 flex items-center justify-center rounded-t-2xl"><i class="ri-archive-line text-5xl text-slate-600"></i></div>`}
        <div class="p-6">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <h2 class="text-2xl font-bold text-white mb-1">${esc(item.name)}</h2>
                    <div class="flex items-center gap-2">
                        <span class="badge ${statusClass}"><i class="${statusIcon} mr-1"></i>${statusLabel}</span>
                        <span class="badge bg-red-500/10 text-red-400"><i class="ri-delete-bin-line mr-1"></i>已删除</span>
                    </div>
                </div>
                <button onclick="closeDetailModal()" class="text-slate-400 hover:text-white transition"><i class="ri-close-line text-2xl"></i></button>
            </div>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">分类</p><p class="text-sm text-white">${item.category_icon || '📦'} ${esc(item.category_name || '未分类')}${item.subcategory_name ? ` <span class="text-slate-500">/</span> <span class="text-cyan-300">${esc(item.subcategory_name)}</span>` : ''}</p></div>
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">位置</p><p class="text-sm text-white"><i class="ri-map-pin-2-line text-xs mr-1"></i>${esc(item.location_name || '未设定')}</p></div>
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">数量</p><p class="text-sm text-white">${item.quantity}</p></div>
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">价值</p><p class="text-sm text-amber-400 font-medium">¥${Number(item.purchase_price || 0).toLocaleString()}</p></div>
                ${item.purchase_date ? `<div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">购入日期</p><p class="text-sm text-white">${item.purchase_date}</p></div>` : ''}
                ${item.expiry_date ? `<div class="p-3 rounded-xl ${expiryBg(item.expiry_date)}"><p class="text-xs text-slate-500 mb-1">过期日期</p><p class="text-sm font-medium ${expiryColor(item.expiry_date)}">${item.expiry_date} ${expiryLabel(item.expiry_date)}</p></div>` : ''}
                ${item.purchase_from ? `<div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">购入渠道</p><p class="text-sm text-white">${esc(item.purchase_from)}</p></div>` : ''}
                ${item.barcode ? `<div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">条码/序列号</p><p class="text-sm text-white font-mono">${esc(item.barcode)}</p></div>` : ''}
                <div class="p-3 rounded-xl bg-red-500/5"><p class="text-xs text-slate-500 mb-1">删除时间</p><p class="text-sm text-red-400">${item.deleted_at || '-'}</p></div>
            </div>
            ${item.notes ? `<div class="mb-4"><p class="text-xs text-slate-500 mb-1">备注</p><p class="text-sm text-slate-400 whitespace-pre-wrap">${esc(item.notes)}</p></div>` : ''}
            ${item.tags ? `<div class="mb-4"><p class="text-xs text-slate-500 mb-2">标签</p><div class="flex flex-wrap gap-2">${item.tags.split(',').map(t => `<span class="badge bg-white/5 text-slate-300">${esc(t.trim())}</span>`).join('')}</div></div>` : ''}
            <div class="text-xs text-slate-600 mt-4 pt-4 border-t border-white/5">
                创建: ${item.created_at} &nbsp;|&nbsp; 更新: ${item.updated_at}
            </div>
            <div class="flex gap-3 mt-4">
                <button onclick="closeDetailModal();trashRestore(${item.id})" class="btn btn-primary flex-1"><i class="ri-arrow-go-back-line"></i>恢复物品</button>
                <button onclick="closeDetailModal();trashPermanentDelete(${item.id},'${esc(item.name)}')" class="btn btn-danger flex-1"><i class="ri-close-circle-line"></i>彻底删除</button>
            </div>
        </div>
    `;
            document.getElementById('detailModal').classList.add('show');
        }

        async function trashRestore(id) {
            const res = await apiPost('trash/restore', { id });
            if (res.success) { toast('物品已恢复'); renderView(); }
        }

        async function trashPermanentDelete(id, name) {
            if (!confirm(`确定要彻底删除「${name}」吗？此操作不可撤销，图片也将被永久删除。`)) return;
            const res = await apiPost('trash/permanent-delete', { id });
            if (res.success) { toast('已彻底删除'); renderView(); }
        }

        async function trashRestoreAll() {
            if (!confirm('确定要恢复回收站中的所有物品吗？')) return;
            const res = await api('trash');
            if (res.success && res.data.length > 0) {
                const ids = res.data.map(i => i.id);
                const r = await apiPost('trash/batch-restore', { ids });
                if (r.success) { toast('全部物品已恢复'); renderView(); }
            }
        }

        async function trashEmptyAll() {
            if (!confirm('⚠️ 确定要清空回收站吗？所有物品及其图片将被永久删除，此操作不可撤销！')) return;
            const res = await apiPost('trash/empty', {});
            if (res.success) { toast('回收站已清空'); renderView(); }
        }
 
        // ---------- 更新记录数据 ----------
        const CHANGELOG = [
            {
                version: 'v1.6.2', date: '2026-02-20', title: '帮助模式默认开启',
                changes: [
                    '帮助模式改为默认开启：首次进入即可在字段名后看到问号提示，降低上手门槛',
                    '顶部“菜单”展示当前登录用户名，并统一承载帮助模式开关与退出登录',
                    '帮助提示定位与换行策略优化，编辑物品左侧字段提示不再溢出遮挡',
                    '帮助文案改为更适合零基础用户的混合版表达，字段解释更直白',
                    '设置二级菜单中“帮助文档”持续位于“更新记录”上方，查阅路径更稳定',
                    '提醒相关示例统一强调“循环提醒初始日期 + 循环频率 = 下次提醒日期”'
                ]
            },
            {
                version: 'v1.6.1', date: '2026-02-19', title: '设置体验优化 + 页面响应提升',
                changes: [
                    '通用设置结构重整：按“仪表盘相关 / 列表页面相关”分组展示，查找更直观',
                    '提醒能力整合：余量提醒阈值并入通用设置，避免多入口来回切换',
                    '设置项顺序优化：仪表盘组内按“提醒显示范围 → 分类统计排序 → 余量提醒阈值”排列',
                    '用户操作日志优化：仅显示业务描述，不再展示“当前返回多少条”',
                    '设置变更日志优化：只记录实际改动项，避免未改动项重复出现',
                    '下拉筛选与编辑表单的交互更顺滑，长时间停留页面时资源占用更稳定',
                    '分类与位置等基础数据加载策略优化，频繁切换页面时等待更少',
                    '物品详情打开流程优化，减少无效加载带来的等待'
                ]
            },
            {
                version: 'v1.6.0', date: '2026-02-19', title: '仪表盘管理上线：提醒范围可配置 + 展示自适应优化',
                changes: [
                    '设置页新增“仪表盘管理”，可分别配置过期提醒与备忘提醒的显示时间范围',
                    '过期提醒支持配置“过期 X 天到未来 X 天”；输入留空可按需改为不限制',
                    '备忘提醒支持配置“过期 X 天到未来 X 天”；输入留空可按需改为不限制',
                    '默认范围更新为：过期提醒“过期不限制，未来 60 天”；备忘提醒“过期不限制，未来 3 天”',
                    '仪表盘提醒卡片网格升级为自适应铺满：仅在可容纳新卡片时自动增列，减少右侧空白',
                    '提醒时间文案优化为单行显示，避免“已过期 X 天”在窄卡片中换行影响阅读'
                ]
            },
            {
                version: 'v1.5.2', date: '2026-02-19', title: '账号体验升级：注册双态提示 + 自定义验证问题',
                changes: [
                    '注册页新增“开放注册/暂未开放”双态提示，登录页与注册页提示语分开显示，信息更清晰',
                    '平台关闭注册时，仍保留“注册”入口，但创建账号按钮会禁用并显示关闭说明',
                    '注册关闭时不再展示用户名、密码等注册输入框，避免无效填写',
                    '注册验证问题新增“自定义问题”，可自行填写问题与答案，找回密码时可直接显示该问题',
                    '用户管理卡片新增每位成员的操作日志条数，便于管理员快速判断活跃度'
                ]
            },
            {
                version: 'v1.5.1', date: '2026-02-18', title: '分类与位置体验升级：二级分类联动 + Emoji 图标分组 + 移动端优化',
                changes: [
                    '新增默认一级分类“食物”，并补齐常用一级分类的预设二级分类，开箱即可直接使用',
                    '二级分类升级为独立物品属性，在“编辑物品”和“已购买入库”流程中都可填写',
                    '二级分类与一级分类联动，只显示当前一级分类下的可选项，减少误选',
                    '分类管理升级为一对多可视化视图，可直接查看一级分类与其二级分类关系',
                    '分类图标改为可展开的分组 Emoji 选择面板，图标选择更直观',
                    '位置图标统一改为 Emoji 展示，列表、筛选和编辑流程保持一致',
                    '位置编辑弹窗新增分组 Emoji 选择能力，与分类编辑体验统一',
                    '公共频道“加入购物清单”流程优化，加入动作更稳定，备注文案更清晰（如“1件”）',
                    '公共频道权限体验优化：可清楚区分“仅发布者可编辑”与“其他用户可查看/评论”',
                    '公共频道数据隔离优化，避免不同账号之间出现错误穿透',
                    '移动端体验优化：日期输入框尺寸统一，分类管理与物品管理关键操作按钮改为纵向排布'
                ]
            },
            {
                version: 'v1.5.0', date: '2026-02-16', title: '公共频道升级：发布者编辑 + 推荐理由 + 评论协作',
                changes: [
                    '新增公共频道编辑能力：共享物品卡片支持“编辑”，仅发布者可修改名称、分类、购入价格、购入渠道与推荐理由',
                    '公共频道新增“推荐理由”展示，帮助其他人更快判断是否值得购买',
                    '新增公共频道评论能力：所有用户都可以发表评论，支持多人互动',
                    '新增评论删除能力：仅评论者本人或管理员可删除评论，评论区更可控',
                    '系统会根据身份自动显示可执行操作，减少误操作',
                    '共享物品加入购物清单时会自动带上推荐理由，后续回看更直观',
                    '共享物品下架后，相关评论会同步清理，公共频道保持整洁',
                    '共享信息编辑流程更集中，维护公共频道内容更高效',
                    '侧边栏信息架构微调：公共频道、位置管理、分类管理与设置分组顺序优化'
                ]
            },
            {
                version: 'v1.4.0', date: '2026-02-12', title: '多用户登录与管理',
                changes: [
                    '新增账号体系：支持登录/注册/退出登录，每位用户只看到自己的物品数据',
                    '新增管理员角色与默认管理员账号（admin），支持历史账号自动升级为管理员',
                    '注册流程新增验证问题与答案，用于后续密码找回',
                    '新增“忘记密码”流程：先查询验证问题，再校验答案并重置密码',
                    '新增管理员“用户管理”页面：查看用户、角色、物品种类数/总件数、最近登录时间，并可重置用户密码'
                ]
            },
            {
                version: 'v1.3.0', date: '2026-02-11', title: '购物清单增强 + 备忘提醒重构 + 交互统一',
                changes: [
                    '新增购物清单模块，支持预算、优先级、提醒日期与提醒备注',
                    '仪表盘「循环提醒」更名为「备忘提醒」，合并展示循环提醒与购物清单提醒',
                    '备忘提醒中的购物清单项支持「查看清单」直达并自动打开对应编辑弹窗',
                    '编辑清单弹窗新增左下角「已购买入库」按钮，可直接进入该条目的入库流程',
                    '入库流程与物品编辑体验保持一致，提交后会自动移除对应清单项',
                    '购物清单新增状态字段（待购买/待收货），并按状态分组显示（待购买在上）',
                    '编辑清单新增状态切换按钮（已购买/待购买），点击后自动保存并关闭弹窗',
                    '待收货分组为空时不再显示“暂无待收货清单”占位文案',
                    '循环提醒支持待完成、已完成、撤销三种操作，处理更灵活',
                    '点击「待完成」后状态变为「已完成」，并自动生成下一次提醒记录',
                    '已完成状态新增「撤销」，可回滚为待完成并撤销对应生成的下一条提醒记录',
                    '物品编辑支持手动修改下次提醒日期，循环提醒字段布局与顺序统一优化',
                    '日期输入空值统一占位为 ____年/__月/__日，并修复空值/有值切换时输入框尺寸跳动',
                    '优化位置管理描述显示、购物清单提醒备注单行截断、浅色模式中尺寸卡片操作区视觉',
                    '修复浅色模式状态管理中图标下拉菜单背景过深问题，提升可读性',
                    '优化浅色模式下“查看清单/待完成/已完成/撤销”按钮文字与边框对比',
                    '优化仪表盘过期提醒与备忘提醒卡片在深浅色模式下的配色协调性',
                    '仪表盘备忘提醒新增分项统计（过期/循环/购物），分类统计与状态统计统一单位“件”'
                ]
            },
            {
                version: 'v1.2.0', date: '2026-02-09', title: '数据管理增强 + 批量导入完善 + 仪表盘优化',
                changes: [
                    '设置菜单中的「导入/导出」统一改名为「数据管理」',
                    '新增「物品数据重置」与「恢复默认环境」两项能力',
                    '重置或恢复默认时，历史图片会先进入回收区，降低误删风险',
                    '新增购入渠道管理（默认：淘宝/京东/拼多多/闲鱼/官方渠道/线下/礼品），表单改为下拉选择',
                    '移除位置上下级功能，位置管理统一为单级结构',
                    '分类管理固定显示「未分类」、位置管理固定显示「未设定」，并支持一键查看对应物品',
                    '物品管理过滤器新增「未分类 / 未设定」选项，便于筛出未绑定分类或位置的物品',
                    '物品管理新增「过期管理」过滤按钮，一键筛选带过期日期的物品',
                    '物品管理搜索栏支持属性关键词检索（分类/位置/购入渠道/备注/状态等），支持搜索按钮和 Enter 触发',
                    '物品排序新增名称 Z-A、价格低→高、数量少→多、最早更新/添加、过期日期近→远与远→近（空过期日期自动置后）',
                    '分类管理与位置管理新增排序按钮；下拉层级遮挡问题已修复，并默认跟随系统排序设置',
                    '导出文件名精确到秒，并支持按需导出图片',
                    '导入时可同时恢复已导出的图片内容',
                    '新增手动批量导入（CSV 模板），模板示例明确必填与可选项',
                    '批量导入日期支持多种常见写法，错误行会自动跳过并提示',
                    '导入时分类/位置/购入渠道/状态支持模糊匹配已有值，不存在时自动回退默认值',
                    '仪表盘新增状态统计；分类统计可直接看到未分类件数，并聚焦在使用中的物品',
                    '仪表盘「过期提醒」「状态统计」在无数据时也保持显示空态，不再整块隐藏',
                    '浅色模式下优化过期提醒卡片与时间文字、分类进度条背景，降低突兀感',
                    '状态图标选择器升级为可视化下拉（图标 + 名称）'
                ]
            },
            {
                version: 'v1.1.0', date: '2026-02-08', title: '核心功能完善与交互优化',
                changes: [
                    '新增过期日期字段、过期提醒板块与三级过期视觉状态',
                    '新增排序设置（仪表盘/物品/分类/位置）并持久化保存',
                    '新增复制物品、一键从分类/位置跳转筛选物品',
                    '新增回收站（软删除、恢复、彻底删除、清空）与回收站详情',
                    '侧边栏设置菜单重构，更新记录独立页面，Logo 旁显示版本号',
                    '仪表盘与最近更新区域布局优化，物品视图支持大/中/小尺寸切换',
                    '物品管理支持按状态分组显示，空状态组自动隐藏',
                    '新增状态管理（新增/删除）并支持编辑状态名称、图标、颜色',
                    '新增属性显示控制（分类/位置/件数/价格/过期日期/购入渠道/备注）',
                    '新增购入渠道与备注字段，物品表单布局优化为 3 列',
                    '新增筛选栏重置按钮与属性按钮样式优化',
                    '列表切换与编辑流程更顺滑，并尽量保持当前浏览位置',
                    '状态管理支持编辑已有状态（名称、图标、颜色）',
                    '物品卡片中件数显示位置调整到分类前面，并修复部分显示与编辑回填问题',
                ]
            },
            {
                version: 'v1.0.0', date: '2026-02-08', title: '初始版本发布',
                changes: [
                    '完整的物品增删改查功能',
                    '仪表盘统计面板 + 分类进度条',
                    '分类管理（Emoji 图标 + 自定义颜色）',
                    '位置管理（单级结构）',
                    '图片上传与预览',
                    '全局搜索 + 多维度筛选 + 多种排序',
                    '数据导出（JSON/CSV）与导入',
                    '深色/浅色主题切换',
                    '全响应式布局 + 毛玻璃风格界面'
                ]
            }
        ];
        const APP_VERSION = CHANGELOG[0].version;
        const HELP_DOC_QUICK_START = [
            '右上角用户名菜单里的「帮助模式」默认已开启，看到字段名后的 ?，鼠标悬停即可查看说明。',
            '先进入「分类管理」和「位置管理」，补齐你家里常用的分类与存放位置。',
            '在「状态管理」「购入渠道管理」里先把常用选项配好，后续录入会更快。',
            '点击右上角「添加物品」，建议按“名称 → 分类/位置 → 余量/数量 → 价格/渠道”顺序填写。',
            '要用循环提醒时，先填「循环提醒初始日期」，再填「循环频率」，系统会自动算出「下次提醒日期」。',
            '需要采购时先记到「购物清单」，买完后点「已购买入库」可直接转成物品。',
            '多人协作时勾选「共享到公共频道」，其他成员可查看、评论并加入自己的购物清单。',
            '定期到「数据管理」做导出备份，重置或恢复默认环境前先备份。'
        ];
        const HELP_DOC_FEATURES = [
            { name: '仪表盘', desc: '查看总量、分类统计、过期提醒、备忘提醒和低余量提醒。' },
            { name: '物品管理', desc: '添加、编辑、删除物品，支持筛选、排序、复制和回收站。' },
            { name: '购物清单', desc: '记录待买和待收货商品，设置优先级、预算和提醒，并可一键入库。' },
            { name: '任务清单', desc: '多人任务协作，支持待办/完成切换、编辑、删除。' },
            { name: '公共频道', desc: '分享推荐物品、填写推荐理由、评论互动，并可加入自己的购物清单。' },
            { name: '分类管理', desc: '维护一级/二级分类、图标和颜色，方便统一管理。' },
            { name: '位置管理', desc: '维护存放位置、图标与描述，支持按位置追踪物品。' },
            { name: '数据管理', desc: '支持导入导出、批量模板导入、重置物品数据、恢复默认环境。' },
            { name: '帮助模式', desc: '默认开启，字段名后会显示问号，悬停即可查看该字段的用途说明。' },
            { name: '设置中心', desc: '统一设置排序、提醒范围、余量阈值、状态、渠道与平台配置。' }
        ];
        const HELP_DOC_FIELD_GROUPS = [
            {
                title: '物品字段（物品管理 / 添加物品）',
                icon: 'ri-archive-line',
                fields: [
                    { name: '物品名称（必填）', desc: '给物品起一个你一眼能认出的名字。' },
                    { name: '分类 / 二级分类', desc: '先选大类，再按需要选小类；不选二级分类也可以。' },
                    { name: '位置', desc: '填物品放在哪里，例如“厨房上柜”“书房抽屉”。' },
                    { name: '状态', desc: '表示当前情况，例如“使用中”“已归档”。' },
                    { name: '余量 / 数量', desc: '数量=总共有多少，余量=现在还剩多少；例如买 10 个还剩 3 个，就填 3 / 10。' },
                    { name: '购入价格', desc: '购买价格，方便后续比价和预算回顾。' },
                    { name: '购入渠道', desc: '在哪里买的，方便下次复购。' },
                    { name: '购入日期', desc: '什么时候买的，不确定可留空。' },
                    { name: '过期日期', desc: '填写后会自动进入到期提醒。' },
                    { name: '条码/序列号', desc: '用于盘点、对账或售后，可不填。' },
                    { name: '循环提醒初始日期', desc: '第一次提醒从哪一天开始算；留空=不开启循环提醒（例如填“滤芯安装日”）。' },
                    { name: '循环频率（每 X 天/周/年）', desc: '这个频率是基于“循环提醒初始日期”来计算下次提醒日期的。' },
                    { name: '下次提醒日期', desc: '本次即将提醒的日期，通常由系统自动生成和更新，也可以手动改。' },
                    { name: '循环提醒备注', desc: '提醒弹出时要做什么，例如“更换滤芯”。' },
                    { name: '标签（逗号分隔）', desc: '多个关键词用逗号分隔，便于快速搜索。' },
                    { name: '备注', desc: '其他补充信息都可以写这里。' },
                    { name: '图片', desc: '上传物品照片或票据，方便识别和回看。' },
                    { name: '共享到公共频道', desc: '勾选后会分享给其他成员查看。' }
                ]
            },
            {
                title: '购物清单字段（购物清单 / 添加清单）',
                icon: 'ri-shopping-cart-2-line',
                fields: [
                    { name: '名称（必填）', desc: '写你准备购买的商品名称。' },
                    { name: '计划数量', desc: '计划买几件。' },
                    { name: '状态', desc: '待购买=还没下单；待收货=已下单但还没到货。' },
                    { name: '优先级', desc: '高优先表示更急，建议先买。' },
                    { name: '预算单价', desc: '预计单价，用来估算总预算。' },
                    { name: '提醒日期', desc: '到了这一天系统会提醒你处理这条清单。' },
                    { name: '提醒备注', desc: '提醒时显示的补充说明。' },
                    { name: '备注', desc: '可记录品牌、型号、链接、比价结论。' }
                ]
            },
            {
                title: '分类与位置字段',
                icon: 'ri-price-tag-3-line',
                fields: [
                    { name: '分类名称（必填）', desc: '分类显示名称，建议用常用叫法。' },
                    { name: '上级分类', desc: '不选是一级分类；选了就是该上级下的二级分类。' },
                    { name: '分类图标 / 颜色', desc: '只影响界面显示，方便快速识别。' },
                    { name: '位置名称（必填）', desc: '存放地点名称，建议尽量具体。' },
                    { name: '位置图标', desc: '用于界面展示和筛选识别。' },
                    { name: '位置描述', desc: '补充说明位置细节，例如“柜子第二层右侧”。' }
                ]
            },
            {
                title: '公共频道字段',
                icon: 'ri-broadcast-line',
                fields: [
                    { name: '物品名称 / 分类', desc: '共享后别人先看到的基础信息。' },
                    { name: '购入价格 / 购入渠道', desc: '给其他成员做比价和购买参考。' },
                    { name: '推荐理由', desc: '说明你为什么推荐这件物品。' },
                    { name: '评论内容', desc: '成员交流用，评论者本人或管理员可删除评论。' }
                ]
            },
            {
                title: '设置字段（通用设置 / 平台设置）',
                icon: 'ri-settings-3-line',
                fields: [
                    { name: '过期提醒范围：过期天数下限/未来天数上限', desc: '定义仪表盘“过期提醒”的时间窗口边界（过去/未来天数）。' },
                    { name: '备忘提醒范围：过期天数下限/未来天数上限', desc: '定义仪表盘“备忘提醒”的时间窗口边界（过去/未来天数）。' },
                    { name: '余量提醒阈值（%）', desc: '低余量触发阈值。余量占比低于该值时生成补货提醒；0 表示禁用。' },
                    { name: '仪表盘/物品/分类/位置排序项', desc: '各页面的默认排序策略配置。' },
                    { name: '状态管理：名称/图标/颜色', desc: '状态字典维护，影响表单可选项与卡片展示。' },
                    { name: '购入渠道管理：渠道名称', desc: '渠道字典维护，用于统一录入来源渠道。' },
                    { name: '平台设置：开放注册（管理员）', desc: '平台注册策略开关：启用自助注册或仅允许既有账号登录。' }
                ]
            }
        ];
        const HELP_DOC_SYSTEM_FIELDS = [
            { name: 'id', desc: '主键编号，系统自动生成。' },
            { name: 'created_at', desc: '创建时间，系统自动记录。' },
            { name: 'updated_at', desc: '更新时间，系统自动刷新。' },
            { name: 'deleted_at', desc: '软删除时间（回收站场景），仅系统维护。' },
            { name: 'source_shared_id', desc: '购物清单来源共享记录 ID，来自公共频道时自动写入。' }
        ];

        function renderChangelogHelp() {
            return `
            <div class="glass rounded-2xl p-6 anim-up">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/10 flex items-center justify-center"><i class="ri-book-open-line text-xl text-emerald-400"></i></div>
                    <div>
                        <h3 class="font-semibold text-white">使用帮助文档</h3>
                        <p class="text-xs text-slate-500">快速上手、字段说明与模块功能导航</p>
                    </div>
                </div>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="rounded-xl border border-white/10 bg-white/[0.02] p-4">
                        <h4 class="text-sm font-semibold text-white mb-3 flex items-center gap-2"><i class="ri-rocket-line text-cyan-400"></i>快速上手</h4>
                        <ol class="space-y-2">
                            ${HELP_DOC_QUICK_START.map((step, idx) => `
                                <li class="text-xs text-slate-400 flex items-start gap-2">
                                    <span class="w-5 h-5 rounded-full bg-white/10 text-[11px] text-slate-200 flex items-center justify-center flex-shrink-0 mt-0.5">${idx + 1}</span>
                                    <span>${esc(step)}</span>
                                </li>
                            `).join('')}
                        </ol>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/[0.02] p-4">
                        <h4 class="text-sm font-semibold text-white mb-3 flex items-center gap-2"><i class="ri-compass-3-line text-violet-400"></i>功能导航</h4>
                        <div class="space-y-2">
                            ${HELP_DOC_FEATURES.map(feature => `
                                <div class="text-xs text-slate-400 leading-5">
                                    <span class="inline-flex px-2 py-0.5 rounded-md bg-white/5 text-slate-200 font-medium mr-2">${esc(feature.name)}</span>
                                    <span>${esc(feature.desc)}</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
                <div class="mt-4 space-y-3">
                    ${HELP_DOC_FIELD_GROUPS.map(group => `
                        <div class="rounded-xl border border-white/10 bg-white/[0.02] p-4">
                            <h4 class="text-sm font-semibold text-white mb-3 flex items-center gap-2"><i class="${esc(group.icon)} text-sky-400"></i>${esc(group.title)}</h4>
                            <div class="space-y-2">
                                ${group.fields.map(field => `
                                    <div class="text-xs text-slate-400 md:flex md:items-start md:gap-3">
                                        <span class="inline-flex px-2 py-0.5 rounded-md bg-white/5 text-slate-200 font-mono md:w-56 md:flex-shrink-0">${esc(field.name)}</span>
                                        <span class="block mt-1 md:mt-0">${esc(field.desc)}</span>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    `).join('')}
                    <div class="rounded-xl border border-white/10 bg-white/[0.02] p-4">
                        <h4 class="text-sm font-semibold text-white mb-3 flex items-center gap-2"><i class="ri-database-2-line text-amber-400"></i>系统自动字段（无需手动填写）</h4>
                        <div class="space-y-2">
                            ${HELP_DOC_SYSTEM_FIELDS.map(field => `
                                <div class="text-xs text-slate-400 md:flex md:items-start md:gap-3">
                                    <span class="inline-flex px-2 py-0.5 rounded-md bg-white/5 text-slate-200 font-mono md:w-48 md:flex-shrink-0">${esc(field.name)}</span>
                                    <span class="block mt-1 md:mt-0">${esc(field.desc)}</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
            </div>`;
        }

        // ---------- 设置页面 ----------
        function renderSettings(container) {
            const s = App.sortSettings;
            const d = normalizeDashboardSettings(App.dashboardSettings || defaultDashboardSettings);
            container.innerHTML = `
        <div class="max-w-3xl mx-auto space-y-6">
            <div class="px-1 anim-up">
                <h3 class="text-sm font-semibold text-slate-200">仪表盘相关</h3>
                <p class="text-xs text-slate-500 mt-1">先设置提醒显示与统计排序，页面展示将按这些规则更新</p>
            </div>

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.02s">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-10 h-10 rounded-xl bg-cyan-500/10 flex items-center justify-center"><i class="ri-dashboard-3-line text-xl text-cyan-400"></i></div>
                    <div><h3 class="font-semibold text-white">仪表盘管理 · 提醒显示范围</h3><p class="text-xs text-slate-500">可分别控制过期提醒与备忘提醒在仪表盘中的可见时间窗口</p></div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">过期提醒：过期天数下限（天）</label>
                        <input type="number" min="0" step="1" id="set_expiry_past_days" class="input" value="${d.expiry_past_days === null ? '' : Number(d.expiry_past_days)}" placeholder="留空=不过滤过期天数">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">过期提醒：未来天数上限（天）</label>
                        <input type="number" min="0" step="1" id="set_expiry_future_days" class="input" value="${d.expiry_future_days === null ? '' : Number(d.expiry_future_days)}" placeholder="默认 60，留空=不限制">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">备忘提醒：过期天数下限（天）</label>
                        <input type="number" min="0" step="1" id="set_reminder_past_days" class="input" value="${d.reminder_past_days === null ? '' : Number(d.reminder_past_days)}" placeholder="留空=不过滤过期天数">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">备忘提醒：未来天数上限（天）</label>
                        <input type="number" min="0" step="1" id="set_reminder_future_days" class="input" value="${d.reminder_future_days === null ? '' : Number(d.reminder_future_days)}" placeholder="默认 3，留空=不限制">
                    </div>
                </div>
                <p class="text-xs text-slate-500 mt-4">当前默认：过期提醒（过期不限制，未来 60 天）；备忘提醒（过期不限制，未来 3 天）。输入留空表示不限制。</p>
            </div>

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.04s">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-10 h-10 rounded-xl bg-violet-500/10 flex items-center justify-center"><i class="ri-sort-asc text-xl text-violet-400"></i></div>
                    <div><h3 class="font-semibold text-white">仪表盘 · 分类统计排序</h3><p class="text-xs text-slate-500">控制仪表盘分类统计板块的显示顺序</p></div>
                </div>
                <select class="input" id="set_dashboard_categories" value="${s.dashboard_categories}">
                    <option value="count_desc" ${s.dashboard_categories === 'count_desc' ? 'selected' : ''}>按物品种类数 多→少</option>
                    <option value="total_qty_desc" ${s.dashboard_categories === 'total_qty_desc' ? 'selected' : ''}>按物品总件数 多→少</option>
                    <option value="name_asc" ${s.dashboard_categories === 'name_asc' ? 'selected' : ''}>按名称首字母 A→Z</option>
                </select>
            </div>

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.06s">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-10 h-10 rounded-xl bg-teal-500/10 flex items-center justify-center"><i class="ri-notification-3-line text-xl text-teal-400"></i></div>
                    <div><h3 class="font-semibold text-white">仪表盘管理 · 余量提醒阈值</h3><p class="text-xs text-slate-500">当余量/数量低于阈值时，自动在备忘提醒中生成补货提醒</p></div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">触发阈值（%）</label>
                        <input type="number" min="0" max="100" step="1" id="set_low_stock_threshold_pct" class="input" value="${Number(d.low_stock_threshold_pct)}" placeholder="默认 20">
                    </div>
                    <div class="text-xs text-slate-500 leading-6">
                        <p>推荐值：20%</p>
                        <p>设置为 0 表示关闭自动余量提醒。</p>
                    </div>
                </div>
            </div>

            <div class="px-1 pt-1 anim-up" style="animation-delay:0.08s">
                <h3 class="text-sm font-semibold text-slate-200">列表页面相关</h3>
                <p class="text-xs text-slate-500 mt-1">控制物品、分类、位置等管理页面的默认排序方式</p>
            </div>

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.1s">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-10 h-10 rounded-xl bg-sky-500/10 flex items-center justify-center"><i class="ri-archive-line text-xl text-sky-400"></i></div>
                    <div><h3 class="font-semibold text-white">物品管理 · 默认排序</h3><p class="text-xs text-slate-500">控制进入物品列表时的默认排序方式</p></div>
                </div>
                <select class="input" id="set_items_default">
                    <option value="updated_at:DESC" ${s.items_default === 'updated_at:DESC' ? 'selected' : ''}>最近更新</option>
                    <option value="created_at:DESC" ${s.items_default === 'created_at:DESC' ? 'selected' : ''}>最近添加</option>
                    <option value="name:ASC" ${s.items_default === 'name:ASC' ? 'selected' : ''}>名称 A→Z</option>
                    <option value="purchase_price:DESC" ${s.items_default === 'purchase_price:DESC' ? 'selected' : ''}>价格 高→低</option>
                    <option value="quantity:DESC" ${s.items_default === 'quantity:DESC' ? 'selected' : ''}>数量 多→少</option>
                </select>
            </div>

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.12s">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/10 flex items-center justify-center"><i class="ri-price-tag-3-line text-xl text-emerald-400"></i></div>
                    <div><h3 class="font-semibold text-white">分类管理 · 列表排序</h3><p class="text-xs text-slate-500">控制分类管理页面的卡片显示顺序</p></div>
                </div>
                <select class="input" id="set_categories_list">
                    <option value="custom" ${s.categories_list === 'custom' ? 'selected' : ''}>系统默认顺序</option>
                    <option value="count_desc" ${s.categories_list === 'count_desc' ? 'selected' : ''}>按物品数量 多→少</option>
                    <option value="name_asc" ${s.categories_list === 'name_asc' ? 'selected' : ''}>按名称首字母 A→Z</option>
                </select>
            </div>

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.14s">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-10 h-10 rounded-xl bg-amber-500/10 flex items-center justify-center"><i class="ri-map-pin-line text-xl text-amber-400"></i></div>
                    <div><h3 class="font-semibold text-white">位置管理 · 列表排序</h3><p class="text-xs text-slate-500">控制位置管理页面的卡片显示顺序</p></div>
                </div>
                <select class="input" id="set_locations_list">
                    <option value="custom" ${s.locations_list === 'custom' ? 'selected' : ''}>系统默认顺序</option>
                    <option value="count_desc" ${s.locations_list === 'count_desc' ? 'selected' : ''}>按物品数量 多→少</option>
                    <option value="name_asc" ${s.locations_list === 'name_asc' ? 'selected' : ''}>按名称首字母 A→Z</option>
                </select>
            </div>

            <button onclick="applySettings()" class="btn btn-primary w-full"><i class="ri-save-line"></i>保存设置</button>
        </div>
    `;
        }

        function renderReminderSettings(container) {
            renderSettings(container);
        }

        function saveReminderSettings() {
            applySettings();
        }

        async function renderPlatformSettings(container) {
            if (!CURRENT_USER || !CURRENT_USER.is_admin) {
                container.innerHTML = '<div class="glass rounded-2xl p-8 text-center text-slate-400">仅管理员可访问平台设置</div>';
                return;
            }
            const res = await api('platform-settings');
            if (!res || !res.success) {
                container.innerHTML = `<div class="glass rounded-2xl p-8 text-center text-red-400">${esc(res?.message || '平台设置加载失败')}</div>`;
                return;
            }
            const allowRegistration = !!(res.data && res.data.allow_registration);
            container.innerHTML = `
        <div class="max-w-2xl mx-auto space-y-6">
            <div class="glass rounded-2xl p-6 anim-up">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-10 h-10 rounded-xl bg-cyan-500/10 flex items-center justify-center"><i class="ri-global-line text-xl text-cyan-400"></i></div>
                    <div><h3 class="font-semibold text-white">账号注册设置</h3><p class="text-xs text-slate-500">控制平台是否允许新用户自行注册</p></div>
                </div>
                <label class="flex items-center justify-between gap-4 p-4 rounded-xl bg-white/[0.03] border border-white/[0.06]">
                    <div>
                        <p class="text-sm text-white">开放注册</p>
                        <p class="text-xs text-slate-500">关闭后，仅管理员预置账号可登录平台</p>
                    </div>
                    <input type="checkbox" id="platformAllowRegistration" class="w-5 h-5 accent-sky-500" ${allowRegistration ? 'checked' : ''}>
                </label>
                <button onclick="savePlatformSettings()" class="btn btn-primary w-full mt-5"><i class="ri-save-line"></i>保存平台设置</button>
            </div>
        </div>
    `;
        }

        async function savePlatformSettings() {
            if (!CURRENT_USER || !CURRENT_USER.is_admin) {
                toast('仅管理员可操作', 'error');
                return;
            }
            const allow = document.getElementById('platformAllowRegistration')?.checked ? 1 : 0;
            const res = await apiPost('platform-settings', { allow_registration: allow });
            if (!res || !res.success) {
                toast(res?.message || '保存失败', 'error');
                return;
            }
            toast('平台设置已保存');
        }

        // ---------- 操作日志 ----------
        async function renderOperationLogs(container) {
            const isAdmin = !!(CURRENT_USER && CURRENT_USER.is_admin);
            let query = 'operation-logs&page=1&limit=30';
            if (isAdmin) {
                const f = App.operationLogsFilters || { keyword: '', actorUserId: 0, sort: 'time_desc' };
                const params = new URLSearchParams();
                params.set('page', '1');
                params.set('limit', '10000');
                params.set('sort', String(f.sort || 'time_desc'));
                if (String(f.keyword || '').trim() !== '') {
                    params.set('keyword', String(f.keyword || '').trim());
                }
                if (Number(f.actorUserId || 0) > 0) {
                    params.set('actor_user_id', String(Number(f.actorUserId || 0)));
                }
                query = 'operation-logs&' + params.toString();
            }
            const res = await api(query);
            if (!res || !res.success) {
                container.innerHTML = `<div class="glass rounded-2xl p-8 text-center text-red-400">${esc(res?.message || '日志加载失败')}</div>`;
                return;
            }
            const rows = Array.isArray(res.data) ? res.data : [];
            const scope = String(res.scope || (isAdmin ? 'admin' : 'user'));
            if (scope === 'admin') {
                const f = App.operationLogsFilters || { keyword: '', actorUserId: 0, sort: 'time_desc' };
                const members = Array.isArray(res.members) ? res.members : [];

                container.innerHTML = `
        <div class="max-w-5xl mx-auto space-y-6">
            <div class="glass rounded-2xl p-6 anim-up">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-cyan-500/10 flex items-center justify-center"><i class="ri-file-list-3-line text-xl text-cyan-400"></i></div>
                        <div>
                            <h3 class="font-semibold text-white">操作日志（管理员汇总）</h3>
                            <p class="text-xs text-slate-500">共 ${Number(res.total || rows.length)} 条日志，可按成员/关键词过滤并排序</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button onclick="renderView()" class="btn btn-ghost btn-sm"><i class="ri-refresh-line"></i>刷新</button>
                        <button onclick="clearOperationLogs()" class="btn btn-danger btn-sm"><i class="ri-delete-bin-line"></i>清空汇总日志</button>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
                    <input id="opLogKeyword" class="input md:col-span-2" placeholder="关键词（成员/动作/详情）" value="${esc(String(f.keyword || ''))}">
                    <select id="opLogActor" class="input">
                        <option value="0">全部成员</option>
                        ${members.map(m => {
                            const uid = Number(m.id || 0);
                            const display = String(m.display_name || m.username || ('用户#' + uid));
                            const role = String(m.role || 'user') === 'admin' ? '管理员' : '普通用户';
                            return `<option value="${uid}" ${Number(f.actorUserId || 0) === uid ? 'selected' : ''}>${esc(display)}（${esc(role)}）</option>`;
                        }).join('')}
                    </select>
                    <select id="opLogSort" class="input">
                        <option value="time_desc" ${String(f.sort || 'time_desc') === 'time_desc' ? 'selected' : ''}>时间：新→旧</option>
                        <option value="time_asc" ${String(f.sort || '') === 'time_asc' ? 'selected' : ''}>时间：旧→新</option>
                        <option value="user_asc" ${String(f.sort || '') === 'user_asc' ? 'selected' : ''}>成员：A→Z</option>
                        <option value="user_desc" ${String(f.sort || '') === 'user_desc' ? 'selected' : ''}>成员：Z→A</option>
                        <option value="action_asc" ${String(f.sort || '') === 'action_asc' ? 'selected' : ''}>动作：A→Z</option>
                        <option value="action_desc" ${String(f.sort || '') === 'action_desc' ? 'selected' : ''}>动作：Z→A</option>
                    </select>
                </div>
                <div class="flex items-center gap-2 mb-4">
                    <button onclick="applyOperationLogsFilters()" class="btn btn-primary btn-sm"><i class="ri-filter-3-line"></i>应用过滤</button>
                    <button onclick="resetOperationLogsFilters()" class="btn btn-ghost btn-sm"><i class="ri-close-line"></i>重置</button>
                </div>
                <div class="space-y-2">
                    ${rows.map(log => {
                        const actorDisplay = String(log.actor_display_name || log.actor_username || (`用户#${Number(log.actor_user_id || 0)}`));
                        const actorRole = String(log.actor_role || 'user') === 'admin' ? '管理员' : '普通用户';
                        return `
                        <div class="rounded-xl border border-white/5 bg-white/[0.02] p-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm text-white">${esc(log.action_label || '操作')}</p>
                                    <p class="text-[11px] text-slate-500 mt-0.5">@${esc(actorDisplay)} · ${esc(actorRole)}</p>
                                    ${log.details ? `<p class="text-xs text-slate-400 mt-1 break-all">${esc(log.details)}</p>` : ''}
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="text-[11px] text-slate-500">${esc(formatDateTimeText(log.created_at, ''))}</p>
                                    <p class="text-[10px] text-slate-600 mt-0.5 font-mono">${esc((log.method || '') + ' ' + (log.api || ''))}</p>
                                </div>
                            </div>
                        </div>
                    `;
                    }).join('')}
                    ${rows.length === 0 ? '<div class="text-center text-slate-500 text-sm py-10">暂无汇总日志</div>' : ''}
                </div>
            </div>
        </div>`;
                return;
            }

            container.innerHTML = `
        <div class="max-w-3xl mx-auto space-y-6">
            <div class="glass rounded-2xl p-6 anim-up">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-cyan-500/10 flex items-center justify-center"><i class="ri-file-list-3-line text-xl text-cyan-400"></i></div>
                        <div>
                            <h3 class="font-semibold text-white">操作日志</h3>
                            <p class="text-xs text-slate-500">仅显示当前账号最近 30 条操作记录</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button onclick="renderView()" class="btn btn-ghost btn-sm"><i class="ri-refresh-line"></i>刷新</button>
                    </div>
                </div>
                <div class="space-y-2">
                    ${rows.map(log => `
                        <div class="rounded-xl border border-white/5 bg-white/[0.02] p-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm text-white">${esc(log.action_label || '操作')}</p>
                                    ${log.details ? `<p class="text-xs text-slate-400 mt-1 break-all">${esc(log.details)}</p>` : ''}
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="text-[11px] text-slate-500">${esc(formatDateTimeText(log.created_at, ''))}</p>
                                </div>
                            </div>
                        </div>
                    `).join('')}
                    ${rows.length === 0 ? '<div class="text-center text-slate-500 text-sm py-10">暂无操作日志</div>' : ''}
                </div>
            </div>
        </div>`;
        }

        function applyOperationLogsFilters() {
            if (!(CURRENT_USER && CURRENT_USER.is_admin)) return;
            App.operationLogsFilters = {
                keyword: String(document.getElementById('opLogKeyword')?.value || '').trim(),
                actorUserId: Number(document.getElementById('opLogActor')?.value || 0),
                sort: String(document.getElementById('opLogSort')?.value || 'time_desc')
            };
            renderView();
        }

        function resetOperationLogsFilters() {
            if (!(CURRENT_USER && CURRENT_USER.is_admin)) return;
            App.operationLogsFilters = { keyword: '', actorUserId: 0, sort: 'time_desc' };
            renderView();
        }

        async function clearOperationLogs() {
            if (!(CURRENT_USER && CURRENT_USER.is_admin)) {
                toast('仅管理员可清空汇总日志', 'error');
                return;
            }
            if (!confirm('确定清空管理员汇总日志吗？此操作不会影响各成员个人日志。')) return;
            const res = await apiPost('operation-logs/clear', {});
            if (!res || !res.success) {
                toast(res?.message || '清空失败', 'error');
                return;
            }
            toast('管理员汇总日志已清空');
            renderView();
        }

        // ---------- 更新记录页面 ----------
        function renderHelpDocs(container) {
            container.innerHTML = `
        <div class="max-w-5xl mx-auto space-y-6">
            ${renderChangelogHelp()}
        </div>
    `;
        }

        function renderChangelog(container) {
            container.innerHTML = `
        <div class="max-w-2xl mx-auto space-y-6">
            <div class="glass rounded-2xl p-6 anim-up">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-10 h-10 rounded-xl bg-sky-500/10 flex items-center justify-center"><i class="ri-history-line text-xl text-sky-400"></i></div>
                    <div><h3 class="font-semibold text-white">更新记录</h3><p class="text-xs text-slate-500">版本历史与功能更新</p></div>
                </div>
                <div class="space-y-5">
                    ${CHANGELOG.map((log, idx) => `
                    <div class="${idx > 0 ? 'pt-5 border-t border-white/5' : ''}">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="px-2 py-0.5 rounded-md text-xs font-mono font-semibold ${idx === 0 ? 'bg-sky-500/20 text-sky-400' : 'bg-white/5 text-slate-400'}">${log.version}</span>
                            <span class="text-xs text-slate-500">${log.date}</span>
                        </div>
                        <h4 class="text-sm font-medium text-white mb-2">${esc(log.title)}</h4>
                        <ul class="space-y-1">
                            ${log.changes.map(c => `<li class="text-xs text-slate-400 flex gap-2"><span class="text-slate-600 flex-shrink-0">•</span><span>${esc(c)}</span></li>`).join('')}
                        </ul>
                    </div>`).join('')}
                </div>
            </div>
        </div>
    `;
        }

        function readOptionalRangeInput(inputId, label) {
            const el = document.getElementById(inputId);
            if (!el) return { ok: false, value: null };
            const raw = String(el.value || '').trim();
            if (raw === '') return { ok: true, value: null };
            const value = Number.parseInt(raw, 10);
            if (!Number.isFinite(value) || value < 0) {
                toast(`${label}需为大于等于 0 的整数，留空表示不限制`, 'error');
                el.focus();
                return { ok: false, value: null };
            }
            return { ok: true, value };
        }

        function applySettings() {
            const prev = { ...App.sortSettings };
            const prevDashboard = normalizeDashboardSettings(App.dashboardSettings || defaultDashboardSettings);
            const s = {
                dashboard_categories: document.getElementById('set_dashboard_categories').value,
                items_default: document.getElementById('set_items_default').value,
                categories_list: document.getElementById('set_categories_list').value,
                locations_list: document.getElementById('set_locations_list').value,
            };
            const expiryPast = readOptionalRangeInput('set_expiry_past_days', '过期提醒过期天数下限');
            if (!expiryPast.ok) return;
            const expiryFuture = readOptionalRangeInput('set_expiry_future_days', '过期提醒未来天数上限');
            if (!expiryFuture.ok) return;
            const reminderPast = readOptionalRangeInput('set_reminder_past_days', '备忘提醒过期天数下限');
            if (!reminderPast.ok) return;
            const reminderFuture = readOptionalRangeInput('set_reminder_future_days', '备忘提醒未来天数上限');
            if (!reminderFuture.ok) return;
            const lowStockInput = document.getElementById('set_low_stock_threshold_pct');
            if (!lowStockInput) return;
            const lowStockRaw = String(lowStockInput.value || '').trim();
            if (lowStockRaw === '' || !/^\d+$/.test(lowStockRaw)) {
                toast('余量提醒阈值需为 0-100 的整数', 'error');
                lowStockInput.focus();
                return;
            }
            const lowStockThresholdPct = Math.max(0, Math.min(100, Number.parseInt(lowStockRaw, 10)));
            const nextDashboard = saveDashboardSettings({
                expiry_past_days: expiryPast.value,
                expiry_future_days: expiryFuture.value,
                reminder_past_days: reminderPast.value,
                reminder_future_days: reminderFuture.value,
                low_stock_threshold_pct: lowStockThresholdPct,
            });
            saveSortSettings(s);
            // 同步物品默认排序
            const [sort, order] = s.items_default.split(':');
            App.itemsSort = sort; App.itemsOrder = order;
            const sortLabelMaps = {
                dashboard_categories: {
                    count_desc: '按物品种类数 多→少',
                    total_qty_desc: '按物品总件数 多→少',
                    name_asc: '按名称首字母 A→Z'
                },
                items_default: {
                    'updated_at:DESC': '最近更新',
                    'created_at:DESC': '最近添加',
                    'name:ASC': '名称 A→Z',
                    'purchase_price:DESC': '价格 高→低',
                    'quantity:DESC': '数量 多→少'
                },
                categories_list: {
                    custom: '系统默认顺序',
                    count_desc: '按物品数量 多→少',
                    name_asc: '按名称首字母 A→Z'
                },
                locations_list: {
                    custom: '系统默认顺序',
                    count_desc: '按物品数量 多→少',
                    name_asc: '按名称首字母 A→Z'
                }
            };
            const sortFields = [
                ['dashboard_categories', '仪表盘分类排序'],
                ['items_default', '物品默认排序'],
                ['categories_list', '分类列表排序'],
                ['locations_list', '位置列表排序']
            ];
            const sortChanges = [];
            sortFields.forEach(([key, label]) => {
                const before = String(prev[key] || '');
                const after = String(s[key] || '');
                if (before === after) return;
                const beforeText = sortLabelMaps[key]?.[before] || before;
                const afterText = sortLabelMaps[key]?.[after] || after;
                sortChanges.push(`${label}：“${beforeText}” → “${afterText}”`);
            });
            if (sortChanges.length > 0) {
                logSettingEvent('settings.sort', sortChanges.join('；'));
            }

            const rangeChanges = [];
            if (prevDashboard.expiry_past_days !== nextDashboard.expiry_past_days) {
                rangeChanges.push(`过期提醒过期天数下限：${formatRangeLimitLabel(prevDashboard.expiry_past_days)} → ${formatRangeLimitLabel(nextDashboard.expiry_past_days)}`);
            }
            if (prevDashboard.expiry_future_days !== nextDashboard.expiry_future_days) {
                rangeChanges.push(`过期提醒未来天数上限：${formatRangeLimitLabel(prevDashboard.expiry_future_days)} → ${formatRangeLimitLabel(nextDashboard.expiry_future_days)}`);
            }
            if (prevDashboard.reminder_past_days !== nextDashboard.reminder_past_days) {
                rangeChanges.push(`备忘提醒过期天数下限：${formatRangeLimitLabel(prevDashboard.reminder_past_days)} → ${formatRangeLimitLabel(nextDashboard.reminder_past_days)}`);
            }
            if (prevDashboard.reminder_future_days !== nextDashboard.reminder_future_days) {
                rangeChanges.push(`备忘提醒未来天数上限：${formatRangeLimitLabel(prevDashboard.reminder_future_days)} → ${formatRangeLimitLabel(nextDashboard.reminder_future_days)}`);
            }
            if (rangeChanges.length > 0) {
                logSettingEvent('settings.dashboard_ranges', rangeChanges.join('；'));
            }

            if (Number(prevDashboard.low_stock_threshold_pct) !== Number(nextDashboard.low_stock_threshold_pct)) {
                const lowStockDetail = `余量提醒阈值：${Number(prevDashboard.low_stock_threshold_pct)}% → ${Number(nextDashboard.low_stock_threshold_pct)}%`;
                logSettingEvent('settings.reminder_low_stock', lowStockDetail);
            }
            toast('设置已保存');
        }

        async function resetItemData() {
            if (!confirm('确定重置物品数据吗？此操作仅清空物品列表和回收站，图片会移动到 uploads/trash，且不可撤销。')) return;
            const res = await apiPost('items/reset-all', {});
            if (!res.success) { toast(res.message || '删除失败', 'error'); return; }
            App.selectedItems.clear();
            App._cachedItems = null;
            App._cachedTotal = 0;
            App._cachedPages = 0;
            toast('物品数据已重置');
            renderView();
        }

        async function restoreDefaultEnvironment() {
            if (!confirm('确定恢复默认环境吗？此操作会清空所有数据并重置本地设置，且不可撤销。')) return;
            const res = await apiPost('system/reset-default', {});
            if (!res.success) { toast(res.message || '恢复失败', 'error'); return; }

            localStorage.removeItem(SORT_SETTINGS_KEY);
            localStorage.removeItem(DASHBOARD_SETTINGS_KEY);
            localStorage.removeItem(ITEMS_SIZE_KEY);
            localStorage.removeItem(ITEM_ATTRS_KEY);
            localStorage.removeItem(STATUS_KEY);
            localStorage.removeItem(CHANNEL_KEY);
            localStorage.removeItem(THEME_KEY);

            App.statuses = defaultStatuses.map(s => ({ ...s }));
            App.purchaseChannels = [...defaultPurchaseChannels];
            App.itemsSize = 'large';
            App.itemAttrs = [...defaultItemAttrs];
            App.sortSettings = { ...defaultSortSettings };
            App.dashboardSettings = { ...defaultDashboardSettings };
            App.itemsFilter = { search: '', category: 0, location: 0, status: '', expiryOnly: false };
            App.itemsPage = 1;
            App.itemsSort = 'updated_at';
            App.itemsOrder = 'DESC';
            App.categories = [];
            App.locations = [];
            invalidateBaseDataCache();
            App.selectedItems.clear();
            App._cachedItems = null;
            App._cachedTotal = 0;
            App._cachedPages = 0;

            document.body.classList.remove('light');
            document.getElementById('themeIcon').className = 'ri-moon-line';
            document.getElementById('themeText').textContent = '深色模式';

            toast('已恢复默认环境');
            switchView('dashboard');
        }

        // ---------- 状态管理页面 ----------
        function renderStatusSettings(container) {
            const badgeColors = [
                { value: 'badge-active', label: '绿色' },
                { value: 'badge-lent', label: '蓝色' },
                { value: 'badge-archived', label: '灰色' },
                { value: 'badge-warning', label: '橙色' },
                { value: 'badge-danger', label: '红色' },
            ];

            container.innerHTML = `
        <div class="max-w-2xl mx-auto space-y-6">
            <div class="glass rounded-2xl p-6 anim-up">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/10 flex items-center justify-center"><i class="ri-list-settings-line text-xl text-emerald-400"></i></div>
                    <div><h3 class="font-semibold text-white">物品状态列表</h3><p class="text-xs text-slate-500">管理物品可用的状态选项</p></div>
                </div>
                <div class="space-y-3" id="statusList">
                    ${App.statuses.map((s, idx) => `
                    <div class="flex items-center gap-3 p-3 rounded-xl bg-white/[0.03] border border-white/[0.04]" id="statusRow${idx}">
                        <i class="${s.icon} ${s.color} text-lg"></i>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm text-white">${esc(s.label)}</div>
                            <div class="text-[10px] text-slate-500">${esc(s.key)}</div>
                        </div>
                        <span class="badge ${s.badge} !text-[10px]">${s.label}</span>
                        <button onclick="openEditStatus(${idx})" class="p-1 text-slate-600 hover:text-sky-400 transition" title="编辑"><i class="ri-edit-line"></i></button>
                        ${App.statuses.length > 1 ? `<button onclick="removeStatus(${idx})" class="p-1 text-slate-600 hover:text-red-400 transition" title="删除"><i class="ri-close-line"></i></button>` : ''}
                    </div>`).join('')}
                </div>
            </div>

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.05s">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-10 h-10 rounded-xl bg-sky-500/10 flex items-center justify-center"><i class="ri-add-circle-line text-xl text-sky-400"></i></div>
                    <div><h3 class="font-semibold text-white">添加新状态</h3><p class="text-xs text-slate-500">自定义你需要的物品状态</p></div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">状态名称</label>
                        <input type="text" id="newStatusLabel" class="input" placeholder="例如: 维修中">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">状态标识（英文）</label>
                        <input type="text" id="newStatusKey" class="input" placeholder="例如: repairing">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">图标</label>
                        ${renderStatusIconPicker('newStatusIconPicker', 'newStatusIcon', STATUS_ICON_OPTIONS[0])}
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">颜色</label>
                        <select id="newStatusBadge" class="input">
                            ${badgeColors.map(bc => `<option value="${bc.value}">${bc.label}</option>`).join('')}
                        </select>
                    </div>
                </div>
                <button onclick="addStatus()" class="btn btn-primary w-full mt-4"><i class="ri-add-line"></i>添加状态</button>
            </div>

            <button onclick="resetStatuses()" class="btn btn-ghost w-full text-slate-500 text-sm">恢复默认状态</button>
        </div>
    `;
        }

        function addStatus() {
            const label = document.getElementById('newStatusLabel').value.trim();
            const key = document.getElementById('newStatusKey').value.trim().toLowerCase();
            const icon = document.getElementById('newStatusIcon').value;
            const badge = document.getElementById('newStatusBadge').value;
            if (!label) { toast('请填写状态名称', 'error'); return; }
            if (!key) { toast('请填写英文状态标识', 'error'); return; }
            if (!/^[a-z][a-z0-9_-]*$/.test(key)) { toast('状态标识仅支持英文、数字、-、_，且需以字母开头', 'error'); return; }
            if (App.statuses.find(s => s.key === key)) { toast('该状态已存在', 'error'); return; }
            const badgeToColor = { 'badge-active': 'text-emerald-400', 'badge-lent': 'text-sky-400', 'badge-archived': 'text-slate-400', 'badge-warning': 'text-amber-400', 'badge-danger': 'text-red-400' };
            App.statuses.push({ key, label, icon, color: badgeToColor[badge] || 'text-slate-400', badge });
            saveStatuses(App.statuses);
            toast('状态已添加');
            renderView();
        }

        function removeStatus(idx) {
            const s = App.statuses[idx];
            if (!confirm(`确定删除状态「${s.label}」？已使用该状态的物品不会被修改。`)) return;
            App.statuses.splice(idx, 1);
            saveStatuses(App.statuses);
            toast('状态已删除');
            renderView();
        }

        function openEditStatus(idx) {
            const s = App.statuses[idx];
            const badgeColors = [
                { value: 'badge-active', label: '绿色' }, { value: 'badge-lent', label: '蓝色' },
                { value: 'badge-archived', label: '灰色' }, { value: 'badge-warning', label: '橙色' }, { value: 'badge-danger', label: '红色' },
            ];
            const row = document.getElementById('statusRow' + idx);
            if (!row) return;
            row.innerHTML = `
                <div class="w-full space-y-2">
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-2">
                        <div>
                            <label class="block text-[10px] text-slate-500 mb-0.5">名称</label>
                            <input type="text" id="editLabel${idx}" class="input !py-1 text-xs" value="${esc(s.label)}">
                        </div>
                        <div>
                            <label class="block text-[10px] text-slate-500 mb-0.5">标识</label>
                            <input type="text" id="editKey${idx}" class="input !py-1 text-xs" value="${esc(s.key)}" readonly>
                        </div>
                        <div>
                            <label class="block text-[10px] text-slate-500 mb-0.5">图标</label>
                            ${renderStatusIconPicker('editStatusIconPicker' + idx, 'editIcon' + idx, s.icon)}
                        </div>
                        <div>
                            <label class="block text-[10px] text-slate-500 mb-0.5">颜色</label>
                            <select id="editBadge${idx}" class="input !py-1 text-xs">
                                ${badgeColors.map(bc => `<option value="${bc.value}" ${s.badge === bc.value ? 'selected' : ''}>${bc.label}</option>`).join('')}
                            </select>
                        </div>
                    </div>
                    <div class="flex gap-2 justify-end">
                        <button onclick="renderView()" class="btn btn-ghost btn-sm text-xs">取消</button>
                        <button onclick="saveEditStatus(${idx})" class="btn btn-primary btn-sm text-xs"><i class="ri-check-line"></i>保存</button>
                    </div>
                </div>`;
        }

        function saveEditStatus(idx) {
            const label = document.getElementById('editLabel' + idx).value.trim();
            const icon = document.getElementById('editIcon' + idx).value;
            const badge = document.getElementById('editBadge' + idx).value;
            if (!label) { toast('名称不能为空', 'error'); return; }
            const duplicated = App.statuses.some((s, i) => i !== idx && s.label === label);
            if (duplicated) { toast('该状态已存在', 'error'); return; }
            const badgeToColor = { 'badge-active': 'text-emerald-400', 'badge-lent': 'text-sky-400', 'badge-archived': 'text-slate-400', 'badge-warning': 'text-amber-400', 'badge-danger': 'text-red-400' };
            App.statuses[idx].label = label;
            App.statuses[idx].icon = icon;
            App.statuses[idx].badge = badge;
            App.statuses[idx].color = badgeToColor[badge] || 'text-slate-400';
            saveStatuses(App.statuses);
            toast('状态已更新');
            renderView();
        }

        function resetStatuses() {
            if (!confirm('确定恢复为默认状态？')) return;
            saveStatuses(defaultStatuses.map(s => ({ ...s })));
            toast('已恢复默认状态');
            renderView();
        }

        // ---------- 用户管理（管理员） ----------
        function formatDateTimeText(v, empty = '未记录') {
            if (!v) return empty;
            const s = String(v).replace('T', ' ');
            return s.length >= 19 ? s.slice(0, 19) : s;
        }

        async function adminResetUserPassword(userId, username) {
            const newPassword = prompt(`为用户「${username}」设置新密码（至少 6 位）：`);
            if (newPassword === null) return;
            if (String(newPassword).length < 6) {
                toast('密码至少 6 位', 'error');
                return;
            }
            const res = await apiPost('auth/admin-reset-password', {
                user_id: Number(userId || 0),
                new_password: String(newPassword)
            });
            if (!res.success) {
                toast(res.message || '重置失败', 'error');
                return;
            }
            toast(res.message || '密码已重置');
            renderView();
        }

        function openUserOperationLogs(userId, username = '') {
            if (!(CURRENT_USER && CURRENT_USER.is_admin)) return;
            App.operationLogsFilters = {
                keyword: '',
                actorUserId: Number(userId || 0),
                sort: 'time_desc'
            };
            switchView('operation-logs');
            if (username) {
                toast(`已切换到 ${username} 的日志`, 'success', { duration: 1600 });
            }
        }

        async function renderUserManagement(container) {
            if (!CURRENT_USER || !CURRENT_USER.is_admin) {
                container.innerHTML = '<div class="glass rounded-2xl p-8 text-center text-slate-400">仅管理员可访问用户管理</div>';
                return;
            }
            const res = await api('auth/users');
            if (!res.success) {
                container.innerHTML = `<div class="glass rounded-2xl p-8 text-center text-red-400">${esc(res.message || '加载失败')}</div>`;
                return;
            }
            const users = Array.isArray(res.data) ? res.data : [];
            const totalKinds = users.reduce((sum, u) => sum + Number(u.item_kinds || 0), 0);
            const totalQty = users.reduce((sum, u) => sum + Number(u.item_qty || 0), 0);

            container.innerHTML = `
        <div class="space-y-6">
            <div class="glass rounded-2xl p-4 anim-up">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                    <span class="text-sm text-slate-400"><i class="ri-team-line mr-1 text-sky-400"></i>用户数 ${users.length}</span>
                    <span class="text-sm text-slate-400"><i class="ri-archive-line mr-1 text-violet-400"></i>总物品种类 ${totalKinds}</span>
                    <span class="text-sm text-slate-400"><i class="ri-stack-line mr-1 text-emerald-400"></i>总物品件数 ${totalQty}</span>
                </div>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                ${users.map(u => `
                    <div class="glass rounded-2xl p-5 anim-up">
                        <div class="flex items-center justify-between gap-3 mb-3">
                            <div class="min-w-0">
                                <h3 class="text-white font-semibold truncate">${esc(u.display_name || u.username)}</h3>
                                <p class="text-xs text-slate-500 truncate">@${esc(u.username)}</p>
                            </div>
                            <span class="badge ${u.is_admin ? 'badge-danger' : 'badge-lent'}">${u.is_admin ? '管理员' : '普通用户'}</span>
                        </div>
                        <div class="space-y-1.5 text-xs text-slate-400 mb-4">
                            <p><i class="ri-archive-line mr-1 text-sky-400"></i>物品种类：${Number(u.item_kinds || 0)} 种</p>
                            <p><i class="ri-stack-line mr-1 text-violet-400"></i>物品件数：${Number(u.item_qty || 0)} 件</p>
                            <p><i class="ri-file-list-3-line mr-1 text-emerald-400"></i>操作日志：${Number(u.operation_log_count || 0)} 条</p>
                            <p><i class="ri-time-line mr-1 text-amber-400"></i>最近登录：${esc(formatDateTimeText(u.last_login_at, '从未登录'))}</p>
                            <p><i class="ri-edit-2-line mr-1 text-slate-500"></i>最近物品变更：${esc(formatDateTimeText(u.last_item_at, '暂无记录'))}</p>
                        </div>
                        <div class="flex items-center justify-end gap-2">
                            <button onclick='openUserOperationLogs(${Number(u.id || 0)}, ${JSON.stringify(String(u.username || ""))})' class="btn btn-ghost btn-sm text-emerald-300 border-emerald-400/30 hover:border-emerald-300/50">
                                <i class="ri-file-list-3-line"></i>查看日志
                            </button>
                            <button onclick='adminResetUserPassword(${Number(u.id || 0)}, ${JSON.stringify(String(u.username || ""))})' class="btn btn-ghost btn-sm text-cyan-300 border-cyan-400/30 hover:border-cyan-300/50">
                                <i class="ri-lock-password-line"></i>重置密码
                            </button>
                        </div>
                    </div>
                `).join('')}
            </div>
            ${users.length === 0 ? '<div class="glass rounded-2xl p-8 text-center text-slate-500">暂无用户数据</div>' : ''}
        </div>
    `;
        }

        // ---------- 购入渠道管理页面 ----------
        function renderChannelSettings(container) {
            container.innerHTML = `
        <div class="max-w-2xl mx-auto space-y-6">
            <div class="glass rounded-2xl p-6 anim-up">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-10 h-10 rounded-xl bg-sky-500/10 flex items-center justify-center"><i class="ri-shopping-bag-line text-xl text-sky-400"></i></div>
                    <div><h3 class="font-semibold text-white">购入渠道列表</h3><p class="text-xs text-slate-500">用于物品表单中的购入渠道下拉选项</p></div>
                </div>
                <div class="space-y-3">
                    ${App.purchaseChannels.map((channel, idx) => `
                    <div class="flex items-center gap-3 p-3 rounded-xl bg-white/[0.03] border border-white/[0.04]" id="channelRow${idx}">
                        <i class="ri-shopping-bag-line text-sky-400"></i>
                        <span class="text-sm text-white flex-1">${esc(channel)}</span>
                        <button onclick="openEditChannel(${idx})" class="p-1 text-slate-600 hover:text-sky-400 transition" title="编辑"><i class="ri-edit-line"></i></button>
                        <button onclick="removePurchaseChannel(${idx})" class="p-1 text-slate-600 hover:text-red-400 transition" title="删除"><i class="ri-close-line"></i></button>
                    </div>`).join('')}
                    ${App.purchaseChannels.length === 0 ? '<p class="text-xs text-slate-500 text-center py-4">暂无购入渠道，请先添加</p>' : ''}
                </div>
            </div>

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.05s">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/10 flex items-center justify-center"><i class="ri-add-circle-line text-xl text-emerald-400"></i></div>
                    <div><h3 class="font-semibold text-white">添加购入渠道</h3><p class="text-xs text-slate-500">例如：淘宝、京东、线下门店</p></div>
                </div>
                <div class="flex gap-3">
                    <input type="text" id="newChannel" class="input flex-1" placeholder="输入渠道名称">
                    <button onclick="addPurchaseChannel()" class="btn btn-primary"><i class="ri-add-line"></i>添加</button>
                </div>
            </div>

            <button onclick="resetPurchaseChannels()" class="btn btn-ghost w-full text-slate-500 text-sm">恢复默认渠道</button>
        </div>
    `;
        }

        function addPurchaseChannel() {
            const input = document.getElementById('newChannel');
            if (!input) return;
            const channel = input.value.trim();
            if (!channel) { toast('请输入渠道名称', 'error'); return; }
            if (App.purchaseChannels.includes(channel)) { toast('该渠道已存在', 'error'); return; }
            savePurchaseChannels([...App.purchaseChannels, channel]);
            toast('购入渠道已添加');
            renderView();
        }

        function removePurchaseChannel(idx) {
            const channel = App.purchaseChannels[idx];
            if (!channel) return;
            if (!confirm(`确定删除渠道「${channel}」？已保存到物品中的该值不会被修改。`)) return;
            const next = [...App.purchaseChannels];
            next.splice(idx, 1);
            savePurchaseChannels(next);
            toast('购入渠道已删除');
            renderView();
        }

        function openEditChannel(idx) {
            const channel = App.purchaseChannels[idx];
            const row = document.getElementById('channelRow' + idx);
            if (!channel || !row) return;
            row.innerHTML = `
                <div class="w-full space-y-2">
                    <label class="block text-[10px] text-slate-500">渠道名称</label>
                    <div class="flex gap-2">
                        <input type="text" id="editChannel${idx}" class="input !py-1 text-xs flex-1" value="${esc(channel)}">
                        <button onclick="saveEditChannel(${idx})" class="btn btn-primary btn-sm text-xs"><i class="ri-check-line"></i>保存</button>
                        <button onclick="renderView()" class="btn btn-ghost btn-sm text-xs">取消</button>
                    </div>
                </div>`;
        }

        function saveEditChannel(idx) {
            const input = document.getElementById('editChannel' + idx);
            if (!input) return;
            const channel = input.value.trim();
            if (!channel) { toast('渠道名称不能为空', 'error'); return; }
            const duplicated = App.purchaseChannels.some((c, i) => i !== idx && c === channel);
            if (duplicated) { toast('该渠道已存在', 'error'); return; }
            const next = [...App.purchaseChannels];
            next[idx] = channel;
            savePurchaseChannels(next);
            toast('购入渠道已更新');
            renderView();
        }

        function resetPurchaseChannels() {
            if (!confirm('确定恢复默认购入渠道？')) return;
            savePurchaseChannels([...defaultPurchaseChannels]);
            toast('已恢复默认渠道');
            renderView();
        }

        // ---------- 过期日期工具 ----------
        function daysUntilDate(dateStr) {
            if (!dateStr) return Infinity;
            const today = new Date(); today.setHours(0, 0, 0, 0);
            const target = new Date(dateStr); target.setHours(0, 0, 0, 0);
            return Math.ceil((target - today) / (1000 * 60 * 60 * 24));
        }
        function daysUntilExpiry(dateStr) {
            return daysUntilDate(dateStr);
        }
        function daysUntilReminder(dateStr) {
            return daysUntilDate(dateStr);
        }
        function reminderDisplayDate(item) {
            if (!item) return '';
            if (item.reminder_due_date) return item.reminder_due_date;
            return item.reminder_next_date || item.reminder_date || '';
        }
        function expiryColor(dateStr) {
            const days = daysUntilExpiry(dateStr);
            if (days < 0) return 'text-red-400';
            if (days <= 7) return 'text-amber-400';
            if (days <= 30) return 'text-yellow-400';
            return 'text-emerald-400';
        }
        function expiryBg(dateStr) {
            const days = daysUntilExpiry(dateStr);
            if (days < 0) return 'bg-red-500/10';
            if (days <= 7) return 'bg-amber-500/10';
            if (days <= 30) return 'bg-yellow-500/5';
            return 'bg-white/5';
        }
        function expiryLabel(dateStr) {
            const days = daysUntilExpiry(dateStr);
            if (days < 0) return `(已过期 ${Math.abs(days)} 天)`;
            if (days === 0) return '(今天过期)';
            if (days === 1) return '(明天过期)';
            return `(剩余 ${days} 天)`;
        }
        function reminderCycleLabel(value, unit) {
            const n = Math.max(1, Number(value || 1));
            if (unit === 'day') return `每 ${n} 天`;
            if (unit === 'week') return `每 ${n} 周`;
            if (unit === 'year') return `每 ${n} 年`;
            return '未设置周期';
        }
        function reminderDueLabel(dateStr) {
            const days = daysUntilReminder(dateStr);
            if (!Number.isFinite(days)) return '无提醒日期';
            if (days < 0) return `已超期 ${Math.abs(days)} 天`;
            if (days === 0) return '今天提醒';
            if (days === 1) return '明天提醒';
            return `${days} 天后提醒`;
        }

        // ============================================================
        // 🎬 初始化
        // ============================================================
        initTheme();
        initCustomSelects();
        setupDateInputPlaceholders();
        initFormEmojiPickers();
        updateHelpModeMenuUI();
        if (App.helpMode)
            applyHelpModeHints(document);
        // 设置版本号
        document.getElementById('appVersion').textContent = APP_VERSION;
        // 应用默认排序设置
        const initSort = App.sortSettings.items_default.split(':');
        if (initSort.length === 2) { App.itemsSort = initSort[0]; App.itemsOrder = initSort[1]; }
        renderView();
    </script>
</body>

</html>
