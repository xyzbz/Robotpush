<?php
if (!defined('__TYPECHO_ADMIN__')) exit;

// 复用插件的 reasonLabel 输出中文原因
require_once __DIR__ . '/inc/notify.php';

$db = \Typecho\Db::get();
$prefix = $db->getPrefix();
$table = $prefix . 'commentguard_log';

// 处理删除单条日志
if (isset($_GET['del']) && \Typecho\Widget::widget('Widget_User')->pass('administrator')) {
    $id = (int) $_GET['del'];
    $db->query($db->delete($table)->where('id = ?', $id));
    Typecho_Widget::widget('Widget_Notice')->set(_t('日志已删除'), 'success');
}

// 清空日志
if (isset($_GET['clear']) && \Typecho\Widget::widget('Widget_User')->pass('administrator')) {
    $db->query($db->delete($table));
    Typecho_Widget::widget('Widget_Notice')->set(_t('日志已清空'), 'success');
}

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$pageSize = 20;
$offset = ($page - 1) * $pageSize;

$total = 0;
$logs = array();
try {
    $total = (int) $db->fetchObject($db->select(array('COUNT(*)' => 'num'))->from($table))->num;
    $logs = $db->fetchAll($db->select()->from($table)->order('created', \Typecho\Db::SORT_DESC)->offset($offset)->limit($pageSize));
} catch (\Throwable $e) {
    // 表不存在
}

$totalPages = max(1, (int) ceil($total / $pageSize));

// 原因 → 中文标签 + 类型徽章（低饱和、贴 Typecho 调性）
function cg_reason_label($reason)
{
    return method_exists('CommentGuard_Notify', 'reasonLabel')
        ? CommentGuard_Notify::reasonLabel($reason)
        : $reason;
}

function cg_reason_badge($reason)
{
    $label = cg_reason_label($reason);
    if ($reason === 'ai') {
        $cls = 'cg-badge-ai';
    } elseif (strpos($reason, 'keyword:') === 0 || strpos($reason, 'name:') === 0
        || strpos($reason, 'url:') === 0 || strpos($reason, 'mail:') === 0) {
        $cls = 'cg-badge-rule';
    } elseif ($reason === 'no_chinese') {
        $cls = 'cg-badge-gray';
    } else {
        $cls = 'cg-badge-other';
    }
    return '<span class="cg-badge ' . $cls . '">' . htmlspecialchars($label) . '</span>';
}
?>
<style>
/* 拦截日志面板 —— 灰底白卡，对齐 Typecho 后台视觉 */
.cg-wrap{padding:4px 0 8px;}
.cg-card{background:#fff;border:1px solid #e3e5e8;border-radius:6px;box-shadow:0 1px 2px rgba(0,0,0,.04);overflow:hidden;}
.cg-card-hd{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #eef0f2;background:#fafbfc;}
.cg-card-hd h3{margin:0;font-size:14px;font-weight:600;color:#333;}
.cg-card-hd h3 .cg-count{color:#467b96;font-weight:700;}
.cg-card-hd .cg-actions{display:flex;gap:8px;}
.cg-card-hd .cg-btn{display:inline-block;padding:5px 14px;border-radius:4px;font-size:13px;line-height:1.4;text-decoration:none;cursor:pointer;border:1px solid #d9d9d6;background:#fff;color:#555;}
.cg-card-hd .cg-btn:hover{background:#f5f5f5;text-decoration:none;}
.cg-card-hd .cg-btn-primary{background:#467b96;border-color:#467b96;color:#fff;}
.cg-card-hd .cg-btn-primary:hover{background:#3d6c82;color:#fff;}
.cg-card-hd .cg-btn-danger{color:#c0392b;border-color:#e0c7c4;}
.cg-card-hd .cg-btn-danger:hover{background:#fbf0ef;}

.cg-table{width:100%;border-collapse:collapse;font-size:13px;}
.cg-table th{padding:11px 16px;text-align:left;font-size:12px;font-weight:600;color:#8a8a8a;background:#fafbfc;border-bottom:1px solid #eef0f2;white-space:nowrap;}
.cg-table td{padding:12px 16px;border-bottom:1px solid #f2f3f5;color:#444;vertical-align:middle;}
.cg-table tbody tr:last-child td{border-bottom:none;}
.cg-table tbody tr:hover{background:#f7fafc;}
.cg-table .cg-muted{color:#999;font-size:12px;}
.cg-table .cg-ip{font-family:Consolas,Monaco,monospace;color:#888;font-size:12px;}
.cg-table .cg-text{display:block;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

.cg-badge{display:inline-block;padding:2px 10px;border-radius:10px;font-size:12px;line-height:18px;white-space:nowrap;}
.cg-badge-ai{background:#fdf0e7;color:#c06a2f;}
.cg-badge-rule{background:#eaf2fb;color:#4a7fbd;}
.cg-badge-gray{background:#f0f1f2;color:#8a8a8a;}
.cg-badge-other{background:#eef0f2;color:#7a7a7a;}

.cg-del{color:#c0392b;text-decoration:none;}
.cg-del:hover{text-decoration:underline;}
.cg-empty{padding:48px 0;text-align:center;color:#b5b5b5;}
.cg-pager{margin:0;padding:14px 18px;border-top:1px solid #eef0f2;}
</style>

<div class="typecho-page-title">
    <h2><?php _e('评论卫士 · 拦截日志'); ?></h2>
</div>

<div class="row typecho-page-main" role="main">
    <div class="col-mb-12">
        <div class="cg-wrap">
            <div class="cg-card">
                <div class="cg-card-hd">
                    <h3><?php _e('共拦截'); ?> <span class="cg-count"><?php echo $total; ?></span> <?php _e('条评论'); ?></h3>
                    <div class="cg-actions">
                        <a class="cg-btn" href="<?php $options->adminUrl('plugins.php?config=CommentGuard'); ?>"><?php _e('配置规则'); ?></a>
                        <a class="cg-btn cg-btn-danger" href="<?php echo \Typecho\Common::url('extending.php?panel=CommentGuard%2Fpanel.php&clear=1', \Typecho\Widget::widget('Widget_Options')->adminUrl); ?>" onclick="return confirm('<?php _e('确定清空所有拦截日志？此操作不可恢复。'); ?>')"><?php _e('清空日志'); ?></a>
                    </div>
                </div>

                <table class="cg-table">
                    <thead>
                        <tr>
                            <th><?php _e('ID'); ?></th>
                            <th><?php _e('昵称'); ?></th>
                            <th><?php _e('邮箱'); ?></th>
                            <th><?php _e('原因'); ?></th>
                            <th><?php _e('内容'); ?></th>
                            <th><?php _e('IP'); ?></th>
                            <th><?php _e('时间'); ?></th>
                            <th><?php _e('操作'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="8" class="cg-empty"><?php _e('暂无拦截记录'); ?></td>
                        </tr>
                        <?php else: foreach ($logs as $log): ?>
                        <tr>
                            <td class="cg-muted"><?php echo $log['id']; ?></td>
                            <td><?php echo htmlspecialchars($log['author']); ?></td>
                            <td class="cg-muted" title="<?php echo htmlspecialchars($log['mail']); ?>"><span class="cg-text"><?php echo htmlspecialchars($log['mail']); ?></span></td>
                            <td><?php echo cg_reason_badge($log['reason']); ?></td>
                            <td title="<?php echo htmlspecialchars($log['text']); ?>"><span class="cg-text"><?php echo htmlspecialchars($log['text']); ?></span></td>
                            <td><span class="cg-ip"><?php echo htmlspecialchars($log['ip']); ?></span></td>
                            <td class="cg-muted"><?php echo date('Y-m-d H:i', $log['created']); ?></td>
                            <td><a class="cg-del" href="<?php echo \Typecho\Common::url('extending.php?panel=CommentGuard%2Fpanel.php&del=' . $log['id'], \Typecho\Widget::widget('Widget_Options')->adminUrl); ?>" onclick="return confirm('<?php _e('删除这条拦截记录？'); ?>')"><?php _e('删除'); ?></a></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <?php if ($totalPages > 1): ?>
                <div class="typecho-pager cg-pager">
                    <div class="typecho-pager-content">
                        <ul>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="<?php echo $i === $page ? 'current' : ''; ?>">
                                <a href="<?php echo \Typecho\Common::url('extending.php?panel=CommentGuard%2Fpanel.php&page=' . $i, \Typecho\Widget::widget('Widget_Options')->adminUrl); ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
