<?php
/**
 * Checked database operations, pinned during advisory-lock critical sections.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever\Database;

final class Sql
{
    private static $connection = null;
    private static int $depth = 0;

    public static function pin(): void
    {
        global $wpdb;
        if (self::$depth++ === 0) {
            self::$connection = $wpdb->dbh ?? null;
        }
    }

    public static function unpin(): void
    {
        if (--self::$depth === 0) {
            self::$connection = null;
        }
    }

    public static function query(string $sql): int
    {
        global $wpdb;
        if (self::$connection instanceof \mysqli) {
            // A wpdb reconnect could replay a transaction statement in autocommit.
            // Use the original connection only; a lost session must fail closed.
            try {
                // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Callers pass wpdb-prepared SQL or fixed transaction statements; wpdb reconnect/replay would violate atomicity on this pinned session.
                $result = mysqli_query(self::$connection, $sql);
            } catch (\mysqli_sql_exception $e) {
                throw new \RuntimeException("Database operation failed (code " . (int) $e->getCode() . ").", (int) $e->getCode());
            } catch (\Error $e) {
                self::throw_session_error($e);
            }
            if ($result === false) {
                // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- Read the original pinned session's numeric error without reconnecting or exposing SQL text.
                throw new \RuntimeException("Database operation failed (code " . (int) mysqli_errno(self::$connection) . ").", (int) mysqli_errno(self::$connection));
            }
            if ($result instanceof \mysqli_result) {
                $result->free();
            }
            // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_affected_rows -- The affected-row count must belong to the pinned session, not a reconnected wpdb handle.
            return max(0, mysqli_affected_rows(self::$connection));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal callers prepare identifiers/values before this checked adapter; transaction/control mutations cannot use cached results.
        $result = $wpdb->query($sql);
        if ($result === false) {
            throw new \RuntimeException("Database operation failed; check database availability and schema.");
        }
        return (int) $result;
    }

    /** @return array<int,array<string,mixed>> */
    public static function rows(string $sql): array
    {
        global $wpdb;
        if (self::$connection instanceof \mysqli) {
            try {
                // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Callers prepare SQL before this adapter; lock ownership and transaction reads must use the original session without wpdb reconnect.
                $result = mysqli_query(self::$connection, $sql);
            } catch (\mysqli_sql_exception $e) {
                throw new \RuntimeException("Database read failed (code " . (int) $e->getCode() . ").", (int) $e->getCode());
            } catch (\Error $e) {
                self::throw_session_error($e);
            }
            if (!($result instanceof \mysqli_result)) {
                throw new \RuntimeException("Database read failed.");
            }
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
            return $rows;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal callers prepare SQL; current generations, lock ownership, receipts, and queue state require fresh database reads.
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error !== "") {
            throw new \RuntimeException("Database read failed; check database availability and schema.");
        }
        return $rows;
    }

    public static function value(string $sql)
    {
        $rows = self::rows($sql);
        return $rows === [] ? null : reset($rows[0]);
    }

    private static function throw_session_error(\Error $error): never
    {
        if (!($error instanceof \TypeError) && str_contains(strtolower($error->getMessage()), "mysqli object is already closed")) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed safe message; the third argument preserves the closed-session cause, not output.
            throw new \RuntimeException("Database session closed; pinned work must be retried.", 2006, $error);
        }
        throw $error;
    }

    public static function transaction(callable $callback)
    {
        // Source-range locks must also fence newly inserted meta/relationships.
        // This fails (rather than implicitly committing) inside a caller's transaction.
        self::query("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        self::query("START TRANSACTION");
        try {
            $result = $callback();
            self::query("COMMIT");
            return $result;
        } catch (\Throwable $e) {
            try {
                self::query("ROLLBACK");
            } catch (\Throwable $ignored) {
                // COMMIT connection loss is ambiguous; a later receipt read reconciles it.
            }
            throw $e;
        }
    }
}
