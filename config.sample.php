<?php
/**
 * Copy this file to config.php and fill in the values.
 *
 *   cp config.sample.php config.php
 *
 * config.php contains the OpenList admin token. It lives inside the
 * include-only app directory, which is blocked from direct HTTP access by
 * .htaccess. Keep it out of Git.
 */

return [
    // OpenList: Settings -> Other -> Token.
    // This is the instance signing token, not a user password.
    'token' => 'CHANGE_ME',

    // Usually leave empty: the path prefix is auto-detected from dl.php's URL.
    // Set it only if you use a rewrite such as /dl/... -> dl.php.
    'path_prefix' => '',

    // OpenList mount path => local root + public URL prefix.
    'mounts' => [
        '/storagebox' => [
            // The same directory you set as the SFTP "root folder path" in
            // OpenList. This is where the files actually live on this host.
            'root' => '/home/username/files',

            // The URL of the symlink you created in public_html.
            // A root-relative value such as /f also works.
            'public' => '/f',
        ],
    ],

    // Verify the OpenList HMAC. Keep this true unless you really want
    // "anyone who knows the path can download".
    'require_sign' => true,

    // If "public" is empty, stream the file through PHP instead of 302.
    // Still does not go through OpenList, but uses a PHP worker.
    'stream_fallback' => false,

    // Log request paths to PHP's error_log. Turn off after debugging.
    'debug' => false,
];
