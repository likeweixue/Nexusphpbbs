<?php
require "../include/bittorrent.php";
dbconn();
loggedinorreturn();
parked();

if (get_user_class() < UC_ADMINISTRATOR) {
    permissiondenied();
}

$isChinese = str_starts_with(get_langfolder_cookie(), 'ch');
$L = $isChinese ? [
    'title' => '菜单栏设置',
    'desc' => '增加、减少、调整网站的菜单，支持二级子菜单。',
    'name' => '菜单名称',
    'url' => '链接地址',
    'parent' => '父菜单',
    'top' => '（顶级菜单）',
    'target' => '打开方式',
    'self' => '当前窗口',
    'blank' => '新窗口',
    'sort' => '排序',
    'enabled' => '启用',
    'yes' => '是',
    'no' => '否',
    'actions' => '操作',
    'edit' => '编辑',
    'delete' => '删除',
    'toggle' => '启用/停用',
    'move_up' => '上移',
    'move_down' => '下移',
    'add' => '添加菜单',
    'save' => '保存',
    'cancel' => '取消',
    'empty' => '还没有自定义菜单，当前显示系统默认菜单。添加第一条菜单后将替换默认菜单。',
    'confirm_delete' => '确定删除这个菜单吗？子菜单会变成顶级菜单。',
    'invalid_name' => '菜单名称不能为空，且不能超过 100 个字符。',
    'invalid_url' => '链接地址不能使用 javascript: / data: 等危险协议。',
    'invalid_parent' => '父菜单无效。',
    'saved' => '已保存。',
    'url_help' => '支持相对地址（如 forums.php）、站内路径（/forums.php）和完整 http(s) 地址。',
    'enabled_help' => '停用后不会显示在网站菜单中。',
    'sort_help' => '数字越小越靠前。',
] : [
    'title' => 'Menu Settings',
    'desc' => 'Add, remove or reorder site menu items. Two levels are supported.',
    'name' => 'Name',
    'url' => 'URL',
    'parent' => 'Parent',
    'top' => '(top level)',
    'target' => 'Target',
    'self' => 'Same window',
    'blank' => 'New window',
    'sort' => 'Sort',
    'enabled' => 'Enabled',
    'yes' => 'YES',
    'no' => 'NO',
    'actions' => 'Actions',
    'edit' => 'Edit',
    'delete' => 'Delete',
    'toggle' => 'Enable/Disable',
    'move_up' => 'Up',
    'move_down' => 'Down',
    'add' => 'Add menu item',
    'save' => 'Save',
    'cancel' => 'Cancel',
    'empty' => 'No custom menu yet. The default menu is shown until you add the first item.',
    'confirm_delete' => 'Delete this menu item? Its children will become top-level items.',
    'invalid_name' => 'Name is required and must be at most 100 characters.',
    'invalid_url' => 'The URL uses a disallowed protocol.',
    'invalid_parent' => 'Invalid parent menu.',
    'saved' => 'Saved.',
    'url_help' => 'Relative paths (forums.php), site paths (/forums.php) and full http(s) URLs are supported.',
    'enabled_help' => 'Disabled items are not shown.',
    'sort_help' => 'Smaller numbers are shown first.',
];

function custommenu_clear_cache(): void
{
    global $Cache;
    $Cache->delete_value('custom_menu_rows');
}

function custommenu_redirect(string $url): void
{
    header('Location: ' . $url);
    die;
}

function custommenu_fetch(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $res = sql_query("SELECT * FROM custom_menus WHERE id = " . sqlesc($id) . " LIMIT 1");
    $row = mysql_fetch_assoc($res);
    return $row ?: null;
}

$action = $_GET['action'] ?? 'list';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
}

$errors = [];
$editRow = null;

if ($action === 'save') {
    $id = intval($_POST['id'] ?? 0);
    $parentId = intval($_POST['parent_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $target = ($_POST['target'] ?? '_self') === '_blank' ? '_blank' : '_self';
    $sort = intval($_POST['sort'] ?? 0);
    $enabled = isset($_POST['enabled']) ? 1 : 0;

    if ($name === '' || mb_strlen($name) > 100) {
        $errors[] = $L['invalid_name'];
    }
    if (mb_strlen($url) > 500 || preg_match('/^\s*(javascript|data|vbscript):/i', $url)) {
        $errors[] = $L['invalid_url'];
    }
    if ($parentId > 0) {
        if ($id > 0 && $parentId === $id) {
            $errors[] = $L['invalid_parent'];
        } else {
            $parent = custommenu_fetch($parentId);
            if (!$parent || intval($parent['parent_id']) !== 0) {
                $errors[] = $L['invalid_parent'];
            }
        }
    }

    if (empty($errors)) {
        $now = date('Y-m-d H:i:s');
        if ($id > 0) {
            sql_query(sprintf(
                "UPDATE custom_menus SET parent_id = %d, name = %s, url = %s, target = %s, sort = %d, enabled = %d, updated_at = %s WHERE id = %d",
                $parentId,
                sqlesc($name),
                sqlesc($url),
                sqlesc($target),
                $sort,
                $enabled,
                sqlesc($now),
                $id
            ));
        } else {
            sql_query(sprintf(
                "INSERT INTO custom_menus (parent_id, name, url, target, sort, enabled, created_at, updated_at) VALUES (%d, %s, %s, %s, %d, %d, %s, %s)",
                $parentId,
                sqlesc($name),
                sqlesc($url),
                sqlesc($target),
                $sort,
                $enabled,
                sqlesc($now),
                sqlesc($now)
            ));
        }
        custommenu_clear_cache();
        custommenu_redirect('custommenu.php?saved=1');
    }

    $editRow = [
        'id' => $id,
        'parent_id' => $parentId,
        'name' => $name,
        'url' => $url,
        'target' => $target,
        'sort' => $sort,
        'enabled' => $enabled,
    ];
} elseif ($action === 'edit' || $action === 'add') {
    $editRow = $action === 'edit' ? custommenu_fetch(intval($_GET['id'] ?? 0)) : [
        'id' => 0,
        'parent_id' => 0,
        'name' => '',
        'url' => '',
        'target' => '_self',
        'sort' => 0,
        'enabled' => 1,
    ];
    if ($editRow === null) {
        custommenu_redirect('custommenu.php');
    }
} elseif ($action === 'delete') {
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        sql_query("UPDATE custom_menus SET parent_id = 0 WHERE parent_id = " . sqlesc($id));
        sql_query("DELETE FROM custom_menus WHERE id = " . sqlesc($id));
        custommenu_clear_cache();
    }
    custommenu_redirect('custommenu.php');
} elseif ($action === 'toggle') {
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        sql_query("UPDATE custom_menus SET enabled = 1 - enabled WHERE id = " . sqlesc($id));
        custommenu_clear_cache();
    }
    custommenu_redirect('custommenu.php');
} elseif ($action === 'move') {
    $id = intval($_GET['id'] ?? 0);
    $dir = ($_GET['dir'] ?? 'up') === 'down' ? 'down' : 'up';
    $row = custommenu_fetch($id);
    if ($row) {
        $parentId = intval($row['parent_id']);
        $res = sql_query("SELECT id FROM custom_menus WHERE parent_id = " . sqlesc($parentId) . " ORDER BY sort ASC, id ASC");
        $ids = [];
        while ($item = mysql_fetch_assoc($res)) {
            $ids[] = intval($item['id']);
        }
        $index = array_search($id, $ids, true);
        $swap = $dir === 'up' ? $index - 1 : $index + 1;
        if ($index !== false && isset($ids[$swap])) {
            $tmp = $ids[$index];
            $ids[$index] = $ids[$swap];
            $ids[$swap] = $tmp;
            foreach ($ids as $position => $itemId) {
                sql_query("UPDATE custom_menus SET sort = " . intval($position) . " WHERE id = " . intval($itemId));
            }
            custommenu_clear_cache();
        }
    }
    custommenu_redirect('custommenu.php');
}

$allRows = [];
$res = sql_query("SELECT * FROM custom_menus ORDER BY parent_id ASC, sort ASC, id ASC");
while ($row = mysql_fetch_assoc($res)) {
    $allRows[] = $row;
}
$namesById = [];
foreach ($allRows as $row) {
    $namesById[intval($row['id'])] = $row['name'];
}
$roots = array_values(array_filter($allRows, fn($row) => intval($row['parent_id']) === 0));

stdhead($L['title']);
begin_main_frame();
?>
<h1 align="center"><?php echo htmlspecialchars($L['title']) ?></h1>
<p align="center"><?php echo htmlspecialchars($L['desc']) ?></p>

<?php if (!empty($errors)): ?>
    <div align="center" style="color: #c00; padding: 8px;">
        <?php echo htmlspecialchars(implode(' ', $errors)) ?>
    </div>
<?php endif; ?>
<?php if (isset($_GET['saved'])): ?>
    <div align="center" style="color: #080; padding: 8px;"><?php echo htmlspecialchars($L['saved']) ?></div>
<?php endif; ?>

<?php if ($editRow !== null): ?>
    <h2 align="left"><?php echo htmlspecialchars($L['add']) ?></h2>
    <form method="post" action="custommenu.php">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo intval($editRow['id']) ?>">
        <table class="main" width="97%" border="1" cellspacing="0" cellpadding="5" align="center">
            <tr>
                <td class="rowhead" width="18%"><?php echo htmlspecialchars($L['name']) ?></td>
                <td class="rowfollow"><input type="text" name="name" value="<?php echo htmlspecialchars($editRow['name']) ?>" style="width: 320px" maxlength="100"></td>
            </tr>
            <tr>
                <td class="rowhead"><?php echo htmlspecialchars($L['url']) ?></td>
                <td class="rowfollow">
                    <input type="text" name="url" value="<?php echo htmlspecialchars($editRow['url']) ?>" style="width: 420px" maxlength="500">
                    <div class="small"><?php echo htmlspecialchars($L['url_help']) ?></div>
                </td>
            </tr>
            <tr>
                <td class="rowhead"><?php echo htmlspecialchars($L['parent']) ?></td>
                <td class="rowfollow">
                    <select name="parent_id">
                        <option value="0"><?php echo htmlspecialchars($L['top']) ?></option>
                        <?php foreach ($roots as $root): ?>
                            <?php if (intval($root['id']) === intval($editRow['id'])) continue; ?>
                            <option value="<?php echo intval($root['id']) ?>"<?php echo intval($editRow['parent_id']) === intval($root['id']) ? ' selected' : '' ?>>
                                <?php echo htmlspecialchars($root['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <td class="rowhead"><?php echo htmlspecialchars($L['target']) ?></td>
                <td class="rowfollow">
                    <select name="target">
                        <option value="_self"<?php echo $editRow['target'] !== '_blank' ? ' selected' : '' ?>><?php echo htmlspecialchars($L['self']) ?></option>
                        <option value="_blank"<?php echo $editRow['target'] === '_blank' ? ' selected' : '' ?>><?php echo htmlspecialchars($L['blank']) ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <td class="rowhead"><?php echo htmlspecialchars($L['sort']) ?></td>
                <td class="rowfollow">
                    <input type="number" name="sort" value="<?php echo intval($editRow['sort']) ?>" style="width: 80px">
                    <div class="small"><?php echo htmlspecialchars($L['sort_help']) ?></div>
                </td>
            </tr>
            <tr>
                <td class="rowhead"><?php echo htmlspecialchars($L['enabled']) ?></td>
                <td class="rowfollow">
                    <label><input type="checkbox" name="enabled" value="1"<?php echo intval($editRow['enabled']) ? ' checked' : '' ?>> <?php echo htmlspecialchars($L['enabled']) ?></label>
                    <div class="small"><?php echo htmlspecialchars($L['enabled_help']) ?></div>
                </td>
            </tr>
            <tr>
                <td colspan="2" align="center">
                    <input type="submit" value="<?php echo htmlspecialchars($L['save']) ?>" class="btn">
                    <a href="custommenu.php" class="btn"><?php echo htmlspecialchars($L['cancel']) ?></a>
                </td>
            </tr>
        </table>
    </form>
<?php endif; ?>

<h2 align="left"><?php echo htmlspecialchars($L['title']) ?></h2>
<p><a href="custommenu.php?action=add" class="btn"><?php echo htmlspecialchars($L['add']) ?></a></p>

<?php if (empty($allRows)): ?>
    <p align="center"><?php echo htmlspecialchars($L['empty']) ?></p>
<?php else: ?>
    <table class="main" width="97%" border="1" cellspacing="0" cellpadding="5" align="center">
        <tr>
            <td class="colhead">ID</td>
            <td class="colhead"><?php echo htmlspecialchars($L['name']) ?></td>
            <td class="colhead"><?php echo htmlspecialchars($L['url']) ?></td>
            <td class="colhead"><?php echo htmlspecialchars($L['parent']) ?></td>
            <td class="colhead"><?php echo htmlspecialchars($L['sort']) ?></td>
            <td class="colhead"><?php echo htmlspecialchars($L['enabled']) ?></td>
            <td class="colhead"><?php echo htmlspecialchars($L['actions']) ?></td>
        </tr>
        <?php foreach ($allRows as $row): ?>
            <?php
            $id = intval($row['id']);
            $parentId = intval($row['parent_id']);
            $parentName = $parentId > 0 ? ($namesById[$parentId] ?? ('#' . $parentId)) : '—';
            ?>
            <tr>
                <td class="rowfollow"><?php echo $id ?></td>
                <td class="rowfollow"><?php echo htmlspecialchars($row['name']) ?></td>
                <td class="rowfollow"><?php echo htmlspecialchars($row['url']) ?></td>
                <td class="rowfollow"><?php echo htmlspecialchars($parentName) ?></td>
                <td class="rowfollow"><?php echo intval($row['sort']) ?></td>
                <td class="rowfollow"><?php echo intval($row['enabled']) ? $L['yes'] : $L['no'] ?></td>
                <td class="rowfollow nowrap">
                    <a href="custommenu.php?action=edit&amp;id=<?php echo $id ?>"><?php echo htmlspecialchars($L['edit']) ?></a> |
                    <a href="custommenu.php?action=toggle&amp;id=<?php echo $id ?>"><?php echo htmlspecialchars($L['toggle']) ?></a> |
                    <a href="custommenu.php?action=move&amp;dir=up&amp;id=<?php echo $id ?>"><?php echo htmlspecialchars($L['move_up']) ?></a> |
                    <a href="custommenu.php?action=move&amp;dir=down&amp;id=<?php echo $id ?>"><?php echo htmlspecialchars($L['move_down']) ?></a> |
                    <a href="custommenu.php?action=delete&amp;id=<?php echo $id ?>" onclick="return confirm('<?php echo htmlspecialchars($L['confirm_delete'], ENT_QUOTES) ?>')"><?php echo htmlspecialchars($L['delete']) ?></a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<?php
end_main_frame();
stdfoot();
