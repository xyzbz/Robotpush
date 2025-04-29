<?php
/**
 * Typecho后台登录、评论消息、实时提醒插件（支持钉钉、飞书和企业微信机器人）
 * 
 * @package Robotpush
 * @author 子夜松声、DeepSeek人工智能
 * @version 0.0.9
 * @link https://xyzbz.cn
 */

class Robotpush_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        // 挂载登录成功后的钩子
        Typecho_Plugin::factory('Widget_User')->loginSucceed = array('Robotpush_Plugin', 'sendLoginNotify');

        // 挂载评论提交后的钩子
        Typecho_Plugin::factory('Widget_Feedback')->comment = array('Robotpush_Plugin', 'sendCommentNotify');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        // 禁用时无需额外操作
    }

    /**
     * 插件配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 添加机器人类型选择（多选）
        $robotTypes = new Typecho_Widget_Helper_Form_Element_Checkbox('robotTypes', array(
            'dingtalk' => _t('钉钉机器人'),
            'feishu' => _t('飞书机器人'),
            'wecom' => _t('企业微信机器人')
        ), array('dingtalk'), _t('机器人类型'), _t('选择使用的机器人类型（可多选）。'));
        $form->addInput($robotTypes);

        // 添加钉钉机器人Webhook地址输入框
        $dingtalkWebhookUrl = new Typecho_Widget_Helper_Form_Element_Text('dingtalkWebhookUrl', NULL, '', _t('钉钉机器人Webhook地址'), _t('请填写钉钉机器人的Webhook地址。'));
        $form->addInput($dingtalkWebhookUrl);

        // 添加飞书机器人Webhook地址输入框
        $feishuWebhookUrl = new Typecho_Widget_Helper_Form_Element_Text('feishuWebhookUrl', NULL, '', _t('飞书机器人Webhook地址'), _t('请填写飞书机器人的Webhook地址。'));
        $form->addInput($feishuWebhookUrl);

        // 添加企业微信机器人Webhook地址输入框
        $wecomWebhookUrl = new Typecho_Widget_Helper_Form_Element_Text('wecomWebhookUrl', NULL, '', _t('企业微信机器人Webhook地址'), _t('请填写企业微信机器人的Webhook地址。'));
        $form->addInput($wecomWebhookUrl);

        // 添加登录消息模板输入框
        $loginMessageTemplate = new Typecho_Widget_Helper_Form_Element_Textarea('loginMessageTemplate', NULL, "管理员登录成功：\n用户名：{username}\nIP地址：{ip}\n地理位置：{geo}\n时间：{time}", _t('登录消息模板'), _t('支持以下变量：{username}（登录用户名）、{ip}（登录IP地址）、{geo}（地理位置）、{time}（登录时间）'));
        $form->addInput($loginMessageTemplate);

        // 添加评论消息模板输入框
        $commentMessageTemplate = new Typecho_Widget_Helper_Form_Element_Textarea('commentMessageTemplate', NULL, "有新评论：\n评论人：{author}\n评论内容：{text}\n文章标题：{title}\n文章链接：{permalink}\nIP地址：{ip}\n地理位置：{geo}\n时间：{time}", _t('评论消息模板'), _t('支持以下变量：{author}（评论人）、{text}（评论内容）、{title}（文章标题）、{permalink}（文章链接）、{ip}（评论IP地址）、{geo}（地理位置）、{time}（评论时间）'));
        $form->addInput($commentMessageTemplate);

        // 添加是否显示完整评论内容的选项
        $showFullComment = new Typecho_Widget_Helper_Form_Element_Radio('showFullComment', array(
            '1' => _t('显示完整评论内容'),
            '0' => _t('隐藏评论内容并对评论人脱敏')
        ), '1', _t('评论内容显示设置'), _t('选择是否显示完整评论内容。'));
        $form->addInput($showFullComment);
    }

    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
        // 无需个人配置
    }

    /**
     * 获取IP地理位置信息
     */
    private static function getIpGeoInfo($ip)
    {
        if (empty($ip)) {
            return '未知';
        }

        try {
            $url = "https://v2.xxapi.cn/api/ip?ip={$ip}";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5秒超时
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                if (isset($data['data']['address'])) {
                    return $data['data']['address'];
                }
            }
        } catch (Exception $e) {
            // 忽略异常
        }

        return "地理位置查询超时";
    }

    /**
     * 发送登录通知
     */
    public static function sendLoginNotify($user)
    {
        // 获取插件配置
        $options = Typecho_Widget::widget('Widget_Options')->plugin('Robotpush');
        $robotTypes = $options->robotTypes;
        $dingtalkWebhookUrl = $options->dingtalkWebhookUrl;
        $feishuWebhookUrl = $options->feishuWebhookUrl;
        $wecomWebhookUrl = $options->wecomWebhookUrl;
        $loginMessageTemplate = $options->loginMessageTemplate;

        // 获取 Typecho 的请求对象
        $request = Typecho_Request::getInstance();

        // 获取登录IP地址
        $ip = $request->getIp();
        
        // 获取IP地理位置
        $geo = self::getIpGeoInfo($ip);

        // 替换模板中的变量
        $message = str_replace(
            array('{username}', '{ip}', '{geo}', '{time}'),
            array($user->name, $ip, $geo, date('Y-m-d H:i:s')),
            $loginMessageTemplate
        );

        // 发送通知
        self::sendRobotMessages($robotTypes, $dingtalkWebhookUrl, $feishuWebhookUrl, $wecomWebhookUrl, $message);
    }

    /**
     * 发送评论通知
     */
    public static function sendCommentNotify($comment, $post)
    {
        // 获取插件配置
        $options = Typecho_Widget::widget('Widget_Options')->plugin('Robotpush');
        $robotTypes = $options->robotTypes;
        $dingtalkWebhookUrl = $options->dingtalkWebhookUrl;
        $feishuWebhookUrl = $options->feishuWebhookUrl;
        $wecomWebhookUrl = $options->wecomWebhookUrl;
        $commentMessageTemplate = $options->commentMessageTemplate;
        $showFullComment = $options->showFullComment;

        // 获取 Typecho 的请求对象
        $request = Typecho_Request::getInstance();

        // 获取评论IP地址
        $ip = $request->getIp();
        
        // 获取IP地理位置
        $geo = self::getIpGeoInfo($ip);

        // 获取评论相关信息
        $author = $comment['author']; // 评论人
        $text = $comment['text']; // 评论内容

        // 获取文章标题和链接
        $title = $post->title; // 文章标题
        $permalink = $post->permalink; // 文章链接

        // 根据配置决定是否显示完整评论内容
        if (!$showFullComment) {
            // 对评论人进行脱敏处理
            $strlen = mb_strlen($author, 'utf-8');
            $firstStr = mb_substr($author, 0, 1, 'utf-8');
            $lastStr = mb_substr($author, -1, 1, 'utf-8');
            $author = $strlen == 2 ? $firstStr . str_repeat('*', $strlen - 1) : $firstStr . str_repeat("*", $strlen - 2) . $lastStr;

            // 不显示评论内容
            $text = '（评论内容已隐藏）';
        }

        // 替换模板中的变量
        $message = str_replace(
            array('{author}', '{text}', '{title}', '{permalink}', '{ip}', '{geo}', '{time}'),
            array($author, $text, $title, $permalink, $ip, $geo, date('Y-m-d H:i:s')),
            $commentMessageTemplate
        );

        // 发送通知
        self::sendRobotMessages($robotTypes, $dingtalkWebhookUrl, $feishuWebhookUrl, $wecomWebhookUrl, $message);
    }

    /**
     * 发送机器人消息
     *
     * @param array $robotTypes 选择的机器人类型
     * @param string $dingtalkWebhookUrl 钉钉机器人Webhook地址
     * @param string $feishuWebhookUrl 飞书机器人Webhook地址
     * @param string $wecomWebhookUrl 企业微信机器人Webhook地址
     * @param string $message 消息内容
     */
    private static function sendRobotMessages($robotTypes, $dingtalkWebhookUrl, $feishuWebhookUrl, $wecomWebhookUrl, $message)
    {
        foreach ($robotTypes as $robotType) {
            switch ($robotType) {
                case 'dingtalk':
                    if (!empty($dingtalkWebhookUrl)) {
                        self::sendRobotMessage('dingtalk', $dingtalkWebhookUrl, $message);
                    }
                    break;
                case 'feishu':
                    if (!empty($feishuWebhookUrl)) {
                        self::sendRobotMessage('feishu', $feishuWebhookUrl, $message);
                    }
                    break;
                case 'wecom':
                    if (!empty($wecomWebhookUrl)) {
                        self::sendRobotMessage('wecom', $wecomWebhookUrl, $message);
                    }
                    break;
            }
        }
    }

    /**
     * 发送单个机器人消息
     *
     * @param string $robotType 机器人类型（dingtalk/feishu/wecom）
     * @param string $webhookUrl 机器人Webhook地址
     * @param string $message 消息内容
     */
    private static function sendRobotMessage($robotType, $webhookUrl, $message)
    {
        if ($robotType === 'dingtalk') {
            // 钉钉机器人消息体
            $data = array(
                'msgtype' => 'text',
                'text' => array(
                    'content' => $message
                )
            );
        } elseif ($robotType === 'feishu') {
            // 飞书机器人消息体
            $data = array(
                'msg_type' => 'text',
                'content' => array(
                    'text' => $message
                )
            );
        } elseif ($robotType === 'wecom') {
            // 企业微信机器人消息体
            $data = array(
                'msgtype' => 'text',
                'text' => array(
                    'content' => $message
                )
            );
        } else {
            throw new Exception('不支持的机器人类型：' . $robotType);
        }

        // 发送HTTP请求
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $webhookUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 调试信息
        if ($httpCode !== 200) {
            throw new Exception('推送失败，HTTP 状态码：' . $httpCode . '，响应内容：' . $response);
        }
    }
}
