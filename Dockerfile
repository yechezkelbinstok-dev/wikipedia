# Wikipedia-clone MediaWiki image.
# Base image ships the bundled extensions; we add the ones enwiki relies on
# that are not bundled (mobile skin/frontend, TemplateStyles, Popups).
FROM mediawiki:1.43

ARG MW_BRANCH=REL1_43

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git curl ca-certificates; \
    rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

# APCu backs $wgMainCacheType = CACHE_ACCEL. Some base image builds already
# ship it, and pecl install fails outright on a rebuild when it does, so only
# install it when it is genuinely absent.
RUN set -eux; \
    if ! php -m | grep -qix apcu; then \
        pecl install apcu; \
        docker-php-ext-enable apcu; \
    fi

# Scribunto's default engine spawns a separate `lua` process per parse and
# talks to it over pipes. LuaSandbox runs Lua in-process instead, which is what
# Wikimedia runs and is markedly faster on the module-heavy articles that
# dominate the cost here. Compilation can fail against a given PHP or Lua
# version, and this is an optimisation rather than a requirement, so a failure
# must not fail the build — LocalSettings.php picks the engine by what actually
# loaded.
# liblua5.1-0 is what luasandbox.so links against at runtime, and is installed
# separately from the -dev package on purpose: the build-time purge below
# removes orphaned dependencies, and taking the runtime library with them is
# what left the extension present on disk but unloadable.
RUN set -eu; \
    apt-get update; \
    apt-get install -y --no-install-recommends liblua5.1-0; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS liblua5.1-0-dev; \
    rm -f /usr/local/etc/php/conf.d/docker-php-ext-luasandbox.ini; \
    pecl uninstall -r luasandbox >/dev/null 2>&1 || true; \
    if pecl install --force luasandbox \
        && docker-php-ext-enable luasandbox \
        && php -r 'exit(extension_loaded("luasandbox") ? 0 : 1);'; then \
        echo "luasandbox: installed"; \
    else \
        echo "luasandbox: unavailable, falling back to the standalone engine"; \
        rm -f /usr/local/etc/php/conf.d/docker-php-ext-luasandbox.ini; \
    fi; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS liblua5.1-0-dev; \
    php -r 'exit(extension_loaded("luasandbox") ? 0 : 1);' \
        && echo "luasandbox: still loadable after cleanup" \
        || echo "luasandbox: LOST during cleanup"; \
    rm -rf /var/lib/apt/lists/*

# Extensions Wikipedia's articles genuinely need. A failure here should fail
# the build.
RUN set -eux; \
    cd /var/www/html/extensions; \
    for ext in MobileFrontend TemplateStyles Popups; do \
        if [ ! -d "$ext" ]; then \
            git clone --depth 1 -b "$MW_BRANCH" \
                "https://github.com/wikimedia/mediawiki-extensions-$ext.git" "$ext"; \
        fi; \
    done; \
    cd /var/www/html/skins; \
    if [ ! -d MinervaNeue ]; then \
        git clone --depth 1 -b "$MW_BRANCH" \
            https://github.com/wikimedia/mediawiki-skins-MinervaNeue.git MinervaNeue; \
    fi
