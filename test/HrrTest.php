<?php

declare(strict_types=1);

namespace Mercurius\Memory\Test;

use Mercurius\Memory\Domain\Hrr;
use PHPUnit\Framework\TestCase;

/**
 * The golden numbers come from a plain-Python copy of holographic.py@9b49943 (encode_atom … encode_fact).
 */
final class HrrTest extends TestCase {
    public function testAtomsMatchTheReference(): void {
        $atom = Hrr::atom('сервер');

        self::assertCount(Hrr::DIM, $atom);
        self::assertEqualsWithDelta(0.32165659646, $atom[0], 1e-9);
        self::assertEqualsWithDelta(3.14657809115, $atom[1], 1e-9);
        self::assertEqualsWithDelta(2.086022123926, $atom[2], 1e-9);
        self::assertEqualsWithDelta(5.59423618582, $atom[1023], 1e-9);
    }

    public function testFactQueriesMatchTheReference(): void {
        $fact = Hrr::fact(['сервер', 'pve-01', 'хельсинк']);

        self::assertEqualsWithDelta(0.415011248082, Hrr::similarity($fact, Hrr::query(['сервер', 'хельсинк'])), 1e-6);
        self::assertEqualsWithDelta(0.63425356173, Hrr::similarity($fact, Hrr::probes('pve-01')[0]), 1e-6);
        self::assertEqualsWithDelta(-0.043474194162, Hrr::similarity($fact, Hrr::probes('pve-02')[0]), 1e-6);
        self::assertEqualsWithDelta(0.049531985042, Hrr::similarity($fact, Hrr::query(['кофе'])), 1e-6);
    }

    public function testPhasorInnerProductIsTheSimilarity(): void {
        $a = Hrr::fact(['диск', 'nvme', 'pve-01']);
        $b = Hrr::query(['диск', 'nvme']);

        self::assertEqualsWithDelta(Hrr::similarity($a, $b), Hrr::dot(Hrr::phasor($a), Hrr::phasor($b)), 1e-9);
        self::assertCount(2 * Hrr::DIM, Hrr::phasor($a));
    }

    public function testRelatedWordsStandAboveTheNoise(): void {
        $fact = Hrr::fact(['бэкап', 'база', 'ночь']);

        self::assertGreaterThan(Hrr::RELATED_THRESHOLD, Hrr::similarity($fact, Hrr::probes('бэкап')[1]));
        self::assertLessThan(Hrr::RELATED_THRESHOLD, Hrr::similarity($fact, Hrr::probes('кофе')[1]));
    }
}
