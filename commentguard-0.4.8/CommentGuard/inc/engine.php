<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * CommentGuard 规则引擎 + AI 审核
 */
class CommentGuard_Engine
{
    /**
     * 本地规则拦截
     * 返回命中的规则描述（字符串）表示拦截，null 表示放行。
     */
    public static function checkRules($author, $mail, $url, $text, $options)
    {
        // 非中文评论检测（纯外文/无中文，常见垃圾特征）
        if (!empty($options['no_chinese'])) {
            if (!preg_match('/[\x{4e00}-\x{9fa5}]/u', $text)) {
                return 'no_chinese';
            }
        }

        // 关键词（匹配评论内容）
        if (!empty($options['keywords'])) {
            foreach (self::parseRules($options['keywords']) as $rule) {
                if (self::match($rule, $text)) {
                    return 'keyword:' . $rule['raw'];
                }
            }
        }

        // 昵称
        if (!empty($options['block_names'])) {
            foreach (self::parseRules($options['block_names']) as $rule) {
                if (self::match($rule, $author)) {
                    return 'name:' . $rule['raw'];
                }
            }
        }

        // 网址
        if (!empty($options['block_urls'])) {
            foreach (self::parseRules($options['block_urls']) as $rule) {
                if (self::match($rule, $url)) {
                    return 'url:' . $rule['raw'];
                }
            }
        }

        // 邮箱
        if (!empty($options['block_mails'])) {
            foreach (self::parseRules($options['block_mails']) as $rule) {
                if (self::match($rule, $mail)) {
                    return 'mail:' . $rule['raw'];
                }
            }
        }

        return null;
    }

    /**
     * 解析规则文本。每行一条，支持三种写法：
     *   /正则/            → 正则
     *   =精确值           → 精确匹配（大小写不敏感）
     *   普通词            → 包含匹配
     */
    private static function parseRules($raw)
    {
        $rules = array();
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $rules[] = array(
                'raw' => $line,
                'type' => self::ruleType($line),
                'value' => self::ruleValue($line),
            );
        }
        return $rules;
    }

    private static function ruleType($line)
    {
        // 正则：以 / 开头，且能找到闭合的 /pattern/flags 结构
        if (strlen($line) >= 2 && $line[0] === '/') {
            $body = substr($line, 1);
            $lastSlash = strrpos($body, '/');
            if ($lastSlash !== false) {
                // / 后面只能是合法的修饰符字母
                $tail = substr($body, $lastSlash + 1);
                if ($tail === '' || preg_match('/^[imsxuADSUJ]*$/', $tail)) {
                    return 'regex';
                }
            }
        }
        if (strlen($line) >= 1 && $line[0] === '=') {
            return 'exact';
        }
        return 'contains';
    }

    private static function ruleValue($line)
    {
        if (self::ruleType($line) === 'regex') {
            // 提取 /pattern/flags
            $body = substr($line, 1);
            $lastSlash = strrpos($body, '/');
            $pattern = substr($body, 0, $lastSlash);
            $flags = substr($body, $lastSlash + 1);
            if (strpos($flags, 'u') === false) $flags .= 'u';
            if (strpos($flags, 'i') === false) $flags .= 'i';
            return array('pattern' => $pattern, 'flags' => $flags);
        }
        if (self::ruleType($line) === 'exact') {
            return substr($line, 1);
        }
        return $line;
    }

    private static function match($rule, $subject)
    {
        if ($subject === '') {
            return false;
        }
        switch ($rule['type']) {
            case 'regex':
                $val = $rule['value'];
                return @preg_match('~' . $val['pattern'] . '~' . $val['flags'], $subject) === 1;
            case 'exact':
                return mb_strtolower($subject, 'UTF-8') === mb_strtolower($rule['value'], 'UTF-8');
            case 'contains':
            default:
                return mb_stripos($subject, $rule['value'], 0, 'UTF-8') !== false;
        }
    }

    /**
     * AI 审核。返回 'block' / 'pass' / 'error'
     * error 表示接口失败（积分耗尽、网络超时、鉴权错误等），由调用方决定兜底策略。
     */
    public static function aiModerate($author, $mail, $url, $text, $options)
    {
        $baseUrl = rtrim($options['ai_base_url'], '/');
        $apiKey = $options['ai_api_key'];
        $model = $options['ai_model'];
        $timeout = max(1, (int) $options['ai_timeout']);

        $prompt = isset($options['ai_prompt']) && $options['ai_prompt'] !== ''
            ? $options['ai_prompt']
            : '你是内容安全审核员，判断评论是否违规，只回答 block 或 pass。';

        $userMsg = str_replace(
            array('{author}', '{mail}', '{url}', '{text}'),
            array($author, $mail, $url, $text),
            $prompt
        );

        $payload = array(
            'model' => $model,
            'messages' => array(
                array('role' => 'system', 'content' => '你是一个严格的内容安全审核助手，只输出一个词：block 或 pass。'),
                array('role' => 'user', 'content' => $userMsg),
            ),
            'temperature' => 0,
            'max_tokens' => 8,
        );

        $ch = curl_init($baseUrl . '/chat/completions');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        // 网络/超时/鉴权失败 → error
        if ($response === false || $httpCode !== 200 || empty($response)) {
            error_log('[CommentGuard] AI 审核 error：http=' . $httpCode . ' curl=' . $curlErr . ' url=' . $baseUrl . ' model=' . $model);
            return 'error';
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[CommentGuard] AI 审核 error：响应非 JSON');
            return 'error';
        }

        // 检查是否积分/额度耗尽类错误
        if (isset($data['error'])) {
            error_log('[CommentGuard] AI 审核 error：' . json_encode($data['error'], JSON_UNESCAPED_UNICODE));
            return 'error';
        }

        if (!isset($data['choices'][0]['message']['content'])) {
            error_log('[CommentGuard] AI 审核 error：响应缺少 choices');
            return 'error';
        }

        $answer = strtolower(trim($data['choices'][0]['message']['content']));
        error_log('[CommentGuard] AI 审核结果：' . $answer . '（原文：' . substr(trim($data['choices'][0]['message']['content']), 0, 50) . '）');
        if (strpos($answer, 'block') !== false) {
            return 'block';
        }
        if (strpos($answer, 'pass') !== false) {
            return 'pass';
        }
        error_log('[CommentGuard] AI 审核 error：无法解析结果 "' . $answer . '"');
        return 'error';
    }
}
