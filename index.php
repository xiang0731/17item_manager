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
    } catch (Exception $e) { /* 字段已存在则忽略 */ }

    // 数据库迁移：为旧数据库添加 deleted_at 字段（回收站软删除）
    try {
        $db->exec("ALTER TABLE items ADD COLUMN deleted_at DATETIME DEFAULT NULL");
    } catch (Exception $e) { /* 字段已存在则忽略 */ }

    // 数据库迁移：购入渠道、备注
    try { $db->exec("ALTER TABLE items ADD COLUMN purchase_from TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE items ADD COLUMN notes TEXT DEFAULT ''"); } catch (Exception $e) {}

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
            ['阳台', 0],
            ['储物间', 0],
        ];
        $stmt = $db->prepare("INSERT INTO locations (name, parent_id) VALUES (?, ?)");
        foreach ($defaults as $loc)
            $stmt->execute($loc);
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
                $categoryStats = $db->query("SELECT c.name, c.icon, c.color, COUNT(i.id) as count, COALESCE(SUM(i.quantity),0) as total_qty FROM categories c LEFT JOIN items i ON c.id=i.category_id AND i.deleted_at IS NULL GROUP BY c.id ORDER BY count DESC")->fetchAll();
                $expiringItems = $db->query("SELECT i.*, c.name as category_name, c.icon as category_icon, l.name as location_name FROM items i LEFT JOIN categories c ON i.category_id=c.id LEFT JOIN locations l ON i.location_id=l.id WHERE i.deleted_at IS NULL AND i.expiry_date != '' AND i.expiry_date IS NOT NULL ORDER BY i.expiry_date ASC LIMIT 10")->fetchAll();
                $result = ['success' => true, 'data' => compact('totalItems', 'totalKinds', 'totalCategories', 'totalLocations', 'totalValue', 'recentItems', 'categoryStats', 'expiringItems')];
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
                    $sort = $_GET['sort'] ?? 'updated_at';
                    $order = ($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

                    $where = ['i.deleted_at IS NULL'];
                    $params = [];
                    if ($search) {
                        $where[] = "(i.name LIKE ? OR i.description LIKE ? OR i.tags LIKE ? OR i.barcode LIKE ?)";
                        $s = "%$search%";
                        $params = array_merge($params, [$s, $s, $s, $s]);
                    }
                    if ($category) {
                        $where[] = "i.category_id = ?";
                        $params[] = $category;
                    }
                    if ($location) {
                        $where[] = "i.location_id = ?";
                        $params[] = $location;
                    }
                    if ($status) {
                        $where[] = "i.status = ?";
                        $params[] = $status;
                    }

                    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
                    $allowedSort = ['name', 'quantity', 'purchase_price', 'created_at', 'updated_at'];
                    $sortCol = in_array($sort, $allowedSort) ? $sort : 'updated_at';

                    $countStmt = $db->prepare("SELECT COUNT(*) FROM items i $whereSQL");
                    $countStmt->execute($params);
                    $total = $countStmt->fetchColumn();

                    $stmt = $db->prepare("SELECT i.*, c.name as category_name, c.icon as category_icon, c.color as category_color, l.name as location_name FROM items i LEFT JOIN categories c ON i.category_id=c.id LEFT JOIN locations l ON i.location_id=l.id $whereSQL ORDER BY i.$sortCol $order LIMIT $limit OFFSET $offset");
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
                        $data['status'] ?? 'active',
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
                        $data['status'] ?? 'active',
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
                    if ($img && file_exists(UPLOAD_DIR . $img)) @rename(UPLOAD_DIR . $img, TRASH_DIR . $img);
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
                            if (file_exists(UPLOAD_DIR . $img)) @rename(UPLOAD_DIR . $img, TRASH_DIR . $img);
                        }
                        $db->exec("UPDATE items SET deleted_at=datetime('now','localtime') WHERE id IN ($placeholders)");
                    }
                    $result = ['success' => true, 'message' => '已移入回收站'];
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
                    if ($img && file_exists(TRASH_DIR . $img)) @rename(TRASH_DIR . $img, UPLOAD_DIR . $img);
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
                            if (file_exists(TRASH_DIR . $img)) @rename(TRASH_DIR . $img, UPLOAD_DIR . $img);
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
                    if ($img && file_exists(TRASH_DIR . $img)) unlink(TRASH_DIR . $img);
                    $db->exec("DELETE FROM items WHERE id=$id");
                    $result = ['success' => true, 'message' => '已彻底删除'];
                }
                break;

            case 'trash/empty':
                if ($method === 'POST') {
                    $images = $db->query("SELECT image FROM items WHERE deleted_at IS NOT NULL AND image != ''")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($images as $img) {
                        if (file_exists(TRASH_DIR . $img)) unlink(TRASH_DIR . $img);
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
                    $stmt->execute([$data['name'], intval($data['parent_id'] ?? 0), $data['description'] ?? '']);
                    $result = ['success' => true, 'message' => '位置添加成功', 'id' => $db->lastInsertId()];
                }
                break;

            case 'locations/update':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $stmt = $db->prepare("UPDATE locations SET name=?, parent_id=?, description=? WHERE id=?");
                    $stmt->execute([$data['name'], intval($data['parent_id'] ?? 0), $data['description'] ?? '', intval($data['id'])]);
                    $result = ['success' => true, 'message' => '位置更新成功'];
                }
                break;

            case 'locations/delete':
                if ($method === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $id = intval($data['id'] ?? 0);
                    $db->exec("UPDATE items SET location_id=0 WHERE location_id=$id");
                    $db->exec("UPDATE locations SET parent_id=0 WHERE parent_id=$id");
                    $db->exec("DELETE FROM locations WHERE id=$id");
                    $result = ['success' => true, 'message' => '位置删除成功'];
                }
                break;

            // ---------- 图片上传 ----------
            case 'upload':
                if ($method === 'POST' && isset($_FILES['image'])) {
                    $file = $_FILES['image'];
                    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    if (!in_array($file['type'], $allowed)) {
                        $result = ['success' => false, 'message' => '不支持的图片格式'];
                        break;
                    }
                    if ($file['size'] > MAX_UPLOAD_SIZE) {
                        $result = ['success' => false, 'message' => '文件超过10MB限制'];
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
                    if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $filename)) {
                        $result = ['success' => true, 'filename' => $filename];
                    } else {
                        $result = ['success' => false, 'message' => '上传失败'];
                    }
                }
                break;

            // ---------- 数据导出 ----------
            case 'export':
                $items = $db->query("SELECT i.*, c.name as category_name, l.name as location_name FROM items i LEFT JOIN categories c ON i.category_id=c.id LEFT JOIN locations l ON i.location_id=l.id WHERE i.deleted_at IS NULL ORDER BY i.id")->fetchAll();
                $categories = $db->query("SELECT * FROM categories ORDER BY id")->fetchAll();
                $locations = $db->query("SELECT * FROM locations ORDER BY id")->fetchAll();
                $result = ['success' => true, 'data' => ['items' => $items, 'categories' => $categories, 'locations' => $locations, 'exported_at' => date('Y-m-d H:i:s'), 'version' => '1.0.0']];
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
                        $imported = 0;
                        $stmtItem = $db->prepare("INSERT INTO items (name, category_id, location_id, quantity, description, barcode, purchase_date, purchase_price, tags, status, expiry_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
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
                            $stmtItem->execute([
                                $item['name'] ?? '未命名',
                                $catId,
                                $locId,
                                intval($item['quantity'] ?? 1),
                                $item['description'] ?? '',
                                $item['barcode'] ?? '',
                                $item['purchase_date'] ?? '',
                                floatval($item['purchase_price'] ?? 0),
                                $item['tags'] ?? '',
                                $item['status'] ?? 'active',
                                $item['expiry_date'] ?? ''
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

        .sidebar-parent { cursor: pointer; }
        .sidebar-parent .sub-arrow { font-size: 16px; width: auto; }
        .sidebar-group.open .sub-arrow { transform: rotate(180deg); }

        .sidebar-submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.25s ease;
        }
        .sidebar-group.open .sidebar-submenu {
            max-height: 200px;
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
            background: rgba(251, 146, 60, 0.15);
            color: #fb923c;
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
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
            align-items: center;
            gap: 8px;
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
        .size-btn:hover { color: #e2e8f0; }
        .size-btn.active {
            background: rgba(56,189,248,0.2);
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

        .empty-state i {
            font-size: 64px;
            margin-bottom: 16px;
            display: block;
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
            <div class="sidebar-link" data-view="categories" onclick="switchView('categories')">
                <i class="ri-price-tag-3-line"></i><span class="sidebar-text">分类管理</span>
            </div>
            <div class="sidebar-link" data-view="locations" onclick="switchView('locations')">
                <i class="ri-map-pin-line"></i><span class="sidebar-text">位置管理</span>
            </div>
            <div class="mt-6 mb-2 px-4">
                <div class="border-t border-white/5"></div>
            </div>
            <div class="sidebar-group">
                <div class="sidebar-link sidebar-parent" onclick="toggleSubMenu(this)">
                    <i class="ri-settings-3-line"></i><span class="sidebar-text">设置</span>
                    <i class="ri-arrow-down-s-line sidebar-text ml-auto sub-arrow transition-transform duration-200"></i>
                </div>
                <div class="sidebar-submenu">
                    <div class="sidebar-link sidebar-sub" data-view="import-export" onclick="switchView('import-export')">
                        <i class="ri-swap-line"></i><span class="sidebar-text">导入/导出</span>
                    </div>
                    <div class="sidebar-link sidebar-sub" data-view="settings" onclick="switchView('settings')">
                        <i class="ri-sort-asc"></i><span class="sidebar-text">排序设置</span>
                    </div>
                    <div class="sidebar-link sidebar-sub" data-view="status-settings" onclick="switchView('status-settings')">
                        <i class="ri-list-settings-line"></i><span class="sidebar-text">状态管理</span>
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
                        <input type="text" id="itemPurchaseFrom" class="input" placeholder="例如: 京东、淘宝">
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">购入日期</label>
                        <input type="date" id="itemDate" class="input">
                    </div>
                    <div>
                        <label class="block text-sm text-slate-400 mb-1.5">过期时间</label>
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
                    <div class="sm:col-span-2">
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
                    <div><label class="block text-sm text-slate-400 mb-1.5">上级位置</label><select id="locParent"
                            class="input">
                            <option value="0">无 (顶级位置)</option>
                        </select></div>
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
            { key: 'expiry', label: '过期时间' },
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
        const defaultStatuses = [
            { key: 'active', label: '使用中', icon: 'ri-checkbox-circle-line', color: 'text-emerald-400', badge: 'badge-active' },
            { key: 'archived', label: '已归档', icon: 'ri-archive-line', color: 'text-slate-400', badge: 'badge-archived' },
            { key: 'lent', label: '已借出', icon: 'ri-share-forward-line', color: 'text-sky-400', badge: 'badge-lent' },
        ];
        function loadStatuses() {
            try {
                const saved = localStorage.getItem(STATUS_KEY);
                return saved ? JSON.parse(saved) : defaultStatuses.map(s => ({...s}));
            } catch { return defaultStatuses.map(s => ({...s})); }
        }
        function saveStatuses(arr) { localStorage.setItem(STATUS_KEY, JSON.stringify(arr)); App.statuses = arr; }
        function getStatusMap() {
            const m = {};
            App.statuses.forEach(s => { m[s.key] = [s.label, s.badge, s.icon]; });
            return m;
        }
        function getStatusGroups() {
            return App.statuses.map(s => ({ key: s.key, label: s.label, icon: s.icon, color: s.color }));
        }

        const App = {
            statuses: loadStatuses(),
            currentView: 'dashboard',
            categories: [],
            itemsSize: loadItemsSize(),
            itemAttrs: loadItemAttrs(),
            locations: [],
            selectedItems: new Set(),
            itemsPage: 1,
            itemsSort: 'updated_at',
            itemsOrder: 'DESC',
            itemsFilter: { search: '', category: 0, location: 0, status: '' },
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
        function toast(msg, type = 'success') {
            const c = document.getElementById('toast-container');
            const el = document.createElement('div');
            el.className = `toast toast-${type}`;
            el.innerHTML = `<i class="ri-${type === 'success' ? 'check' : 'error-warning'}-line"></i>${msg}`;
            c.appendChild(el);
            setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateY(-10px)'; setTimeout(() => el.remove(), 300); }, 2500);
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
        const settingsSubViews = ['import-export', 'settings', 'status-settings', 'changelog'];

        function switchView(view) {
            App.currentView = view;
            document.querySelectorAll('.sidebar-link[data-view]').forEach(el => {
                el.classList.toggle('active', el.dataset.view === view);
            });
            const titles = { dashboard: '仪表盘', items: '物品管理', categories: '分类管理', locations: '位置管理', trash: '物品管理', 'import-export': '导入/导出', settings: '排序设置', 'status-settings': '状态管理', changelog: '更新记录' };
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

        ${d.expiringItems && d.expiringItems.length > 0 ? `
        <div class="glass rounded-2xl p-5 mb-6 anim-up">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-white flex items-center gap-2"><i class="ri-alarm-warning-line text-amber-400"></i>过期提醒</h3>
                <span class="text-xs text-slate-500">${d.expiringItems.length} 件物品设有过期时间</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                ${d.expiringItems.map(item => {
                const days = daysUntilExpiry(item.expiry_date);
                const urgency = days < 0 ? 'expired' : days <= 7 ? 'urgent' : days <= 30 ? 'warning' : 'normal';
                const bgMap = { expired: 'bg-red-500/10 border-red-500/20', urgent: 'bg-amber-500/10 border-amber-500/20', warning: 'bg-yellow-500/5 border-yellow-500/15', normal: 'bg-white/5 border-white/5' };
                const textMap = { expired: 'text-red-400', urgent: 'text-amber-400', warning: 'text-yellow-400', normal: 'text-slate-400' };
                const labelMap = { expired: '已过期 ' + Math.abs(days) + ' 天', urgent: '剩余 ' + days + ' 天', warning: '剩余 ' + days + ' 天', normal: '剩余 ' + days + ' 天' };
                return `<div class="flex items-center gap-3 p-3 rounded-xl border ${bgMap[urgency]} cursor-pointer hover:brightness-110 transition" onclick="showDetail(${item.id})">
                        <div class="w-9 h-9 rounded-lg ${item.image ? '' : 'bg-slate-700/50 flex items-center justify-center text-base'} flex-shrink-0 overflow-hidden">
                            ${item.image ? `<img src="?img=${item.image}" class="w-full h-full object-cover rounded-lg">` : `<span>${item.category_icon || '📦'}</span>`}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-200 truncate">${esc(item.name)}</p>
                            <p class="text-xs ${textMap[urgency]} font-medium">${item.expiry_date} · ${labelMap[urgency]}</p>
                        </div>
                        ${urgency === 'expired' ? '<i class="ri-error-warning-fill text-red-400 flex-shrink-0"></i>' : urgency === 'urgent' ? '<i class="ri-alarm-warning-fill text-amber-400 flex-shrink-0"></i>' : ''}
                    </div>`;
            }).join('')}
            </div>
        </div>
        ` : ''}

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

            <div class="glass rounded-2xl p-5 anim-up" style="animation-delay:0.1s">
                <h3 class="font-semibold text-white flex items-center gap-2 mb-4"><i class="ri-pie-chart-line text-violet-400"></i>分类统计</h3>
                <div class="space-y-3">
                    ${sortCategoryStats(d.categoryStats.filter(c => c.count > 0)).map(cat => {
                const pct = d.totalKinds > 0 ? Math.round(cat.count / d.totalKinds * 100) : 0;
                return `<div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm text-slate-300">${cat.icon} ${esc(cat.name)}</span>
                                <span class="text-xs text-slate-500">${cat.count} 种 / ${cat.total_qty} 件</span>
                            </div>
                            <div class="h-2 bg-slate-700/50 rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-500" style="width:${pct}%;background:${cat.color}"></div>
                            </div>
                        </div>`;
            }).join('')}
                    ${d.categoryStats.filter(c => c.count > 0).length === 0 ? '<p class="text-slate-500 text-sm text-center py-4">暂无数据</p>' : ''}
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
            const scrollY = window.scrollY;

            container.innerHTML = `
        <div class="glass rounded-2xl p-4 mb-6 anim-up">
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative flex-1 min-w-[200px]">
                    <i class="ri-search-line absolute left-3 top-1/2 -translate-y-1/2 text-slate-500"></i>
                    <input type="text" id="itemSearch" class="input pl-10 !py-2" placeholder="搜索物品名称、描述、标签..." value="${esc(f.search)}" onkeyup="handleItemSearch(event)">
                </div>
                <select class="input !w-auto !py-2" onchange="App.itemsFilter.category=+this.value;App.itemsPage=1;renderView()">
                    <option value="0">所有分类</option>
                    ${App.categories.map(c => `<option value="${c.id}" ${f.category == c.id ? 'selected' : ''}>${c.icon} ${esc(c.name)}</option>`).join('')}
                </select>
                <select class="input !w-auto !py-2" onchange="App.itemsFilter.location=+this.value;App.itemsPage=1;renderView()">
                    <option value="0">所有位置</option>
                    ${App.locations.map(l => `<option value="${l.id}" ${f.location == l.id ? 'selected' : ''}>${esc(l.name)}</option>`).join('')}
                </select>
                <select class="input !w-auto !py-2" onchange="App.itemsFilter.status=this.value;App.itemsPage=1;renderView()">
                    <option value="">所有状态</option>
                    ${App.statuses.map(s => `<option value="${s.key}" ${f.status === s.key ? 'selected' : ''}>${s.label}</option>`).join('')}
                </select>
                <select class="input !w-auto !py-2" onchange="const [s,o]=this.value.split(':');App.itemsSort=s;App.itemsOrder=o;renderView()">
                    <option value="updated_at:DESC" ${App.itemsSort === 'updated_at' && App.itemsOrder === 'DESC' ? 'selected' : ''}>最近更新</option>
                    <option value="created_at:DESC" ${App.itemsSort === 'created_at' && App.itemsOrder === 'DESC' ? 'selected' : ''}>最近添加</option>
                    <option value="name:ASC" ${App.itemsSort === 'name' && App.itemsOrder === 'ASC' ? 'selected' : ''}>名称 A-Z</option>
                    <option value="purchase_price:DESC" ${App.itemsSort === 'purchase_price' && App.itemsOrder === 'DESC' ? 'selected' : ''}>价格高→低</option>
                    <option value="quantity:DESC" ${App.itemsSort === 'quantity' && App.itemsOrder === 'DESC' ? 'selected' : ''}>数量多→少</option>
                </select>
                ${(f.search || f.category || f.location || f.status || App.itemsSort !== 'updated_at' || App.itemsOrder !== 'DESC') ? `
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
            <p class="text-sm text-slate-500">共 ${total} 件物品</p>
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
                    <button onclick="setItemsSize('large')" class="size-btn ${App.itemsSize==='large'?'active':''}" title="大"><i class="ri-layout-grid-fill"></i></button>
                    <button onclick="setItemsSize('medium')" class="size-btn ${App.itemsSize==='medium'?'active':''}" title="中"><i class="ri-grid-fill"></i></button>
                    <button onclick="setItemsSize('small')" class="size-btn ${App.itemsSize==='small'?'active':''}" title="小"><i class="ri-list-check"></i></button>
                </div>
                <button onclick="switchView('trash')" class="btn btn-ghost btn-sm text-slate-400 hover:text-red-400 transition" title="回收站">
                    <i class="ri-delete-bin-line mr-1"></i>回收站
                </button>
            </div>
        </div>

        ${items.length === 0 ? `
            <div class="empty-state anim-up">
                <i class="ri-archive-line"></i>
                <h3 class="text-xl font-semibold text-slate-400 mb-2">暂无物品</h3>
                <p class="text-slate-500 mb-4">${f.search || f.category || f.location || f.status ? '没有找到匹配的物品，试试其他搜索条件？' : '点击「添加物品」按钮开始管理你的物品吧'}</p>
                ${!f.search && !f.category && !f.location && !f.status ? '<button onclick="openAddItem()" class="btn btn-primary"><i class="ri-add-line"></i>添加第一件物品</button>' : ''}
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
        function handleItemSearch(e) { if (e.key === 'Enter' || e.target.value === '') { App.itemsFilter.search = e.target.value; App.itemsPage = 1; renderView(); } }
        function handleGlobalSearch(e) { if (e.key === 'Enter') { App.itemsFilter.search = e.target.value; switchView('items'); } }
        function resetItemsFilter() {
            App.itemsFilter = { search: '', category: 0, location: 0, status: '' };
            App.itemsSort = 'updated_at';
            App.itemsOrder = 'DESC';
            App.itemsPage = 1;
            renderView();
        }
        function toggleSelect(id, checked) { checked ? App.selectedItems.add(id) : App.selectedItems.delete(id); renderItemsFast(); }

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
                ${item.expiry_date ? `<div class="p-3 rounded-xl ${expiryBg(item.expiry_date)}"><p class="text-xs text-slate-500 mb-1">过期时间</p><p class="text-sm font-medium ${expiryColor(item.expiry_date)}">${item.expiry_date} ${expiryLabel(item.expiry_date)}</p></div>` : ''}
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
            document.getElementById('itemStatus').value = 'active';
            document.getElementById('itemExpiry').value = '';
            document.getElementById('itemPurchaseFrom').value = '';
            document.getElementById('itemNotes').value = '';
            resetUploadZone();
            await populateSelects();
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
            document.getElementById('itemStatus').value = item.status;
            document.getElementById('itemImage').value = item.image || '';
            document.getElementById('itemPurchaseFrom').value = item.purchase_from || '';
            document.getElementById('itemNotes').value = item.notes || '';

            resetUploadZone();
            if (item.image) {
                document.getElementById('uploadPreview').src = `?img=${item.image}`;
                document.getElementById('uploadPreview').classList.remove('hidden');
                document.getElementById('uploadPlaceholder').classList.add('hidden');
                document.getElementById('uploadZone').classList.add('has-image');
            }

            // 关键：await 等待下拉框填充完成后再设置值
            await populateSelects();
            document.getElementById('itemCategory').value = item.category_id;
            document.getElementById('itemLocation').value = item.location_id;
            document.getElementById('itemModal').classList.add('show');
        }

        async function populateSelects() {
            await loadBaseData();
            const catSelect = document.getElementById('itemCategory');
            catSelect.innerHTML = '<option value="0">选择分类</option>' + App.categories.map(c => `<option value="${c.id}">${c.icon} ${esc(c.name)}</option>`).join('');
            const locSelect = document.getElementById('itemLocation');
            locSelect.innerHTML = '<option value="0">选择位置</option>' + App.locations.map(l => `<option value="${l.id}">${esc(l.name)}</option>`).join('');
            const statusSelect = document.getElementById('itemStatus');
            statusSelect.innerHTML = App.statuses.map(s => `<option value="${s.key}">${s.label}</option>`).join('');
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
                purchase_from: document.getElementById('itemPurchaseFrom').value.trim(),
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
            const res = await fetch('?api=upload', { method: 'POST', body: fd }).then(r => r.json());
            if (res.success) {
                document.getElementById('itemImage').value = res.filename;
                document.getElementById('uploadPreview').src = `?img=${res.filename}`;
                document.getElementById('uploadPreview').classList.remove('hidden');
                document.getElementById('uploadPlaceholder').classList.add('hidden');
                document.getElementById('uploadZone').classList.add('has-image');
            } else {
                toast(res.message || '上传失败', 'error');
            }
            input.value = '';
        }

        // ============================================================
        // 🏷️ 分类管理
        // ============================================================
        async function renderCategories(container) {
            await loadBaseData();
            const sortedCats = sortListData(App.categories, App.sortSettings.categories_list);
            container.innerHTML = `
        <div class="flex items-center justify-between mb-6 anim-up">
            <p class="text-sm text-slate-500">共 ${App.categories.length} 个分类</p>
            <button onclick="openAddCategory()" class="btn btn-primary btn-sm"><i class="ri-add-line"></i>添加分类</button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            ${sortedCats.map((cat, i) => `
                <div class="glass glass-hover rounded-2xl p-5 anim-up" style="animation-delay:${i * 40}ms;border-left:3px solid ${cat.color}">
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
            const sortedLocs = sortListData(App.locations, App.sortSettings.locations_list);
            const topLevel = sortedLocs.filter(l => !l.parent_id || l.parent_id === 0);
            const children = sortedLocs.filter(l => l.parent_id && l.parent_id !== 0);

            container.innerHTML = `
        <div class="flex items-center justify-between mb-6 anim-up">
            <p class="text-sm text-slate-500">共 ${App.locations.length} 个位置</p>
            <button onclick="openAddLocation()" class="btn btn-primary btn-sm"><i class="ri-add-line"></i>添加位置</button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            ${topLevel.map((loc, i) => {
                const subs = children.filter(c => c.parent_id === loc.id);
                return `
                    <div class="glass glass-hover rounded-2xl p-5 anim-up" style="animation-delay:${i * 40}ms">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-xl bg-amber-500/10 flex items-center justify-center"><i class="ri-map-pin-2-fill text-amber-400 text-xl"></i></div>
                            <div>
                                <h3 class="font-semibold text-white">${esc(loc.name)}</h3>
                                <p class="text-xs text-slate-500">${loc.item_count} 件物品${subs.length ? ` · ${subs.length} 个子位置` : ''}</p>
                            </div>
                        </div>
                        ${loc.description ? `<p class="text-xs text-slate-500 mb-3">${esc(loc.description)}</p>` : ''}
                        ${subs.length ? `<div class="mb-3 pl-3 border-l-2 border-white/5 space-y-1">${subs.map(s => `<div class="text-xs text-slate-400 flex justify-between"><span>↳ ${esc(s.name)}</span><span class="text-slate-600">${s.item_count} 件</span></div>`).join('')}</div>` : ''}
                        <div class="flex gap-2">
                            <button onclick="viewItemsByLocation(${loc.id})" class="btn btn-ghost btn-sm flex-1" style="color:#38bdf8" title="查看物品"><i class="ri-archive-line"></i>物品</button>
                            <button onclick="editLocation(${loc.id})" class="btn btn-ghost btn-sm flex-1"><i class="ri-edit-line"></i>编辑</button>
                            <button onclick="deleteLocation(${loc.id},'${esc(loc.name)}',${loc.item_count})" class="btn btn-danger btn-sm flex-1"><i class="ri-delete-bin-line"></i>删除</button>
                        </div>
                    </div>`;
            }).join('')}
            ${children.filter(c => !topLevel.find(t => t.id === c.parent_id)).map((loc, i) => `
                <div class="glass glass-hover rounded-2xl p-5 anim-up">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-10 h-10 rounded-xl bg-slate-500/10 flex items-center justify-center"><i class="ri-map-pin-line text-slate-400 text-xl"></i></div>
                        <div><h3 class="font-semibold text-white">${esc(loc.name)}</h3><p class="text-xs text-slate-500">${loc.item_count} 件物品</p></div>
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
            App.itemsFilter = { search: '', category: catId, location: 0, status: '' };
            App.itemsPage = 1;
            switchView('items');
        }

        function viewItemsByLocation(locId) {
            App.itemsFilter = { search: '', category: 0, location: locId, status: '' };
            App.itemsPage = 1;
            switchView('items');
        }

        function openAddLocation() {
            document.getElementById('locModalTitle').textContent = '添加位置';
            document.getElementById('locId').value = '';
            document.getElementById('locName').value = '';
            document.getElementById('locDesc').value = '';
            populateLocationParent();
            document.getElementById('locParent').value = '0';
            document.getElementById('locationModal').classList.add('show');
        }

        function editLocation(id) {
            const loc = App.locations.find(l => l.id === id);
            if (!loc) return;
            document.getElementById('locModalTitle').textContent = '编辑位置';
            document.getElementById('locId').value = loc.id;
            document.getElementById('locName').value = loc.name;
            document.getElementById('locDesc').value = loc.description || '';
            populateLocationParent(loc.id);
            document.getElementById('locParent').value = loc.parent_id || 0;
            document.getElementById('locationModal').classList.add('show');
        }

        function populateLocationParent(excludeId) {
            const sel = document.getElementById('locParent');
            sel.innerHTML = '<option value="0">无 (顶级位置)</option>' + App.locations.filter(l => l.id !== excludeId).map(l => `<option value="${l.id}">${esc(l.name)}</option>`).join('');
        }

        async function saveLocation(e) {
            e.preventDefault();
            const id = document.getElementById('locId').value;
            const data = { id: id ? +id : undefined, name: document.getElementById('locName').value.trim(), parent_id: +document.getElementById('locParent').value, description: document.getElementById('locDesc').value.trim() };
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
        // 🔄 导入 / 导出
        // ============================================================
        function renderImportExport(container) {
            container.innerHTML = `
        <div class="max-w-2xl mx-auto space-y-6">
            <div class="glass rounded-2xl p-6 anim-up">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 rounded-xl bg-sky-500/10 flex items-center justify-center"><i class="ri-download-cloud-line text-2xl text-sky-400"></i></div>
                    <div><h3 class="font-semibold text-white text-lg">导出数据</h3><p class="text-sm text-slate-500">将所有物品、分类和位置数据导出为 JSON 文件</p></div>
                </div>
                <button onclick="exportData()" class="btn btn-primary w-full"><i class="ri-download-line"></i>导出 JSON 文件</button>
            </div>

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.1s">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 rounded-xl bg-emerald-500/10 flex items-center justify-center"><i class="ri-upload-cloud-line text-2xl text-emerald-400"></i></div>
                    <div><h3 class="font-semibold text-white text-lg">导入数据</h3><p class="text-sm text-slate-500">从之前导出的 JSON 文件中恢复物品数据</p></div>
                </div>
                <div class="upload-zone mb-4" onclick="document.getElementById('importInput').click()">
                    <i class="ri-file-upload-line text-3xl text-slate-500 mb-2"></i>
                    <p class="text-sm text-slate-400">点击选择 JSON 文件</p>
                </div>
                <input type="file" id="importInput" class="hidden" accept=".json" onchange="importData(this)">
            </div>

            <div class="glass rounded-2xl p-6 anim-up" style="animation-delay:0.2s">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 rounded-xl bg-amber-500/10 flex items-center justify-center"><i class="ri-file-excel-line text-2xl text-amber-400"></i></div>
                    <div><h3 class="font-semibold text-white text-lg">导出 CSV</h3><p class="text-sm text-slate-500">导出物品列表为 CSV 格式，方便在 Excel 中查看</p></div>
                </div>
                <button onclick="exportCSV()" class="btn btn-ghost w-full"><i class="ri-file-download-line"></i>导出 CSV 文件</button>
            </div>
        </div>
    `;
        }

        async function exportData() {
            const res = await api('export');
            if (!res.success) { toast('导出失败', 'error'); return; }
            const blob = new Blob([JSON.stringify(res.data, null, 2)], { type: 'application/json' });
            downloadBlob(blob, `items_backup_${dateStr()}.json`);
            toast('导出成功');
        }

        async function exportCSV() {
            const res = await api('export');
            if (!res.success) { toast('导出失败', 'error'); return; }
            const items = res.data.items;
            const header = ['ID', '名称', '分类', '位置', '数量', '价格', '购入渠道', '购入日期', '过期时间', '条码', '标签', '状态', '描述', '备注'];
            const rows = items.map(i => [i.id, i.name, i.category_name || '', i.location_name || '', i.quantity, i.purchase_price, i.purchase_from || '', i.purchase_date, i.expiry_date || '', i.barcode, i.tags, i.status, i.description, i.notes || ''].map(v => `"${String(v || '').replace(/"/g, '""')}"`));
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
                const importPayload = data.items ? data : { items: data };
                if (!confirm(`即将导入 ${importPayload.items.length} 件物品，确认继续？`)) return;
                const res = await apiPost('import', importPayload);
                if (res.success) { toast(res.message); renderView(); } else toast(res.message, 'error');
            } catch (e) { toast('文件解析失败', 'error'); }
            input.value = '';
        }

        // ---------- 工具函数 ----------
        function esc(str) { if (!str) return ''; const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
        function dateStr() { return new Date().toISOString().slice(0, 10); }
        function downloadBlob(blob, name) { const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(a.href); }

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
            document.getElementById('itemStatus').value = item.status;
            document.getElementById('itemImage').value = item.image || '';
            document.getElementById('itemPurchaseFrom').value = item.purchase_from || '';
            document.getElementById('itemNotes').value = item.notes || '';

            resetUploadZone();
            if (item.image) {
                document.getElementById('uploadPreview').src = `?img=${item.image}`;
                document.getElementById('uploadPreview').classList.remove('hidden');
                document.getElementById('uploadPlaceholder').classList.add('hidden');
                document.getElementById('uploadZone').classList.add('has-image');
            }

            await populateSelects();
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
                ${item.expiry_date ? `<div class="p-3 rounded-xl ${expiryBg(item.expiry_date)}"><p class="text-xs text-slate-500 mb-1">过期时间</p><p class="text-sm font-medium ${expiryColor(item.expiry_date)}">${item.expiry_date} ${expiryLabel(item.expiry_date)}</p></div>` : ''}
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
                version: 'v2.0.0', date: '2026-02-08', title: '筛选重置 + 属性按钮优化 + 交互性能提升',
                changes: [
                    '物品管理筛选栏新增「重置」按钮，一键清除所有筛选/排序条件',
                    '「属性」按钮 UI 优化为更明显的下拉按钮样式',
                    '点击物品复选框、属性下拉复选框、切换视图大小不再触发页面刷新和 API 请求',
                    '属性下拉面板勾选后自动保持打开，无需反复点击',
                    '整体交互性能大幅提升，滚动位置自动保持',
                ]
            },
            {
                version: 'v1.9.0', date: '2026-02-08', title: '状态编辑 + 属性显示控制 + 件数位置调整',
                changes: [
                    '状态管理支持编辑已有状态（名称、图标、颜色）',
                    '物品管理新增「属性」按钮，可勾选物品卡片中显示的属性字段',
                    '属性显示设置保存到本地存储，默认显示位置和过期时间',
                    '物品卡片中件数显示位置调整到分类前面',
                ]
            },
            {
                version: 'v1.8.0', date: '2026-02-08', title: '分类查看物品 + 状态管理 + 新增物品属性',
                changes: [
                    '分类管理添加「物品」按钮，跳转物品管理并应用分类过滤',
                    '新增状态管理设置，支持自定义物品状态（新增/删除）',
                    '物品新增「购入渠道」和「备注」属性',
                    '物品表单布局优化为3列网格',
                ]
            },
            {
                version: 'v1.7.0', date: '2026-02-08', title: '物品状态分组 + 过期显示 + 缩略图',
                changes: [
                    '物品管理按状态分组显示，空组自动隐藏',
                    '物品卡片和列表显示过期时间',
                    '小尺寸列表显示物品缩略图',
                    '修复中尺寸按钮图标缺失',
                ]
            },
            {
                version: 'v1.6.0', date: '2026-02-08', title: '仪表盘布局优化 + 物品视图大小',
                changes: [
                    '仪表盘统计改为紧凑行内布局',
                    '最近更新区域优化显示密度',
                    '物品管理新增大/中/小三种显示尺寸切换',
                ]
            },
            {
                version: 'v1.5.0', date: '2026-02-08', title: '侧边栏设置菜单重构',
                changes: [
                    '侧边栏新增「设置」父菜单，支持展开/收起',
                    '「导入/导出」「排序设置」「更新记录」收纳为二级子菜单',
                    '更新记录拆分为独立页面',
                    '切换到设置子页面时菜单自动展开'
                ]
            },
            {
                version: 'v1.4.0', date: '2026-02-08', title: '回收站详情 + 位置查看物品 + 更新记录',
                changes: [
                    '回收站物品可点击查看完整详情信息',
                    '位置管理每个位置新增「物品」按钮，点击跳转物品管理并自动筛选',
                    '排序设置页面下方新增「更新记录」板块',
                    '左上角 Logo 旁显示当前版本号，自动取最新版本'
                ]
            },
            {
                version: 'v1.3.0', date: '2026-02-08', title: '回收站功能',
                changes: [
                    '新增「回收站」视图，入口位于物品管理页面内',
                    '删除物品改为软删除，物品移入回收站而非永久删除',
                    '物品关联图片同步移入 uploads/trash/ 目录保存',
                    '回收站支持：单个恢复、全部恢复、单个彻底删除、清空回收站',
                    '恢复物品时图片自动移回 uploads/',
                    '回收站物品可点击查看完整详情信息',
                    '所有常规查询自动过滤已删除物品'
                ]
            },
            {
                version: 'v1.2.0', date: '2026-02-08', title: '排序设置 + 复制物品 + 仪表盘优化',
                changes: [
                    '新增「排序设置」页面，可自定义各列表默认排序',
                    '排序设置通过 localStorage 持久化保存',
                    '新增「复制物品」功能，一键复制物品资料到新建表单',
                    '仪表盘分类统计自动隐藏无物品的空分类',
                    '位置管理新增「查看物品」按钮，快速跳转筛选'
                ]
            },
            {
                version: 'v1.1.0', date: '2026-02-08', title: '新增过期时间 + 修复编辑 Bug',
                changes: [
                    '新增「过期时间」字段，支持在物品表单中设置',
                    '仪表盘新增「过期提醒」板块，按过期时间排列',
                    '过期状态三级视觉标识：已过期/即将过期/正常',
                    '修复编辑物品时分类和位置下拉框未正确显示原有值'
                ]
            },
            {
                version: 'v1.0.0', date: '2026-02-08', title: '初始版本发布',
                changes: [
                    '完整的物品 CRUD 功能',
                    '仪表盘统计面板 + 分类进度条',
                    '分类管理（Emoji 图标 + 自定义颜色）',
                    '位置管理（支持父子层级）',
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

        // ---------- 状态管理页面 ----------
        function renderStatusSettings(container) {
            const badgeColors = [
                { value: 'badge-active', label: '绿色' },
                { value: 'badge-lent', label: '蓝色' },
                { value: 'badge-archived', label: '灰色' },
                { value: 'badge-warning', label: '橙色' },
                { value: 'badge-danger', label: '红色' },
            ];
            const iconColors = ['text-emerald-400', 'text-sky-400', 'text-slate-400', 'text-amber-400', 'text-red-400', 'text-violet-400', 'text-rose-400'];
            const iconOptions = ['ri-checkbox-circle-line', 'ri-archive-line', 'ri-share-forward-line', 'ri-tools-line', 'ri-error-warning-line', 'ri-time-line', 'ri-shopping-bag-line', 'ri-gift-line', 'ri-heart-line', 'ri-star-line'];

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
                            <span class="text-sm text-white">${esc(s.label)}</span>
                            <span class="text-[11px] text-slate-600 ml-2 font-mono">${s.key}</span>
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
                        <label class="block text-xs text-slate-500 mb-1">状态标识（英文）</label>
                        <input type="text" id="newStatusKey" class="input" placeholder="例如: repair">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">显示名称</label>
                        <input type="text" id="newStatusLabel" class="input" placeholder="例如: 维修中">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">图标</label>
                        <select id="newStatusIcon" class="input">
                            ${iconOptions.map(ic => `<option value="${ic}"><i class="${ic}"></i> ${ic.replace('ri-','').replace('-line','')}</option>`).join('')}
                        </select>
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
            const key = document.getElementById('newStatusKey').value.trim().toLowerCase().replace(/[^a-z0-9_]/g, '');
            const label = document.getElementById('newStatusLabel').value.trim();
            const icon = document.getElementById('newStatusIcon').value;
            const badge = document.getElementById('newStatusBadge').value;
            if (!key || !label) { toast('请填写标识和名称', 'error'); return; }
            if (App.statuses.find(s => s.key === key)) { toast('该标识已存在', 'error'); return; }
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
            const iconOptions = ['ri-checkbox-circle-line','ri-archive-line','ri-share-forward-line','ri-tools-line','ri-error-warning-line','ri-time-line','ri-shopping-bag-line','ri-gift-line','ri-heart-line','ri-star-line'];
            const row = document.getElementById('statusRow' + idx);
            if (!row) return;
            row.innerHTML = `
                <div class="w-full space-y-2">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <div>
                            <label class="block text-[10px] text-slate-500 mb-0.5">标识</label>
                            <input type="text" id="editKey${idx}" class="input !py-1 text-xs" value="${esc(s.key)}" readonly style="opacity:0.5" title="标识不可修改">
                        </div>
                        <div>
                            <label class="block text-[10px] text-slate-500 mb-0.5">名称</label>
                            <input type="text" id="editLabel${idx}" class="input !py-1 text-xs" value="${esc(s.label)}">
                        </div>
                        <div>
                            <label class="block text-[10px] text-slate-500 mb-0.5">图标</label>
                            <select id="editIcon${idx}" class="input !py-1 text-xs">
                                ${iconOptions.map(ic => `<option value="${ic}" ${s.icon === ic ? 'selected' : ''}>${ic.replace('ri-','').replace('-line','')}</option>`).join('')}
                            </select>
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
            saveStatuses(defaultStatuses.map(s => ({...s})));
            toast('已恢复默认状态');
            renderView();
        }

        // ---------- 过期时间工具 ----------
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