<?php

declare(strict_types=1);

namespace AlpacaBot\Settings;

use AlpacaBot\Mcp\ServerSettings;

/**
 * The write sequence shared by every writer of the settings that stores nothing on a refusal and
 * says what a move cleared: the REST route (Rest\SettingsController::update()),
 * `wp alpaca-bot settings` (Cli\ChatCommand::settings()) and a raw `wp option update|patch|add`
 * of the option (Cli\RawOptionWrite). Each of them keeps only how it answers (Written).
 *
 * @since 0.6.2
 */
final class Writer
{
    public function __construct(private Store $store, private ServerSettings $servers)
    {
    }

    /**
     * In this order, and nothing after a refusal:
     * 1. When `$input` carries `toolkits.mcp_servers`, ServerSettings::check() of it against the
     *    stored list; a refusal is answered as it came (Written::refused()).
     * 2. ProviderKey::clearedByMove() of `$input` against the settings held before the write.
     * 3. `$beforeStore`, when given (Cli\RawOptionWrite notes what is stored and disarms there).
     * 4. Store::replace() of `$input`.
     *
     * @param array<string, mixed>   $input       the keys to write, as Store::replace() takes them
     * @param (\Closure(): void)|null $beforeStore run once the checks have passed, just before the write
     */
    public function write(#[\SensitiveParameter] array $input, ?\Closure $beforeStore = null): Written
    {
        $cleared = [];
        if (array_key_exists('toolkits.mcp_servers', $input)) {
            $check = $this->servers->check($input['toolkits.mcp_servers'], $this->store->get('toolkits.mcp_servers'));
            if ($check->isRefused()) {
                return Written::refused($check);
            }
            $cleared = $check->cleared;
        }
        $keyCleared = ProviderKey::clearedByMove($input, $this->store->all());
        if ($beforeStore !== null) {
            $beforeStore();
        }
        $this->store->replace($input);
        return Written::stored($cleared, $keyCleared);
    }
}
