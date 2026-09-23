<?php
/**
 * WordPress locale options used by the RAG target language setting.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever;

final class LanguageOptions
{
    public const SITE_DEFAULT = "site";
    public const CONTEXT_VERSION = "locale-v2";

    private function __construct() {}

    public static function sanitize_locale(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === "" || $locale === self::SITE_DEFAULT) {
            return self::SITE_DEFAULT;
        }
        return preg_match('/^[A-Za-z0-9_@-]{2,32}$/', $locale) === 1
            ? $locale
            : self::SITE_DEFAULT;
    }

    public static function selected_locale(): string
    {
        $selected = self::sanitize_locale((string) Settings::get("target_locale"));
        if ($selected !== self::SITE_DEFAULT) {
            return $selected;
        }
        $locale = function_exists("get_locale") ? (string) get_locale() : "en_US";
        return self::sanitize_locale($locale) === self::SITE_DEFAULT
            ? "en_US"
            : $locale;
    }

    public static function selected_label(): string
    {
        return self::label_for_locale(self::selected_locale());
    }

    public static function embedding_context_prefix(): string
    {
        $locale = self::selected_locale();
        return "RiTriever embedding context: " .
            self::CONTEXT_VERSION .
            "\nSearch target locale: " .
            $locale .
            "\n\n";
    }

    public static function with_embedding_context(string $text): string
    {
        return self::embedding_context_prefix() . $text;
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        $options = [
            self::SITE_DEFAULT => self::site_default_label(),
            "en_US" => "English (United States) - en_US",
        ];

        $locale = function_exists("get_locale") ? (string) get_locale() : "";
        if ($locale !== "" && $locale !== "en_US") {
            $options[$locale] = self::label_for_locale($locale);
        }

        foreach (self::wp_translations() as $translation_locale => $translation) {
            $translation_locale = self::sanitize_locale((string) $translation_locale);
            if ($translation_locale === self::SITE_DEFAULT) {
                continue;
            }
            $options[$translation_locale] = self::translation_label(
                $translation_locale,
                is_array($translation) ? $translation : [],
            );
        }

        $saved = self::sanitize_locale((string) Settings::get("target_locale"));
        if ($saved !== self::SITE_DEFAULT && !isset($options[$saved])) {
            $options[$saved] = str_replace("_", "-", $saved);
        }

        $site_default = $options[self::SITE_DEFAULT];
        unset($options[self::SITE_DEFAULT]);
        natcasesort($options);
        return [self::SITE_DEFAULT => $site_default] + $options;
    }

    public static function label_for_locale(string $locale): string
    {
        $locale = self::sanitize_locale($locale);
        if ($locale === self::SITE_DEFAULT) {
            return self::site_default_label();
        }

        $translations = self::wp_translations();
        if (isset($translations[$locale]) && is_array($translations[$locale])) {
            return self::translation_label($locale, $translations[$locale]);
        }

        return $locale === "en_US"
            ? "English (United States)"
            : str_replace("_", "-", $locale);
    }

    private static function site_default_label(): string
    {
        $locale = function_exists("get_locale") ? (string) get_locale() : "en_US";
        return sprintf(
            /* translators: %s: current WordPress site locale. */
            __("Site language (%s)", "ritriever"),
            $locale,
        );
    }

    /** @return array<string,array<string,mixed>> */
    private static function wp_translations(): array
    {
        if (!function_exists("wp_get_available_translations")) {
            $file = ABSPATH . "wp-admin/includes/translation-install.php";
            if (is_readable($file)) {
                require_once $file;
            }
        }

        if (!function_exists("wp_get_available_translations")) {
            return [];
        }

        $translations = wp_get_available_translations();
        return is_array($translations) ? $translations : [];
    }

    /** @param array<string,mixed> $translation */
    private static function translation_label(
        string $locale,
        array $translation,
    ): string {
        $native = trim((string) ($translation["native_name"] ?? ""));
        $english = trim((string) ($translation["english_name"] ?? ""));

        if ($native !== "" && $english !== "" && $native !== $english) {
            return $native . " / " . $english . " - " . $locale;
        }
        if ($native !== "") {
            return $native . " - " . $locale;
        }
        if ($english !== "") {
            return $english . " - " . $locale;
        }
        return $locale;
    }
}
