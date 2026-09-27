<?php

namespace WPMCP\Pro\Chat;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Drives the chat conversation one model step at a time (issue #73).
 *
 * One step is at most one provider request, followed by the tool calls that
 * response proposed. Keeping each HTTP request to a single provider call is
 * what makes the loop safe on shared hosting: the browser asks for the next
 * step (/chat/continue) until the model is done, instead of one PHP request
 * holding a multi-minute agent loop open.
 *
 * Trust boundary, stated once: the model only proposes. Every proposed call
 * is executed by Tool_Executor, which resolves it against the governed
 * inventory and runs the registered ability through its own permission and
 * rate-limit wrapper under the chat identity. A call that changes the site is
 * NOT executed in the step at all. It is parked as a server-stored proposal
 * (ability name and arguments exactly as the model sent them) and a
 * single-use Approval_Gate token bound to (user, ability, args) is returned
 * to the administrator's browser. Only approve() can run it, it runs the
 * STORED arguments, never anything the client re-sends, and it needs both
 * the token and the proposal id it was minted for. Injected text in a tool
 * result can therefore at most cause a proposal the administrator sees and
 * can decline; it cannot reach a write.
 */
final class Turn_Runner
{
    /** The model has answered; nothing further is owed. */
    public const DONE = 'done';
    /** Tool results were recorded; ask for another step to let the model continue. */
    public const CONTINUE = 'continue';
    /** One or more calls wait for the administrator. */
    public const APPROVAL_REQUIRED = 'approval_required';
    /** The step failed; see 'error'. */
    public const ERROR = 'error';

    /** Model steps allowed after one user message before the loop stops itself. */
    public const MAX_ROUNDS = 20;

    /** Byte ceiling on one tool result sent back to the model. */
    public const MAX_RESULT_BYTES = 8000;

    public function __construct(
        private Conversation_Store $store,
        private Key_Vault $vault,
        private Chat_Provider $provider,
        private ?Tool_Executor $executor = null,
        private ?Tool_Inventory $inventory = null,
        private ?Approval_Gate $gate = null
    ) {
    }

    private function inventory(): Tool_Inventory
    {
        return $this->inventory ??= new Tool_Inventory();
    }

    private function gate(): Approval_Gate
    {
        return $this->gate ??= new Approval_Gate();
    }

    private function executor(): Tool_Executor
    {
        return $this->executor ??= new Tool_Executor($this->inventory(), $this->gate());
    }

    /**
     * Runs one model step for a conversation the caller owns.
     *
     * @return array<string, mixed>
     */
    public function step(int $user_id, int $conversation_id): array
    {
        if (! $this->store->is_owned_by($conversation_id, $user_id)) {
            return self::error('invalid_conversation');
        }

        $state = $this->store->get_state($conversation_id, $user_id);
        if (! empty($state['pending']['proposals'])) {
            return [
                'status'    => self::APPROVAL_REQUIRED,
                'proposals' => $this->reissue_proposals($user_id, $conversation_id, $state),
            ];
        }

        $entries  = $this->store->get_messages($conversation_id, $user_id);
        $messages = self::provider_messages($entries);
        if ([] === $messages) {
            return self::error('empty_conversation');
        }
        $last = $messages[ count($messages) - 1 ];
        if ('user' !== $last['role']) {
            // Nothing is owed: the model already answered the latest turn.
            return ['status' => self::DONE, 'reply' => ''];
        }
        if (self::rounds_since_user_text($entries) >= self::MAX_ROUNDS) {
            return self::error('round_limit');
        }

        try {
            $key = $this->vault->get_key($user_id);
        } catch (Key_Vault_Corrupted_Exception) {
            $status = $this->vault->get_status($user_id);
            return self::error('no_usable_provider_key', ['key_status' => (string) ($status['status'] ?? 'corrupted')]);
        }
        if (null === $key || '' === $key) {
            return self::error('no_usable_provider_key', ['key_status' => 'missing']);
        }

        $advertised = Chat_Identity::run(fn () => $this->inventory()->advertised());
        $domains    = array_values(array_filter((array) ($state['domains'] ?? []), 'is_string'));
        $system     = System_Prompt::build($user_id, Tool_Inventory::by_domain($advertised));
        $tools      = Tool_Inventory::definitions($advertised, $domains);

        $response = $this->provider->send($key, $system, $messages, $tools);
        unset($key);
        if (is_wp_error($response)) {
            return self::error('provider_error', [
                'message' => $response->get_error_message(),
            ]);
        }

        $content = array_values(array_filter((array) $response['content'], 'is_array'));
        $stop    = (string) $response['stop_reason'];

        if ('max_tokens' === $stop) {
            // A tool_use cut off mid-generation can carry truncated input.
            // Never run it; keep only the text.
            $content = array_values(array_filter(
                $content,
                static fn (array $b): bool => 'tool_use' !== ($b['type'] ?? '')
            ));
        }
        if ([] === $content) {
            $content = [['type' => 'text', 'text' => 'refusal' === $stop
                ? '[The model declined to answer this request.]'
                : '[The model returned an empty response.]']];
        }

        $reply    = self::text_of($content);
        $appended = $this->store->append_message($conversation_id, $user_id, [
            'role'    => 'assistant',
            'content' => $reply,
            'blocks'  => $content,
        ]);
        if (! $appended) {
            return self::error('store_failed');
        }

        $tool_uses = array_values(array_filter(
            $content,
            static fn (array $b): bool => 'tool_use' === ($b['type'] ?? '') && isset($b['id'], $b['name'])
        ));
        if ([] === $tool_uses) {
            return ['status' => self::DONE, 'reply' => $reply, 'stop_reason' => $stop];
        }

        $order     = [];
        $results   = [];
        $proposals = [];
        $events    = [];
        foreach ($tool_uses as $use) {
            $id    = (string) $use['id'];
            $tool  = (string) $use['name'];
            $input = is_array($use['input'] ?? null) ? $use['input'] : [];
            $order[] = $id;

            if (Tool_Inventory::LOAD_TOOLS === $tool) {
                $requested = array_values(array_filter((array) ($input['domains'] ?? []), 'is_string'));
                $known     = array_keys(Tool_Inventory::by_domain($advertised));
                $loaded    = array_values(array_intersect($requested, $known));
                $domains   = array_values(array_unique(array_merge($domains, $loaded)));
                $results[ $id ] = self::result_block(
                    $id,
                    [] === $loaded
                        ? 'No matching domains. Available: ' . implode(', ', $known)
                        : 'Loaded: ' . implode(', ', $loaded) . '. Their tools are available from your next step.',
                    [] === $loaded
                );
                continue;
            }

            $ability = $advertised[ $tool ] ?? null;
            if (null === $ability) {
                $results[ $id ] = self::result_block($id, sprintf('The tool %s is not available to this chat.', $tool), true);
                $events[]       = ['tool' => $tool, 'status' => 'unavailable'];
                continue;
            }

            if (Tool_Executor::requires_approval($ability)) {
                $proposals[ $id ] = [
                    'tool'    => $tool,
                    'ability' => $ability->name,
                    'args'    => $input,
                ];
                continue;
            }

            $outcome        = $this->executor()->execute($user_id, $ability->name, $input);
            $results[ $id ] = self::outcome_block($id, $outcome);
            $events[]       = self::event($tool, $outcome);
        }

        $state['domains'] = $domains;

        if ([] !== $proposals) {
            $state['pending'] = [
                'order'     => $order,
                'results'   => $results,
                'proposals' => $proposals,
            ];
            if (! $this->store->set_state($conversation_id, $user_id, $state)) {
                return self::error('store_failed');
            }
            return [
                'status'      => self::APPROVAL_REQUIRED,
                'reply'       => $reply,
                'tool_events' => $events,
                'proposals'   => $this->reissue_proposals($user_id, $conversation_id, $state),
            ];
        }

        $this->store->set_state($conversation_id, $user_id, $state);
        if (! $this->append_results($conversation_id, $user_id, $order, $results)) {
            return self::error('store_failed');
        }

        return ['status' => self::CONTINUE, 'reply' => $reply, 'tool_events' => $events];
    }

    /**
     * Approves or declines one parked proposal.
     *
     * The client names the proposal and, to approve, presents the token that
     * was minted for it. The arguments executed are the ones stored with the
     * proposal; nothing the client sends here can change them.
     *
     * @return array<string, mixed>
     */
    public function resolve(int $user_id, int $conversation_id, string $tool_use_id, bool $approve, string $token = ''): array
    {
        if (! $this->store->is_owned_by($conversation_id, $user_id)) {
            return self::error('invalid_conversation');
        }

        $state    = $this->store->get_state($conversation_id, $user_id);
        $pending  = is_array($state['pending'] ?? null) ? $state['pending'] : [];
        $proposal = $pending['proposals'][ $tool_use_id ] ?? null;
        if (! is_array($proposal)) {
            return self::error('unknown_proposal');
        }

        $event = ['tool' => (string) $proposal['tool'], 'status' => 'declined'];
        if ($approve) {
            $hash = (string) ($proposal['token_hash'] ?? '');
            if ('' === $token || '' === $hash || ! hash_equals($hash, hash('sha256', $token))) {
                return self::error('invalid_approval');
            }
            $outcome = $this->executor()->execute(
                $user_id,
                (string) $proposal['ability'],
                is_array($proposal['args']) ? $proposal['args'] : [],
                $token
            );
            $spent = Tool_Executor::APPROVAL_REQUIRED === $outcome['status']
                || (Tool_Executor::ERROR === $outcome['status'] && 'invalid_approval' === ($outcome['code'] ?? ''));
            if ($spent) {
                // Expired or already used: the proposal stays parked so the
                // administrator can approve it again with a fresh token.
                return self::error('invalid_approval', [
                    'proposals' => $this->reissue_proposals($user_id, $conversation_id, $state),
                ]);
            }
            $pending['results'][ $tool_use_id ] = self::outcome_block($tool_use_id, $outcome);
            $event = self::event((string) $proposal['tool'], $outcome);
        } else {
            $pending['results'][ $tool_use_id ] = self::result_block(
                $tool_use_id,
                'The administrator declined this call. Do not retry it; ask what they want instead.',
                true
            );
        }

        unset($pending['proposals'][ $tool_use_id ]);

        if ([] !== $pending['proposals']) {
            $state['pending'] = $pending;
            if (! $this->store->set_state($conversation_id, $user_id, $state)) {
                return self::error('store_failed');
            }
            return [
                'status'      => self::APPROVAL_REQUIRED,
                'tool_events' => [$event],
                'remaining'   => array_keys($pending['proposals']),
            ];
        }

        unset($state['pending']);
        if (! $this->store->set_state($conversation_id, $user_id, $state)) {
            return self::error('store_failed');
        }
        if (! $this->append_results($conversation_id, $user_id, (array) $pending['order'], (array) $pending['results'])) {
            return self::error('store_failed');
        }

        return ['status' => self::CONTINUE, 'tool_events' => [$event]];
    }

    /**
     * The parked proposals with a freshly minted token each, for display.
     * Minting replaces the stored token hash, so only the newest token for a
     * proposal is accepted and a reload of the chat screen can still approve.
     *
     * @param array<string, mixed> $state
     * @return array<int, array<string, mixed>>
     */
    public function reissue_proposals(int $user_id, int $conversation_id, array $state): array
    {
        $out = [];
        if (empty($state['pending']['proposals']) || ! is_array($state['pending']['proposals'])) {
            return $out;
        }
        foreach ($state['pending']['proposals'] as $id => $proposal) {
            $args = is_array($proposal['args'] ?? null) ? $proposal['args'] : [];
            try {
                $token = $this->gate()->issue_token($user_id, (string) $proposal['ability'], $args);
            } catch (\Throwable) {
                continue;
            }
            $state['pending']['proposals'][ $id ]['token_hash'] = hash('sha256', $token);
            $out[] = [
                'tool_use_id'    => (string) $id,
                'tool'           => (string) $proposal['tool'],
                'ability'        => (string) $proposal['ability'],
                'args'           => $args,
                'approval_token' => $token,
            ];
        }
        $this->store->set_state($conversation_id, $user_id, $state);
        return $out;
    }

    /**
     * Converts stored history into a valid provider message list.
     *
     * History is trimmed from the front and a request can die between
     * writes, so the stored list is repaired rather than trusted: it starts
     * at the first plain user turn, every assistant tool_use is answered by a
     * tool_result (a synthetic error for any that never ran), orphan tool
     * results are dropped, and consecutive same-role turns are merged.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array{role: string, content: array<int, mixed>}>
     */
    public static function provider_messages(array $entries): array
    {
        $out     = [];
        $started = false;
        $owed    = [];

        foreach ($entries as $entry) {
            $role = (string) ($entry['role'] ?? '');

            if ('user' === $role) {
                $text = (string) ($entry['content'] ?? '');
                if ('' === $text) {
                    continue;
                }
                $started = true;
                self::settle($out, $owed, []);
                self::push($out, 'user', [['type' => 'text', 'text' => $text]]);
                continue;
            }
            if (! $started) {
                continue;
            }

            if ('assistant' === $role) {
                self::settle($out, $owed, []);
                $blocks = isset($entry['blocks']) && is_array($entry['blocks'])
                    ? self::outbound_blocks($entry['blocks'])
                    : [['type' => 'text', 'text' => (string) ($entry['content'] ?? '')]];
                $blocks = array_values(array_filter(
                    $blocks,
                    static fn ($b): bool => is_array($b) && ! ('text' === ($b['type'] ?? '') && '' === (string) ($b['text'] ?? ''))
                ));
                if ([] === $blocks) {
                    continue;
                }
                self::push($out, 'assistant', $blocks);
                foreach ($blocks as $b) {
                    if ('tool_use' === ($b['type'] ?? '') && isset($b['id'])) {
                        $owed[] = (string) $b['id'];
                    }
                }
                continue;
            }

            if ('tool' === $role) {
                $results = [];
                foreach ((array) ($entry['blocks'] ?? []) as $b) {
                    if (is_array($b) && 'tool_result' === ($b['type'] ?? '') && in_array((string) ($b['tool_use_id'] ?? ''), $owed, true)) {
                        $results[] = $b;
                    }
                }
                self::settle($out, $owed, $results);
            }
        }
        self::settle($out, $owed, []);

        return $out;
    }

    /**
     * Answers every owed tool_use: the given results first, then a synthetic
     * error for each call that has none.
     *
     * @param array<int, array<string, mixed>> $out
     * @param string[]                         $owed
     * @param array<int, array<string, mixed>> $results
     */
    private static function settle(array &$out, array &$owed, array $results): void
    {
        if ([] === $owed) {
            return;
        }
        $have = [];
        foreach ($results as $r) {
            $have[] = (string) $r['tool_use_id'];
        }
        foreach ($owed as $id) {
            if (! in_array($id, $have, true)) {
                $results[] = self::result_block($id, 'This call was not executed.', true);
            }
        }
        $owed = [];
        self::push($out, 'user', $results);
    }

    /**
     * @param array<int, array<string, mixed>> $out
     * @param array<int, mixed>                $content
     */
    private static function push(array &$out, string $role, array $content): void
    {
        $n = count($out);
        if ($n > 0 && $out[ $n - 1 ]['role'] === $role) {
            $out[ $n - 1 ]['content'] = array_merge($out[ $n - 1 ]['content'], $content);
            return;
        }
        $out[] = ['role' => $role, 'content' => $content];
    }

    /**
     * JSON decoding turns an empty tool input object into an empty PHP
     * array, which would re-encode as a list and be rejected. Objects are
     * restored only here, on the way out, so stdClass never reaches post meta
     * (where update_metadata()'s unslash would walk into it unslashed).
     *
     * @param array<int, mixed> $blocks
     * @return array<int, mixed>
     */
    private static function outbound_blocks(array $blocks): array
    {
        foreach ($blocks as $i => $b) {
            if (is_array($b) && 'tool_use' === ($b['type'] ?? '')) {
                $input = $b['input'] ?? [];
                if (! is_array($input) || [] === $input) {
                    $blocks[ $i ]['input'] = new \stdClass();
                }
            }
        }
        return $blocks;
    }

    /** @param array<int, array<string, mixed>> $entries */
    private static function rounds_since_user_text(array $entries): int
    {
        $rounds = 0;
        for ($i = count($entries) - 1; $i >= 0; $i--) {
            $role = (string) ($entries[ $i ]['role'] ?? '');
            if ('user' === $role) {
                break;
            }
            if ('assistant' === $role) {
                $rounds++;
            }
        }
        return $rounds;
    }

    /**
     * @param string[]                            $order
     * @param array<string, array<string, mixed>> $results
     */
    private function append_results(int $conversation_id, int $user_id, array $order, array $results): bool
    {
        $blocks = [];
        foreach ($order as $id) {
            $blocks[] = $results[ $id ] ?? self::result_block((string) $id, 'This call was not executed.', true);
        }
        return $this->store->append_message($conversation_id, $user_id, [
            'role'    => 'tool',
            'content' => '',
            'blocks'  => $blocks,
        ]);
    }

    /** @param array<int, array<string, mixed>> $content */
    private static function text_of(array $content): string
    {
        $parts = [];
        foreach ($content as $b) {
            if ('text' === ($b['type'] ?? '') && '' !== (string) ($b['text'] ?? '')) {
                $parts[] = (string) $b['text'];
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * A tool_result block. Successful output is labelled as untrusted data
     * so the system prompt's rule has something concrete to point at.
     *
     * @return array<string, mixed>
     */
    private static function result_block(string $id, string $text, bool $is_error = false): array
    {
        if (strlen($text) > self::MAX_RESULT_BYTES) {
            $text = mb_strcut($text, 0, self::MAX_RESULT_BYTES, 'UTF-8') . "\n[truncated]";
        }
        $block = ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => $text];
        if ($is_error) {
            $block['is_error'] = true;
        }
        return $block;
    }

    /**
     * @param array<string, mixed> $outcome
     * @return array<string, mixed>
     */
    private static function outcome_block(string $id, array $outcome): array
    {
        if (Tool_Executor::OK === $outcome['status']) {
            $json = wp_json_encode($outcome['result'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return self::result_block(
                $id,
                "<untrusted_tool_output>\n" . (false === $json ? 'null' : $json) . "\n</untrusted_tool_output>"
            );
        }
        return self::result_block(
            $id,
            sprintf('Error (%s): %s', (string) ($outcome['code'] ?? 'error'), (string) ($outcome['message'] ?? '')),
            true
        );
    }

    /**
     * @param array<string, mixed> $outcome
     * @return array<string, mixed>
     */
    private static function event(string $tool, array $outcome): array
    {
        $event = ['tool' => $tool, 'status' => (string) $outcome['status']];
        if (Tool_Executor::ERROR === $outcome['status']) {
            $event['code'] = (string) ($outcome['code'] ?? '');
        }
        if (Tool_Executor::OK === $outcome['status'] && is_array($outcome['result'] ?? null) && ! empty($outcome['result']['operation_id'])) {
            $event['operation_id'] = (string) $outcome['result']['operation_id'];
        }
        return $event;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function error(string $code, array $extra = []): array
    {
        return array_merge(['status' => self::ERROR, 'error' => $code], $extra);
    }
}
