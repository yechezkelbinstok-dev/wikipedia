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
