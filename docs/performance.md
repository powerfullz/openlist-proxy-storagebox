# 性能与压缩

## gzip 压缩策略

压缩使用 **MIME 白名单**，只有文本型内容允许 gzip：

```text
text/*
application/json
application/ld+json
application/xml
application/xhtml+xml
application/javascript
application/x-javascript
image/svg+xml
application/x-subrip
application/vnd.apple.mpegurl
application/dash+xml
```

视频、音频、图片（SVG 除外）、压缩包、PDF、字体、`application/octet-stream` 等二进制类型一律不压缩。

压缩由 `dl.php` 自己完成，而不是交给 Apache/Nginx：

- 文本文件且客户端接受 gzip 时才压缩
- 小于 2 MiB 的文本文件在 PHP 内压缩，能返回压缩后的 `Content-Length`
- 更大的文本文件流式 gzip，不返回 `Content-Length`
- 二进制文件永远不压缩，始终返回原始 `Content-Length`
- `Range` / `206` 永远不压缩，保证断点续传和播放器 seek
- `.htaccess` 对 `dl.php` 显式设置 `no-gzip`，避免 Apache 再压缩一次导致长度丢失
- `.htaccess` 对 `dl.php` 设置 `ap_trust_cgilike_cl 1`。Apache 2.4.62+ 配合 PHP-FPM 时会丢弃脚本设置的 `Content-Length` 并强制 chunked，这个开关让 Apache 信任脚本给出的长度
- `Cache-Control` 带 `no-transform`，避免 Cloudflare 等 CDN 改动响应

`.htaccess` 里的 `AddOutputFilterByType DEFLATE ...` 白名单仍然保留，用于站点里的静态文本文件；`RemoveOutputFilter DEFLATE` 会先清掉主机可能配置的全量压缩。

Nginx 不读取 `.htaccess`，建议同样使用白名单：

```nginx
gzip on;
gzip_vary on;
gzip_types
    text/plain text/css text/xml text/csv text/vtt
    application/json application/ld+json application/xml
    application/xhtml+xml application/javascript
    image/svg+xml application/x-subrip
    application/vnd.apple.mpegurl application/dash+xml;
```

不要使用 `gzip_types *`，否则二进制文件也会被压缩。`dl.php` 返回 `Content-Encoding: gzip` 时 Nginx 不会再次压缩；二进制响应则保持原始长度。

小于 1 KiB 的文本文件不会压缩，属于正常现象；以响应头是否出现 `Content-Encoding: gzip` 为准。

如果站点前面有 Cloudflare，响应里还会带 `Cache-Control: no-transform`。Cloudflare 在压缩或重新压缩响应时可能移除 `Content-Length`，加上 `no-transform` 后才会保留源站的文件大小，浏览器下载时也才能显示总大小。

## 大文件与性能

每个下载都会占用一个 PHP worker。代码里已经做了：

- 清理输出缓冲，避免整份文件进内存
- `set_time_limit(0)`，避免 PHP 超时截断
- 全文件用 `readfile()`，Range 用 `stream_copy_to_stream()`，都是 C 层复制；客户端断开导致写入失败时立即停止
- 大文本 gzip 仍按 64 KiB 块 `deflate_add`（实测比 zlib 流过滤器略快）
- 运行时关闭 `zlib.output_compression`，避免和脚本自己的 gzip 二次压缩、长度失真
- `Cache-Control: private, no-store, no-transform`，避免共享缓存保存签名内容，也避免 Cloudflare 等 CDN 改动响应并丢失 `Content-Length`
- 音视频、HLS/DASH 清单和 WebVTT 使用 `Content-Disposition: inline`，其他文件使用 `attachment`

如果主机有 Nginx，可以在未来加 `X-Accel-Redirect`；Apache 的对应方案见下节。默认（`xsendfile => false`）使用 PHP 流式输出，兼容性最好。

## 可选：X-Sendfile 加速（Apache 原生分发）

PHP 流式输出每个下载占一个 worker，CPU 开销远高于 Apache 原生 `sendfile`。如果主机 Apache 装有 `mod_xsendfile`，可以启用 `xsendfile`：PHP 照常验签、解析路径，然后只返回 `X-Sendfile` 头，由 Apache 用内核零拷贝发送文件，原生处理 Range/HEAD/Content-Length，worker 立即释放。**签名校验一次不少**。

策略是混合的：本来就要 PHP gzip 的文本仍走压缩路径；二进制、Range、HEAD 等响应走 X-Sendfile。

**前置条件（缺一不可）**：

1. Apache 加载了 `mod_xsendfile`。检测方法：
   - mod_php：`php -r "print_r(apache_get_modules());"` 或 phpinfo 里找 `mod_xsendfile`；
   - PHP-FPM 下无法从 PHP 探测，问主机商，或启用后按下面的 curl 验证。
2. **主配置**（httpd.conf / vhost / `<Directory>`）里配置白名单，圈住数据根的**真实路径**：

   ```apache
   <Directory /home/username/public_html>
       AllowOverride FileInfo
   </Directory>
   XSendFile On
   XSendFilePath /home/username/files
   ```

   `XSendFilePath` **不能写在 `.htaccess` 里**，共享主机需要请主机商配置。数据根如果是软链，填解析后的真实路径，否则 404。
3. `config.php` 里设 `'xsendfile' => true`。包里的 `.htaccess` 已含 `XSendFile On`（模块存在时生效）。

**启用后验证**：

```bash
curl -sD - -o /dev/null 'https://example.com/dl.php/storagebox/foo.mkv?sign=...'
# 期望 200、Content-Length = 文件大小、X-Proxy-Mode: sendfile，
# 且响应里没有 X-Sendfile 头（模块会自动剥掉）

curl -I -H 'Range: bytes=0-4' 'https://example.com/dl.php/storagebox/foo.mkv?sign=...'
# 期望 206 + Content-Range
```

- 响应 **0 字节**：模块没加载（`.htaccess` 兜底已剥掉路径头，不会泄漏绝对路径）。立即把 `xsendfile` 改回 `false`。
- **404**：`XSendFilePath` 没配置，或和数据根真实路径不一致。
- 行为差异：多区间 `Range` 由 Apache 返回 206 multipart（PHP 流式按 200 整份返回），常规播放器和下载工具不受影响。