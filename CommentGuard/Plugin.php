<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 评论卫士：恶意评论拦截插件（本地规则 + AI 审核）
 *
 * 在 RobotPush 通知插件基础上扩展，增加评论入库前的三层拦截：
 *   1. 本地规则拦截（关键词 / 昵称 / 网址 / 邮箱，精确/包含/正则）
 *   2. AI 审核（OpenAI 兼容接口，仅本地规则漏网时触发，违规则拦）
 *   3. 拦截动作（静默丢弃 / 转待审核）
 *
 * @package RobotPush
 * @author 阿枢 (xyzbz)
 * @version 0.4.4
 * @link https://xyzbz.cn
 */

require_once __DIR__ . '/inc/engine.php';
require_once __DIR__ . '/inc/notify.php';
require_once __DIR__ . '/inc/mail.php';

class CommentGuard_Plugin implements Typecho_Plugin_Interface
{
    const DATA_VERSION = 1;

    /**
     * 本次请求内「是否已由 applyBlock 发过待审核通知」的标记。
     * guardComment（拦截）先于 finishComment（notifyNewComment）执行，
     * 用静态标记避免「CommentGuard 拦截通知」与「站点审核待审核通知」重复发。
     */
    private static $pendingNotified = false;

    public static function activate()
    {
        // 全程 try-catch 防御：启用流程中本方法若抛出致命错误，
        // 会把插件卡在「已启用但无配置记录」的半残状态（设置页报
        // 「插件配置信息没有找到」）。任何一步失败只记日志，绝不中断启用。
        try {
            // 评论入库前拦截：filter('comment', $comment, $content) 抛异常即拦
            \Typecho\Plugin::factory('Widget_Feedback')->comment = array('CommentGuard_Plugin', 'guardComment');
            // 新评论通知（邮件 + 机器人），照搬 Comment2Mail 的钩子
            \Typecho\Plugin::factory('Widget_Feedback')->finishComment = array('CommentGuard_Plugin', 'notifyNewComment');
            \Typecho\Plugin::factory('Widget_Comments_Edit')->finishComment = array('CommentGuard_Plugin', 'notifyNewComment');
            // 后台登录通知（照搬 RobotPush 的 loginSucceed 钩子）
            \Typecho\Plugin::factory('Widget_User')->loginSucceed = array('CommentGuard_Plugin', 'notifyLogin');
        } catch (\Throwable $e) {
            error_log('[CommentGuard] activate 挂载钩子失败: ' . $e->getMessage());
        }

        try {
            \Utils\Helper::addPanel(2, 'CommentGuard/panel.php', '评论卫士', '拦截日志', 'administrator');
        } catch (\Throwable $e) {
            error_log('[CommentGuard] activate 注册面板失败: ' . $e->getMessage());
        }

        // 创建日志表（幂等）
        try {
            \Typecho\Db::get()->query(self::installLogTable());
        } catch (\Throwable $e) {
            error_log('[CommentGuard] activate 建表失败: ' . $e->getMessage());
        }

        return _t('评论卫士已启用。请在「控制台 → 评论卫士」配置拦截规则。');
    }

    public static function deactivate()
    {
        // 注意：不能给插件钩子赋 null。Typecho 1.3 的 Plugin::__set 强类型声明为 callable，
        // 赋 null 会抛 TypeError（"must be of type callable, null given"）导致禁用时 500。
        // 钩子的清理由 Typecho\Plugin::deactivate() 自动完成，这里只需移除后台面板。
        // try-catch 防御：面板不存在等异常不应导致禁用 500
        try {
            \Utils\Helper::removePanel(2, 'CommentGuard/panel.php');
        } catch (\Throwable $e) {
            error_log('[CommentGuard] deactivate 移除面板失败: ' . $e->getMessage());
        }
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 自愈：修复「已启用但配置记录缺失」的半残状态
        self::ensureConfigRow();

        try {
            self::buildConfigForm($form);
        } catch (\Throwable $e) {
            error_log('[CommentGuard] config 渲染失败: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            // 兜底：保证表单至少有一个带默认值的字段，
            // Typecho 启用流程才能写入配置记录，不会再留半残状态
            $fallback = new Typecho_Widget_Helper_Form_Element_Text(
                'cg_fallback', null, '1', _t('配置加载异常'),
                _t('配置面板渲染失败，请查看 PHP 错误日志中的 [CommentGuard] 记录后反馈。')
            );
            $form->addInput($fallback);
        }
    }

    /**
     * 自愈：补写缺失的 plugin:CommentGuard 配置记录。
     * 成因：旧版在启用流程中调 config() 时崩溃，Typecho 已标记启用
     * 但没来得及写配置记录，之后打开设置页会报
     * 「插件配置信息没有找到」(Widget/Options.php)。
     */
    private static function ensureConfigRow()
    {
        try {
            $db = \Typecho\Db::get();
            $exists = $db->fetchRow(
                $db->select('name')->from('table.options')
                    ->where('name = ?', 'plugin:CommentGuard')
            );
            if (!empty($exists)) {
                return;
            }

            $db->query($db->insert('table.options')->rows(array(
                'name'  => 'plugin:CommentGuard',
                'user'  => 0,
                'value' => '{}',
            )));

            // 设置页场景：Options 组件在本请求开始时已缓存旧数据，
            // 补写后需刷新一次才能读到新记录。
            // 启用流程（/action/plugins-edit）中 Typecho 会自己写入默认配置，无需刷新。
            $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
            if (strpos($uri, 'options-plugin.php') !== false) {
                \Typecho\Response::getInstance()->setStatus(302)->setHeader('Location', $uri)->respond();
            }
        } catch (\Throwable $e) {
            // 自愈失败不阻塞：记录日志，按原流程走（错误表现与修复前一致，不会更糟）
            error_log('[CommentGuard] 配置记录自愈失败: ' . $e->getMessage());
        }
    }

    private static function buildConfigForm(Typecho_Widget_Helper_Form $form)
    {
        $desc = new Typecho_Widget_Helper_Form_Element_Textarea('ai_prompt', null, self::defaultPrompt(), _t('AI 审核提示词'), _t('发送给模型的系统提示词，说明审核规则（大陆法规合规）。可用占位符 {author} {mail} {url} {text}。'));
        $form->addInput($desc);

        $baseUrl = new Typecho_Widget_Helper_Form_Element_Text('ai_base_url', null, '', _t('AI 接口地址 (base_url)'), _t('OpenAI 兼容接口，如 https://api.deepseek.com（不带末尾斜杠）或 https://api.openai.com/v1。留空则关闭 AI 审核。'));
        $form->addInput($baseUrl);

        $apiKey = new Typecho_Widget_Helper_Form_Element_Text('ai_api_key', null, '', _t('AI API Key'));
        $form->addInput($apiKey);

        $model = new Typecho_Widget_Helper_Form_Element_Text('ai_model', null, 'deepseek-chat', _t('AI 模型名'), _t('DeepSeek 用 deepseek-chat / deepseek-reasoner；OpenAI 用 gpt-4o-mini 等。'));
        $form->addInput($model);

        $timeout = new Typecho_Widget_Helper_Form_Element_Text('ai_timeout', null, '15', _t('AI 超时（秒）'), _t('AI 接口无响应时的超时时间，超时则放行评论。DeepSeek 建议 15 秒以上。'));
        $form->addInput($timeout);

        $action = new Typecho_Widget_Helper_Form_Element_Radio('block_action', array(
            'waiting' => _t('转待审核（推荐，可后台放行）'),
            'drop' => _t('静默丢弃（直接拦下，不入库）')
        ), 'waiting', _t('拦截动作'), _t('命中规则或 AI 判定违规时怎么处理。'));
        $form->addInput($action);

        $moderateAll = new Typecho_Widget_Helper_Form_Element_Radio('ai_moderate_all', array(
            '0' => _t('仅本地规则漏网时走 AI（推荐，省费用）'),
            '1' => _t('每条评论都走 AI 审核')
        ), '0', _t('AI 审核范围'));
        $form->addInput($moderateAll);

        $aiFailAction = new Typecho_Widget_Helper_Form_Element_Radio('ai_fail_action', array(
            'pass' => _t('放行（AI 挂了不阻塞评论）'),
            'waiting' => _t('转待审核（AI 异常时保守处理）')
        ), 'pass', _t('AI 失败兜底'), _t('AI 接口报错（积分耗尽、超时、鉴权失败）时怎么处理。'));
        $form->addInput($aiFailAction);

        $notice = new Typecho_Widget_Helper_Form_Element_Text('block_message', null, '评论内容未通过审核，请修改后重试。', _t('拦截提示语'), _t('访客看到这条评论被拦时返回的提示。'));
        $form->addInput($notice);

        // ===== 本地拦截规则 =====
        $noChinese = new Typecho_Widget_Helper_Form_Element_Radio('no_chinese', array(
            '0' => _t('不检测'),
            '1' => _t('非中文评论按拦截动作处理')
        ), '0', _t('非中文评论检测'), _t('评论正文不含任何汉字时视为可疑（常见垃圾评论特征）。'));
        $form->addInput($noChinese);

        $keywords = new Typecho_Widget_Helper_Form_Element_Textarea('keywords', null, '', _t('拦截关键词（内容）'), _t('每行一条。三种写法：/正则/ 正则匹配；=词 精确匹配；普通词 包含匹配。# 开头是注释。匹配评论正文。'));
        $form->addInput($keywords);

        $names = new Typecho_Widget_Helper_Form_Element_Textarea('block_names', null, '', _t('拦截昵称'), _t('每行一条，规则写法同上，匹配评论人昵称。'));
        $form->addInput($names);

        $urls = new Typecho_Widget_Helper_Form_Element_Textarea('block_urls', null, '', _t('拦截网址'), _t('每行一条，规则写法同上，匹配评论人填的主页网址。'));
        $form->addInput($urls);

        $mails = new Typecho_Widget_Helper_Form_Element_Textarea('block_mails', null, '', _t('拦截邮箱'), _t('每行一条，规则写法同上，匹配评论人填的邮箱。'));
        $form->addInput($mails);

        // ===== 机器人通知 =====
        $notifyTypes = new Typecho_Widget_Helper_Form_Element_Checkbox('notify_types', array(
            'dingtalk' => _t('钉钉机器人'),
            'feishu' => _t('飞书机器人'),
            'wecom' => _t('企业微信机器人')
        ), array(), _t('人工审核通知机器人'), _t('拦截评论转待审核时，推送通知给这些机器人（可多选）。'));
        $form->addInput($notifyTypes);

        $dingtalk = new Typecho_Widget_Helper_Form_Element_Text('dingtalk_webhook', null, '', _t('钉钉 Webhook'));
        $form->addInput($dingtalk);

        $feishu = new Typecho_Widget_Helper_Form_Element_Text('feishu_webhook', null, '', _t('飞书 Webhook'));
        $form->addInput($feishu);

        $wecom = new Typecho_Widget_Helper_Form_Element_Text('wecom_webhook', null, '', _t('企业微信 Webhook'));
        $form->addInput($wecom);

        // ===== 邮件通知 =====
        $mailNotify = new Typecho_Widget_Helper_Form_Element_Radio('mail_notify', array(
            '0' => _t('关闭'),
            '1' => _t('开启')
        ), '0', _t('邮件通知'), _t('开启后，通知事件会同时发邮件到下方收件人。'));
        $form->addInput($mailNotify);

        $smtpHost = new Typecho_Widget_Helper_Form_Element_Text('smtp_host', null, '', _t('SMTP 服务器'), _t('如 smtp.qq.com / smtp.163.com / smtp.gmail.com。'));
        $form->addInput($smtpHost);

        $smtpPort = new Typecho_Widget_Helper_Form_Element_Text('smtp_port', null, '25', _t('SMTP 端口'), _t('默认 25，SSL 465，TLS 587。'));
        $form->addInput($smtpPort);

        $smtpSecure = new Typecho_Widget_Helper_Form_Element_Radio('smtp_secure', array(
            '' => _t('无加密'),
            'ssl' => _t('SSL'),
            'tls' => _t('TLS')
        ), '', _t('加密方式'));
        $form->addInput($smtpSecure);

        $smtpUser = new Typecho_Widget_Helper_Form_Element_Text('smtp_user', null, '', _t('SMTP 登录用户'), _t('一般为邮箱地址。QQ 邮箱需用独立 SMTP 授权码作为密码。'));
        $form->addInput($smtpUser);

        $smtpPass = new Typecho_Widget_Helper_Form_Element_Text('smtp_pass', null, '', _t('SMTP 密码'), _t('邮箱登录密码或 SMTP 授权码。'));
        $form->addInput($smtpPass);

        $mailFrom = new Typecho_Widget_Helper_Form_Element_Text('mail_from', null, '', _t('发件邮箱'));
        $form->addInput($mailFrom);

        $mailFromName = new Typecho_Widget_Helper_Form_Element_Text('mail_from_name', null, '', _t('发件人名称'));
        $form->addInput($mailFromName);

        // 通知收件人默认填博主邮箱，留空时发送端也会自动回落到博主邮箱
        $defaultRecipient = '';
        try {
            $currentUser = Typecho_Widget::widget('Widget_User');
            if ($currentUser && $currentUser->hasLogin() && !empty($currentUser->mail)) {
                $defaultRecipient = (string) $currentUser->mail;
            }
        } catch (\Throwable $e) {
        }

        $mailTo = new Typecho_Widget_Helper_Form_Element_Text('mail_to', null, $defaultRecipient, _t('通知收件人（拦截/审核）'), _t('拦截待审核、AI 失败等事件发到这个邮箱，多个用逗号分隔。留空则默认发给博主邮箱。'));
        $form->addInput($mailTo);

        // ===== 通知事件开关 =====
        $notifyEvents = new Typecho_Widget_Helper_Form_Element_Checkbox('notify_events', array(
            'new' => _t('新评论'),
            'pending' => _t('拦截待审核'),
            'ai_error' => _t('AI 失败'),
            'drop' => _t('静默丢弃')
        ), array('new', 'pending', 'ai_error', 'drop'), _t('通知事件'), _t('勾选的事件会通过「邮件 + 机器人」两条通道同时通知（可多选）。'));
        $form->addInput($notifyEvents);

        // ===== 后台登录通知 =====
        $loginNotify = new Typecho_Widget_Helper_Form_Element_Radio('login_notify', array(
            '0' => _t('关闭'),
            '1' => _t('开启')
        ), '0', _t('后台登录通知'), _t('管理员登录后台时推送通知（照搬 RobotPush 的登录提醒）。'));
        $form->addInput($loginNotify);

        $loginChannels = new Typecho_Widget_Helper_Form_Element_Checkbox('login_notify_channels', array(
            'robot' => _t('机器人'),
            'mail' => _t('邮件')
        ), array('robot', 'mail'), _t('登录通知通道'), _t('登录通知通过哪条通道发送（可多选）。机器人沿用上方 webhook；邮件沿用下方 SMTP 与收件人。'));
        $form->addInput($loginChannels);

        $loginTemplate = new Typecho_Widget_Helper_Form_Element_Textarea('login_message_template', null, "管理员登录成功\n用户名：{username}\nIP地址：{ip}\n地理位置：{geo}\n时间：{time}", _t('登录消息模板'), _t('支持占位符 {username} {ip} {geo} {time}。{geo} 为 IP 地理位置，走在线查询（失败显示「未知位置」）。'));
        $form->addInput($loginTemplate);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    // 注意：不要定义 configHandle() 方法！
    // Typecho 检测到插件存在 configHandle 时会认为「插件自己接管配置存储」，
    // 从而跳过默认的 configPlugin() 写库逻辑，导致设置页保存无效。
    // 本插件不需要自定义存储，交给 Typecho 默认保存即可。

    /**
     * 评论入库前拦截入口
     * 返回 null 即放行；抛异常即拦截
     */
    public static function guardComment($comment, $content)
    {
        error_log('[CommentGuard] guardComment 钩子触发');
        // 管理员/登录用户评论不拦（信任内部用户）
        try {
            $user = Typecho_Widget::widget('Widget_User');
            if ($user->hasLogin()) {
                error_log('[CommentGuard] 当前为登录用户，跳过审核（测试请退出登录或用游客身份评论）');
                return $comment;
            }
        } catch (\Throwable $e) {
        }

        $options = self::options();
        $author = isset($comment['author']) ? (string) $comment['author'] : '';
        $mail   = isset($comment['mail']) ? (string) $comment['mail'] : '';
        $url    = isset($comment['url']) ? (string) $comment['url'] : '';
        $text   = isset($comment['text']) ? (string) $comment['text'] : '';

        // 1. 本地规则拦截
        $hit = CommentGuard_Engine::checkRules($author, $mail, $url, $text, $options);
        if ($hit !== null) {
            return self::applyBlock($comment, $hit, $options, $content);
        }

        // 2. AI 审核。
        // 只要配置了 AI 接口，本地规则漏网的评论一律走 AI 二次判断；
        // 「AI 审核范围」开关仅决定是否连「本地规则已命中」的评论也要走 AI（几乎用不到）。
        // 原先逻辑：ai_moderate_all=0 时直接 return 放行，导致 AI 完全不被调用。
        $hasAi = !empty($options['ai_base_url']) && !empty($options['ai_api_key']);
        if (!$hasAi) {
            return $comment;
        }

        $aiResult = CommentGuard_Engine::aiModerate($author, $mail, $url, $text, $options);
        if ($aiResult === 'block') {
            return self::applyBlock($comment, 'ai', $options, $content);
        }
        if ($aiResult === 'error') {
            // AI 接口失败（积分耗尽/超时/鉴权失败）
            self::enrichCommentContext($comment, $content);
            self::notifyEvent('ai_error', $comment, 'ai_error', $options);
            $failAction = isset($options['ai_fail_action']) ? $options['ai_fail_action'] : 'pass';
            if ($failAction === 'waiting') {
                return self::applyBlock($comment, 'ai_error', $options, $content);
            }
            // pass：放行，不阻塞评论
        }

        return $comment;
    }

    /**
     * 应用拦截动作。
     * drop：抛异常直接拦下，不入库；
     * waiting：改 status 为 waiting 并放行，进库待审核，同时推送通知。
     */
    private static function applyBlock($comment, $reason, $options, $content = null)
    {
        self::logBlock($comment, $reason);
        $action = isset($options['block_action']) ? $options['block_action'] : 'waiting';

        if ($action === 'drop') {
            // 静默丢弃：若开启了 drop 事件通知，也推送一声（避免误杀无感知）
            self::enrichCommentContext($comment, $content);
            self::notifyEvent('drop', $comment, $reason, $options);
            throw new \Typecho\Exception($options['block_message']);
        }

        // waiting：转待审核，放行入库
        $comment['status'] = 'waiting';

        // 入库前补全通知所需的上下文字段（title / permalink / ip / created_at），
        // 否则邮件和机器人模板里这些字段缺失，可能触发未定义键警告甚至致命错误。
        self::enrichCommentContext($comment, $content);

        // 拦截待审核事件通知（邮件 + 机器人）
        self::notifyEvent('pending', $comment, $reason, $options);
        self::$pendingNotified = true;

        return $comment;
    }

    /**
     * 入库前补全评论数组的通知所需字段。
     * guardComment 拿到的 $comment 只有 author/mail/url/text/status，
     * 通知模板还需要 title / permalink / ip / created_at。
     * 文章信息从 Widget_Feedback 对象（guardComment 的 $content 参数）读取。
     */
    private static function enrichCommentContext(&$comment, $content = null)
    {
        if (!isset($comment['ip']) || $comment['ip'] === '') {
            $comment['ip'] = self::clientIp();
        }
        if (!isset($comment['created_at']) || $comment['created_at'] === '') {
            $comment['created_at'] = date('Y-m-d H:i:s');
        }
        try {
            $title = '';
            $permalink = '';
            if ($content && is_object($content)) {
                $title = isset($content->content->title) ? $content->content->title : '';
                $cid = isset($content->content->cid) ? intval($content->content->cid) : 0;
                if ($cid) {
                    // 用 DB 查 slug + 路由表拼 permalink，比手动拼 Typecho_Router::url 更稳
                    $permalink = self::postPermalink($cid);
                }
            }
            if (!isset($comment['title']) || $comment['title'] === '') {
                $comment['title'] = $title;
            }
            if (!isset($comment['permalink']) || $comment['permalink'] === '') {
                $comment['permalink'] = $permalink;
            }
        } catch (\Throwable $e) {
            if (!isset($comment['title'])) $comment['title'] = '';
            if (!isset($comment['permalink'])) $comment['permalink'] = '';
        }
    }

    /**
     * 按文章 cid 查询并生成 permalink。
     */
    private static function postPermalink($cid)
    {
        try {
            $db = \Typecho\Db::get();
            $row = $db->fetchRow($db->select('cid', 'slug', 'type')->from('table.contents')->where('cid = ?', $cid));
            if (!$row) {
                return '';
            }
            $slug = $row['slug'];
            $type = $row['type'];
            // 用 Typecho 的路由生成 URL，兼容各种 permalink 结构
            $path = Typecho_Router::url(
                $type === 'page' ? 'page' : 'post',
                array('cid' => $cid, 'slug' => $slug)
            );
            $siteUrl = \Typecho\Widget::widget('Widget_Options')->siteUrl;
            return Typecho_Common::url($path, $siteUrl);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 统一通知分发：邮件 + 机器人两条通道并行，覆盖某个事件。
     *
     * @param string $event  事件名：new / pending / ai_error / drop
     * @param array  $comment 评论数组
     * @param string $reason  拦截原因（新评论为空）
     * @param array  $options 插件配置
     */
    private static function notifyEvent($event, $comment, $reason, $options)
    {
        $events = isset($options['notify_events']) ? $options['notify_events'] : array();
        if (!is_array($events) || !in_array($event, $events, true)) {
            error_log('[CommentGuard] 事件「' . $event . '」未勾选，通知跳过');
            return;
        }

        // 机器人消息
        if ($event === 'new') {
            $message = CommentGuard_Notify::buildNewCommentMessage($comment);
        } else {
            $message = CommentGuard_Notify::buildPendingMessage($comment, $reason, $options);
        }
        CommentGuard_Notify::send($options, $message);

        // 邮件
        if ($event === 'new') {
            // 新评论：收件人由 notifyNewComment 计算后直接发，这里不发（避免重复）
            return;
        }
        CommentGuard_Mail::sendPending($comment, $reason, $options);
    }

    /**
     * 新评论通知（邮件 + 机器人）。
     * 挂在 Widget_Feedback / Widget_Comments_Edit 的 finishComment 钩子。
     * 逻辑照搬 Comment2Mail：审核通过的评论通知「文章作者 + 上级评论人」（排除自己/博主），
     * 待审核评论通知博主。邮件与机器人两条通道并行。
     */
    public static function notifyNewComment($comment)
    {
        error_log('[CommentGuard] notifyNewComment 钩子触发');
        $options = self::options();

        // 机器人通知「全量」：新评论无论是否勾选「新评论」事件，机器人一律推送，
        // 保证站长不因漏勾事件而收不到新评论机器人提醒。
        // （邮件仍按事件开关控制，见下方分支）

        // 兼容对象和数组两种形态
        $isObject = is_object($comment);
        $get = function ($key) use ($comment, $isObject) {
            if ($isObject) {
                return isset($comment->{$key}) ? $comment->{$key} : '';
            }
            return isset($comment[$key]) ? $comment[$key] : '';
        };

        $status = $get('status');
        $from   = isset($options['mail_from']) ? $options['mail_from'] : '';

        // 组装评论数组（供邮件/机器人消息使用）
        $c = array(
            'author' => $get('author'),
            'mail'   => $get('mail'),
            'url'    => $get('url'),
            'text'   => $get('text'),
            'title'  => $get('title'),
            'permalink' => $get('permalink'),
            'created_at' => $get('created') ? date('Y-m-d H:i:s', (int) $get('created')) : date('Y-m-d H:i:s'),
            'ip'     => $get('ip'),
        );

        $recipients = array();

        if ($status === 'approved') {
            // 文章作者邮箱（排除自己发自己）
            $authorMail = self::articleAuthorMail($comment);
            if ($authorMail !== '' && $authorMail !== $c['mail']) {
                $recipients[] = array('name' => '', 'mail' => $authorMail);
            }
            // 上级评论人
            $parentMail = self::parentMail($comment);
            if ($parentMail !== '' && $parentMail !== $c['mail'] && $parentMail !== $authorMail) {
                $recipients[] = array('name' => '', 'mail' => $parentMail);
            }
            // 机器人通知：全量发，不依赖任何事件开关。
            // 注意：站长自己测试评论时收件人必然为空，不能因此跳过机器人通知。
            CommentGuard_Notify::send($options, CommentGuard_Notify::buildNewCommentMessage($c));
            // 邮件：仅当「新评论」事件被勾选时，才发给文章作者 + 上级评论人。
            $events = isset($options['notify_events']) ? $options['notify_events'] : array();
            $newMailOn = is_array($events) && in_array('new', $events, true);
            if ($newMailOn && !empty($recipients)) {
                CommentGuard_Mail::sendNewComment($recipients, $c, $options, '您有一条新的回复');
            }
        } else {
            // 待审核评论。
            // 分两种来源：
            //  (a) CommentGuard 拦截（applyBlock 已发过带原因的待审核通知）→ 跳过，避免重复；
            //  (b) 站点自身的「所有评论必须审核」策略置为 waiting，评论本身没违规 →
            //      也通知博主一声，原因是「站点审核策略」，否则站长收不到这类评论的提醒。
            if (!self::$pendingNotified) {
                CommentGuard_Notify::send($options, CommentGuard_Notify::buildPendingMessage($c, 'site_pending', $options));
                CommentGuard_Mail::sendPending($c, 'site_pending', $options);
            }
        }
        // 重置标记，避免下一条评论误判
        self::$pendingNotified = false;
    }

    /**
     * 后台登录通知（照搬 RobotPush 的 loginSucceed 逻辑）。
     * 管理员登录后台成功时，按「登录通知通道」勾选推机器人 / 邮件。
     */
    public static function notifyLogin($user)
    {
        error_log('[CommentGuard] notifyLogin 钩子触发');
        $options = self::options();

        if (empty($options['login_notify'])) {
            error_log('[CommentGuard] 登录通知未开启，跳过');
            return;
        }

        $channels = isset($options['login_notify_channels']) ? $options['login_notify_channels'] : array();
        if (!is_array($channels) || empty($channels)) {
            error_log('[CommentGuard] 登录通知通道未勾选，跳过');
            return;
        }

        $ip = self::clientIp();
        $geo = self::ipGeo($ip);

        $username = is_object($user) && isset($user->name) ? $user->name : (isset($user['name']) ? $user['name'] : '');
        $template = isset($options['login_message_template']) && $options['login_message_template'] !== ''
            ? $options['login_message_template']
            : "管理员登录成功\n用户名：{username}\nIP地址：{ip}\n地理位置：{geo}\n时间：{time}";

        $message = str_replace(
            array('{username}', '{ip}', '{geo}', '{time}'),
            array($username, $ip, $geo, date('Y-m-d H:i:s')),
            $template
        );

        if (in_array('robot', $channels, true)) {
            CommentGuard_Notify::send($options, $message);
        }
        if (in_array('mail', $channels, true)) {
            // 邮件走卡片式 HTML，传结构化字段（非渲染后的纯文本）
            CommentGuard_Mail::sendLoginNotify(array(
                'username' => $username,
                'ip'       => $ip,
                'geo'      => $geo,
                'time'     => date('Y-m-d H:i:s'),
            ), $options);
        }
    }

    /**
     * IP 地理位置查询（照搬 RobotPush，带完整异常捕获，失败返回「未知位置」）。
     */
    private static function ipGeo($ip)
    {
        if (empty($ip) || $ip === '127.0.0.1' || $ip === '::1') {
            return '本地局域网';
        }
        try {
            $url = 'https://v2.xxapi.cn/api/ip?ip=' . urlencode($ip);
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ));
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && !empty($resp)) {
                $data = json_decode($resp, true);
                if (isset($data['data']['address']) && !empty($data['data']['address'])) {
                    return $data['data']['address'];
                }
            }
        } catch (\Throwable $e) {
            // 静默失败，不影响主流程
        }
        return '未知位置';
    }

    /**
     * 查询文章作者邮箱。
     */
    private static function articleAuthorMail($comment)
    {
        try {
            $db = \Typecho\Db::get();
            $ownerId = is_object($comment) ? $comment->ownerId : (isset($comment['ownerId']) ? $comment['ownerId'] : 0);
            if (!$ownerId) {
                return '';
            }
            $row = $db->fetchRow($db->select('mail')->from('table.users')->where('uid = ?', $ownerId));
            return $row ? $row['mail'] : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 查询上级评论人邮箱。
     */
    private static function parentMail($comment)
    {
        try {
            $db = \Typecho\Db::get();
            $parent = is_object($comment) ? $comment->parent : (isset($comment['parent']) ? $comment['parent'] : 0);
            if (!$parent) {
                return '';
            }
            $row = $db->fetchRow($db->select('mail')->from('table.comments')->where('coid = ?', $parent));
            return $row ? $row['mail'] : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function logBlock($comment, $reason)
    {
        try {
            $db = \Typecho\Db::get();
            $prefix = $db->getPrefix();
            $table = $prefix . 'commentguard_log';
            $db->query($db->insert($table)->rows(array(
                'author' => isset($comment['author']) ? $comment['author'] : '',
                'mail'   => isset($comment['mail']) ? $comment['mail'] : '',
                'url'    => isset($comment['url']) ? $comment['url'] : '',
                'text'   => mb_substr(isset($comment['text']) ? $comment['text'] : '', 0, 500),
                'ip'     => self::clientIp(),
                'reason' => $reason,
                'created'=> time(),
            )));
        } catch (\Throwable $e) {
            // 日志表不存在时静默
        }
    }

    private static function clientIp()
    {
        try {
            return \Typecho\Request::getInstance()->getIp();
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function installLogTable()
    {
        $db = \Typecho\Db::get();
        $prefix = $db->getPrefix();
        $adapter = $db->getAdapterName();
        $charset = 'utf8mb4';

        if (stripos($adapter, 'sqlite') !== false) {
            return "CREATE TABLE IF NOT EXISTS {$prefix}commentguard_log ("
                . "id INTEGER PRIMARY KEY AUTOINCREMENT,"
                . "author TEXT DEFAULT '',"
                . "mail TEXT DEFAULT '',"
                . "url TEXT DEFAULT '',"
                . "text TEXT DEFAULT '',"
                . "ip TEXT DEFAULT '',"
                . "reason TEXT DEFAULT '',"
                . "created INTEGER DEFAULT 0)";
        }

        return "CREATE TABLE IF NOT EXISTS {$prefix}commentguard_log ("
            . "id INT UNSIGNED NOT NULL AUTO_INCREMENT,"
            . "author VARCHAR(150) DEFAULT '',"
            . "mail VARCHAR(150) DEFAULT '',"
            . "url VARCHAR(255) DEFAULT '',"
            . "text TEXT,"
            . "ip VARCHAR(64) DEFAULT '',"
            . "reason VARCHAR(64) DEFAULT '',"
            . "created INT UNSIGNED DEFAULT 0,"
            . "PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET={$charset}";
    }

    private static function options()
    {
        static $opts = null;
        if ($opts !== null) {
            return $opts;
        }
        $opts = array(
            'ai_base_url' => '',
            'ai_api_key' => '',
            'ai_model' => 'deepseek-chat',
            'ai_timeout' => '15',
            'ai_moderate_all' => '0',
            'ai_fail_action' => 'pass',
            'block_action' => 'waiting',
            'block_message' => '评论内容未通过审核，请修改后重试。',
            'ai_prompt' => self::defaultPrompt(),
            'no_chinese' => '0',
            'keywords' => '',
            'block_names' => '',
            'block_urls' => '',
            'block_mails' => '',
            'notify_types' => array(),
            'dingtalk_webhook' => '',
            'feishu_webhook' => '',
            'wecom_webhook' => '',
            'mail_notify' => '0',
            'smtp_host' => '',
            'smtp_port' => '25',
            'smtp_secure' => '',
            'smtp_user' => '',
            'smtp_pass' => '',
            'mail_from' => '',
            'mail_from_name' => '',
            'mail_to' => '',
            'notify_events' => array('new', 'pending', 'ai_error', 'drop'),
            'login_notify' => '0',
            'login_notify_channels' => array('robot', 'mail'),
            'login_message_template' => "管理员登录成功\n用户名：{username}\nIP地址：{ip}\n地理位置：{geo}\n时间：{time}",
        );
        try {
            $plugin = Typecho_Widget::widget('Widget_Options')->plugin('CommentGuard');
            if ($plugin && is_object($plugin)) {
                foreach ($opts as $k => $v) {
                    $val = $plugin->{$k};
                    if ($val !== null && $val !== '') {
                        $opts[$k] = $val;
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return $opts;
    }

    private static function defaultPrompt()
    {
        return "你是中国大陆网站的内容安全审核员。请判断以下访客评论是否包含违规内容，包括但不限于："
            . "色情低俗、赌博、诈骗、政治敏感、违法广告、辱骂攻击、招嫖、枪支毒品等。"
            . "只回答一个词：block 或 pass。"
            . "\n\n评论人：{author}\n邮箱：{mail}\n网址：{url}\n内容：{text}";
    }
}
