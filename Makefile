PLUGIN_SLUG := ritriever
PLUGIN_FILE := ritriever.php
PLUGIN_VERSION := $(shell awk -F ': *' '/^ \* Version:/ { print $$2; exit }' $(PLUGIN_FILE))
WP_TESTED_VERSION := $(shell awk -F ': *' '/^ \* Tested up to:/ { print $$2; exit }' $(PLUGIN_FILE))
BUILD_DIR := build
DIST_DIR := dist
RELEASE_DIR := $(BUILD_DIR)/$(PLUGIN_SLUG)
ZIP_FILE := $(DIST_DIR)/$(PLUGIN_SLUG)-$(PLUGIN_VERSION).zip
ZIP_FILE_ABS := $(abspath $(ZIP_FILE))
PHP_FILES := ritriever.php uninstall.php includes
POT_FILE := languages/$(PLUGIN_SLUG).pot
WPO_SVN_URL ?= https://plugins.svn.wordpress.org/$(PLUGIN_SLUG)
WPO_SVN_USERNAME ?= rioriost
WPO_SVN_DIR ?= .wordpress-org/svn
WPO_ASSETS_DIR ?= wordpress.org
SVN_TAG_DIR := $(WPO_SVN_DIR)/tags/$(PLUGIN_VERSION)
WP_STABLE_VERSION ?= 7.0.4
WP_RC_VERSION ?= 7.1-RC3
WP_COMPAT_DB ?= mariadb

.PHONY: help version install-tools static-check check composer-validate lint phpcs-security composer-audit compose-config plugin-check wordpress-compat wordpress-compat-stable wordpress-compat-rc wordpress-compat-matrix wordpress-compat-mysql apple-container-up apple-container-down apple-container-reset i18n-pot i18n-pot-check release-audit review-audit package-audit wordpress-org-assets-audit wordpress-org-checkout wordpress-org-stage wordpress-org-release clean package release

help:
	@echo "Targets:"
	@echo "  make check                    Run WordPress.org release gate checks"
	@echo "  make release                  Run release gates and build $(ZIP_FILE)"
	@echo "  make wordpress-compat-stable  Test WordPress $(WP_STABLE_VERSION) on MariaDB"
	@echo "  make wordpress-compat-rc      Test WordPress $(WP_RC_VERSION) on MariaDB and run Plugin Check"
	@echo "  make wordpress-compat-matrix  Run the stable and RC MariaDB compatibility tests"
	@echo "  make wordpress-compat-mysql   Test WordPress $(WP_RC_VERSION) with the MySQL fallback"
	@echo "  make wordpress-compat WP_VERSION=7.1-RC3 WP_COMPAT_DB=mariadb"
	@echo "  make wordpress-org-stage    Stage trunk, tags/$(PLUGIN_VERSION), and assets in $(WPO_SVN_DIR)"
	@echo "  make wordpress-org-release  Commit staged release to WordPress.org SVN"
	@echo "  make apple-container-up    Start local WordPress with Apple container"
	@echo "  version       $(PLUGIN_VERSION)"
	@echo "  make clean    Remove build artifacts"

version:
	@echo $(PLUGIN_VERSION)

install-tools:
	composer install --no-interaction --no-progress

composer-validate: install-tools
	composer validate --strict --no-interaction

lint:
	find . -path ./vendor -prune -o -path ./$(BUILD_DIR) -prune -o -path ./$(DIST_DIR) -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l

phpcs-security: install-tools
	vendor/bin/phpcs --standard=phpcs-security.xml.dist --report=summary

composer-audit: install-tools
	composer audit --no-interaction

compose-config:
	if command -v docker >/dev/null 2>&1; then docker compose config >/dev/null && docker compose --profile tools config >/dev/null; else echo "docker not found; skipping Compose config validation"; fi

plugin-check:
	APPLE_CONTAINER_AUTO_START=$${APPLE_CONTAINER_AUTO_START:-1} PLUGIN_ZIP="$(ZIP_FILE)" sh scripts/run-plugin-check.sh

wordpress-compat:
	test -n "$(WP_VERSION)"
	sh scripts/test-wordpress-compat.sh "$(WP_VERSION)" "$(WP_COMPAT_DB)"

wordpress-compat-stable:
	sh scripts/test-wordpress-compat.sh "$(WP_STABLE_VERSION)" mariadb

wordpress-compat-rc:
	RITRIEVER_RUN_PLUGIN_CHECK=1 sh scripts/test-wordpress-compat.sh "$(WP_RC_VERSION)" mariadb

wordpress-compat-matrix: wordpress-compat-stable wordpress-compat-rc

wordpress-compat-mysql:
	sh scripts/test-wordpress-compat.sh "$(WP_RC_VERSION)" mysql

apple-container-up:
	sh scripts/apple-container-wordpress.sh up

apple-container-down:
	sh scripts/apple-container-wordpress.sh down

apple-container-reset:
	sh scripts/apple-container-wordpress.sh reset

i18n-pot:
	php scripts/build-pot.php $(POT_FILE)

i18n-pot-check:
	mkdir -p output
	tmp="output/$(PLUGIN_SLUG)-check.pot"; trap 'rm -f "$$tmp"' EXIT INT TERM; php scripts/build-pot.php "$$tmp"; diff -u $(POT_FILE) "$$tmp"

release-audit:
	grep -q '^ \* Plugin Name:       RiTriever$$' $(PLUGIN_FILE)
	grep -q '^ \* Text Domain:       $(PLUGIN_SLUG)$$' $(PLUGIN_FILE)
	test "$$(awk -F ': *' '/^ \* Tested up to:/ { print $$2; exit }' $(PLUGIN_FILE))" = "$$(awk -F ': *' '/^Tested up to:/ { print $$2; exit }' readme.txt)"
	grep -q '^=== RiTriever ===$$' readme.txt
	grep -q '^Stable tag: $(PLUGIN_VERSION)$$' readme.txt
	test -f $(POT_FILE)
	test -f readme.txt
	grep -q '^== External services ==$$' readme.txt

wordpress-org-assets-audit:
	test -f $(WPO_ASSETS_DIR)/banner-772x250.jpg
	test -f $(WPO_ASSETS_DIR)/banner-1544x500.jpg
	test -f $(WPO_ASSETS_DIR)/icon.svg

review-audit:
	! grep -RInE 'WPRetriever|WP_RETRIEVER|wp_retriever|wp-retriever|wpRetriever|AI Retriever|ai-retriever' -- $(PLUGIN_FILE) uninstall.php includes assets languages readme.txt README.md composer.json phpcs-security.xml.dist scripts
	! grep -RInE 'wp_ajax_wp_|admin_post_wp_|wp_enqueue_script\("wp-' -- $(PLUGIN_FILE) includes assets
	! grep -RInE '<script[[:space:]>]|<style[[:space:]>]' -- $(PLUGIN_FILE) uninstall.php includes
	! grep -RInE 'wp_ai_client|WordPressAiEmbeddingProvider|generate_embeddings' -- $(PLUGIN_FILE) uninstall.php includes assets
	! grep -RInE 'badge_html\([^)]*\) \. \$$title|badge_html\([^)]*\) \. \(string\) \$$title' includes/SearchInterceptor.php
	grep -RIn 'wp_remote_post("https://api.openai.com/v1/embeddings"' includes/Embedding >/dev/null
	grep -RIn 'WordPress 7.0.*AI Client.*embeddings API' readme.txt >/dev/null
	grep -RIn 'OpenAI.*Terms of use https://openai.com/policies/terms-of-use/.*Privacy policy https://openai.com/policies/privacy-policy/' readme.txt >/dev/null
	grep -RIn 'Azure OpenAI.*Terms of use https://www.microsoft.com/licensing/terms/productoffering/MicrosoftAzure/MCA.*Privacy statement https://privacy.microsoft.com/privacystatement' readme.txt >/dev/null
	grep -RIn 'Local or self-hosted endpoints.*Ollama.*LM Studio.*Infinity.*TEI.*Custom HTTP' readme.txt >/dev/null
	grep -RIn 'private const CACHE_MAX_ENTRIES = 100;' includes/SearchInterceptor.php >/dev/null
	grep -RIn 'remember_cache_key' includes/SearchInterceptor.php >/dev/null
	grep -RIn 'delete_option(self::CACHE_INDEX_OPTION)' includes/SearchInterceptor.php >/dev/null

static-check: composer-validate lint phpcs-security composer-audit compose-config i18n-pot-check release-audit wordpress-org-assets-audit review-audit

check: static-check clean package package-audit plugin-check

clean:
	rm -rf $(BUILD_DIR) $(DIST_DIR)

package:
	rm -rf $(RELEASE_DIR)
	mkdir -p $(RELEASE_DIR) $(DIST_DIR)
	cp $(PLUGIN_FILE) $(RELEASE_DIR)/
	cp uninstall.php $(RELEASE_DIR)/
	cp readme.txt $(RELEASE_DIR)/
	cp README.md $(RELEASE_DIR)/
	cp LICENSE $(RELEASE_DIR)/
	cp -R includes $(RELEASE_DIR)/
	if [ -d assets ]; then cp -R assets $(RELEASE_DIR)/; fi
	if [ -d languages ]; then cp -R languages $(RELEASE_DIR)/; fi
	find $(RELEASE_DIR) -name '.DS_Store' -delete
	rm -f $(ZIP_FILE_ABS)
	cd $(BUILD_DIR) && zip -qr $(ZIP_FILE_ABS) $(PLUGIN_SLUG)
	@echo "Built $(ZIP_FILE)"

package-audit:
	test -f $(ZIP_FILE)
	unzip -l $(ZIP_FILE) | grep -q '$(PLUGIN_SLUG)/readme.txt'
	unzip -l $(ZIP_FILE) | grep -q '$(PLUGIN_SLUG)/$(POT_FILE)'
	test "$$(unzip -p $(ZIP_FILE) $(PLUGIN_SLUG)/$(PLUGIN_FILE) | awk -F ': *' '/^ \* Tested up to:/ { print $$2; exit }')" = "$(WP_TESTED_VERSION)"
	test "$$(unzip -p $(ZIP_FILE) $(PLUGIN_SLUG)/readme.txt | awk -F ': *' '/^Tested up to:/ { print $$2; exit }')" = "$(WP_TESTED_VERSION)"
	! unzip -l $(ZIP_FILE) | grep -E 'ai-retriever|wp-retriever|wp_retriever' >/dev/null
	! unzip -l $(ZIP_FILE) | grep -E '/(tmp|vendor|build|dist|\\.git)/' >/dev/null
	! unzip -l $(ZIP_FILE) | grep -E '/\\.[^/]+$$' >/dev/null

release: check

wordpress-org-checkout:
	if [ -d "$(WPO_SVN_DIR)/.svn" ]; then \
		svn update "$(WPO_SVN_DIR)"; \
	else \
		mkdir -p "$(dir $(WPO_SVN_DIR))"; \
		svn checkout "$(WPO_SVN_URL)" "$(WPO_SVN_DIR)"; \
	fi

wordpress-org-stage: release wordpress-org-checkout
	rm -rf "$(SVN_TAG_DIR)"
	mkdir -p "$(WPO_SVN_DIR)/trunk" "$(SVN_TAG_DIR)" "$(WPO_SVN_DIR)/assets"
	rsync -a --delete --exclude='.svn' "$(RELEASE_DIR)/" "$(WPO_SVN_DIR)/trunk/"
	rsync -a --delete --exclude='.svn' "$(RELEASE_DIR)/" "$(SVN_TAG_DIR)/"
	cp "$(WPO_ASSETS_DIR)/banner-772x250.jpg" "$(WPO_SVN_DIR)/assets/"
	cp "$(WPO_ASSETS_DIR)/banner-1544x500.jpg" "$(WPO_SVN_DIR)/assets/"
	cp "$(WPO_ASSETS_DIR)/icon.svg" "$(WPO_SVN_DIR)/assets/"
	svn add --force "$(WPO_SVN_DIR)/trunk" "$(WPO_SVN_DIR)/tags" "$(WPO_SVN_DIR)/assets" --auto-props --parents --depth infinity >/dev/null
	svn status "$(WPO_SVN_DIR)" | awk '/^!/ { print substr($$0, 9) }' | while IFS= read -r path; do [ -z "$$path" ] || svn rm --force "$$path"; done
	@echo "Staged WordPress.org SVN release $(PLUGIN_VERSION) in $(WPO_SVN_DIR)"
	@svn status "$(WPO_SVN_DIR)"

wordpress-org-release: wordpress-org-stage
	@set -e; \
	if [ -f docs/.env ]; then set -a; . docs/.env; set +a; fi; \
	if [ -n "$${SVN_PASSWORD:-}" ]; then \
		svn commit "$(WPO_SVN_DIR)" --non-interactive --username "$(WPO_SVN_USERNAME)" --password "$$SVN_PASSWORD" -m "Release $(PLUGIN_SLUG) $(PLUGIN_VERSION)"; \
	else \
		svn commit "$(WPO_SVN_DIR)" --non-interactive --username "$(WPO_SVN_USERNAME)" -m "Release $(PLUGIN_SLUG) $(PLUGIN_VERSION)"; \
	fi
