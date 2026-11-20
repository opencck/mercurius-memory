<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

use Mercurius\Memory\Domain\MemoryStore;

use function Mercurius\Memory\t;

/**
 * What memory adds to a run: layer 1 for the system prompt and the facts that answer the message.
 *
 * spec: brief/memory.md#память-слои — layer 1 is always in context for the owner; facts come before the turn
 * ref: references/hermes-agent/agent/turn_context.py@e96221fa:L125-L136 (compose_user_api_content) — recalled memory
 *      goes after the user's text, marked as reference data, and is kept in the message so the next turn replays the
 *      same bytes; here it is kept in the transcript
 */
final class MemoryContext {
    public const PROFILE = 'profile';
    public const SUMMARY = 'summary';

    public function __construct(
        private readonly MemoryStore $store,
        private readonly Recall $recall,
    ) {}

    /**
     * @param list<string> $scopes
     * @return string facts as lines, '' when nothing answers the message
     */
    public function facts(string $message, array $scopes, int $conversationId): string {
        return Recall::format($this->recall->find($message, $scopes, $conversationId));
    }

    /**
     * Facts found by words, as recall finds them, without marking them recalled in a conversation.
     *
     * @param list<string> $scopes
     * @return list<\Mercurius\Memory\Domain\Fact>
     */
    public function search(string $words, array $scopes, int $budget): array {
        return $this->recall->find($words, $scopes, null, $budget);
    }

    /**
     * Layer 1: the profile and the summary of what is going on — the owner's, or a participant's own in their private
     * chat; '' while both are empty.
     *
     * spec: brief/participants.md#несколько-пользователей — each user has their own layered memory
     */
    public function core(string $scope = Audience::OWNER): string {
        $parts = [];
        $who = $scope === Audience::OWNER ? t('app.memory_context.vladeltsa') : t('app.memory_context.sobesednika');
        foreach ([self::PROFILE => t('app.memory_context.profil') . $who, self::SUMMARY => t('app.memory_context.tekuschie_dela')] as $section => $title) {
            $text = trim($this->store->core(self::section($section, $scope)));
            if ($text !== '') {
                $parts[] = $title . ":\n" . $text;
            }
        }
        return implode("\n\n", $parts);
    }

    /** The section of layer 1 for a scope: the owner keeps the plain names. */
    public static function section(string $section, string $scope): string {
        return $scope === Audience::OWNER ? $section : $section . '@' . $scope;
    }
}
