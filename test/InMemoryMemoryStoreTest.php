<?php

declare(strict_types=1);

namespace Mercurius\Memory\Test;

use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;
use Mercurius\Memory\Testing\MemoryStoreContract;

final class InMemoryMemoryStoreTest extends MemoryStoreContract {
    protected function setUp(): void {
        parent::setUp();
        $this->store = new InMemoryMemoryStore();
        $this->conversationId = 1;
    }
}
