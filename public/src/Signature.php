<?php

declare(strict_types=1);

namespace OpenListProxy;

/**
 * OpenList's link signature.
 *
 * OpenList signs:  HMAC-SHA256(path + ":" + expire, TOKEN)
 * and encodes it as URL-safe Base64 with the "=" padding kept, then appends
 * ":expire". expire == 0 means the link never expires.
 */
final class Signature
{
    public function __construct(private string $token)
    {
    }

    public function verify(string $path, string $sign): void
    {
        if ($this->token === '' || $this->token === 'CHANGE_ME') {
            throw new HttpException(500, 'token not configured');
        }
        if ($sign === '') {
            throw new HttpException(401, 'missing sign');
        }

        $pos = strrpos($sign, ':');
        if ($pos === false) {
            throw new HttpException(401, 'bad sign');
        }

        $expireRaw = substr($sign, $pos + 1);
        if ($expireRaw === '' || !ctype_digit($expireRaw)) {
            throw new HttpException(401, 'bad expire');
        }

        $expire = (int)$expireRaw;
        if ($expire !== 0 && $expire < time()) {
            throw new HttpException(401, 'expired');
        }

        if (!hash_equals($this->sign($path, $expire), $sign)) {
            throw new HttpException(401, 'bad sign');
        }
    }

    /**
     * Exposed for tests and for generating a link without an OpenList instance.
     */
    public function sign(string $path, int $expire = 0): string
    {
        return strtr(
            base64_encode(hash_hmac('sha256', $path . ':' . $expire, $this->token, true)),
            '+/',
            '-_'
        ) . ':' . $expire;
    }
}
