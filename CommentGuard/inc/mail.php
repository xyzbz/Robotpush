<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * CommentGuard 邮件通知
 * 覆盖两类事件：
 *   1. 新评论提醒（通知文章作者 + 上级评论人，逻辑照搬 Comment2Mail）
 *   2. 拦截待审核提醒（通知站长人工审核）
 *
 * 发信引擎：PHPMailer（取自 Comment2Mail，GPL-3.0）。
 * 邮件样式沿用 Comment2Mail 的卡片式排版。
 */
class CommentGuard_Mail
{
    /**
     * 发一封新评论提醒邮件。
     *
     * @param array $recipients 收件人数组，每项 array('name'=>, 'mail'=>)
     * @param array $comment    评论数组（author/mail/url/text/title/permalink）
     * @param array $options    插件配置
     * @param string $desc      描述（如「您有一条新的回复」）
     * @return bool
     */
    public static function sendNewComment($recipients, $comment, $options, $desc)
    {
        if (empty($recipients)) {
            return false;
        }
        return self::dispatch($recipients, $comment, $options, $desc, '您有一条新的回复');
    }

    /**
     * 发一封「后台登录」提醒邮件给站长。
     * 登录通知走卡片式 HTML 正文（样式沿用评论通知），收件人复用 mail_to（留空回落博主邮箱）。
     *
     * @param array  $loginInfo 登录信息，键：username / ip / geo / time
     * @param array  $options   插件配置
     * @return bool
     */
    public static function sendLoginNotify($loginInfo, $options)
    {
        if (empty($options['mail_notify'])) {
            error_log('[CommentGuard] 登录邮件通知跳过：邮件通知开关未打开');
            return false;
        }
        $to = isset($options['mail_to']) ? trim($options['mail_to']) : '';
        if ($to === '') {
            $to = self::ownerMail();
        }
        $recipients = self::splitRecipients($to);
        if (empty($recipients)) {
            return false;
        }

        $recipientList = array();
        foreach ($recipients as $addr) {
            $recipientList[] = array('name' => '', 'mail' => $addr);
        }

        return self::dispatchLogin($recipientList, $loginInfo, $options, '后台登录提醒');
    }

    /**
     * 登录通知发信入口（卡片式 HTML）。
     */
    private static function dispatchLogin($recipients, $loginInfo, $options, $subject)
    {
        if (empty($options['mail_notify'])) {
            return false;
        }
        $host = isset($options['smtp_host']) ? trim($options['smtp_host']) : '';
        $user = isset($options['smtp_user']) ? trim($options['smtp_user']) : '';
        $from = isset($options['mail_from']) ? trim($options['mail_from']) : '';

        if ($from === '' && $user !== '' && filter_var($user, FILTER_VALIDATE_EMAIL)) {
            $from = $user;
        }
        if ($host === '' || $user === '' || $from === '') {
            error_log('[CommentGuard] 登录邮件跳过：SMTP 配置不完整');
            return false;
        }

        try {
            $mailer = self::buildMailer($options);
            $fromName = isset($options['mail_from_name']) && $options['mail_from_name'] !== ''
                ? $options['mail_from_name'] : $from;
            $mailer->setFrom($from, $fromName);

            foreach ($recipients as $r) {
                if (!empty($r['mail']) && filter_var($r['mail'], FILTER_VALIDATE_EMAIL)) {
                    $mailer->addAddress($r['mail'], isset($r['name']) ? $r['name'] : '');
                }
            }

            $siteTitle = self::siteTitle();
            $mailer->Subject = "{$subject}｜《{$siteTitle}》";
            $mailer->isHTML(true);
            $mailer->Body = self::loginBody($loginInfo, $options);

            $ok = $mailer->send();
            if (!$ok) {
                self::writeLog('登录邮件发信失败: ' . $mailer->ErrorInfo);
            }
            return $ok;
        } catch (\Throwable $e) {
            self::writeLog('登录邮件发信失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 登录通知邮件正文（卡片式 HTML，样式沿用评论通知）。
     */
    private static function loginBody($loginInfo, $options)
    {
        $username = isset($loginInfo['username']) ? htmlspecialchars($loginInfo['username']) : '';
        $ip       = isset($loginInfo['ip']) ? htmlspecialchars($loginInfo['ip']) : '';
        $geo      = isset($loginInfo['geo']) ? htmlspecialchars($loginInfo['geo']) : '';
        $time     = isset($loginInfo['time']) ? htmlspecialchars($loginInfo['time']) : date('Y-m-d H:i:s');

        try {
            $siteUrl = Typecho_Widget::widget('Widget_Options')->siteUrl;
            $siteTitle = Typecho_Widget::widget('Widget_Options')->title;
        } catch (\Throwable $e) {
            $siteUrl = '';
            $siteTitle = '';
        }

        $ipRow = ($ip !== '') ? '<p>IP 地址：<span style="color:#12ADDB;">' . $ip . '</span></p>' : '';
        $geoRow = ($geo !== '') ? '<p>地理位置：' . $geo . '</p>' : '';

        return <<<HTML
<style type="text/css">
.emailz{background-color:white;border-top:2px solid #12ADDB;box-shadow:0 1px 3px #AAAAAA;line-height:180%;padding:0 15px 12px;width:500px;margin:35px auto;color:#555555;font-family:'Century Gothic','Trebuchet MS','Hiragino Sans GB','Microsoft Yahei',Tahoma,Helvetica,Arial,'SimSun',sans-serif;font-size:14px;}
@media(max-width:767px){.emailz{width:88%;}}
</style>
<div class="emailz">
<h2 style="border-bottom:1px solid #DDD;font-size:14px;font-weight:normal;padding:13px 0 10px 8px;"><span style="color:#12ADDB;font-weight:bold;">&gt; </span>后台登录提醒</h2>
<div style="padding:0 12px 0 12px;margin-top:18px">
<p>时间：<span style="border-bottom:1px dashed #ccc;">{$time}</span></p>
<p style="background-color:#f5f5f5;border:0px solid #DDD;padding:10px 15px;margin:18px 0">用户 <span style="color:#12ADDB;font-weight:bold;">{$username}</span> 登录了后台</p>
{$ipRow}{$geoRow}
<p>如非本人操作，请及时修改密码。欢迎回来 <a style="text-decoration:none;color:#12addb" href="{$siteUrl}" target="_blank" rel="noopener">{$siteTitle}</a>。</p>
</div>
</div>
HTML;
    }

    /**
     * 发一封「拦截待审核」提醒邮件给站长。
     *
     * @param array  $comment 评论数组
     * @param string $reason  拦截原因
     * @param array  $options 插件配置
     * @return bool
     */
    public static function sendPending($comment, $reason, $options)
    {
        if (empty($options['mail_notify'])) {
            error_log('[CommentGuard] 邮件通知跳过：邮件通知开关未打开');
            return false;
        }
        $to = isset($options['mail_to']) ? trim($options['mail_to']) : '';
        if ($to === '') {
            // 默认收件人 = 博主（uid=1）邮箱，用户不填也能收到
            $to = self::ownerMail();
        }
        $recipients = self::splitRecipients($to);
        if (empty($recipients)) {
            return false;
        }

        $label = CommentGuard_Notify::reasonLabel($reason);
        $recipientList = array();
        foreach ($recipients as $addr) {
            $recipientList[] = array('name' => '', 'mail' => $addr);
        }

        return self::dispatch($recipientList, $comment, $options, "有评论被拦截，原因：{$label}", '您有一条待审核评论');
    }

    /**
     * 统一发信入口。SMTP 未配置或收件人为空时静默跳过。
     */
    private static function dispatch($recipients, $comment, $options, $desc, $subject)
    {
        if (empty($options['mail_notify'])) {
            error_log('[CommentGuard] 邮件通知跳过：邮件通知开关未打开');
            return false;
        }
        $host = isset($options['smtp_host']) ? trim($options['smtp_host']) : '';
        $user = isset($options['smtp_user']) ? trim($options['smtp_user']) : '';
        $pass = isset($options['smtp_pass']) ? $options['smtp_pass'] : '';
        $from = isset($options['mail_from']) ? trim($options['mail_from']) : '';

        // 发件邮箱留空时用 SMTP 登录账号兜底（绝大多数邮箱服务商要求一致）
        if ($from === '' && $user !== '' && filter_var($user, FILTER_VALIDATE_EMAIL)) {
            $from = $user;
        }

        if ($host === '' || $user === '' || $from === '') {
            error_log('[CommentGuard] 邮件通知跳过：SMTP 配置不完整（host/user/发件邮箱缺失）');
            return false;
        }

        try {
            $mailer = self::buildMailer($options);

            $fromName = isset($options['mail_from_name']) && $options['mail_from_name'] !== ''
                ? $options['mail_from_name'] : $from;
            $mailer->setFrom($from, $fromName);

            foreach ($recipients as $r) {
                if (!empty($r['mail']) && filter_var($r['mail'], FILTER_VALIDATE_EMAIL)) {
                    $mailer->addAddress($r['mail'], isset($r['name']) ? $r['name'] : '');
                }
            }

            $siteTitle = self::siteTitle();
            $mailer->Subject = "{$subject}｜《{$siteTitle}》";
            $mailer->isHTML(true);
            $mailer->Body = self::mailBody($comment, $desc, $options);

            $ok = $mailer->send();
            if (!$ok) {
                self::writeLog('发信失败: ' . $mailer->ErrorInfo);
            }
            return $ok;
        } catch (\Throwable $e) {
            self::writeLog('发信失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 组装 PHPMailer 实例（SMTP 配置）。
     */
    private static function buildMailer($options)
    {
        require_once __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
        require_once __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';
        require_once __DIR__ . '/../vendor/PHPMailer/src/Exception.php';

        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->CharSet = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
        $mailer->Encoding = PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;

        $mailer->isSMTP();
        $mailer->Host = $options['smtp_host'];
        $mailer->SMTPAuth = true;
        $mailer->Username = $options['smtp_user'];
        $mailer->Password = $options['smtp_pass'];
        $mailer->SMTPSecure = isset($options['smtp_secure']) ? $options['smtp_secure'] : '';
        $mailer->Port = (int) (isset($options['smtp_port']) ? $options['smtp_port'] : 25);

        return $mailer;
    }

    /**
     * 逗号分隔的收件人列表 → 数组（校验邮箱格式）。
     */
    private static function splitRecipients($to)
    {
        $list = array();
        foreach (preg_split('/[,;，；]/u', $to) as $addr) {
            $addr = trim($addr);
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $list[] = $addr;
            }
        }
        return $list;
    }

    /**
     * 站点标题（用于邮件主题）。
     */
    private static function siteTitle()
    {
        try {
            $options = Typecho_Widget::widget('Widget_Options');
            return $options->title;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 邮件正文（沿用 Comment2Mail 的卡片式排版）。
     */
    private static function mailBody($comment, $desc, $options)
    {
        $title   = isset($comment['title']) ? htmlspecialchars($comment['title']) : '';
        $author  = isset($comment['author']) ? htmlspecialchars($comment['author']) : '';
        $mail    = isset($comment['mail']) ? htmlspecialchars($comment['mail']) : '';
        $url     = isset($comment['url']) ? htmlspecialchars($comment['url']) : '';
        $ip      = isset($comment['ip']) ? htmlspecialchars($comment['ip']) : '';
        $text    = isset($comment['text']) ? htmlspecialchars(mb_substr($comment['text'], 0, 1000)) : '';
        $link    = isset($comment['permalink']) ? htmlspecialchars($comment['permalink']) : '';
        $created = isset($comment['created_at']) ? htmlspecialchars($comment['created_at']) : date('Y-m-d H:i:s');

        try {
            $siteUrl = Typecho_Widget::widget('Widget_Options')->siteUrl;
            $siteTitle = Typecho_Widget::widget('Widget_Options')->title;
        } catch (\Throwable $e) {
            $siteUrl = '';
            $siteTitle = '';
        }

        $urlRow = ($url !== '') ? '<p>网址：<a href="' . $url . '" style="color:#12ADDB">' . $url . '</a></p>' : '';
        $ipRow = ($ip !== '') ? '<p>IP：' . $ip . '</p>' : '';

        return <<<HTML
<style type="text/css">
.emailz{background-color:white;border-top:2px solid #12ADDB;box-shadow:0 1px 3px #AAAAAA;line-height:180%;padding:0 15px 12px;width:500px;margin:35px auto;color:#555555;font-family:'Century Gothic','Trebuchet MS','Hiragino Sans GB','Microsoft Yahei',Tahoma,Helvetica,Arial,'SimSun',sans-serif;font-size:14px;}
@media(max-width:767px){.emailz{width:88%;}}
</style>
<div class="emailz">
<h2 style="border-bottom:1px solid #DDD;font-size:14px;font-weight:normal;padding:13px 0 10px 8px;"><span style="color:#12ADDB;font-weight:bold;">&gt; </span>{$desc}</h2>
<div style="padding:0 12px 0 12px;margin-top:18px">
<p>文章：<a href="{$link}" target="_blank" rel="noopener" style="text-decoration:none;color:#12ADDB">《{$title}》</a></p>
<p>时间：<span style="border-bottom:1px dashed #ccc;">{$created}</span></p>
<p style="background-color:#f5f5f5;border:0px solid #DDD;padding:10px 15px;margin:18px 0">{$text}</p>
<p>评论者：<span style="color:#12ADDB;">{$author}</span></p>
<p>邮箱：{$mail}</p>
{$urlRow}{$ipRow}
<p>您可以点击 <a style="text-decoration:none;color:#12addb" href="{$link}" target="_blank" rel="noopener">查看评论</a>，欢迎再次光临 <a style="text-decoration:none;color:#12addb" href="{$siteUrl}" target="_blank" rel="noopener">{$siteTitle}</a>。</p>
</div>
</div>
HTML;
    }

    /**
     * 记录发信日志（追加到插件目录 log.txt）。
     */
    private static function writeLog($msg)
    {
        error_log('[CommentGuard] ' . $msg);
        try {
            $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
            @file_put_contents(__DIR__ . '/../log.txt', $line, FILE_APPEND);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 博主（uid=1）邮箱，作为「通知收件人」留空时的默认值。
     */
    private static function ownerMail()
    {
        try {
            $db = \Typecho\Db::get();
            $row = $db->fetchRow($db->select('mail')->from('table.users')->where('uid = ?', 1));
            return ($row && !empty($row['mail'])) ? trim($row['mail']) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
