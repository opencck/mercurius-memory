<?php

declare(strict_types=1);

namespace Mercurius\Memory\App;

use Mercurius\Memory\Domain\Proposal;

/** What the owner's press did: the decided proposal and what actually happened to memory. */
final class Decision {
    public function __construct(
        public readonly Proposal $proposal,
        /** memory changed */
        public readonly bool $applied,
        /** for the owner: what happened */
        public readonly string $outcome,
    ) {}
}
