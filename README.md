# OpenList StorageBox Proxy

[![Proudly Vibe Coded - Neon Flame](https://vibecoded.fyi/badges/terminal/main/proudly-vibe-coded-neon-flame.svg)](https://vibecoded.fyi/)

让 OpenList 里**没有公网直链**的存储（SFTP、rsync 虚拟主机、StorageBox 等）也能下载：OpenList 只负责目录和签名，真正的文件由本机的 `dl.php` **校验签名后流式输出**。

- 一个 `dl.php` 入口，发布包解压后直接复制到网站根目录即可
- 每次下载都校验 OpenList 同款 HMAC，不再暴露永久静态直链
- 多挂载点、最长前缀匹配
- 支持 Range / HEAD / 断点续传 / 媒体拖动
- 只对文本型 MIME 启用 gzip，二进制和 Range 响应永不压缩
- 路径穿越和符号链接逃逸防护
- 无 Composer 依赖，PHP 8.0+

---

## 它解决什么问题

很多 StorageBox / 虚拟主机只给 SFTP 或 rsync，没有公网直链。挂到 OpenList 后，默认下载会经 OpenList 中转，两边各承担一份完整流量。本项目把路径拉直：OpenList 只负责目录和签名，文件由存储所在主机上的 `dl.php` 验签后直接输出，不再经过 OpenList 中转。

架构图、下载时序、签名算法与设计决策见 [docs/architecture.md](docs/architecture.md)。

---

## 快速开始

### 1. 上传

下载 Release 里的 `openlist-proxy.zip`（没有 zip 时会生成 `openlist-proxy.tar.gz`），解压后直接把里面的文件复制到网站根目录。使用 Git 仓库时，仓库根目录本身就是部署目录，不再额外经过 `public/` 这一层。

```text
public_html/
├── dl.php
├── bootstrap.php
├── config.sample.php
├── config.php          # cp config.sample.php config.php 之后生成
├── .htaccess
├── scripts/
├── src/
└── tests/
```

覆盖升级时保留已有的 `config.php`，发布包不会携带或覆盖它。

> 建议放在独立子域或独立目录。如果直接丢进已有站点的根目录，`src/`、`tests/`、`scripts/` 可能和现有目录重名；改名后同步改 `bootstrap.php` 和 `.htaccess` 即可。

### 2. 配置

```bash
cd /home/username/public_html
cp config.sample.php config.php
nano config.php
```

最少改这两处：

```php
'token' => '你的 OpenList 管理员 Token',

'mounts' => [
    '/storagebox' => [
        'root' => '/home/username/files',
    ],
],
```

- `token`：OpenList 后台 **设置 → 其他 → Token**。
- `mounts` 的键：OpenList 里的挂载路径，例如 `/storagebox`。
- `root`：这个挂载在服务器上的真实目录，必须和 OpenList SFTP 的“根文件夹路径”一致。
- `path_prefix`：通常留空，自动从 `dl.php` 的 URL 推断。只有用了 `/dl/...` 这类 rewrite 才需要手动填。
- `base_url`：可选，只给 `scripts/sign.php` 生成测试链接时使用，例如 `https://example.com`。

**不需要再创建 `/f` 软链，也不要把数据目录映射成公开静态目录。**

### 3. 配置 OpenList

编辑这个 SFTP 存储：

| 项目 | 值 |
| --- | --- |
| 挂载路径 | `/storagebox` |
| 根文件夹路径 | `/home/username/files` |
| 下载代理 URL | `https://example.com/dl.php` |
| WebDAV 策略 | `使用代理网址`（如果要用 WebDAV） |

注意：

- **下载代理 URL 结尾不要带 `/`**。OpenList 是直接拼接路径的。
- SFTP 驱动本身是 `OnlyProxy`，所以 **Web 代理开关不开都可以**。
- 如果你在用 WebDAV，把策略设为 `use_proxy_url`，否则 WebDAV 下载可能仍然走 OpenList。
- **链接过期时间**（`link_expiration`，单位小时）建议设成非 `0`。设为 `0` 时签名永不过期；非 `0` 时 `dl.php` 会校验过期时间。

### 4. 验证

先放一个测试文件：

```bash
echo 'hello' > /home/username/files/hello.txt
```

没有签名时必须被拒绝：

```bash
curl -I https://example.com/dl.php/storagebox/hello.txt
# 期望 401 missing sign
```

生成带签名的测试链接：

```bash
php scripts/sign.php /storagebox/hello.txt
```

如果 `config.php` 里配置了 `base_url`，会直接输出完整 URL；否则在前面补上站点地址：

```bash
curl -I 'https://example.com/dl.php/storagebox/hello.txt?sign=...'
# 期望 200
```

再在 OpenList 里点一次下载，浏览器网络面板应该只看到一个请求：

```text
GET /dl.php/storagebox/...?sign=...   → 200 / 206
```

断点续传：

```bash
curl -I -H 'Range: bytes=0-4' \
  'https://example.com/dl.php/storagebox/hello.txt?sign=...'
# 期望 206，Content-Range: bytes 0-4/5
```

单元测试：

```bash
php tests/run.php
# OK (64 assertions)
```

---

## 配置项

| 键 | 说明 |
| --- | --- |
| `token` | OpenList 管理员 Token，用于校验签名。 |
| `path_prefix` | `dl.php` 的 URL 前缀。留空自动推断；用 rewrite 时再填。 |
| `base_url` | 可选，`scripts/sign.php` 生成完整测试 URL 时使用的站点地址。 |
| `mounts` | OpenList 挂载路径 → `root`（本地绝对路径）。 |
| `debug` | 把请求路径写进 PHP error_log，排查完关掉。 |
| `xsendfile` | 可选，默认 `false`。验签后交给 Apache 原生分发文件，需要 `mod_xsendfile`，见 [docs/performance.md](docs/performance.md)。 |

多个挂载就继续往 `mounts` 里加，匹配时取最长前缀，所以 `/` 和 `/storagebox` 可以共存。

旧版本配置里的 `public`、`require_sign`、`stream_fallback` 已废弃，会被忽略。**签名校验现在始终开启，不能通过配置关闭。**

---

## 安全与限制

- **Token 是密钥**。`config.php` 不要提交到 Git。项目里的 `.gitignore` 已经忽略它。
- **每个下载请求都必须带有效签名**。没有签名、签名错误或链接过期都会返回 `401`，不再有可以绕过的静态 `/f/...` 地址。
- **签名链接在过期前仍然可以被复制和转发**。这是无状态 HMAC 的固有特性；要真正做到一次性链接，需要额外的 nonce/数据库存储，本项目暂不包含。建议在 OpenList 里设置非 `0` 的链接过期时间。
- **数据目录不要放在 Web 可直接访问的位置**。如果必须放在 `public_html` 下，请用 `.htaccess` 或 Nginx 规则拒绝直接访问。
- **启用 X-Sendfile 时**，`XSendFilePath` 白名单只圈数据根：该机制会绕过 `Deny from all` 发送白名单内的任何文件，切勿圈 Web 根或应用目录。
- **路径穿越和符号链接逃逸**已经拦截：`..`、空段、`realpath` 越出 `root` 都会返回 404。
- **目录列表**：包里的 `.htaccess` 默认已经加了 `Options -Indexes`；如果和现有站点冲突，可以删掉那一行，并在数据目录单独加一个 `.htaccess`。
- **敏感文件默认拒绝直接访问**：站点根目录的 `.htaccess` 会拒绝 `config.php`、`bootstrap.php`，以及 `src/`、`tests/`、`scripts/` 下的所有文件。
  - Nginx 用户请在站点配置里加：
    ```nginx
    location ~ ^/(src|tests|scripts)/ { deny all; }
    location = /config.php { deny all; }
    location = /bootstrap.php { deny all; }
    ```
    如果项目放在子目录，记得把路径前缀补上。
- **PHP 的 `open_basedir`** 必须允许读取 `root`。大多数虚拟主机默认允许整个用户目录。
- **HTTPS**：代理 URL 建议走 HTTPS，避免签名链接在传输中泄漏。
- **Token 轮换**：改了 OpenList Token 后，同步改根目录的 `config.php`。

---

## 性能与压缩

- 只对文本型 MIME 白名单启用 gzip；二进制和 Range 响应永不压缩，保证断点续传和播放器 seek。
- 默认 PHP 流式输出；主机 Apache 装有 `mod_xsendfile` 时可启用 `xsendfile`，验签后交给 Apache 原生分发，大幅降低 CPU 与 worker 占用。

gzip 策略细节、Content-Length 注意事项、X-Sendfile 启用与验证步骤见 [docs/performance.md](docs/performance.md)。

---

## 从旧版本升级

旧版本会在验签后 302 到 `/f/...` 静态软链，那个地址可以绕过 `dl.php`。升级时：

1. 更新 `dl.php`、`src/`、`.htaccess` 等文件。
2. **删除** `public_html/f` 这个软链，或删除对应的 `Alias`。
3. 从 `config.php` 里删掉 `public`、`require_sign`、`stream_fallback`（不删也会被忽略）。
4. 确认数据目录不能被 Web 直接访问。
5. 重新点一次 OpenList 下载验证：网络面板里应该只有 `dl.php` 请求，没有 `/f/...`。

新版本新增可选配置键 `xsendfile`，默认 `false`，旧部署不需要任何改动。

---

## 常见问题

| 现象 | 可能原因 | 处理 |
| --- | --- | --- |
| `dl.php` 返回 401 `missing sign` | URL 没有带 `sign` | 从 OpenList 重新取链接；确认中间层没有丢掉 query string |
| 401 `bad sign` | Token 不一致；或 URL 被代理/CDN 规范化了 | 核对 Token；检查中间层是否改写 `//`、`..`、`%2F` |
| 401 `expired` | OpenList 的链接过期时间到了 | 重新点下载，或把 `link_expiration` 设为 0 |
| 500 `token not configured` | 还在用 `CHANGE_ME` | 填真实 Token |
| `dl.php` 返回 404 `mount not found` | `mounts` 的键和 OpenList 挂载路径不一致 | 打开 `debug`，看 error_log 里的实际 path |
| `dl.php` 返回 404 `not found` | `root` 配错，或 OpenList 路径层级不对 | 确认 SFTP 的“根文件夹路径”和 `root` 是同一个目录 |
| 下载到一半中断 | PHP/FPM/反向代理超时 | 检查 `max_execution_time`、FPM `request_terminate_timeout`、Nginx `fastcgi_read_timeout` |
| 浏览器下载不显示总大小 | Apache 2.4.62+ + PHP-FPM 丢弃了脚本的 `Content-Length`，或 CDN 压缩后移除了长度 | 保留 `.htaccess` 里的 `ap_trust_cgilike_cl 1`、`no-gzip` 和响应头 `Cache-Control: no-transform` |
| 视频不能拖动 | 客户端没把 `Range` 发给 `dl.php` | 确认播放器请求的是带 `sign` 的完整 URL，并可返回 206 |
| 下载走回 OpenList | OpenList 没用到“下载代理 URL” | 确认字段没写错、结尾没斜杠；SFTP 正常不需要开 Web 代理 |
| 启用 `xsendfile` 后下载 0 字节 | Apache 没加载 `mod_xsendfile` | 把 `xsendfile` 改回 `false`；或请主机商装模块并配 `XSendFilePath` |
| 启用 `xsendfile` 后 404 | `XSendFilePath` 未配置，或与数据根真实路径不一致 | 在主配置里配白名单，填 realpath |
| 客户端看到 `X-Sendfile` 响应头 | 模块未加载且 `.htaccess` 兜底未生效 | 检查主机是否允许 `.htaccess`（AllowOverride），并关闭 `xsendfile` |
| OpenList 里复制大目录很慢 | SFTP 没有原生 Copy，OpenList 会回退成“读源文件再写到目标” | 单文件正常；大目录建议在服务器上用 `cp`/`rsync` |
| PATH_INFO 被主机禁用 | 访问 `/dl.php/...` 直接 404，PHP 没执行 | 加 rewrite，见下 |

如果 PATH_INFO 被禁用，加一条 rewrite：

```apache
RewriteEngine On
RewriteRule ^dl/(.*)$ dl.php [L,QSA]
```

然后把 OpenList 的 **下载代理 URL** 改成 `https://example.com/dl`，并在 `config.php` 里设置：

```php
'path_prefix' => '/dl',
```

---

## 开发与测试

```bash
make test      # 单元测试
make lint      # PHP 语法检查
make package   # 生成 dist/openlist-proxy.zip（没有 zip 时生成 .tar.gz）
```

本地没有 PHP 时可以用 Docker：

```bash
docker run --rm -v "$PWD":/src -w /src \
  php:8.2-cli php tests/run.php

docker run --rm -v "$PWD":/src -w /src php:8.2-cli \
  sh -c 'for file in dl.php bootstrap.php config.sample.php; do php -l "$file"; done && find src scripts tests -name "*.php" -print0 | xargs -0 -n1 php -l'
```

目录结构：

```text
./                            # 仓库根目录，也是发布包的部署根目录
├── dl.php                    # 唯一 Web 入口，验签 + 流式输出
├── bootstrap.php
├── config.sample.php
├── .htaccess                 # 拒绝 config/bootstrap 和 src/tests/scripts
├── scripts/
│   ├── sign.php
│   └── package.sh
├── src/
├── tests/run.php
├── docs/                     # 架构与设计决策、性能与压缩
├── Makefile
└── LICENSE
```

---

## English quick start

A tiny PHP download proxy for OpenList storages that have no public direct link (SFTP, rsync-only StorageBoxes, etc.). Every request is signature-checked, then `dl.php` streams the file itself; there is no public static file directory.

1. Extract the release archive and copy its contents directly to `public_html`. The repository root uses the same deployable layout.
2. `cp config.sample.php config.php` and fill in `token` plus the `mounts` mapping (`root` only).
3. In OpenList, set **Download Proxy URL** to `https://example.com/dl.php` (no trailing slash). Set a non-zero `link_expiration` if you want links to expire.
4. Test: `php scripts/sign.php /storagebox/hello.txt`, then `curl -I` the returned signed URL. Without `sign`, `dl.php` returns `401`.

OpenList signs `HMAC-SHA256(path + ":" + expire, token)` with URL-safe Base64 (padding kept). `dl.php` verifies it, maps the OpenList path to a local file, and streams the file with Range support. No `/f` symlink is needed.

---

## License

MIT. See [LICENSE](LICENSE).
