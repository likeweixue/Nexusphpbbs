<?php
require "../include/bittorrent.php";
dbconn();

if (empty($CURUSER) && !guest_forum_access_enabled()) {
    loggedinorreturn();
}
if (!empty($CURUSER)) {
    parked();
}

$isChinese = str_starts_with(get_langfolder_cookie(), 'ch');
$L = $isChinese ? [
    'title' => 'RSS订阅',
    'intro' => '选择分区、板块或主题，生成对应的 RSS 订阅地址。',
    'section' => '分区新主题',
    'forum' => '板块新主题',
    'topic' => '主题新回复',
    'choose' => '请选择',
    'topic_id' => '主题 ID',
    'generate' => '生成 RSS',
    'preview' => '预览',
    'feed_url' => '订阅地址',
    'no_items' => '暂无内容',
    'invalid' => '参数无效，请重新选择。',
    'items' => '条',
    'title_col' => '标题',
    'time_col' => '时间',
    'forum_col' => '板块',
    'author_col' => '作者',
    'copy_hint' => '把下面的地址复制到 RSS 阅读器即可订阅。',
    'guest_hint' => '当前为访客浏览，仅显示公开板块。',
] : [
    'title' => 'RSS Feeds',
    'intro' => 'Choose a section, forum or topic to generate an RSS feed.',
    'section' => 'New topics in section',
    'forum' => 'New topics in forum',
    'topic' => 'New replies in topic',
    'choose' => 'Please choose',
    'topic_id' => 'Topic ID',
    'generate' => 'Generate RSS',
    'preview' => 'Preview',
    'feed_url' => 'Feed URL',
    'no_items' => 'No items',
    'invalid' => 'Invalid parameters, please choose again.',
    'items' => 'items',
    'title_col' => 'Title',
    'time_col' => 'Time',
    'forum_col' => 'Forum',
    'author_col' => 'Author',
    'copy_hint' => 'Copy the URL below into your RSS reader.',
    'guest_hint' => 'You are browsing as a guest; only public forums are shown.',
];

$type = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';
$id = intval($_GET['id'] ?? 0);
$format = isset($_GET['format']) && $_GET['format'] === 'rss' ? 'rss' : '';
$class = intval(get_user_class());

function rss_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Resolve the selected section/forum/topic and return its name + base query info.
 * Returns null when invalid or not readable by the current user.
 */
function rss_resolve_target(string $type, int $id, int $class): ?array
{
    if ($id <= 0) {
        return null;
    }
    if ($type === 'section') {
        $res = sql_query("SELECT * FROM overforums WHERE id = " . sqlesc($id) . " LIMIT 1");
        $row = mysql_fetch_assoc($res);
        if (!$row || intval($row['minclassview']) > $class) {
            return null;
        }
        return ['type' => 'section', 'id' => $id, 'name' => $row['name']];
    }
    if ($type === 'forum') {
        $res = sql_query("SELECT * FROM forums WHERE id = " . sqlesc($id) . " LIMIT 1");
        $row = mysql_fetch_assoc($res);
        if (!$row || intval($row['minclassread']) > $class) {
            return null;
        }
        return ['type' => 'forum', 'id' => $id, 'name' => $row['name']];
    }
    if ($type === 'topic') {
        $res = sql_query("SELECT t.*, f.name AS forum_name, f.minclassread FROM topics t INNER JOIN forums f ON f.id = t.forumid WHERE t.id = " . sqlesc($id) . " LIMIT 1");
        $row = mysql_fetch_assoc($res);
        if (!$row || intval($row['minclassread']) > $class) {
            return null;
        }
        return ['type' => 'topic', 'id' => $id, 'name' => $row['subject'], 'forum_name' => $row['forum_name']];
    }
    return null;
}

function rss_fetch_items(array $target, int $class): array
{
    if ($target['type'] === 'section') {
        $sql = "SELECT t.id, t.subject, p.added, f.name AS forum_name, p.body, u.username
                FROM topics t
                INNER JOIN forums f ON f.id = t.forumid
                LEFT JOIN posts p ON p.id = t.firstpost
                LEFT JOIN users u ON u.id = t.userid
                WHERE f.forid = " . intval($target['id']) . " AND f.minclassread <= " . $class . "
                ORDER BY t.id DESC LIMIT 50";
    } elseif ($target['type'] === 'forum') {
        $sql = "SELECT t.id, t.subject, p.added, f.name AS forum_name, p.body, u.username
                FROM topics t
                INNER JOIN forums f ON f.id = t.forumid
                LEFT JOIN posts p ON p.id = t.firstpost
                LEFT JOIN users u ON u.id = t.userid
                WHERE t.forumid = " . intval($target['id']) . " AND f.minclassread <= " . $class . "
                ORDER BY t.id DESC LIMIT 50";
    } else {
        $sql = "SELECT p.id, p.topicid, p.added, p.body, t.subject, f.name AS forum_name, u.username
                FROM posts p
                INNER JOIN topics t ON t.id = p.topicid
                INNER JOIN forums f ON f.id = t.forumid
                LEFT JOIN users u ON u.id = p.userid
                WHERE p.topicid = " . intval($target['id']) . " AND f.minclassread <= " . $class . "
                ORDER BY p.id DESC LIMIT 50";
    }
    $items = [];
    $res = sql_query($sql);
    while ($row = mysql_fetch_assoc($res)) {
        $items[] = $row;
    }
    return $items;
}

$target = null;
if ($type !== '' || $id > 0) {
    $target = rss_resolve_target($type, $id, $class);
}

if ($format === 'rss') {
    if ($target === null) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo $L['invalid'];
        exit;
    }
    $items = rss_fetch_items($target, $class);
    $siteUrl = rtrim(getSchemeAndHttpHost(), '/');
    $channelTitle = $SITENAME . ' - ' . $L[$target['type']] . ': ' . $target['name'];
    $channelLink = $siteUrl . '/forums.php';
    $feedUrl = $siteUrl . '/getrss.php?type=' . rawurlencode($target['type']) . '&id=' . intval($target['id']) . '&format=rss';

    header('Content-Type: application/rss+xml; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    echo '<?xml version="1.0" encoding="utf-8"?>' . "\n";
    ?>
<rss version="2.0">
    <channel>
        <title><?php echo rss_escape($channelTitle) ?></title>
        <link><?php echo rss_escape($channelLink) ?></link>
        <description><?php echo rss_escape($SITENAME . ' forum RSS') ?></description>
        <language><?php echo $isChinese ? 'zh-cn' : 'en' ?></language>
        <lastBuildDate><?php echo date(DATE_RSS) ?></lastBuildDate>
        <atom:link xmlns:atom="http://www.w3.org/2005/Atom" href="<?php echo rss_escape($feedUrl) ?>" rel="self" type="application/rss+xml" />
        <?php foreach ($items as $item): ?>
            <?php
            if ($target['type'] === 'topic') {
                $itemTitle = 'Re: ' . $item['subject'];
                $itemLink = $siteUrl . '/forums.php?action=viewtopic&topicid=' . intval($item['topicid']) . '&page=p' . intval($item['id']) . '#pid' . intval($item['id']);
                $description = trim(strip_tags((string)$item['body']));
            } else {
                $itemTitle = $item['subject'];
                $itemLink = $siteUrl . '/forums.php?action=viewtopic&topicid=' . intval($item['id']);
                $description = trim(strip_tags((string)($item['body'] ?? '')));
            }
            $description = mb_substr($description, 0, 500, 'UTF-8');
            ?>
            <item>
                <title><?php echo rss_escape($itemTitle) ?></title>
                <link><?php echo rss_escape($itemLink) ?></link>
                <description><?php echo rss_escape($description) ?></description>
                <pubDate><?php echo date(DATE_RSS, strtotime($item['added'])) ?></pubDate>
                <guid isPermaLink="true"><?php echo rss_escape($itemLink) ?></guid>
                <?php if (!empty($item['username'])): ?>
                <author><?php echo rss_escape($item['username']) ?></author>
                <?php endif; ?>
            </item>
        <?php endforeach; ?>
    </channel>
</rss>
    <?php
    exit;
}

// ----- HTML page -----
$overforums = [];
$res = sql_query("SELECT * FROM overforums WHERE minclassview <= " . $class . " ORDER BY sort ASC, id ASC");
while ($row = mysql_fetch_assoc($res)) {
    $overforums[] = $row;
}
$forums = [];
$res = sql_query("SELECT * FROM forums WHERE minclassread <= " . $class . " ORDER BY forid ASC, sort ASC, id ASC");
while ($row = mysql_fetch_assoc($res)) {
    $forums[] = $row;
}
$forumGroups = [];
foreach ($forums as $forum) {
    $forumGroups[intval($forum['forid'])][] = $forum;
}

$items = $target !== null ? rss_fetch_items($target, $class) : [];
$feedUrl = '';
if ($target !== null) {
    $feedUrl = rtrim(getSchemeAndHttpHost(), '/') . '/getrss.php?type=' . rawurlencode($target['type']) . '&id=' . intval($target['id']) . '&format=rss';
}

stdhead($L['title']);
begin_main_frame();
?>
<h1 align="center"><?php echo htmlspecialchars($L['title']) ?></h1>
<p align="center"><?php echo htmlspecialchars($L['intro']) ?></p>
<?php if (empty($CURUSER)): ?>
    <p align="center" class="small"><?php echo htmlspecialchars($L['guest_hint']) ?></p>
<?php endif; ?>

<?php if ($target === null && ($type !== '' || $id > 0)): ?>
    <p align="center" style="color:#c00"><?php echo htmlspecialchars($L['invalid']) ?></p>
<?php endif; ?>

<?php if ($target !== null): ?>
    <h2 align="left"><?php echo htmlspecialchars($L['preview']) ?>: <?php echo htmlspecialchars($L[$target['type']] . ' - ' . $target['name']) ?></h2>
    <table class="main" width="97%" border="1" cellspacing="0" cellpadding="5" align="center">
        <tr>
            <td class="rowhead"><?php echo htmlspecialchars($L['feed_url']) ?></td>
            <td class="rowfollow">
                <input type="text" readonly value="<?php echo htmlspecialchars($feedUrl) ?>" style="width: 88%" onclick="this.select()">
                <div class="small"><?php echo htmlspecialchars($L['copy_hint']) ?></div>
            </td>
        </tr>
    </table>
    <br />
    <table class="main" width="97%" border="1" cellspacing="0" cellpadding="5" align="center">
        <tr>
            <td class="colhead"><?php echo htmlspecialchars($L['title_col']) ?></td>
            <td class="colhead"><?php echo htmlspecialchars($L['forum_col']) ?></td>
            <td class="colhead"><?php echo htmlspecialchars($L['author_col']) ?></td>
            <td class="colhead"><?php echo htmlspecialchars($L['time_col']) ?></td>
        </tr>
        <?php if (empty($items)): ?>
            <tr><td class="rowfollow" colspan="4" align="center"><?php echo htmlspecialchars($L['no_items']) ?></td></tr>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
                <?php
                if ($target['type'] === 'topic') {
                    $title = 'Re: ' . $item['subject'];
                    $link = 'forums.php?action=viewtopic&topicid=' . intval($item['topicid']) . '&page=p' . intval($item['id']) . '#pid' . intval($item['id']);
                } else {
                    $title = $item['subject'];
                    $link = 'forums.php?action=viewtopic&topicid=' . intval($item['id']);
                }
                ?>
                <tr>
                    <td class="rowfollow"><a href="<?php echo htmlspecialchars($link) ?>"><?php echo htmlspecialchars($title) ?></a></td>
                    <td class="rowfollow"><?php echo htmlspecialchars($item['forum_name'] ?? '') ?></td>
                    <td class="rowfollow"><?php echo htmlspecialchars($item['username'] ?? '') ?></td>
                    <td class="rowfollow"><?php echo htmlspecialchars($item['added']) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>
    <br />
<?php endif; ?>

<h2 align="left"><?php echo htmlspecialchars($L['section']) ?></h2>
<form method="get" action="getrss.php">
    <input type="hidden" name="type" value="section">
    <table class="main" width="97%" border="1" cellspacing="0" cellpadding="5" align="center">
        <tr>
            <td class="rowhead" width="20%"><?php echo htmlspecialchars($L['section']) ?></td>
            <td class="rowfollow">
                <select name="id">
                    <option value="0"><?php echo htmlspecialchars($L['choose']) ?></option>
                    <?php foreach ($overforums as $overforum): ?>
                        <option value="<?php echo intval($overforum['id']) ?>"<?php echo ($type === 'section' && $id === intval($overforum['id'])) ? ' selected' : '' ?>><?php echo htmlspecialchars($overforum['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" value="<?php echo htmlspecialchars($L['generate']) ?>" class="btn">
            </td>
        </tr>
    </table>
</form>

<h2 align="left"><?php echo htmlspecialchars($L['forum']) ?></h2>
<form method="get" action="getrss.php">
    <input type="hidden" name="type" value="forum">
    <table class="main" width="97%" border="1" cellspacing="0" cellpadding="5" align="center">
        <tr>
            <td class="rowhead" width="20%"><?php echo htmlspecialchars($L['forum']) ?></td>
            <td class="rowfollow">
                <select name="id">
                    <option value="0"><?php echo htmlspecialchars($L['choose']) ?></option>
                    <?php foreach ($overforums as $overforum): ?>
                        <?php if (empty($forumGroups[intval($overforum['id'])])) continue; ?>
                        <optgroup label="<?php echo htmlspecialchars($overforum['name']) ?>">
                            <?php foreach ($forumGroups[intval($overforum['id'])] as $forum): ?>
                                <option value="<?php echo intval($forum['id']) ?>"<?php echo ($type === 'forum' && $id === intval($forum['id'])) ? ' selected' : '' ?>><?php echo htmlspecialchars($forum['name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <input type="submit" value="<?php echo htmlspecialchars($L['generate']) ?>" class="btn">
            </td>
        </tr>
    </table>
</form>

<h2 align="left"><?php echo htmlspecialchars($L['topic']) ?></h2>
<form method="get" action="getrss.php">
    <input type="hidden" name="type" value="topic">
    <table class="main" width="97%" border="1" cellspacing="0" cellpadding="5" align="center">
        <tr>
            <td class="rowhead" width="20%"><?php echo htmlspecialchars($L['topic_id']) ?></td>
            <td class="rowfollow">
                <input type="number" name="id" value="<?php echo $type === 'topic' && $id > 0 ? $id : '' ?>" min="1" style="width: 120px">
                <input type="submit" value="<?php echo htmlspecialchars($L['generate']) ?>" class="btn">
            </td>
        </tr>
    </table>
</form>
<?php
end_main_frame();
stdfoot();
