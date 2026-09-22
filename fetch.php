<?php
/**
 * 每日抓取 stair-YYYYMMDD.yaml → 覆盖写入 settings.yaml
 *
 * 两种使用方式：
 *   1) GitHub Actions（推荐）：CI 定时执行 `php fetch.php`，成功后由 workflow
 *      提交回仓库；订阅地址直接用 raw.githubusercontent.com 链接。
 *   2) 网页触发（InfinityFree / HelioHost 等虚拟主机）：
 *      浏览器访问 https://<你的域名>/fetch.php?token=<ACCESS_TOKEN>
 *
 * 行为约定:
 *   - 只抓“当天”（时区见 TIMEZONE）的 stair-YYYYMMDD.yaml
 *   - 成功则【覆盖】写入 settings.yaml（rename 替换，非追加）
 *   - 失败 / 404 / 内容异常 时【保留】旧文件，绝不写入半截或错误内容
 *   - 网页模式校验 token；CLI 模式免 token（供 CI 使用）
 */
declare(strict_types=1);

/* ===================== 可配置项 ===================== */
const SOURCE_HOST  = 'https://stairnode.cczzuu.top';      // 源站
const TIMEZONE     = 'Asia/Shanghai';                      // 决定“当天”的时区
const OUTPUT_FILE  = 'settings.yaml';                      // 输出文件（相对本脚本目录）
const ACCESS_TOKEN = 'REPLACE_WITH_A_LONG_RANDOM_STRING';  // 访问密钥（务必改掉）
const CURL_TIMEOUT = 15;                                   // 请求超时（秒）
const MAX_BYTES    = 5 * 1024 * 1024;                      // 响应大小上限（5MB）
/* =================================================== */

/* rules 中的代理策略名：Clash 只内置 DIRECT / REJECT，其余策略名必须是已存在的 proxy-group。
   源配置没有名为 PROXY 的组，因此把示例 rules 里的 PROXY 统一指向实际存在的「🚀 节点选择」组。 */
const PROXY_GROUP = '🚀 节点选择';

/* 抓取后：先删除顶层 rules 段，再在文件末尾固定追加 rule-providers 与新的 rules（注意层级缩进） */
const APPEND_BLOCK = <<<'YAML'
rule-providers:
  reject:
    type: http
    behavior: domain
    url: "https://cdn.jsdelivr.net/gh/Loyalsoldier/clash-rules@release/reject.txt"
    path: ./ruleset/reject.yaml
    interval: 86400
  icloud:
    type: http
    behavior: domain
    url: "https://cdn.jsdelivr.net/gh/Loyalsoldier/clash-rules@release/icloud.txt"
    path: ./ruleset/icloud.yaml
    interval: 86400
  apple:
    type: http
    behavior: domain
    url: "https://cdn.jsdelivr.net/gh/Loyalsoldier/clash-rules@release/apple.txt"
    path: ./ruleset/apple.yaml
    interval: 86400
  google:
    type: http
    behavior: domain
    url: "https://cdn.jsdelivr.net/gh/Loyalsoldier/clash-rules@release/google.txt"
    path: ./ruleset/google.yaml
    interval: 86400
  proxy:
    type: http
    behavior: domain
    url: "https://cdn.jsdelivr.net/gh/Loyalsoldier/clash-rules@release/proxy.txt"
    path: ./ruleset/proxy.yaml
    interval: 86400
  direct:
    type: http
    behavior: domain
    url: "https://cdn.jsdelivr.net/gh/Loyalsoldier/clash-rules@release/direct.txt"
    path: ./ruleset/direct.yaml
    interval: 86400
  private:
    type: http
    behavior: domain
    url: "https://cdn.jsdelivr.net/gh/Loyalsoldier/clash-rules@release/private.txt"
    path: ./ruleset/private.yaml
    interval: 86400
  gfw:
    type: http
    behavior: domain
    url: "https://cdn.jsdelivr.net/gh/Loyalsoldier/clash-rules@release/gfw.txt"
    path: ./ruleset/gfw.yaml
    interval: 86400
  tld-not-cn:
    type: http
    behavior: domain
    url: "https://cdn.jsdelivr.net/gh/Loyalsoldier/clash-rules@release/tld-not-cn.txt"
    path: ./ruleset/tld-not-cn.yaml
    interval: 86400
  telegramcidr:
    type: http
    behavior: ipcidr
    url: "https://cdn.jsdelivr.net/gh/Loyalsoldier/clash-rules@release/telegramcidr.txt"
    path: ./ruleset/telegramcidr.yaml
    interval: 86400
  cncidr:
    type: http
    behavior: ipcidr
    url: "https://cdn.jsdelivr.net/gh/Loyalsoldier/clash-rules@release/cncidr.txt"
    path: ./ruleset/cncidr.yaml
    interval: 86400
  lancidr:
    type: http
    behavior: ipcidr
    url: "https://cdn.jsdelivr.net/gh/Loyalsoldier/clash-rules@release/lancidr.txt"
    path: ./ruleset/lancidr.yaml
    interval: 86400
  applications:
    type: http
    behavior: classical
    url: "https://cdn.jsdelivr.net/gh/Loyalsoldier/clash-rules@release/applications.txt"
    path: ./ruleset/applications.yaml
    interval: 86400

rules:
  - RULE-SET,applications,DIRECT
  - DOMAIN,clash.razord.top,DIRECT
  - DOMAIN,yacd.haishan.me,DIRECT
  - RULE-SET,private,DIRECT
  - RULE-SET,reject,REJECT
  - RULE-SET,icloud,DIRECT
  - RULE-SET,apple,DIRECT
  - RULE-SET,google,{{PROXY}}
  - RULE-SET,proxy,{{PROXY}}
  - RULE-SET,direct,DIRECT
  - RULE-SET,lancidr,DIRECT
  - RULE-SET,cncidr,DIRECT
  - RULE-SET,telegramcidr,{{PROXY}}
  - GEOIP,LAN,DIRECT
  - GEOIP,CN,DIRECT
  - MATCH,{{PROXY}}
YAML;

set_time_limit(30);
date_default_timezone_set(TIMEZONE);
header('Content-Type: text/plain; charset=utf-8');

/** 输出结果并结束；HTTP 码 >= 400 时以非零退出码结束（供 CI 判定失败） */
function done(int $httpCode, string $msg): void {
    if (PHP_SAPI === 'cli') {
        fwrite($httpCode >= 400 ? STDERR : STDOUT, $msg . "\n");
    } else {
        http_response_code($httpCode);
        echo $msg . "\n";
    }
    exit($httpCode >= 400 ? 1 : 0);
}

/**
 * GET 请求，返回 [HTTP状态码, 响应体, 错误信息]
 * 优先 cURL，失败则回退 file_get_contents
 */
function http_get(string $url): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => CURL_TIMEOUT,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; DailyFetch/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return [0, '', "cURL: {$err}"];
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, (string)$body, null];
    }

    // 回退方案：InfinityFree 已启用 allow_url_fopen
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => CURL_TIMEOUT,
        'user_agent'    => 'Mozilla/5.0 (compatible; DailyFetch/1.0)',
        'ignore_errors' => true, // 4xx/5xx 时也能拿到响应体
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return [0, '', 'file_get_contents 请求失败'];
    }
    $code = 0;
    if (isset($http_response_header[0])
        && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    return [$code, (string)$body, null];
}

/* ---------- 1) 鉴权（仅网页模式需要，CI/CLI 免 token） ---------- */
if (PHP_SAPI !== 'cli') {
    $token = isset($_GET['token']) ? (string)$_GET['token'] : '';
    if ($token === '' || !hash_equals(ACCESS_TOKEN, $token)) {
        done(403, '403 Forbidden: token 缺失或错误');
    }
}

/* ---------- 2) 并发锁，避免重复执行 ---------- */
$lockFp = @fopen(__DIR__ . '/.fetch.lock', 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    done(503, '503 Busy: 上一次抓取尚未结束');
}

/* ---------- 3) 拼出今日 URL ---------- */
$date = date('Ymd');                                  // 例: 20260919
$url  = sprintf('%s/%s/%s/stair-%s.yaml',
    SOURCE_HOST, substr($date, 0, 4), substr($date, 4, 2), $date);

/* ---------- 4) 发起请求 ---------- */
[$code, $body, $err] = http_get($url);
if ($err !== null) {
    done(502, "502 请求失败: {$err}\nURL: {$url}");
}

/* ---------- 5) 按状态码处理 ---------- */
if ($code === 404) {
    // 今日文件可能尚未发布：保留旧文件，视为“跳过”
    done(200, "SKIP 404 —— 今日文件尚未生成，保留现有 " . OUTPUT_FILE . "\nURL: {$url}");
}
if ($code !== 200) {
    done(502, "502 源站返回 HTTP {$code}，保留现有 " . OUTPUT_FILE . "\nURL: {$url}");
}

/* ---------- 6) 内容校验，防止把错误页写进去 ---------- */
$len = strlen($body);
if ($len === 0) {
    done(502, "502 响应为空，保留现有 " . OUTPUT_FILE);
}
if ($len > MAX_BYTES) {
    done(502, "502 响应过大（{$len} 字节），保留现有 " . OUTPUT_FILE);
}
if (stripos($body, '<html') !== false
    || stripos($body, '<noscript>') !== false
    || stripos($body, 'aes.js') !== false
    || stripos($body, 'requires javascript') !== false) {
    done(502, "502 内容疑似 HTML / 拦截页，拒绝写入，保留现有 " . OUTPUT_FILE);
}

/* ---------- 6.5) 删除顶层 rules 段，并追加固定 rule-providers 与 rules ---------- */
if (preg_match('/^rules\s*:/m', $body, $m, PREG_OFFSET_CAPTURE)) {
    $body = substr($body, 0, $m[0][1]); // 截断：删掉 rules 及其后的全部内容
}
$append = str_replace('{{PROXY}}', PROXY_GROUP, APPEND_BLOCK); // 填入真实代理组名
$body   = rtrim($body, "\r\n") . "\n\n" . $append . "\n";
$len    = strlen($body);

/* ---------- 7) 原子覆盖写入 ---------- */
$finalPath = __DIR__ . '/' . OUTPUT_FILE;
$tmpPath   = $finalPath . '.tmp';
if (@file_put_contents($tmpPath, $body) !== $len) {
    @unlink($tmpPath);
    done(502, '502 写入临时文件失败（请检查目录写权限）');
}
if (!@rename($tmpPath, $finalPath)) { // rename 覆盖旧文件，等价于“覆盖而非追加”
    @unlink($tmpPath);
    done(502, '502 覆盖 ' . OUTPUT_FILE . ' 失败');
}

done(200, 'OK 已更新 ' . OUTPUT_FILE . "\n"
    . "日期: {$date}\n"
    . "来源: {$url}\n"
    . "大小: {$len} 字节\n"
    . '时间: ' . date('Y-m-d H:i:s'));
