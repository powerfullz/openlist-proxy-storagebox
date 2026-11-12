<?php

declare(strict_types=1);

namespace OpenListProxy;

/**
 * Wires the request pipeline together:
 * request -> signature -> mount resolution -> redirect or stream.
 */
final class Application
{
    public function __construct(private Config $config)
    {
    }

    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $query
     */
    public static function run(array $server, array $query): void
    {
        $config = null;

        try {
            $config = Config::fromFile(__DIR__ . '/../config.php');
            (new self($config))->handle(Request::from($server, $query));
        } catch (HttpException $e) {
            Response::text($e->status(), $e->getMessage());
        } catch (\RuntimeException $e) {
            Response::text(500, $e->getMessage());
        } catch (\Throwable $e) {
            if ($config !== null && $config->debug()) {
                error_log('[openlist-proxy] ' . $e);
            }
            Response::text(500, 'internal error');
        }
    }

    private function handle(Request $request): void
    {
        $path = $request->path($this->config->pathPrefix());

        if ($this->config->debug()) {
            error_log('[openlist-proxy] path=' . $path);
        }

        if ($this->config->requireSign()) {
            (new Signature($this->config->token()))->verify($path, $request->sign());
        }

        $file = (new MountResolver($this->config->mounts()))->resolve($path);

        if ($file->mount()->publicBase() !== '') {
            Response::redirect($file->publicUrl());
        }

        if (!$this->config->streamFallback()) {
            throw new HttpException(500, 'public base not configured');
        }

        Response::stream($file->path(), $request->method(), $request->range());
    }
}
