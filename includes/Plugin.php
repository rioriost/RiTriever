<?php
/**
 * Main plugin bootstrap.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever;

use RiTriever\Admin\SettingsPage;
use RiTriever\CLI\BackfillCommand;
use RiTriever\Database\BackfillQueueSchema;
use RiTriever\Database\VectorSchema;

final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;

    private function __construct() {}

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        self::install_current_site();
        PostSync::register();
        SearchInterceptor::register();
        BackfillRunner::register();

        if (is_admin()) {
            SettingsPage::register();
        }

        if (defined("WP_CLI") && WP_CLI) {
            BackfillCommand::register();
        }

        add_action(
            "update_option_" . RITRIEVER_OPTION_KEY,
            [self::class, "on_settings_updated"],
            10,
            2,
        );
        add_action("switch_blog", [self::class, "on_switch_blog"]);
        add_action("wp_initialize_site", [self::class, "on_initialize_site"], 200);
        add_action("admin_notices", [self::class, "setup_notice"]);

        $this->booted = true;
        Logger::debug("plugin", "boot complete", [
            "version" => RITRIEVER_VERSION,
        ]);
    }

    public static function on_settings_updated($old_value, $value): void
    {
        Settings::clear_cache();
        if (!is_array($old_value) || !is_array($value)) {
            return;
        }

        if (IndexState::fingerprint($old_value) !== IndexState::fingerprint($value)) {
            IndexState::invalidate("Index settings changed. Run initialization again.");
            return;
        }

        foreach ([
            "top_k", "min_score", "cache_ttl_seconds",
            "japanese_normalization_enabled", "search_mode", "kill_switch_global",
        ] as $key) {
            if (($old_value[$key] ?? null) !== ($value[$key] ?? null)) {
                SearchInterceptor::purge_query_cache();
                break;
            }
        }

    }

    public static function install_current_site(): void
    {
        Settings::install_or_upgrade();
        try {
            IndexState::ensure_current();
            VectorSchema::install_or_upgrade();
            BackfillQueueSchema::install_or_upgrade();
            delete_option("ritriever_setup_error");
        } catch (\RuntimeException $error) {
            update_option("ritriever_setup_error", $error->getMessage(), false);
            Logger::error("setup", $error->getMessage());
        }
    }

    public static function setup_notice(): void
    {
        $error = get_option("ritriever_setup_error", "");
        if ($error && current_user_can(RITRIEVER_ADMIN_CAPABILITY)) {
            echo '<div class="notice notice-error"><p>' .
                esc_html__("RiTriever setup failed. Standard search remains available. Check diagnostics before initializing.", "ritriever") .
                "</p></div>";
        }
    }

    public static function on_switch_blog(): void
    {
        Settings::clear_cache();
    }

    public static function activate(bool $network_wide = false): void
    {
        self::for_sites($network_wide, [self::class, "install_current_site"]);
    }

    public static function deactivate(bool $network_wide = false): void
    {
        self::for_sites($network_wide, static function (): void {
            BackfillRunner::clear_queue();
            wp_unschedule_hook("ritriever_sync_post");
            wp_unschedule_hook("ritriever_resync_term");
            SearchInterceptor::purge_query_cache();
        });
    }

    public static function on_initialize_site(\WP_Site $site): void
    {
        $network_plugins = get_site_option("active_sitewide_plugins", []);
        if (!isset($network_plugins[plugin_basename(RITRIEVER_PLUGIN_FILE)])) {
            return;
        }
        switch_to_blog((int) $site->blog_id);
        try {
            self::install_current_site();
        } finally {
            restore_current_blog();
        }
    }

    private static function for_sites(bool $network_wide, callable $operation): void
    {
        if (!$network_wide || !is_multisite()) {
            $operation();
            return;
        }
        $offset = 0;
        do {
            $ids = get_sites(["fields" => "ids", "number" => 100, "offset" => $offset]);
            foreach ($ids as $id) {
                switch_to_blog((int) $id);
                try {
                    $operation();
                } finally {
                    restore_current_blog();
                }
            }
            $offset += count($ids);
        } while (count($ids) === 100);
    }
}
