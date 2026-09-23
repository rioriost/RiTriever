<?php
/**
 * Connection-owned locks without expiring leases or reconnect replay.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever\Database;

final class DatabaseLock
{
    private string $name;
    private int $owner;
    private bool $released = false;

    private function __construct(string $name, int $owner)
    {
        $this->name = $name;
        $this->owner = $owner;
    }

    public static function acquire(string $scope, int $timeout = 0): ?self
    {
        global $wpdb;
        Sql::pin();
        try {
            $name = "ritriever:" . substr(hash("sha256", (defined("DB_NAME") ? DB_NAME : "") . "|" . $wpdb->prefix . "|" . $scope), 0, 48);
            $owner = (int) Sql::value("SELECT CONNECTION_ID()");
            if ((string) Sql::value($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $name, $timeout)) !== "1") {
                Sql::unpin();
                return null;
            }
            $lock = new self($name, $owner);
            $lock->assert_owned();
            return $lock;
        } catch (\Throwable $e) {
            Sql::unpin();
            throw $e;
        }
    }

    public function assert_owned(): void
    {
        global $wpdb;
        if ($this->released || (int) Sql::value($wpdb->prepare("SELECT IS_USED_LOCK(%s)", $this->name)) !== $this->owner || (int) Sql::value("SELECT CONNECTION_ID()") !== $this->owner) {
            throw new \RuntimeException("Database lock ownership was lost; work will be retried.");
        }
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        global $wpdb;
        try {
            $this->assert_owned();
            Sql::value($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $this->name));
        } catch (\RuntimeException $ignored) {
            // Never release a replacement session's lock.
        } finally {
            $this->released = true;
            Sql::unpin();
        }
    }

    public static function with(string $scope, callable $callback, int $timeout = 10)
    {
        $lock = self::acquire($scope, $timeout);
        if ($lock === null) {
            throw new \RuntimeException("Index is busy; retry after the current database operation.");
        }
        try {
            return $callback($lock);
        } finally {
            $lock->release();
        }
    }
}
