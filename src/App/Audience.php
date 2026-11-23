<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

/**
 * Which memory a conversation may see: what is known is disclosed by the weakest listener.
 *
 * spec: brief/participants.md#контекст-по-месту-и-аудитории — the owner's facts never reach a group or another participant
 */
final class Audience {
    public const OWNER = 'owner';

    /**
     * @param int|null $projectId the active project of the chat, when the participant may see it: its facts are read
     *                            here too (spec: brief/memory.md#проекты-и-отношение-участника-к-проекту)
     * @return list<string> scopes readable here; the first one is where new facts of this place go
     */
    public static function scopes(bool $owner, int $principalId, string $channel, string $chatId, bool $privateChat, ?int $projectId = null): array {
        $chat = self::chat($channel, $chatId);
        $scopes = !$privateChat ? [$chat] : ($owner ? [self::OWNER, $chat] : [self::principal($principalId), $chat]);
        if ($projectId !== null) {
            // spec: brief/memory.md#проекты-и-отношение-участника-к-проекту — in a group or a topic of a project a new
            //       fact is the project's; a private chat keeps the person's own memory first
            if ($privateChat) {
                $scopes[] = self::project($projectId);
            } else {
                array_unshift($scopes, self::project($projectId));
            }
        }
        return $scopes;
    }

    public static function project(int $projectId): string {
        return 'project:' . $projectId;
    }

    public static function chat(string $channel, string $chatId): string {
        return sprintf('chat:%s:%s', $channel, $chatId);
    }

    public static function principal(int $principalId): string {
        return 'principal:' . $principalId;
    }
}
