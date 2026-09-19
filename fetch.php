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
