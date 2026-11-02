<?php

namespace Tests\MySql;

trait UsesLogisticsDatabase
{
    private static $logisticsRunnerLock;

    private static bool $logisticsSchemaPrepared = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$logisticsRunnerLock = fopen(dirname(__DIR__, 2).'/.codex/t05-runner.lock', 'r');
        if (! self::$logisticsRunnerLock || ! flock(self::$logisticsRunnerLock, LOCK_EX | LOCK_NB)) {
            self::markTestSkipped('The dedicated MySQL runner is already in use.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$logisticsRunnerLock)) {
            flock(self::$logisticsRunnerLock, LOCK_UN);
            fclose(self::$logisticsRunnerLock);
        }
        parent::tearDownAfterClass();
    }

    public function createApplication()
    {
        return Preflight::application();
    }

    /** Run the same service/HTTP tests on the owned disposable InnoDB schema. */
    public function refreshDatabase(): void
    {
        if (! self::$logisticsSchemaPrepared) {
            Preflight::prepareSchema();
            Preflight::clean();
            self::$logisticsSchemaPrepared = true;
        }
        $this->beginDatabaseTransaction();
    }
}
