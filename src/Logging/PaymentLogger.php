<?php

namespace Sunnysideup\Bookings\Logging;

use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;

class PaymentLogger
{
    use Configurable;

    private static bool $enabled = false;

    /**
     * Log an info event
     * @param string $stage
     * @param array $context
     */
    public static function info(string $stage, array $context = []): void
    {
        self::write('info', $stage, $context);
    }

    /**
     * Log an error event
     * @param string $stage
     * @param array $context
     */
    public static function error(string $stage, array $context = []): void
    {
        self::write('error', $stage, $context);
    }

    /**
     * Core writer. JSON-lines to BASE_PATH/payment.log when enabled via config.
     */
    protected static function write(string $level, string $stage, array $context): void
    {
        if (!self::config()->get('enabled')) {
            return;
        }

        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 6);
        $filePath = $basePath . DIRECTORY_SEPARATOR . 'payment.log';

        $request = Controller::curr() ? Controller::curr()->getRequest() : null;

        $payload = [
            'ts' => gmdate('c'),
            'level' => $level,
            'stage' => $stage,
            'request' => [
                'method' => $request ? $request->httpMethod() : null,
                'url' => $request ? $request->getURL() : null,
            ],
            'env' => [
                'app_env' => Environment::getEnv('SS_ENVIRONMENT_TYPE') ?: null,
            ],
            'context' => $context,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{"ts":"' . gmdate('c') . '","level":"' . $level . '","stage":"' . $stage . '","context":"<encoding error>"}';
        }

        // Attempt to append; if it fails, fallback to PHP error_log
        try {
            @file_put_contents($filePath, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            error_log('[payment.log] ' . $json);
        }
    }
}


