<?php
/**
 * Plugin Name:       RiTriever
 * Description:       Native-vector RAG search for WordPress using MariaDB 11.7+ or compatible MySQL 9.x vector indexes. Embeds posts on publish/update and blends vector retrieval with standard WordPress search.
 * Version:           0.2.3
 * Requires at least: 6.6
 * Tested up to:      7.1
 * Requires PHP:      8.1
 * Author:            Rio Fujita
 * Author URI:        https://rio.st/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       ritriever
 * Domain Path:       /languages
 * @package RiTriever
 */

declare(strict_types=1);

if (!defined("ABSPATH")) {
    exit();
}

if (version_compare(PHP_VERSION, "8.1.0", "<")) {
    add_action("admin_notices", static function (): void {
        echo '<div class="notice notice-error"><p>' .
            esc_html__(
                "RiTriever requires PHP 8.1 or higher.",
                "ritriever",
            ) .
            "</p></div>";
    });
    return;
}

const RITRIEVER_VERSION = "0.2.3";
const RITRIEVER_PLUGIN_FILE = __FILE__;
const RITRIEVER_OPTION_KEY = "ritriever_settings";
const RITRIEVER_POSTMETA_CONTENT_HASH = "_ritriever_content_hash";
const RITRIEVER_POSTMETA_INDEXED_AT = "_ritriever_indexed_at";
const RITRIEVER_POSTMETA_LAST_ERROR = "_ritriever_last_error";

define("RITRIEVER_PLUGIN_DIR", plugin_dir_path(__FILE__));
define("RITRIEVER_PLUGIN_URL", plugin_dir_url(__FILE__));

if (!defined("RITRIEVER_ADMIN_CAPABILITY")) {
    define("RITRIEVER_ADMIN_CAPABILITY", "manage_options");
}

spl_autoload_register(static function (string $class): void {
    $prefix = "RiTriever\\";
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path =
        RITRIEVER_PLUGIN_DIR .
        "includes/" .
        str_replace("\\", "/", $relative) .
        ".php";
    if (is_readable($path)) {
        require_once $path;
    }
});

register_activation_hook(RITRIEVER_PLUGIN_FILE, static function (): void {
    \RiTriever\Settings::install_or_upgrade();
    \RiTriever\Database\VectorSchema::install_or_upgrade();
    \RiTriever\Database\BackfillQueueSchema::install_or_upgrade();
});

register_deactivation_hook(RITRIEVER_PLUGIN_FILE, static function (): void {
    \RiTriever\BackfillRunner::clear_queue();
});

add_action(
    "plugins_loaded",
    static function (): void {
        \RiTriever\Plugin::instance()->boot();
    },
    5,
);
