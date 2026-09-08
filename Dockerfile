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

# ShortDescription only affects one cosmetic red link, and unlike the others
# it has no GitHub mirror at all — only Gerrit, and only a master branch, with
# no per-release branches. So it must not be able to fail the build: try Gerrit
# then GitHub, release branch then default, and carry on without it if none
# work. LocalSettings.php loads it only if it actually landed.
ENV GIT_TERMINAL_PROMPT=0
RUN set -eu; \
    cd /var/www/html/extensions; \
    for url in \
        "https://gerrit.wikimedia.org/r/mediawiki/extensions/ShortDescription" \
        "https://github.com/wikimedia/mediawiki-extensions-ShortDescription.git" \
    ; do \
        rm -rf ShortDescription; \
        git clone --depth 1 -b "$MW_BRANCH" "$url" ShortDescription 2>/dev/null && break; \
        rm -rf ShortDescription; \
        git clone --depth 1 "$url" ShortDescription 2>/dev/null && break; \
    done; \
    if [ -f ShortDescription/extension.json ]; then \
        echo "ShortDescription: installed"; \
    else \
        rm -rf ShortDescription; \
        echo "ShortDescription: unavailable, continuing without it"; \
    fi
