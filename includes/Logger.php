<?php
declare(strict_types=1);

namespace FFL\FunnelsSync;

defined('ABSPATH') || exit;

/**
 * Debug-only logging to PHP error_log; gated by the "Logging" checkbox in settings.
 */
final class Logger
{
    public static function error(string $message): void
    {
        $opts = (array) get_option(Plugin::OPT_KEY, []);
        if (empty($opts['debug'])) {
            return;
        }

        error_log('[ffl-funnels-sync] ' . Security::redact_log_message($message));
    }
}
