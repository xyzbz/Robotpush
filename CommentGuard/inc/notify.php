<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * CommentGuard 机器人通知（钉钉 / 飞书 / 企业微信）
 * 拦截评论转待审核、或需要人工审核时，推送通知给站长。
 */
class CommentGuard_Notify
{
    /**
     * 推送一条通知到所有已配置的机器人。
     *
     * @param array  $options 插件配置
     * @param string $message 纯文本消息
     */
    public static function send($options, $message)
    {
        $types = isset($options['notify_types']) ? $options['notify_types'] : array();
        if (empty($types) || !is_array($types)) {
            error_log('[CommentGuard] 机器人通知跳过：未勾选任何通知渠道（钉钉/飞书/企微）');
            return;
        }

        foreach ($types as $type) {
            $webhook = '';
            if ($type === 'dingtalk') $webhook = isset($options['dingtalk_webhook']) ? $options['dingtalk_webhook'] : '';
            elseif ($type === 'feishu') $webhook = isset($options['feishu_webhook']) ? $options['feishu_webhook'] : '';
            elseif ($type === 'wecom') $webhook = isset($options['wecom_webhook']) ? $options['wecom_webhook'] : '';

            // 去掉首尾空格/换行：复制 webhook 时极易混入空格，导致钉钉/飞书/企微 400
            $webhook = trim((string) $webhook);

            if (empty($webhook)) {
                error_log('[CommentGuard] 渠道「' . $type . '」已勾选但 webhook 为空，跳过');
                continue;
            }

            try {
                if ($type === 'dingtalk') {
                    $data = array('msgtype' => 'text', 'text' => array('content' => $message));
                } elseif ($type === 'feishu') {
                    $data = array('msg_type' => 'text', 'content' => array('text' => $message));
                } else { // wecom
                    $data = array('msgtype' => 'text', 'text' => array('content' => $message));
                }

                $ch = curl_init($webhook);
                curl_setopt_array($ch, array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($data),
                    CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
                    CURLOPT_TIMEOUT => 2,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                ));
                $resp = curl_exec($ch);
                if ($resp === false) {
                    error_log('[CommentGuard] 机器人通知发送失败(' . $type . '): ' . curl_error($ch));
                } else {
                    error_log('[CommentGuard] 机器人通知已发送(' . $type . '): ' . substr((string) $resp, 0, 200));
                }
                curl_close($ch);
            } catch (\Throwable $e) {
                error_log('[CommentGuard] 机器人通知异常(' . $type . '): ' . $e->getMessage());
            }
        }
    }

    /**
     * 构造一条「待人工审核评论」通知消息。
     */
    public static function buildPendingMessage($comment, $reason, $options)
    {
        $author = isset($comment['author']) ? $comment['author'] : '';
        $mail   = isset($comment['mail']) ? $comment['mail'] : '';
        $url    = isset($comment['url']) ? $comment['url'] : '';
        $text   = isset($comment['text']) ? mb_substr($comment['text'], 0, 200) : '';
        $ip     = isset($comment['ip']) ? $comment['ip'] : '';
        $reasonLabel = self::reasonLabel($reason);

        return "【评论守护机器人】有评论待人工审核\n"
            . "拦截原因：{$reasonLabel}\n"
            . "昵称：{$author}\n"
            . "邮箱：{$mail}\n"
            . "网址：{$url}\n"
            . "内容：{$text}\n"
            . "IP：{$ip}\n"
            . "时间：" . date('Y-m-d H:i:s');
    }

    /**
     * 构造一条「新评论」通知消息。
     */
    public static function buildNewCommentMessage($comment)
    {
        $author = isset($comment['author']) ? $comment['author'] : '';
        $mail   = isset($comment['mail']) ? $comment['mail'] : '';
        $url    = isset($comment['url']) ? $comment['url'] : '';
        $text   = isset($comment['text']) ? mb_substr($comment['text'], 0, 200) : '';
        $title  = isset($comment['title']) ? $comment['title'] : '';

        return "【评论守护机器人】新评论提醒\n"
            . "文章：《{$title}》\n"
            . "昵称：{$author}\n"
            . "邮箱：{$mail}\n"
            . "网址：{$url}\n"
            . "内容：{$text}\n"
            . "时间：" . date('Y-m-d H:i:s');
    }

    public static function reasonLabel($reason)
    {
        if ($reason === 'ai') return 'AI 判定违规';
        if ($reason === 'no_chinese') return '非中文评论';
        if ($reason === 'site_pending') return '站点审核策略（所有评论需人工审核）';
        if ($reason === 'new_pending') return '待人工审核';
        if (strpos($reason, 'keyword:') === 0) return '命中关键词「' . substr($reason, 8) . '」';
        if (strpos($reason, 'name:') === 0) return '命中昵称规则「' . substr($reason, 5) . '」';
        if (strpos($reason, 'url:') === 0) return '命中网址规则「' . substr($reason, 4) . '」';
        if (strpos($reason, 'mail:') === 0) return '命中邮箱规则「' . substr($reason, 5) . '」';
        return '本地规则';
    }
}
