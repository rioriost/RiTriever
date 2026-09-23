<?php

if (getenv("RITRIEVER_INTEGRATION_TEST") !== "1" || !defined("WP_CLI")) {
    throw new RuntimeException("Isolated WP-CLI integration runner required.");
}
$phase = $args[0] ?? "probe";
$lock = \RiTriever\Database\DatabaseLock::acquire("integration-contention", 0);
if ($phase === "hold") {
    if ($lock === null) {
        throw new RuntimeException("Could not acquire fixture lock.");
    }
    try {
        file_put_contents("/tmp/ritriever-fixture-lock-held", "ready");
        sleep(12);
        $lock->assert_owned();
    } finally {
        $lock->release();
        unlink("/tmp/ritriever-fixture-lock-held");
    }
    WP_CLI::success("Original database session retained and released its lock");
} elseif ($phase === "probe") {
    if ($lock !== null) {
        $lock->release();
        throw new RuntimeException("Two separate database sessions acquired one exclusive lock.");
    }
    WP_CLI::success("Competing database session was correctly denied the lock");
} elseif ($phase === "released") {
    if ($lock === null) {
        throw new RuntimeException("Released lock remained unavailable.");
    }
    $lock->release();
    WP_CLI::success("Released lock can be acquired by a new session");
} else {
    throw new RuntimeException("Unknown lock fixture phase.");
}
