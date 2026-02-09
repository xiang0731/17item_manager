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

define('DB_PATH', __DIR__ . '/data/items_db.sqlite');
define('UPLOAD_DIR', __DIR__ . '/data/uploads/');
define('TRASH_DIR', __DIR__ . '/data/uploads/trash/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB

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
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("PRAGMA journal_mode=WAL");
        $db->exec("PRAGMA foreign_keys=ON");
        initSchema($db);
    }
    return $db;
}

function initSchema($db)
{
    $db->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        icon TEXT DEFAULT '📦',
        color TEXT DEFAULT '#3b82f6',
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS locations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        parent_id INTEGER DEFAULT 0,
        description TEXT DEFAULT '',
        sort_order INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        category_id INTEGER DEFAULT 0,
        location_id INTEGER DEFAULT 0,
        quantity INTEGER DEFAULT 1,
        description TEXT DEFAULT '',
        image TEXT DEFAULT '',
        barcode TEXT DEFAULT '',
        purchase_date TEXT DEFAULT '',
        purchase_price REAL DEFAULT 0,
        tags TEXT DEFAULT '',
        status TEXT DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

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

    // 数据库迁移：位置层级已取消，统一扁平化
    try {
        $db->exec("UPDATE locations SET parent_id=0 WHERE parent_id IS NOT NULL AND parent_id!=0");
    } catch (Exception $e) {
    }

    // 数据库迁移：中文状态值 -> 英文标识
    try {
        $db->exec("UPDATE items SET status='active' WHERE status='使用中' OR status IS NULL OR status=''");
        $db->exec("UPDATE items SET status='archived' WHERE status='已归档'");
        $db->exec("UPDATE items SET status='sold' WHERE status='已转卖'");
    } catch (Exception $e) {
    }

    // 插入默认分类（仅在表为空时）
    $count = $db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($count == 0) {
        $defaults = [
            ['电子设备', '💻', '#3b82f6'],
            ['家具家居', '🛋️', '#8b5cf6'],
            ['厨房用品', '🍳', '#f59e0b'],
            ['衣物鞋帽', '👔', '#ec4899'],
            ['书籍文档', '📚', '#10b981'],
            ['工具五金', '🔧', '#6366f1'],
            ['运动户外', '⚽', '#14b8a6'],
            ['其他', '📦', '#64748b'],
        ];
        $stmt = $db->prepare("INSERT INTO categories (name, icon, color) VALUES (?, ?, ?)");
        foreach ($defaults as $cat)
            $stmt->execute($cat);
    }

    $count = $db->query("SELECT COUNT(*) FROM locations")->fetchColumn();
    if ($count == 0) {
        $defaults = [
            ['客厅', 0],
            ['卧室', 0],
            ['厨房', 0],
            ['书房', 0],
        ];
        $stmt = $db->prepare("INSERT INTO locations (name, parent_id) VALUES (?, ?)");
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

function moveUploadFilesToTrash()
{
    if (!is_dir(UPLOAD_DIR))
        return 0;
    if (!is_dir(TRASH_DIR))
        mkdir(TRASH_DIR, 0755, true);

    $moved = 0;
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
    return $v;
}

// ============================================================
// 🌐 API 路由处理
// ============================================================
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $api = $_GET['api'];
    $method = $_SERVER['REQUEST_METHOD'];

    try {
        $db = getDB();
        $result = ['success' => false, 'message' => '未知操作'];

        switch ($api) {
            // ---------- 仪表盘 ----------
            case 'dashboard':
                $totalItems = $db->query("SELECT COALESCE(SUM(quantity),0) FROM items WHERE deleted_at IS NULL")->fetchColumn();
                $totalKinds = $db->query("SELECT COUNT(*) FROM items WHERE deleted_at IS NULL")->fetchColumn();
                $totalCategories = $db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
                $totalLocations = $db->query("SELECT COUNT(*) FROM locations")->fetchColumn();
                $totalValue = $db->query("SELECT COALESCE(SUM(purchase_price * quantity),0) FROM items WHERE deleted_at IS NULL")->fetchColumn();
                $recentItems = $db->query("SELECT i.*, c.name as category_name, c.icon as category_icon, l.name as location_name FROM items i LEFT JOIN categories c ON i.category_id=c.id LEFT JOIN locations l ON i.location_id=l.id WHERE i.deleted_at IS NULL ORDER BY i.updated_at DESC LIMIT 8")->fetchAll();
                $categoryStats = $db->query("SELECT c.name, c.icon, c.color, COUNT(i.id) as count, COALESCE(SUM(i.quantity),0) as total_qty FROM categories c LEFT JOIN items i ON c.id=i.category_id AND i.deleted_at IS NULL AND i.status='active' GROUP BY c.id ORDER BY count DESC")->fetchAll();
                $statusStats = $db->query("SELECT status, COUNT(*) as count, COALESCE(SUM(quantity),0) as total_qty FROM items WHERE deleted_at IS NULL GROUP BY status ORDER BY total_qty DESC")->fetchAll();
                $uncategorizedQty = $db->query("SELECT COALESCE(SUM(i.quantity),0) FROM items i LEFT JOIN categories c ON i.category_id=c.id WHERE i.deleted_at IS NULL AND i.status='active' AND (i.category_id=0 OR c.id IS NULL)")->fetchColumn();
                $expiringItems = $db->query("SELECT i.*, c.name as category_name, c.icon as category_icon, l.name as location_name FROM items i LEFT JOIN categories c ON i.category_id=c.id LEFT JOIN locations l ON i.location_id=l.id WHERE i.deleted_at IS NULL AND i.expiry_date != '' AND i.expiry_date IS NOT NULL ORDER BY i.expiry_date ASC LIMIT 10")->fetchAll();
                $result = ['success' => true, 'data' => compact('totalItems', 'totalKinds', 'totalCategories', 'totalLocations', 'totalValue', 'recentItems', 'categoryStats', 'statusStats', 'uncategorizedQty', 'expiringItems')];
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
                            OR CAST(i.quantity AS TEXT) LIKE ?
                            OR CAST(i.purchase_price AS TEXT) LIKE ?
                            OR c.name LIKE ?
                            OR l.name LIKE ?
                            OR i.status LIKE ?
                            OR (CASE i.status WHEN 'active' THEN '使用中' WHEN 'archived' THEN '已归档' WHEN 'sold' THEN '已转卖' ELSE i.status END) LIKE ?
                        )";
                        $s = "%$search%";
                        $params = array_merge($params, [$s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s, $s]);
                    }
                    if ($category !== 0) {
                        if ($category === -1) {
                            $where[] = "(i.category_id=0 OR c.id IS NULL)";
                        } else {
                            $where[] = "i.category_id = ?";
                            $params[] = $category;
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

                    $countStmt = $db->prepare("SELECT COUNT(*) FROM items i LEFT JOIN categories c ON i.category_id=c.id LEFT JOIN locations l ON i.location_id=l.id $whereSQL");
                    $countStmt->execute($params);
                    $total = $countStmt->fetchColumn();

                    $orderBy = "i.$sortCol $order";
                    if ($sortCol === 'expiry_date') {
                        // 过期日期排序时，把未设置日期的记录放到最后
                        $orderBy = "(i.expiry_date='' OR i.expiry_date IS NULL) ASC, i.expiry_date $order";
                    }

                    $stmt = $db->prepare("SELECT i.*, c.name as category_name, c.icon as category_icon, c.color as category_color, l.name as location_name FROM items i LEFT JOIN categories c ON i.category_id=c.id LEFT JOIN locations l ON i.location_id=l.id $whereSQL ORDER BY $orderBy LIMIT $limit OFFSET $offset");
                    $stmt->execute($params);
                    $items = $stmt->fetchAll();

                    $result = ['success' => true, 'data' => $items, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $limit)];
                } elseif ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    if (empty($data['name'])) {
                        $result = ['success' => false, 'message' => '物品名称不能为空'];
                        break;
                    }
                    $stmt = $db->prepare("INSERT INTO items (name, category_id, location_id, quantity, description, image, barcode, purchase_date, purchase_price, tags, status, expiry_date, purchase_from, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([
                        $data['name'],
                        intval($data['category_id'] ?? 0),
                        intval($data['location_id'] ?? 0),
                        max(0, intval($data['quantity'] ?? 1)),
                        $data['description'] ?? '',
                        $data['image'] ?? '',
                        $data['barcode'] ?? '',
                        $data['purchase_date'] ?? '',
                        floatval($data['purchase_price'] ?? 0),
                        $data['tags'] ?? '',
                        normalizeStatusValue($data['status'] ?? 'active'),
                        $data['expiry_date'] ?? '',
                        $data['purchase_from'] ?? '',
                        $data['notes'] ?? ''
                    ]);
                    $result = ['success' => true, 'message' => '添加成功', 'id' => $db->lastInsertId()];
                }
                break;

            case 'items/update':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    if (empty($data['id'])) {
                        $result = ['success' => false, 'message' => '缺少物品ID'];
                        break;
                    }
                    $stmt = $db->prepare("UPDATE items SET name=?, category_id=?, location_id=?, quantity=?, description=?, image=?, barcode=?, purchase_date=?, purchase_price=?, tags=?, status=?, expiry_date=?, purchase_from=?, notes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
                    $stmt->execute([
                        $data['name'],
                        intval($data['category_id'] ?? 0),
                        intval($data['location_id'] ?? 0),
                        max(0, intval($data['quantity'] ?? 1)),
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
                        intval($data['id'])
                    ]);
                    $result = ['success' => true, 'message' => '更新成功'];
                }
                break;

            case 'items/delete':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    // 软删除：移入回收站，图片移到 trash 目录
                    $img = $db->query("SELECT image FROM items WHERE id=$id")->fetchColumn();
                    if ($img && file_exists(UPLOAD_DIR . $img))
                        @rename(UPLOAD_DIR . $img, TRASH_DIR . $img);
                    $db->exec("UPDATE items SET deleted_at=datetime('now','localtime') WHERE id=$id");
                    $result = ['success' => true, 'message' => '已移入回收站'];
                }
                break;

            case 'items/batch-delete':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $ids = array_map('intval', $data['ids'] ?? []);
                    if ($ids) {
                        $placeholders = implode(',', $ids);
                        $images = $db->query("SELECT image FROM items WHERE id IN ($placeholders) AND image != ''")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($images as $img) {
                            if (file_exists(UPLOAD_DIR . $img))
                                @rename(UPLOAD_DIR . $img, TRASH_DIR . $img);
                        }
                        $db->exec("UPDATE items SET deleted_at=datetime('now','localtime') WHERE id IN ($placeholders)");
                    }
                    $result = ['success' => true, 'message' => '已移入回收站'];
                }
                break;

            case 'items/reset-all':
                if ($method === 'POST') {
                    $images = $db->query("SELECT image FROM items WHERE image != ''")->fetchAll(PDO::FETCH_COLUMN);
                    $images = array_unique(array_filter($images));
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
                    try {
                        $db->exec("DELETE FROM sqlite_sequence WHERE name='items'");
                    } catch (Exception $e) { /* 某些 SQLite 版本可能无该表 */ }
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
                        $stmt = $db->prepare("INSERT INTO items (name, category_id, location_id, quantity, description, image, barcode, purchase_date, purchase_price, tags, status, expiry_date, purchase_from, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
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

                            try {
                                $stmt->execute([
                                    $name,
                                    intval($row['category_id'] ?? 0),
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
                    $moved = moveUploadFilesToTrash();

                    $db->beginTransaction();
                    try {
                        $db->exec("DELETE FROM items");
                        $db->exec("DELETE FROM categories");
                        $db->exec("DELETE FROM locations");
                        try {
                            $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('items','categories','locations')");
                        } catch (Exception $e) { /* 某些 SQLite 版本可能无该表 */ }
                        $db->commit();
                    } catch (Exception $e) {
                        if ($db->inTransaction())
                            $db->rollBack();
                        throw $e;
                    }

                    // 重新注入默认分类和默认位置
                    initSchema($db);
                    $result = ['success' => true, 'message' => '已恢复默认环境，上传目录文件已移入 trash 目录', 'moved_images' => $moved];
                }
                break;

            case 'system/load-demo':
                if ($method === 'POST') {
                    $moved = moveUploadFilesToTrash();

                    $db->beginTransaction();
                    try {
                        $db->exec("DELETE FROM items");
                        $db->exec("DELETE FROM categories");
                        $db->exec("DELETE FROM locations");
                        try {
                            $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('items','categories','locations')");
                        } catch (Exception $e) { /* 某些 SQLite 版本可能无该表 */ }

                        // 先恢复默认分类与位置，再叠加展示用数据
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

                        $insertLocation = $db->prepare("INSERT INTO locations (name, parent_id, description) VALUES (?,?,?)");
                        $locMap = $loadLocationMap();
                        if (!isset($locMap['储物间'])) {
                            $insertLocation->execute(['储物间', 0, '集中存放不常用物品']);
                            $locMap = $loadLocationMap();
                        }
                        if (!isset($locMap['阳台'])) {
                            $insertLocation->execute(['阳台', 0, '户外和工具相关物品']);
                            $locMap = $loadLocationMap();
                        }
                        if (!isset($locMap['电视柜'])) {
                            $insertLocation->execute(['电视柜', 0, '位置示例']);
                            $locMap = $loadLocationMap();
                        }
                        if (!isset($locMap['书桌抽屉'])) {
                            $insertLocation->execute(['书桌抽屉', 0, '位置示例']);
                            $locMap = $loadLocationMap();
                        }

                        $demoItems = [
                            [
                                'name' => 'MacBook Air M2',
                                'category' => '电子设备',
                                'location' => '书房',
                                'quantity' => 1,
                                'description' => '日常办公主力设备',
                                'barcode' => 'SN-MBA-2026',
                                'purchase_date' => date('Y-m-d', strtotime('-420 days')),
                                'purchase_price' => 7999,
                                'tags' => '电脑,办公',
                                'status' => 'active',
                                'expiry_date' => '',
                                'purchase_from' => '京东',
                                'notes' => '附带保护壳与扩展坞'
                            ],
                            [
                                'name' => 'AirPods Pro',
                                'category' => '电子设备',
                                'location' => '卧室',
                                'quantity' => 1,
                                'description' => '蓝牙耳机',
                                'barcode' => 'SN-AIRPODS-02',
                                'purchase_date' => date('Y-m-d', strtotime('-260 days')),
                                'purchase_price' => 1499,
                                'tags' => '耳机,音频',
                                'status' => 'active',
                                'expiry_date' => '',
                                'purchase_from' => '淘宝',
                                'notes' => '配件齐全'
                            ],
                            [
                                'name' => '机械键盘',
                                'category' => '电子设备',
                                'location' => '书桌抽屉',
                                'quantity' => 1,
                                'description' => '备用键盘',
                                'barcode' => 'KB-RED-87',
                                'purchase_date' => date('Y-m-d', strtotime('-540 days')),
                                'purchase_price' => 399,
                                'tags' => '键盘,外设',
                                'status' => 'archived',
                                'expiry_date' => '',
                                'purchase_from' => '拼多多',
                                'notes' => '归档展示状态'
                            ],
                            [
                                'name' => '二手显示器',
                                'category' => '电子设备',
                                'location' => '储物间',
                                'quantity' => 1,
                                'description' => '已转卖示例物品',
                                'barcode' => 'MON-USED-24',
                                'purchase_date' => date('Y-m-d', strtotime('-800 days')),
                                'purchase_price' => 1200,
                                'tags' => '显示器,转卖',
                                'status' => 'sold',
                                'expiry_date' => '',
                                'purchase_from' => '闲鱼',
                                'notes' => '用于状态统计展示'
                            ],
                            [
                                'name' => '胶囊咖啡机',
                                'category' => '厨房用品',
                                'location' => '厨房',
                                'quantity' => 1,
                                'description' => '家用咖啡机',
                                'barcode' => 'COFFEE-01',
                                'purchase_date' => date('Y-m-d', strtotime('-320 days')),
                                'purchase_price' => 899,
                                'tags' => '咖啡,厨房',
                                'status' => 'active',
                                'expiry_date' => '',
                                'purchase_from' => '线下',
                                'notes' => '常用设备'
                            ],
                            [
                                'name' => '维生素 D3',
                                'category' => '其他',
                                'location' => '厨房',
                                'quantity' => 2,
                                'description' => '保健品',
                                'barcode' => 'HEALTH-D3-01',
                                'purchase_date' => date('Y-m-d', strtotime('-60 days')),
                                'purchase_price' => 128,
                                'tags' => '保健,补剂',
                                'status' => 'active',
                                'expiry_date' => date('Y-m-d', strtotime('+5 days')),
                                'purchase_from' => '线下',
                                'notes' => '即将过期示例'
                            ],
                            [
                                'name' => '车载灭火器',
                                'category' => '工具五金',
                                'location' => '阳台',
                                'quantity' => 1,
                                'description' => '安全应急用品',
                                'barcode' => 'SAFE-FIRE-01',
                                'purchase_date' => date('Y-m-d', strtotime('-480 days')),
                                'purchase_price' => 89,
                                'tags' => '安全,应急',
                                'status' => 'active',
                                'expiry_date' => date('Y-m-d', strtotime('-12 days')),
                                'purchase_from' => '京东',
                                'notes' => '已过期示例'
                            ],
                            [
                                'name' => '沐浴露补充装',
                                'category' => '其他',
                                'location' => '储物间',
                                'quantity' => 3,
                                'description' => '家庭日用品',
                                'barcode' => 'HOME-BATH-03',
                                'purchase_date' => date('Y-m-d', strtotime('-30 days')),
                                'purchase_price' => 75,
                                'tags' => '日用品,家居',
                                'status' => 'active',
                                'expiry_date' => date('Y-m-d', strtotime('+25 days')),
                                'purchase_from' => '拼多多',
                                'notes' => '30 天内过期示例'
                            ],
                            [
                                'name' => '训练足球',
                                'category' => '运动户外',
                                'location' => '阳台',
                                'quantity' => 1,
                                'description' => '周末运动使用',
                                'barcode' => 'SPORT-BALL-01',
                                'purchase_date' => date('Y-m-d', strtotime('-210 days')),
                                'purchase_price' => 199,
                                'tags' => '运动,户外',
                                'status' => 'active',
                                'expiry_date' => '',
                                'purchase_from' => '淘宝',
                                'notes' => '展示分类统计'
                            ],
                            [
                                'name' => '设计模式（第2版）',
                                'category' => '书籍文档',
                                'location' => '书房',
                                'quantity' => 1,
                                'description' => '技术书籍',
                                'barcode' => 'BOOK-DESIGN-02',
                                'purchase_date' => date('Y-m-d', strtotime('-700 days')),
                                'purchase_price' => 88,
                                'tags' => '书籍,学习',
                                'status' => 'archived',
                                'expiry_date' => '',
                                'purchase_from' => '京东',
                                'notes' => '归档示例'
                            ],
                            [
                                'name' => '纪念手表',
                                'category' => '电子设备',
                                'location' => '卧室',
                                'quantity' => 1,
                                'description' => '礼品来源示例',
                                'barcode' => 'GIFT-WATCH-01',
                                'purchase_date' => date('Y-m-d', strtotime('-95 days')),
                                'purchase_price' => 0,
                                'tags' => '礼物,收藏',
                                'status' => 'active',
                                'expiry_date' => '',
                                'purchase_from' => '礼品',
                                'notes' => '展示购入渠道'
                            ],
                            [
                                'name' => '未分类收纳箱',
                                'category' => '',
                                'location' => '',
                                'quantity' => 2,
                                'description' => '用于展示未分类/未设定位置',
                                'barcode' => 'BOX-UNCAT-01',
                                'purchase_date' => date('Y-m-d', strtotime('-15 days')),
                                'purchase_price' => 59,
                                'tags' => '收纳,未分类',
                                'status' => 'active',
                                'expiry_date' => '',
                                'purchase_from' => '线下',
                                'notes' => '演示筛选与统计'
                            ],
                        ];

                        $insertItem = $db->prepare("INSERT INTO items (name, category_id, location_id, quantity, description, image, barcode, purchase_date, purchase_price, tags, status, expiry_date, purchase_from, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        $created = 0;
                        foreach ($demoItems as $item) {
                            $categoryId = isset($catIdByName[$item['category']]) ? intval($catIdByName[$item['category']]) : 0;
                            $locationId = isset($locMap[$item['location']]) ? intval($locMap[$item['location']]) : 0;
                            $insertItem->execute([
                                $item['name'],
                                $categoryId,
                                $locationId,
                                max(0, intval($item['quantity'] ?? 1)),
                                $item['description'] ?? '',
                                '',
                                $item['barcode'] ?? '',
                                normalizeDateYmd($item['purchase_date'] ?? '') ?? '',
                                floatval($item['purchase_price'] ?? 0),
                                $item['tags'] ?? '',
                                normalizeStatusValue($item['status'] ?? 'active'),
                                normalizeDateYmd($item['expiry_date'] ?? '') ?? '',
                                $item['purchase_from'] ?? '',
                                $item['notes'] ?? ''
                            ]);
                            $created++;
                        }

                        $db->commit();
                        $result = ['success' => true, 'message' => "展示模式已加载：$created 件演示物品已就绪", 'created' => $created, 'moved_images' => $moved];
                    } catch (Exception $e) {
                        if ($db->inTransaction())
                            $db->rollBack();
                        throw $e;
                    }
                }
                break;

            // ---------- 回收站 ----------
            case 'trash':
                if ($method === 'GET') {
                    $trashItems = $db->query("SELECT i.*, c.name as category_name, c.icon as category_icon, c.color as category_color, l.name as location_name FROM items i LEFT JOIN categories c ON i.category_id=c.id LEFT JOIN locations l ON i.location_id=l.id WHERE i.deleted_at IS NOT NULL ORDER BY i.deleted_at DESC")->fetchAll();
                    $result = ['success' => true, 'data' => $trashItems];
                }
                break;

            case 'trash/restore':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    $img = $db->query("SELECT image FROM items WHERE id=$id")->fetchColumn();
                    if ($img && file_exists(TRASH_DIR . $img))
                        @rename(TRASH_DIR . $img, UPLOAD_DIR . $img);
                    $db->exec("UPDATE items SET deleted_at=NULL, updated_at=datetime('now','localtime') WHERE id=$id");
                    $result = ['success' => true, 'message' => '已恢复'];
                }
                break;

            case 'trash/batch-restore':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $ids = array_map('intval', $data['ids'] ?? []);
                    if ($ids) {
                        $placeholders = implode(',', $ids);
                        $images = $db->query("SELECT image FROM items WHERE id IN ($placeholders) AND image != ''")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($images as $img) {
                            if (file_exists(TRASH_DIR . $img))
                                @rename(TRASH_DIR . $img, UPLOAD_DIR . $img);
                        }
                        $db->exec("UPDATE items SET deleted_at=NULL, updated_at=datetime('now','localtime') WHERE id IN ($placeholders)");
                    }
                    $result = ['success' => true, 'message' => '已全部恢复'];
                }
                break;

            case 'trash/permanent-delete':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    $img = $db->query("SELECT image FROM items WHERE id=$id")->fetchColumn();
                    if ($img && file_exists(TRASH_DIR . $img))
                        unlink(TRASH_DIR . $img);
                    $db->exec("DELETE FROM items WHERE id=$id");
                    $result = ['success' => true, 'message' => '已彻底删除'];
                }
                break;

            case 'trash/empty':
                if ($method === 'POST') {
                    $images = $db->query("SELECT image FROM items WHERE deleted_at IS NOT NULL AND image != ''")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($images as $img) {
                        if (file_exists(TRASH_DIR . $img))
                            unlink(TRASH_DIR . $img);
                    }
                    $db->exec("DELETE FROM items WHERE deleted_at IS NOT NULL");
                    $result = ['success' => true, 'message' => '回收站已清空'];
                }
                break;

            // ---------- 分类 CRUD ----------
            case 'categories':
                if ($method === 'GET') {
                    $cats = $db->query("SELECT c.*, (SELECT COUNT(*) FROM items WHERE category_id=c.id AND deleted_at IS NULL) as item_count FROM categories c ORDER BY c.sort_order, c.name")->fetchAll();
                    $result = ['success' => true, 'data' => $cats];
                } elseif ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    if (empty($data['name'])) {
                        $result = ['success' => false, 'message' => '分类名称不能为空'];
                        break;
                    }
                    $stmt = $db->prepare("INSERT INTO categories (name, icon, color) VALUES (?,?,?)");
                    $stmt->execute([$data['name'], $data['icon'] ?? '📦', $data['color'] ?? '#3b82f6']);
                    $result = ['success' => true, 'message' => '分类添加成功', 'id' => $db->lastInsertId()];
                }
                break;

            case 'categories/update':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $stmt = $db->prepare("UPDATE categories SET name=?, icon=?, color=? WHERE id=?");
                    $stmt->execute([$data['name'], $data['icon'] ?? '📦', $data['color'] ?? '#3b82f6', intval($data['id'])]);
                    $result = ['success' => true, 'message' => '分类更新成功'];
                }
                break;

            case 'categories/delete':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    $db->exec("UPDATE items SET category_id=0 WHERE category_id=$id");
                    $db->exec("DELETE FROM categories WHERE id=$id");
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
                    $stmt = $db->prepare("INSERT INTO locations (name, parent_id, description) VALUES (?,?,?)");
                    $stmt->execute([$data['name'], 0, $data['description'] ?? '']);
                    $result = ['success' => true, 'message' => '位置添加成功', 'id' => $db->lastInsertId()];
                }
                break;

            case 'locations/update':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $stmt = $db->prepare("UPDATE locations SET name=?, parent_id=?, description=? WHERE id=?");
                    $stmt->execute([$data['name'], 0, $data['description'] ?? '', intval($data['id'])]);
                    $result = ['success' => true, 'message' => '位置更新成功'];
                }
                break;

            case 'locations/delete':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    $db->exec("UPDATE items SET location_id=0 WHERE location_id=$id");
                    $db->exec("DELETE FROM locations WHERE id=$id");
                    $result = ['success' => true, 'message' => '位置删除成功'];
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
                        $result = ['success' => true, 'message' => "成功上传 $uploaded 张图片", 'uploaded' => $uploaded, 'map' => $map, 'errors' => $errors];
                    }
                }
                break;

            // ---------- 数据导出 ----------
            case 'export':
                $items = $db->query("SELECT i.*, c.name as category_name, l.name as location_name FROM items i LEFT JOIN categories c ON i.category_id=c.id LEFT JOIN locations l ON i.location_id=l.id WHERE i.deleted_at IS NULL ORDER BY i.id")->fetchAll();
                $categories = $db->query("SELECT * FROM categories ORDER BY id")->fetchAll();
                $locations = $db->query("SELECT * FROM locations ORDER BY id")->fetchAll();
                $result = ['success' => true, 'data' => ['items' => $items, 'categories' => $categories, 'locations' => $locations, 'exported_at' => date('Y-m-d H:i:s'), 'version' => '1.2.0']];
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
                        $stmtItem = $db->prepare("INSERT INTO items (name, category_id, location_id, quantity, description, image, barcode, purchase_date, purchase_price, tags, status, expiry_date, purchase_from, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        foreach ($data['items'] as $item) {
                            $catId = 0;
                            $locId = 0;
                            if (!empty($item['category_name'])) {
                                $cat = $db->query("SELECT id FROM categories WHERE name=" . $db->quote($item['category_name']))->fetchColumn();
                                $catId = $cat ?: 0;
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
                            $stmtItem->execute([
                                $item['name'] ?? '未命名',
                                $catId,
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
                                $item['notes'] ?? ''
                            ]);
                            $imported++;
                        }
                        $db->commit();
                        $result = ['success' => true, 'message' => "成功导入 $imported 件物品"];
                    } catch (Exception $e) {
                        $db->rollBack();
                        $result = ['success' => false, 'message' => '导入失败: ' . $e->getMessage()];
                    }
                }
                break;
        }

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '服务器错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ---------- 图片访问 ----------
if (isset($_GET['img'])) {
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
getDB(); // 确保数据库初始化
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
            transition: all 0.2s;
            outline: none;
        }

        .input:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15);
        }

        .input::placeholder {
            color: #475569;
        }

        select.input {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 32px;
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

        body.light .category-progress-track {
            background: rgba(148, 163, 184, 0.24);
        }

        /* 仪表盘过期提醒（浅色模式优化） */
        body.light .expiry-remind-item {
            background: rgba(148, 163, 184, 0.08);
            border-color: rgba(148, 163, 184, 0.24);
        }

        body.light .expiry-remind-item.expiry-warning {
            background: rgba(245, 158, 11, 0.06);
            border-color: rgba(245, 158, 11, 0.2);
        }

        body.light .expiry-remind-item.expiry-urgent {
            background: rgba(245, 158, 11, 0.09);
            border-color: rgba(245, 158, 11, 0.26);
        }

        body.light .expiry-remind-item.expiry-expired {
            background: rgba(239, 68, 68, 0.08);
            border-color: rgba(239, 68, 68, 0.24);
        }

        body.light .expiry-remind-item .expiry-meta {
            color: #64748b;
            font-weight: 500;
        }

        body.light .expiry-remind-item.expiry-warning .expiry-meta {
            color: #b45309;
        }

        body.light .expiry-remind-item.expiry-urgent .expiry-meta {
            color: #92400e;
        }

        body.light .expiry-remind-item.expiry-expired .expiry-meta {
            color: #b91c1c;
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
            <div class="sidebar-link" data-view="locations" onclick="switchView('locations')">
                <i class="ri-map-pin-line"></i><span class="sidebar-text">位置管理</span>
            </div>
            <div class="sidebar-link" data-view="categories" onclick="switchView('categories')">
                <i class="ri-price-tag-3-line"></i><span class="sidebar-text">分类管理</span>
            </div>

            <div class="mt-6 mb-2 px-4">
                <div class="border-t border-white/5"></div>
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
                        <i class="ri-sort-asc"></i><span class="sidebar-text">排序设置</span>
                    </div>
                    <div class="sidebar-link sidebar-sub" data-view="status-settings"
                        onclick="switchView('status-settings')">
                        <i class="ri-list-settings-line"></i><span class="sidebar-text">状态管理</span>
                    </div>
                    <div class="sidebar-link sidebar-sub" data-view="channel-settings"
                        onclick="switchView('channel-settings')">
                        <i class="ri-shopping-bag-line"></i><span class="sidebar-text">购入渠道管理</span>
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
                <button onclick="closeItemModal()" class="text-slate-400 hover:text-white transition"><i
                        class="ri-close-line text-2xl"></i></button>
            </div>
            <form id="itemForm" onsubmit="return saveItem(event)">
                <input type="hidden" id="itemId">
                <input type="hidden" id="itemImage">
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
                        <label class="block text-sm text-slate-400 mb-1.5">数量</label>
                        <input type="number" id="itemQuantity" class="input" value="1" min="0">
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
                        <label class="block text-sm text-slate-400 mb-1.5">标签 (逗号分隔)</label>
                        <input type="text" id="itemTags" class="input" placeholder="例如: 重要, 易碎, 保修期内">
                    </div>
                    <div class="sm:col-span-2 md:col-span-3">
                        <label class="block text-sm text-slate-400 mb-1.5">描述</label>
                        <textarea id="itemDesc" class="input" rows="2" placeholder="物品描述..."></textarea>
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
                    <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i>保存</button>
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
                    <div><label class="block text-sm text-slate-400 mb-1.5">图标 (Emoji)</label><input type="text"
                            id="catIcon" class="input" value="📦" placeholder="📦"></div>
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

    <script>
        // ============================================================
        // 🚀 应用状态与核心逻辑
        // ============================================================
        // ---------- 排序设置 ----------
        const SORT_SETTINGS_KEY = 'item_manager_sort_settings';
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

        const ITEMS_SIZE_KEY = 'item_manager_items_size';
        function loadItemsSize() { return localStorage.getItem(ITEMS_SIZE_KEY) || 'large'; }
        function saveItemsSize(s) { localStorage.setItem(ITEMS_SIZE_KEY, s); App.itemsSize = s; }

        // ---------- 属性显示设置 ----------
        const ITEM_ATTRS_KEY = 'item_manager_item_attrs';
        const allItemAttrs = [
            { key: 'category', label: '分类' },
            { key: 'location', label: '位置' },
            { key: 'quantity', label: '件数' },
            { key: 'price', label: '价格' },
            { key: 'expiry', label: '过期日期' },
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
        function saveItemAttrs(arr) { localStorage.setItem(ITEM_ATTRS_KEY, JSON.stringify(arr)); App.itemAttrs = arr; }
        function toggleItemAttr(key) {
            const idx = App.itemAttrs.indexOf(key);
            if (idx > -1) App.itemAttrs.splice(idx, 1);
            else App.itemAttrs.push(key);
            saveItemAttrs(App.itemAttrs);
            renderItemsFast({ openAttrPanel: true });
        }
        function hasAttr(key) { return App.itemAttrs.includes(key); }

        // ---------- 状态管理 ----------
        const STATUS_KEY = 'item_manager_statuses';
        const STATUS_KEY_TO_LABEL_MAP = { active: '使用中', archived: '已归档', sold: '已转卖' };
        const STATUS_LABEL_TO_KEY_MAP = { 使用中: 'active', 已归档: 'archived', 已转卖: 'sold' };
        const defaultStatuses = [
            { key: 'active', label: '使用中', icon: 'ri-checkbox-circle-line', color: 'text-emerald-400', badge: 'badge-active' },
            { key: 'archived', label: '已归档', icon: 'ri-archive-line', color: 'text-slate-400', badge: 'badge-archived' },
            { key: 'sold', label: '已转卖', icon: 'ri-share-forward-line', color: 'text-sky-400', badge: 'badge-lent' },
        ];
        const STATUS_ICON_OPTIONS = ['ri-checkbox-circle-line', 'ri-archive-line', 'ri-share-forward-line', 'ri-tools-line', 'ri-error-warning-line', 'ri-time-line', 'ri-shopping-bag-line', 'ri-gift-line', 'ri-heart-line', 'ri-star-line'];
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
                    <div id="${pickerId}Menu" class="status-icon-picker-menu hidden absolute z-30 mt-1 w-full max-h-56 overflow-auto rounded-xl border border-white/[0.1] bg-slate-900/95 backdrop-blur p-1">
                        ${STATUS_ICON_OPTIONS.map(ic => `
                            <button type="button" data-icon="${ic}" onclick="pickStatusIcon('${pickerId}','${inputId}','${ic}')" class="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-left text-xs transition ${ic === selected ? 'bg-sky-500/20 text-sky-300' : 'text-slate-300 hover:bg-white/[0.08]'}">
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
                    btn.classList.toggle('bg-sky-500/20', selected);
                    btn.classList.toggle('text-sky-300', selected);
                    btn.classList.toggle('text-slate-300', !selected);
                    if (!selected) btn.classList.add('hover:bg-white/[0.08]');
                });
                menu.classList.add('hidden');
            }
        }
        document.addEventListener('click', (e) => {
            if (e.target.closest('.status-icon-picker')) return;
            document.querySelectorAll('.status-icon-picker-menu').forEach(menu => menu.classList.add('hidden'));
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
        function loadStatuses() {
            try {
                const saved = localStorage.getItem(STATUS_KEY);
                const parsed = saved ? JSON.parse(saved) : defaultStatuses.map(s => ({ ...s }));
                const normalized = normalizeStatuses(parsed);
                return normalized.length > 0 ? normalized : defaultStatuses.map(s => ({ ...s }));
            } catch {
                return defaultStatuses.map(s => ({ ...s }));
            }
        }
        function saveStatuses(arr) {
            const normalized = normalizeStatuses(arr);
            const next = normalized.length > 0 ? normalized : defaultStatuses.map(s => ({ ...s }));
            localStorage.setItem(STATUS_KEY, JSON.stringify(next));
            App.statuses = next;
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
        const CHANNEL_KEY = 'item_manager_purchase_channels';
        const defaultPurchaseChannels = ['淘宝', '京东', '拼多多', '闲鱼', '线下', '礼品'];
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
        }

        const App = {
            statuses: loadStatuses(),
            purchaseChannels: loadPurchaseChannels(),
            currentView: 'dashboard',
            categories: [],
            itemsSize: loadItemsSize(),
            itemAttrs: loadItemAttrs(),
            locations: [],
            selectedItems: new Set(),
            itemsPage: 1,
            itemsSort: 'updated_at',
            itemsOrder: 'DESC',
            itemsFilter: { search: '', category: 0, location: 0, status: '', expiryOnly: false },
            sortSettings: loadSortSettings(),
            _cachedItems: null,   // 缓存物品列表数据，避免频繁 API 请求
            _cachedTotal: 0,
            _cachedPages: 0
        };

        // ---------- API 封装 ----------
        async function api(endpoint, options = {}) {
            const url = `?api=${endpoint}`;
            try {
                const res = await fetch(url, options);
                return await res.json();
            } catch (e) {
                toast('网络请求失败', 'error');
                return { success: false };
            }
        }

        async function apiPost(endpoint, data) {
            return api(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
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
            localStorage.setItem('item_theme', isLight ? 'light' : 'dark');
            document.getElementById('themeIcon').className = isLight ? 'ri-sun-line' : 'ri-moon-line';
            document.getElementById('themeText').textContent = isLight ? '浅色模式' : '深色模式';
        }

        function initTheme() {
            if (localStorage.getItem('item_theme') === 'light') {
                document.body.classList.add('light');
                document.getElementById('themeIcon').className = 'ri-sun-line';
                document.getElementById('themeText').textContent = '浅色模式';
            }
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
        const settingsSubViews = ['import-export', 'settings', 'status-settings', 'channel-settings', 'changelog'];

        function switchView(view) {
            App.currentView = view;
            document.querySelectorAll('.sidebar-link[data-view]').forEach(el => {
                el.classList.toggle('active', el.dataset.view === view);
            });
            const titles = { dashboard: '仪表盘', items: '物品管理', categories: '分类管理', locations: '位置管理', trash: '物品管理', 'import-export': '数据管理', settings: '排序设置', 'status-settings': '状态管理', 'channel-settings': '购入渠道管理', changelog: '更新记录' };
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
                case 'categories': await renderCategories(c); break;
                case 'locations': await renderLocations(c); break;
                case 'trash': await renderTrash(c); break;
                case 'import-export': renderImportExport(c); break;
                case 'settings': renderSettings(c); break;
                case 'status-settings': renderStatusSettings(c); break;
                case 'channel-settings': renderChannelSettings(c); break;
                case 'changelog': renderChangelog(c); break;
            }
        }

        // ---------- 加载基础数据 ----------
        async function loadBaseData() {
            const [catRes, locRes] = await Promise.all([api('categories'), api('locations')]);
            if (catRes.success) App.categories = catRes.data;
            if (locRes.success) App.locations = locRes.data;
        }

        // ============================================================
        // 📊 仪表盘
        // ============================================================
        async function renderDashboard(container) {
            const res = await api('dashboard');
            if (!res.success) { container.innerHTML = '<p class="text-red-400">加载失败</p>'; return; }
            const d = res.data;
            const statusMap = getStatusMap();
            const expiringItems = Array.isArray(d.expiringItems) ? d.expiringItems : [];
            const dashboardStatusStats = (d.statusStats || []).filter(s => Number(s.total_qty || 0) > 0);

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
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
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

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 glass rounded-2xl p-5 anim-up">
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

            <div class="space-y-6">
                <div class="glass rounded-2xl p-5 anim-up" style="animation-delay:0.1s">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-semibold text-white flex items-center gap-2"><i class="ri-pie-chart-line text-violet-400"></i>分类统计</h3>
                        <span class="text-xs text-slate-500">未分类 ${Number(d.uncategorizedQty || 0)} 件</span>
                    </div>
                    <div class="space-y-3">
                        ${(() => { const total = d.categoryStats.reduce((sum, c) => sum + Number(c.count || 0), 0);
                return sortCategoryStats(d.categoryStats.filter(c => c.count > 0)).map(cat => {
                    const pct = total > 0 ? Math.round(cat.count / total * 100) : 0;
                    return `<div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm text-slate-300">${cat.icon} ${esc(cat.name)}</span>
                                <span class="text-xs text-slate-500">${cat.count} 种 / ${cat.total_qty} 件</span>
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
                                <span class="text-xs text-slate-500">${s.count} 条 / ${s.total_qty} 件</span>
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
                    <option value="0">所有分类</option>
                    <option value="-1" ${f.category === -1 ? 'selected' : ''}>未分类</option>
                    ${App.categories.map(c => `<option value="${c.id}" ${f.category == c.id ? 'selected' : ''}>${c.icon} ${esc(c.name)}</option>`).join('')}
                </select>
                <select class="input !w-auto !py-2" onchange="App.itemsFilter.location=+this.value;App.itemsPage=1;renderView()">
                    <option value="0">所有位置</option>
                    <option value="-1" ${f.location === -1 ? 'selected' : ''}>未设定</option>
                    ${App.locations.map(l => `<option value="${l.id}" ${f.location == l.id ? 'selected' : ''}>${esc(l.name)}</option>`).join('')}
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
                <button onclick="toggleExpiryOnlyFilter()" class="btn btn-ghost btn-sm ${f.expiryOnly ? 'text-amber-400 border-amber-400/30 bg-amber-500/10' : 'text-slate-400 hover:text-amber-400'}" title="只显示带过期日期的物品">
                    <i class="ri-alarm-warning-line mr-1"></i>过期管理
                </button>
                <button onclick="switchView('trash')" class="btn btn-ghost btn-sm text-slate-400 hover:text-red-400 transition" title="回收站">
                    <i class="ri-delete-bin-line mr-1"></i>回收站
                </button>
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
                ${hasAttr('category') && item.category_icon ? `<span style="color:${item.category_color || '#64748b'}">${item.category_icon} ${esc(item.category_name || '')}</span>` : ''}
                ${hasAttr('location') && item.location_name ? `<span><i class="ri-map-pin-2-line"></i> ${esc(item.location_name)}</span>` : ''}
                ${hasAttr('price') && item.purchase_price > 0 ? `<span class="text-amber-400 font-medium">¥${Number(item.purchase_price).toLocaleString()}</span>` : ''}
                ${hasAttr('purchase_from') && item.purchase_from ? `<span><i class="ri-shopping-bag-line"></i> ${esc(item.purchase_from)}</span>` : ''}
            </div>
            ${hasAttr('expiry') && item.expiry_date ? `<div class="text-xs mt-1 ${expiryColor(item.expiry_date)}"><i class="ri-alarm-warning-line mr-0.5"></i>${item.expiry_date} ${expiryLabel(item.expiry_date)}</div>` : ''}
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
                    ${hasAttr('category') && item.category_icon ? `<span style="color:${item.category_color || '#64748b'}">${item.category_icon}${esc(item.category_name || '')}</span>` : ''}
                    ${hasAttr('location') && item.location_name ? `<span class="truncate"><i class="ri-map-pin-2-line"></i>${esc(item.location_name)}</span>` : ''}
                    ${hasAttr('price') && item.purchase_price > 0 ? `<span class="text-amber-400">¥${Number(item.purchase_price).toLocaleString()}</span>` : ''}
                    ${hasAttr('expiry') && item.expiry_date ? `<span class="${expiryColor(item.expiry_date)}"><i class="ri-alarm-warning-line"></i>${expiryLabel(item.expiry_date)}</span>` : ''}
                    ${hasAttr('purchase_from') && item.purchase_from ? `<span><i class="ri-shopping-bag-line"></i>${esc(item.purchase_from)}</span>` : ''}
                    ${hasAttr('notes') && item.notes ? `<span class="text-slate-600 truncate"><i class="ri-sticky-note-line"></i>${esc(item.notes)}</span>` : ''}
                </div>
            </div>
            <label class="flex-shrink-0 cursor-pointer" title="选中">
                <input type="checkbox" class="hidden" ${isSelected ? 'checked' : ''} onchange="toggleSelect(${item.id}, this.checked)">
                <i class="ri-checkbox-${isSelected ? 'fill text-sky-400' : 'blank-line text-slate-600'}"></i>
            </label>
        </div>
        <div class="flex items-center border-t border-white/5">
            <button onclick="event.stopPropagation();editItem(${item.id})" class="btn btn-ghost btn-sm flex-1 rounded-none !py-1.5 text-xs"><i class="ri-edit-line"></i></button>
            <button onclick="event.stopPropagation();copyItem(${item.id})" class="btn btn-ghost btn-sm flex-1 rounded-none !py-1.5 text-xs" style="color:#38bdf8"><i class="ri-file-copy-line"></i></button>
            <button onclick="event.stopPropagation();deleteItem(${item.id},'${esc(item.name)}')" class="btn btn-danger btn-sm flex-1 rounded-none !py-1.5 text-xs"><i class="ri-delete-bin-line"></i></button>
        </div>
    </div>`;
        }

        function itemRowSmall(item, index) {
            const isSelected = App.selectedItems.has(item.id);

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
            ${hasAttr('category') ? `<span class="text-[11px] text-slate-500 flex-shrink-0">${item.category_icon || '📦'}${esc(item.category_name || '')}</span>` : ''}
            ${hasAttr('location') && item.location_name ? `<span class="text-[11px] text-slate-600 truncate hidden sm:inline"><i class="ri-map-pin-2-line"></i>${esc(item.location_name)}</span>` : ''}
            ${hasAttr('purchase_from') && item.purchase_from ? `<span class="text-[11px] text-slate-600 truncate hidden md:inline"><i class="ri-shopping-bag-line"></i>${esc(item.purchase_from)}</span>` : ''}
        </div>
        <div class="flex items-center gap-3 flex-shrink-0 text-xs">
            ${hasAttr('price') && item.purchase_price > 0 ? `<span class="text-amber-400 w-16 text-right">¥${Number(item.purchase_price).toLocaleString()}</span>` : ''}
            ${hasAttr('expiry') && item.expiry_date ? `<span class="${expiryColor(item.expiry_date)} hidden md:inline text-[11px]">${expiryLabel(item.expiry_date)}</span>` : ''}
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
            const res = await api(`items&page=1&limit=1&search=&category=0&location=0&status=`);
            // 直接单独请求该物品
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
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">分类</p><p class="text-sm text-white">${item.category_icon || '📦'} ${esc(item.category_name || '未分类')}</p></div>
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">位置</p><p class="text-sm text-white"><i class="ri-map-pin-2-line text-xs mr-1"></i>${esc(item.location_name || '未设定')}</p></div>
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">数量</p><p class="text-sm text-white">${item.quantity}</p></div>
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">价值</p><p class="text-sm text-amber-400 font-medium">¥${Number(item.purchase_price || 0).toLocaleString()}</p></div>
                ${item.purchase_date ? `<div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">购入日期</p><p class="text-sm text-white">${item.purchase_date}</p></div>` : ''}
                ${item.expiry_date ? `<div class="p-3 rounded-xl ${expiryBg(item.expiry_date)}"><p class="text-xs text-slate-500 mb-1">过期日期</p><p class="text-sm font-medium ${expiryColor(item.expiry_date)}">${item.expiry_date} ${expiryLabel(item.expiry_date)}</p></div>` : ''}
                ${item.purchase_from ? `<div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">购入渠道</p><p class="text-sm text-white">${esc(item.purchase_from)}</p></div>` : ''}
                ${item.barcode ? `<div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">条码/序列号</p><p class="text-sm text-white font-mono">${esc(item.barcode)}</p></div>` : ''}
            </div>
            ${item.description ? `<div class="mb-4"><p class="text-xs text-slate-500 mb-1">描述</p><p class="text-sm text-slate-300 whitespace-pre-wrap">${esc(item.description)}</p></div>` : ''}
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
            document.getElementById('itemQuantity').value = '1';
            document.getElementById('itemPrice').value = '0';
            document.getElementById('itemExpiry').value = '';
            document.getElementById('itemNotes').value = '';
            resetUploadZone();
            await populateSelects({ status: getDefaultStatusKey(), purchaseFrom: App.purchaseChannels[0] || '' });
            document.getElementById('itemModal').classList.add('show');
        }

        async function editItem(id) {
            const res = await api(`items&page=1&limit=999`);
            if (!res.success) return;
            const item = res.data.find(i => i.id === id);
            if (!item) { toast('物品不存在', 'error'); return; }

            document.getElementById('itemModalTitle').textContent = '编辑物品';
            document.getElementById('itemId').value = item.id;
            document.getElementById('itemName').value = item.name;
            document.getElementById('itemQuantity').value = item.quantity;
            document.getElementById('itemPrice').value = item.purchase_price;
            document.getElementById('itemDate').value = item.purchase_date;
            document.getElementById('itemExpiry').value = item.expiry_date || '';
            document.getElementById('itemBarcode').value = item.barcode;
            document.getElementById('itemTags').value = item.tags;
            document.getElementById('itemDesc').value = item.description;
            document.getElementById('itemImage').value = item.image || '';
            document.getElementById('itemNotes').value = item.notes || '';

            resetUploadZone();
            if (item.image) {
                document.getElementById('uploadPreview').src = `?img=${item.image}`;
                document.getElementById('uploadPreview').classList.remove('hidden');
                document.getElementById('uploadPlaceholder').classList.add('hidden');
                document.getElementById('uploadZone').classList.add('has-image');
            }

            // 关键：await 等待下拉框填充完成后再设置值
            await populateSelects({ status: item.status, purchaseFrom: item.purchase_from || '' });
            document.getElementById('itemCategory').value = item.category_id;
            document.getElementById('itemLocation').value = item.location_id;
            document.getElementById('itemModal').classList.add('show');
        }

        async function populateSelects(options = {}) {
            await loadBaseData();
            const catSelect = document.getElementById('itemCategory');
            catSelect.innerHTML = '<option value="0">选择分类</option>' + App.categories.map(c => `<option value="${c.id}">${c.icon} ${esc(c.name)}</option>`).join('');
            const locSelect = document.getElementById('itemLocation');
            locSelect.innerHTML = '<option value="0">选择位置</option>' + App.locations.map(l => `<option value="${l.id}">${esc(l.name)}</option>`).join('');
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
            const data = {
                id: id ? +id : undefined,
                name: document.getElementById('itemName').value.trim(),
                category_id: +document.getElementById('itemCategory').value,
                location_id: +document.getElementById('itemLocation').value,
                quantity: +document.getElementById('itemQuantity').value,
                purchase_price: +document.getElementById('itemPrice').value,
                purchase_date: document.getElementById('itemDate').value,
                expiry_date: document.getElementById('itemExpiry').value,
                barcode: document.getElementById('itemBarcode').value.trim(),
                tags: document.getElementById('itemTags').value.trim(),
                description: document.getElementById('itemDesc').value.trim(),
                status: document.getElementById('itemStatus').value,
                image: document.getElementById('itemImage').value,
                purchase_from: document.getElementById('itemPurchaseFrom').value,
                notes: document.getElementById('itemNotes').value.trim()
            };
            if (!data.name) { toast('请输入物品名称', 'error'); return false; }

            const endpoint = id ? 'items/update' : 'items';
            const res = await apiPost(endpoint, data);
            if (res.success) { toast(id ? '物品已更新' : '物品已添加'); closeItemModal(); renderView(); } else toast(res.message, 'error');
            return false;
        }

        async function deleteItem(id, name) {
            if (!confirm(`确定删除「${name}」？物品将移入回收站。`)) return;
            const res = await apiPost('items/delete', { id });
            if (res.success) { toast('已移入回收站'); renderView(); } else toast(res.message, 'error');
        }

        function closeItemModal() { document.getElementById('itemModal').classList.remove('show'); }

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
        // 🏷️ 分类管理
        // ============================================================
        async function renderCategories(container) {
            await loadBaseData();
            const uncRes = await api('items&page=1&limit=1&search=&category=-1&location=0&status=');
            const uncategorizedCount = uncRes.success ? Number(uncRes.total || 0) : 0;
            const catSortMode = getEffectiveListSortMode('categories');
            const sortedCats = sortListData(App.categories, catSortMode);
            container.innerHTML = `
        <div class="flex items-center justify-between mb-6 anim-up" style="position:relative;z-index:40;">
            <p class="text-sm text-slate-500">共 ${App.categories.length + 1} 个分类</p>
            <div class="flex items-center gap-2">
                <div class="relative">
                    <button onclick="toggleListSortMenu('categoriesSortMenu', this)" class="btn btn-ghost btn-sm text-slate-400 hover:text-white transition">
                        <i class="ri-sort-desc mr-1"></i>排序：${getListSortLabel(catSortMode)}
                    </button>
                    <div id="categoriesSortMenu" class="list-sort-menu hidden absolute right-0 top-full mt-1 glass rounded-xl p-2 min-w-[180px] z-50 shadow-xl border border-white/[0.06] space-y-1" style="z-index:90;">
                        <button onclick="setListSort('categories','count_desc')" class="w-full text-left px-2 py-1.5 rounded-lg text-xs transition ${catSortMode === 'count_desc' ? 'bg-sky-500/15 text-sky-300' : 'text-slate-300 hover:bg-white/[0.05]'}">按物品数量 多→少</button>
                        <button onclick="setListSort('categories','name_asc')" class="w-full text-left px-2 py-1.5 rounded-lg text-xs transition ${catSortMode === 'name_asc' ? 'bg-sky-500/15 text-sky-300' : 'text-slate-300 hover:bg-white/[0.05]'}">按名称首字母 A→Z</button>
                    </div>
                </div>
                <button onclick="openAddCategory()" class="btn btn-primary btn-sm"><i class="ri-add-line"></i>添加分类</button>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" style="position:relative;z-index:1;">
            <div class="glass glass-hover rounded-2xl p-5 anim-up" style="animation-delay:0ms;border-left:3px solid #64748b">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-3">
                        <span class="text-3xl">📦</span>
                        <div>
                            <h3 class="font-semibold text-white">未分类</h3>
                            <p class="text-xs text-slate-500">${uncategorizedCount} 件物品</p>
                        </div>
                    </div>
                    <div class="w-3 h-3 rounded-full bg-slate-500"></div>
                </div>
                <div class="flex gap-2">
                    <button onclick="viewItemsByCategory(-1)" class="btn btn-ghost btn-sm flex-1" style="color:#38bdf8" title="查看物品"><i class="ri-archive-line"></i>物品</button>
                    <button class="btn btn-ghost btn-sm flex-1 opacity-50 cursor-not-allowed" disabled title="系统固定项"><i class="ri-edit-line"></i>编辑</button>
                    <button class="btn btn-danger btn-sm flex-1 opacity-50 cursor-not-allowed" disabled title="系统固定项"><i class="ri-delete-bin-line"></i>删除</button>
                </div>
            </div>
            ${sortedCats.map((cat, i) => `
                <div class="glass glass-hover rounded-2xl p-5 anim-up" style="animation-delay:${(i + 1) * 40}ms;border-left:3px solid ${cat.color}">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-3">
                            <span class="text-3xl">${cat.icon}</span>
                            <div>
                                <h3 class="font-semibold text-white">${esc(cat.name)}</h3>
                                <p class="text-xs text-slate-500">${cat.item_count} 件物品</p>
                            </div>
                        </div>
                        <div class="w-3 h-3 rounded-full" style="background:${cat.color}"></div>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="viewItemsByCategory(${cat.id})" class="btn btn-ghost btn-sm flex-1" style="color:#38bdf8" title="查看物品"><i class="ri-archive-line"></i>物品</button>
                        <button onclick="editCategory(${cat.id})" class="btn btn-ghost btn-sm flex-1"><i class="ri-edit-line"></i>编辑</button>
                        <button onclick="deleteCategory(${cat.id},'${esc(cat.name)}',${cat.item_count})" class="btn btn-danger btn-sm flex-1"><i class="ri-delete-bin-line"></i>删除</button>
                    </div>
                </div>
            `).join('')}
        </div>
        ${App.categories.length === 0 ? '<div class="empty-state"><i class="ri-price-tag-3-line"></i><h3 class="text-xl font-semibold text-slate-400">暂无分类</h3></div>' : ''}
    `;
        }

        function openAddCategory() {
            document.getElementById('catModalTitle').textContent = '添加分类';
            document.getElementById('catId').value = '';
            document.getElementById('catName').value = '';
            document.getElementById('catIcon').value = '📦';
            document.getElementById('catColor').value = '#3b82f6';
            document.getElementById('categoryModal').classList.add('show');
        }

        function editCategory(id) {
            const cat = App.categories.find(c => c.id === id);
            if (!cat) return;
            document.getElementById('catModalTitle').textContent = '编辑分类';
            document.getElementById('catId').value = cat.id;
            document.getElementById('catName').value = cat.name;
            document.getElementById('catIcon').value = cat.icon;
            document.getElementById('catColor').value = cat.color;
            document.getElementById('categoryModal').classList.add('show');
        }

        async function saveCategory(e) {
            e.preventDefault();
            const id = document.getElementById('catId').value;
            const data = { id: id ? +id : undefined, name: document.getElementById('catName').value.trim(), icon: document.getElementById('catIcon').value.trim() || '📦', color: document.getElementById('catColor').value };
            if (!data.name) { toast('请输入分类名称', 'error'); return false; }
            const endpoint = id ? 'categories/update' : 'categories';
            const res = await apiPost(endpoint, data);
            if (res.success) { toast(id ? '分类已更新' : '分类已添加'); closeCategoryModal(); renderView(); } else toast(res.message, 'error');
            return false;
        }

        async function deleteCategory(id, name, count) {
            if (!confirm(`确定删除分类「${name}」？${count > 0 ? `其下 ${count} 件物品将变为未分类。` : ''}`)) return;
            const res = await apiPost('categories/delete', { id });
            if (res.success) { toast('分类已删除'); renderView(); } else toast(res.message, 'error');
        }

        function closeCategoryModal() { document.getElementById('categoryModal').classList.remove('show'); }

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
                    <div class="w-10 h-10 rounded-xl bg-slate-500/10 flex items-center justify-center"><i class="ri-map-pin-2-line text-slate-400 text-xl"></i></div>
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
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 flex items-center justify-center"><i class="ri-map-pin-2-fill text-amber-400 text-xl"></i></div>
                        <div>
                            <h3 class="font-semibold text-white">${esc(loc.name)}</h3>
                            <p class="text-xs text-slate-500">${loc.item_count} 件物品</p>
                        </div>
                    </div>
                    ${loc.description ? `<p class="text-xs text-slate-500 mb-3">${esc(loc.description)}</p>` : ''}
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
            document.getElementById('locDesc').value = '';
            document.getElementById('locationModal').classList.add('show');
        }

        function editLocation(id) {
            const loc = App.locations.find(l => l.id === id);
            if (!loc) return;
            document.getElementById('locModalTitle').textContent = '编辑位置';
            document.getElementById('locId').value = loc.id;
            document.getElementById('locName').value = loc.name;
            document.getElementById('locDesc').value = loc.description || '';
            document.getElementById('locationModal').classList.add('show');
        }

        async function saveLocation(e) {
            e.preventDefault();
            const id = document.getElementById('locId').value;
            const data = { id: id ? +id : undefined, name: document.getElementById('locName').value.trim(), description: document.getElementById('locDesc').value.trim() };
            if (!data.name) { toast('请输入位置名称', 'error'); return false; }
            const endpoint = id ? 'locations/update' : 'locations';
            const res = await apiPost(endpoint, data);
            if (res.success) { toast(id ? '位置已更新' : '位置已添加'); closeLocationModal(); renderView(); } else toast(res.message, 'error');
            return false;
        }

        async function deleteLocation(id, name, count) {
            if (!confirm(`确定删除位置「${name}」？${count > 0 ? `其下 ${count} 件物品将变为未设定位置。` : ''}`)) return;
            const res = await apiPost('locations/delete', { id });
            if (res.success) { toast('位置已删除'); renderView(); } else toast(res.message, 'error');
        }

        function closeLocationModal() { document.getElementById('locationModal').classList.remove('show'); }

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

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.4s">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 rounded-xl bg-violet-500/10 flex items-center justify-center"><i class="ri-slideshow-3-line text-2xl text-violet-400"></i></div>
                    <div><h3 class="font-semibold text-white text-lg">展示模式</h3><p class="text-sm text-slate-500">一键载入演示数据，快速体验筛选、状态、过期、统计等完整功能</p></div>
                </div>
                <button onclick="loadDemoMode()" class="btn btn-primary w-full" style="background:linear-gradient(135deg,#7c3aed,#4f46e5)"><i class="ri-slideshow-line"></i>加载展示数据</button>
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
            const header = ['ID', '名称', '分类', '位置', '数量', '价格', '购入渠道', '购入日期', '过期日期', '条码', '标签', '状态', '描述', '备注'];
            const rows = items.map(i => [i.id, i.name, i.category_name || '', i.location_name || '', i.quantity, i.purchase_price, i.purchase_from || '', i.purchase_date, i.expiry_date || '', i.barcode, i.tags, statusLabelByKey(i.status), i.description, i.notes || ''].map(csvCell));
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
                    toast(res.message);
                    renderView();
                } else toast(res.message, 'error');
            } catch (e) { toast('文件解析失败', 'error'); }
            input.value = '';
        }

        function downloadManualImportTemplate() {
            const header = ['名称', '分类', '位置', '数量', '状态', '购入价格', '购入渠道', '购入日期', '过期日期', '条码/序列号', '标签', '描述', '备注'];
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
                '这里是描述（可选）',
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
                    '描述': 'description',
                    'description': 'description',
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
                        description: getCell(row, 'description'),
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
            document.getElementById('itemName').value = item.name + ' (副本)';
            document.getElementById('itemQuantity').value = item.quantity;
            document.getElementById('itemPrice').value = item.purchase_price;
            document.getElementById('itemDate').value = item.purchase_date;
            document.getElementById('itemExpiry').value = item.expiry_date || '';
            document.getElementById('itemBarcode').value = item.barcode;
            document.getElementById('itemTags').value = item.tags;
            document.getElementById('itemDesc').value = item.description;
            document.getElementById('itemImage').value = item.image || '';
            document.getElementById('itemNotes').value = item.notes || '';

            resetUploadZone();
            if (item.image) {
                document.getElementById('uploadPreview').src = `?img=${item.image}`;
                document.getElementById('uploadPreview').classList.remove('hidden');
                document.getElementById('uploadPlaceholder').classList.add('hidden');
                document.getElementById('uploadZone').classList.add('has-image');
            }

            await populateSelects({ status: item.status, purchaseFrom: item.purchase_from || '' });
            document.getElementById('itemCategory').value = item.category_id;
            document.getElementById('itemLocation').value = item.location_id;
            document.getElementById('itemModal').classList.add('show');
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
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">分类</p><p class="text-sm text-white">${item.category_icon || '📦'} ${esc(item.category_name || '未分类')}</p></div>
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">位置</p><p class="text-sm text-white"><i class="ri-map-pin-2-line text-xs mr-1"></i>${esc(item.location_name || '未设定')}</p></div>
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">数量</p><p class="text-sm text-white">${item.quantity}</p></div>
                <div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">价值</p><p class="text-sm text-amber-400 font-medium">¥${Number(item.purchase_price || 0).toLocaleString()}</p></div>
                ${item.purchase_date ? `<div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">购入日期</p><p class="text-sm text-white">${item.purchase_date}</p></div>` : ''}
                ${item.expiry_date ? `<div class="p-3 rounded-xl ${expiryBg(item.expiry_date)}"><p class="text-xs text-slate-500 mb-1">过期日期</p><p class="text-sm font-medium ${expiryColor(item.expiry_date)}">${item.expiry_date} ${expiryLabel(item.expiry_date)}</p></div>` : ''}
                ${item.purchase_from ? `<div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">购入渠道</p><p class="text-sm text-white">${esc(item.purchase_from)}</p></div>` : ''}
                ${item.barcode ? `<div class="p-3 rounded-xl bg-white/5"><p class="text-xs text-slate-500 mb-1">条码/序列号</p><p class="text-sm text-white font-mono">${esc(item.barcode)}</p></div>` : ''}
                <div class="p-3 rounded-xl bg-red-500/5"><p class="text-xs text-slate-500 mb-1">删除时间</p><p class="text-sm text-red-400">${item.deleted_at || '-'}</p></div>
            </div>
            ${item.description ? `<div class="mb-4"><p class="text-xs text-slate-500 mb-1">描述</p><p class="text-sm text-slate-300 whitespace-pre-wrap">${esc(item.description)}</p></div>` : ''}
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
                version: 'v1.2.0', date: '2026-02-09', title: '数据管理增强 + 批量导入完善 + 仪表盘优化',
                changes: [
                    '设置菜单中的「导入/导出」统一改名为「数据管理」',
                    '新增「物品数据重置」与「恢复默认环境」两项能力',
                    '重置/恢复默认时，uploads 中图片改为移动到 uploads/trash，不直接删除',
                    '数据管理新增「展示模式」，可一键导入演示数据用于功能展示',
                    '新增购入渠道管理（默认：淘宝/京东/拼多多/闲鱼/线下/礼品），表单改为下拉选择',
                    '移除位置上下级功能，位置管理统一为单级结构',
                    '分类管理固定显示「未分类」、位置管理固定显示「未设定」，并支持一键查看对应物品',
                    '物品管理过滤器新增「未分类 / 未设定」选项，便于筛出未绑定分类或位置的物品',
                    '物品管理新增「过期管理」过滤按钮，一键筛选带过期日期的物品',
                    '物品管理搜索栏支持属性关键词检索（分类/位置/购入渠道/备注/状态等），支持搜索按钮和 Enter 触发',
                    '物品排序新增名称 Z-A、价格低→高、数量少→多、最早更新/添加、过期日期近→远与远→近（空过期日期自动置后）',
                    '分类管理与位置管理新增排序按钮；下拉层级遮挡问题已修复，并默认跟随系统排序设置',
                    '导出 JSON 文件名精确到秒，并支持可选导出图片数据',
                    '导入 JSON 支持读取内置图片数据',
                    '新增手动批量导入（CSV 模板），模板示例标注必填/可选，日期格式改为 YYYY/MM/DD',
                    '批量导入日期校验支持 YYYY-MM-DD / YYYY/MM/DD（含单数字月日），错误行自动跳过并给出持久提示',
                    '导入时分类/位置/购入渠道/状态支持模糊匹配已有值，不存在时自动回退默认值',
                    '仪表盘新增状态统计（0 数据状态隐藏）；分类统计右上角显示未分类件数，且仅统计使用中物品',
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
                    '优化交互性能：减少不必要刷新请求、保持滚动位置',
                    '状态管理支持编辑已有状态（名称、图标、颜色）',
                    '物品卡片中件数显示位置调整到分类前面，修复中尺寸图标缺失与编辑回填问题',
                ]
            },
            {
                version: 'v1.0.0', date: '2026-02-08', title: '初始版本发布',
                changes: [
                    '完整的物品 CRUD 功能',
                    '仪表盘统计面板 + 分类进度条',
                    '分类管理（Emoji 图标 + 自定义颜色）',
                    '位置管理（单级结构）',
                    '图片上传与预览',
                    '全局搜索 + 多维度筛选 + 多种排序',
                    '数据导出（JSON/CSV）与导入',
                    '深色/浅色主题切换',
                    '全响应式布局 + 毛玻璃 UI'
                ]
            }
        ];
        const APP_VERSION = CHANGELOG[0].version;

        // ---------- 设置页面 ----------
        function renderSettings(container) {
            const s = App.sortSettings;
            container.innerHTML = `
        <div class="max-w-2xl mx-auto space-y-6">
            <div class="glass rounded-2xl p-6 anim-up">
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

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.05s">
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

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.1s">
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

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.15s">
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

        // ---------- 更新记录页面 ----------
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

        function applySettings() {
            const s = {
                dashboard_categories: document.getElementById('set_dashboard_categories').value,
                items_default: document.getElementById('set_items_default').value,
                categories_list: document.getElementById('set_categories_list').value,
                locations_list: document.getElementById('set_locations_list').value,
            };
            saveSortSettings(s);
            // 同步物品默认排序
            const [sort, order] = s.items_default.split(':');
            App.itemsSort = sort; App.itemsOrder = order;
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

        async function loadDemoMode() {
            if (!confirm('确定加载展示模式吗？这会覆盖当前物品、分类和位置数据，并将 uploads 中图片移动到 uploads/trash。')) return;
            const res = await apiPost('system/load-demo', {});
            if (!res.success) { toast(res.message || '加载失败', 'error'); return; }

            saveStatuses(defaultStatuses.map(s => ({ ...s })));
            savePurchaseChannels([...defaultPurchaseChannels]);

            App.itemsFilter = { search: '', category: 0, location: 0, status: '', expiryOnly: false };
            App.itemsPage = 1;
            App.selectedItems.clear();
            App._cachedItems = null;
            App._cachedTotal = 0;
            App._cachedPages = 0;

            toast(res.message || '展示模式已加载');
            switchView('dashboard');
        }

        async function restoreDefaultEnvironment() {
            if (!confirm('确定恢复默认环境吗？此操作会清空所有数据并重置本地设置，且不可撤销。')) return;
            const res = await apiPost('system/reset-default', {});
            if (!res.success) { toast(res.message || '恢复失败', 'error'); return; }

            localStorage.removeItem(SORT_SETTINGS_KEY);
            localStorage.removeItem(ITEMS_SIZE_KEY);
            localStorage.removeItem(ITEM_ATTRS_KEY);
            localStorage.removeItem(STATUS_KEY);
            localStorage.removeItem(CHANNEL_KEY);
            localStorage.removeItem('item_theme');

            App.statuses = defaultStatuses.map(s => ({ ...s }));
            App.purchaseChannels = [...defaultPurchaseChannels];
            App.itemsSize = 'large';
            App.itemAttrs = [...defaultItemAttrs];
            App.sortSettings = { ...defaultSortSettings };
            App.itemsFilter = { search: '', category: 0, location: 0, status: '', expiryOnly: false };
            App.itemsPage = 1;
            App.itemsSort = 'updated_at';
            App.itemsOrder = 'DESC';
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
        function daysUntilExpiry(dateStr) {
            if (!dateStr) return Infinity;
            const today = new Date(); today.setHours(0, 0, 0, 0);
            const expiry = new Date(dateStr); expiry.setHours(0, 0, 0, 0);
            return Math.ceil((expiry - today) / (1000 * 60 * 60 * 24));
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

        // ============================================================
        // 🎬 初始化
        // ============================================================
        initTheme();
        // 设置版本号
        document.getElementById('appVersion').textContent = APP_VERSION;
        // 应用默认排序设置
        const initSort = App.sortSettings.items_default.split(':');
        if (initSort.length === 2) { App.itemsSort = initSort[0]; App.itemsOrder = initSort[1]; }
        renderView();
    </script>
</body>

</html>
