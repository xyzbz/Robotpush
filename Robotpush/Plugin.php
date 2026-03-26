<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * Typecho后台登录、评论消息、实时提醒插件（支持钉钉、飞书和企业微信机器人）
 * 
 * @package Robotpush
 * @author 子夜松声、DeepSeek人工智能
 * @version 1.1
 * @link https://xyzbz.cn
 */

class Robotpush_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_User')->loginSucceed = array('Robotpush_Plugin', 'sendLoginNotify');
        Typecho_Plugin::factory('Widget_Feedback')->comment = array('Robotpush_Plugin', 'sendCommentNotify');
    }

    public static function deactivate(){}

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $robotTypes = new Typecho_Widget_Helper_Form_Element_Checkbox('robotTypes', array(
            'dingtalk' => _t('钉钉机器人'),
            'feishu' => _t('飞书机器人'),
            'wecom' => _t('企业微信机器人')
        ), array('dingtalk'), _t('机器人类型'), _t('选择使用的机器人类型（可多选）。'));
        $form->addInput($robotTypes);

        $dingtalkWebhookUrl = new Typecho_Widget_Helper_Form_Element_Text('dingtalkWebhookUrl', NULL, '', _t('钉钉机器人Webhook地址'));
        $form->addInput($dingtalkWebhookUrl);

        $feishuWebhookUrl = new Typecho_Widget_Helper_Form_Element_Text('feishuWebhookUrl', NULL, '', _t('飞书机器人Webhook地址'));
        $form->addInput($feishuWebhookUrl);

        $wecomWebhookUrl = new Typecho_Widget_Helper_Form_Element_Text('wecomWebhookUrl', NULL, '', _t('企业微信机器人Webhook地址'));
        $form->addInput($wecomWebhookUrl);

        $loginMessageTemplate = new Typecho_Widget_Helper_Form_Element_Textarea('loginMessageTemplate', NULL, "管理员登录成功：\n用户名：{username}\nIP地址：{ip}\n地理位置：{geo}\n时间：{time}", _t('登录消息模板'));
        $form->addInput($loginMessageTemplate);

        $commentMessageTemplate = new Typecho_Widget_Helper_Form_Element_Textarea('commentMessageTemplate', NULL, "有新评论：\n评论人：{author}\n评论内容：{text}\n文章标题：{title}\n文章链接：{permalink}\nIP地址：{ip}\n地理位置：{geo}\n时间：{time}", _t('评论消息模板'));
        $form->addInput($commentMessageTemplate);

        $showFullComment = new Typecho_Widget_Helper_Form_Element_Radio('showFullComment', array(
            '1' => _t('显示完整评论内容'),
            '0' => _t('隐藏评论内容并对评论人脱敏')
        ), '1', _t('评论内容显示设置'));
        $form->addInput($showFullComment);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    // 恢复 IP 地理位置查询（带完整异常捕获，绝不报错）
    private static function getIpGeoInfo($ip)
    {
        if (empty($ip) || $ip === '127.0.0.1' || $ip === '::1') {
            return '本地局域网';
        }

        try {
            $url = "https://v2.xxapi.cn/api/ip?ip=" . urlencode($ip);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && !empty($response)) {
                $data = json_decode($response, true);
                if (isset($data['data']['address']) && !empty($data['data']['address'])) {
                    return $data['data']['address'];
                }
            }
        } catch (Throwable $e) {
            // 静默失败，不影响主流程
        }

        return "未知位置";
    }

    public static function sendLoginNotify($user)
    {
        @error_reporting(0);
        try {
            $options = Typecho_Widget::widget('Widget_Options')->plugin('Robotpush');
            $request = Typecho_Request::getInstance();
            $ip = $request->getIp();
            $geo = self::getIpGeoInfo($ip);

            $message = str_replace(
                array('{username}', '{ip}', '{geo}', '{time}'),
                array($user->name, $ip, $geo, date('Y-m-d H:i:s')),
                $options->loginMessageTemplate
            );

            self::sendRobotMessages($options, $message);
        } catch (Throwable $e) {}
    }

    public static function sendCommentNotify($comment, $post)
    {
        @error_reporting(0);
        try {
            $options = Typecho_Widget::widget('Widget_Options')->plugin('Robotpush');
            $request = Typecho_Request::getInstance();
            $ip = $request->getIp();
            $geo = self::getIpGeoInfo($ip);

            $author = $comment['author'];
            $text = $comment['text'];

            if (!$options->showFullComment) {
                $strlen = mb_strlen($author, 'utf-8');
                $firstStr = mb_substr($author, 0, 1, 'utf-8');
                $lastStr = mb_substr($author, -1, 1, 'utf-8');
                $author = $strlen <= 2 ? $firstStr . '*' : $firstStr . str_repeat("*", $strlen - 2) . $lastStr;
                $text = '（评论内容已隐藏）';
            }

            $message = str_replace(
                array('{author}', '{text}', '{title}', '{permalink}', '{ip}', '{geo}', '{time}'),
                array($author, $text, $post->title, $post->permalink, $ip, $geo, date('Y-m-d H:i:s')),
                $options->commentMessageTemplate
            );

            self::sendRobotMessages($options, $message);
        } catch (Throwable $e) {}

        // ✅ 关键修复：必须返回评论数据，Typecho 1.3 要求
        return $comment;
    }

    private static function sendRobotMessages($options, $message)
    {
        if (empty($options->robotTypes) || !is_array($options->robotTypes)) return;

        foreach ($options->robotTypes as $type) {
            try {
                if ($type == 'dingtalk' && !empty($options->dingtalkWebhookUrl)) {
                    self::sendRobot('dingtalk', $options->dingtalkWebhookUrl, $message);
                }
                if ($type == 'feishu' && !empty($options->feishuWebhookUrl)) {
                    self::sendRobot('feishu', $options->feishuWebhookUrl, $message);
                }
                if ($type == 'wecom' && !empty($options->wecomWebhookUrl)) {
                    self::sendRobot('wecom', $options->wecomWebhookUrl, $message);
                }
            } catch (Throwable $e) {}
        }
    }

    private static function sendRobot($robotType, $webhookUrl, $message)
    {
        @error_reporting(0);
        if (empty($webhookUrl)) return;

        if ($robotType === 'dingtalk') {
            $data = ['msgtype' => 'text', 'text' => ['content' => $message]];
        } elseif ($robotType === 'feishu') {
            $data = ['msg_type' => 'text', 'content' => ['text' => $message]];
        } elseif ($robotType === 'wecom') {
            $data = ['msgtype' => 'text', 'text' => ['content' => $message]];
        } else {
            return;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $webhookUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_exec($ch);
        curl_close($ch);
    }
}
