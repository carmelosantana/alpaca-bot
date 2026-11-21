<?php

declare(strict_types=1);

namespace AlpacaBot\Toolkit;

use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Contract\ToolInterface;
use AlpacaBot\Vendor\CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * One tool the model is handed, with the site's word asked before it runs and told after.
 *
 * Every tool a turn's agent loop is given passes through here (Chat\Pipeline::offered() wraps
 * them while Toolkit\FirstWins settles their names), whichever toolkit offered it: a built-in,
 * a companion's from `alpaca_bot/register_toolkits` or `alpaca_bot/toolkits`, the abilities
 * toolkit or an MCP server's. So `alpaca_bot/tool/before_call` and `alpaca_bot/tool/called` are
 * the one place an approval rule, an audit trail or a redaction sees every call the model makes.
 * The model sees the wrapped tool unchanged: name, description, parameters and schema are the
 * inner tool's.
 *
 * What it does not cover: a surface that runs a tool on a person's explicit request rather
 * than the model's (the `[alpacabot_agent]` shim's fetch, the `alpaca-bot/*` abilities) calls
 * the tool directly and fires neither hook; an ability run that way still passes core's own
 * execution filters. And a tool that throws fires no `tool/called`: the agent loop records the
 * failure itself.
 *
 * The refusal is fail-closed on the two answers that read as "no": a WP_Error, whose message
 * is what the model reads, and `false`. Anything else runs the tool, null from a listener that
 * forgot to return included, so a broken listener is never an outage of every tool.
 *
 * @since 0.8.0
 */
final class Guarded implements ToolInterface
{
    public function __construct(private ToolInterface $inner, private string $toolkit, private int $userId) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function description(): string
    {
        return $this->inner->description();
    }

    public function parameters(): array
    {
        return $this->inner->parameters();
    }

    public function toFunctionSchema(): array
    {
        return $this->inner->toFunctionSchema();
    }

    /** @param array<string, mixed> $input */
    public function execute(array $input): ToolResult
    {
        $name = $this->inner->name();
        /**
         * Filters whether the model's call to one tool may run, before it does. Return a WP_Error
         * to refuse it: the tool does not run, and the error's message is the tool's answer to the
         * model, so word it for the model ("Ask an editor to publish this."). `false` refuses too,
         * with a sentence of the plugin's own. Anything else lets the call run, null included.
         * Fires for every tool the agent loop is handed, whichever toolkit offered it (the abilities
         * toolkit's `ability__…` tools and each MCP server's `mcp.{id}` included), and not for a
         * surface that runs a tool on a person's own request (Toolkit\Guarded says which). An
         * ability's own permission callback, and on WordPress 7.1 core's `wp_pre_execute_ability`,
         * still run after this says yes.
         *
         * @since 0.8.0
         * @param mixed                $verdict  null; return a WP_Error or false to refuse
         * @param string               $toolkit  the id the toolkit was enabled under: `web_fetch`, `summarize`, `draft_post`, `abilities`, `mcp.{id}`, or a companion's
         * @param string               $tool     the tool's name as the model called it
         * @param array<string, mixed> $args     what the model sent
         * @param int                  $userId   the user whose turn it is
         */
        $verdict = apply_filters('alpaca_bot/tool/before_call', null, $this->toolkit, $name, $input, $this->userId);
        $refusal = null;
        if ($verdict instanceof \WP_Error || $verdict === false) {
            $message = $verdict instanceof \WP_Error ? $verdict->get_error_message() : '';
            /* translators: %s: the tool's name, e.g. web_fetch */
            $message = $message !== '' ? $message : sprintf(__('The site refused this call to %s.', 'alpaca-bot'), $name);
            $refusal = $verdict instanceof \WP_Error ? $verdict : new \WP_Error('alpaca_bot_tool_refused', $message);
            $result = ToolResult::error($message);
        } else {
            $result = $this->inner->execute($input);
        }
        /**
         * Fires after the model's call to one tool has been answered, by the tool or by a refusal
         * from `alpaca_bot/tool/before_call`: the hook for an audit trail of every call. It fires
         * in-process and stores nothing; the reply's `meta.tool_calls` is the plugin's own record.
         * A tool that throws fires nothing here. An MCP server's calls fire
         * `alpaca_bot/mcp/called` as well, from inside the call, with the server's own tool name.
         *
         * @since 0.8.0
         * @param string               $toolkit the id the toolkit was enabled under
         * @param string               $tool    the tool's name as the model called it
         * @param array<string, mixed> $args    what the model sent
         * @param int                  $userId  the user whose turn it is
         * @param ToolResult           $result  what the model is handed back
         * @param \WP_Error|null       $refusal the refusal when before_call refused the call, else null
         */
        do_action('alpaca_bot/tool/called', $this->toolkit, $name, $input, $this->userId, $result, $refusal);
        return $result;
    }
}
