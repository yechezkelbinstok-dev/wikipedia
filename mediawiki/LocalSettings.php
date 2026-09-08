<?php
/**
 * Wikipedia clone — MediaWiki configuration.
 *
 * No secrets live here: credentials and keys come from the container
 * environment (see .env / docker-compose.yml), so this file is safe to commit.
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}

$wikiDomain = getenv( 'WIKI_DOMAIN' ) ?: 'localhost';

// ---------------------------------------------------------------- identity --
$wgSitename      = 'Wikipedia';
$wgMetaNamespace = 'Wikipedia';
$wgLanguageCode  = 'en';
$wgLocaltimezone = 'UTC';

// ------------------------------------------------------------------- paths --
// Wikipedia's article URLs are /wiki/Title; the rewrite lives in
// mediawiki/apache-limits.conf.
$wgServer      = 'https://' . $wikiDomain;
$wgCanonicalServer = $wgServer;
$wgScriptPath  = '';
$wgArticlePath = '/wiki/$1';
$wgUsePathInfo = true;

$wgResourceBasePath = $wgScriptPath;

// Caddy terminates TLS and forwards over plain HTTP.
$wgUsePrivateIPs = true;

// ---------------------------------------------------------------- database --
$wgDBtype     = 'mysql';
$wgDBserver   = getenv( 'MW_DB_SERVER' );
$wgDBname     = getenv( 'MW_DB_NAME' );
$wgDBuser     = getenv( 'MW_DB_USER' );
$wgDBpassword = getenv( 'MW_DB_PASSWORD' );
$wgDBTableOptions = 'ENGINE=InnoDB, DEFAULT CHARSET=binary';

// ------------------------------------------------------------------ secrets --
$wgSecretKey  = getenv( 'MW_SECRET_KEY' );
$wgUpgradeKey = getenv( 'MW_UPGRADE_KEY' );

// ------------------------------------------------------------------ caching --
$wgMainCacheType    = CACHE_ACCEL;   // APCu
$wgSessionCacheType = CACHE_DB;      // survives container restarts
$wgParserCacheType  = CACHE_DB;      // rendered articles are expensive; keep them
$wgMemCachedServers = [];

// Jobs run from cron (scripts/run-jobs.sh), never on a page view — a page view
// that also runs a job is a page view that feels slow.
$wgJobRunRate = 0;

// Expensive special pages (Special:WantedPages etc.) are meaningless here and
// would try to scan 17M rows.
$wgMiserMode = true;

// --------------------------------------------------------------- media/files --
// Images come straight from Wikimedia Commons; nothing is stored locally.
$wgUseInstantCommons = true;
$wgEnableUploads     = false;
$wgUseImageMagick    = true;
$wgImageMagickConvertCommand = '/usr/bin/convert';

// ------------------------------------------------------------------- limits --
$wgMaxArticleSize = 2048;   // KB — enwiki's value
$wgMaxShellMemory = 307200; // Scribunto needs room on big citation-heavy pages
$wgMaxShellTime   = 60;
$wgMaxShellFileSize = 102400;

// -------------------------------------------------------------- permissions --
// Private by default: this is a public URL wearing Wikipedia's clothes, so
// readers must log in. Flip $wgGroupPermissions['*']['read'] to true to make
// it world-readable.
$wgGroupPermissions['*']['read']          = false;
$wgGroupPermissions['*']['edit']          = false;
$wgGroupPermissions['*']['createaccount'] = false;
$wgGroupPermissions['user']['edit']       = true;

$wgWhitelistRead = [
	'Special:UserLogin',
	'Special:UserLogout',
	'Special:ResetPassword',
	'MediaWiki:Common.css',
	'MediaWiki:Vector.css',
];

// Personal JS/CSS, as on Wikipedia.
$wgAllowUserJs  = true;
$wgAllowUserCss = true;

// ---------------------------------------------------------------- appearance --
wfLoadSkin( 'Vector' );
wfLoadSkin( 'MinervaNeue' );
$wgDefaultSkin = 'vector-2022';

// Mobile: MobileFrontend + Minerva is exactly what en.m.wikipedia.org runs.
wfLoadExtension( 'MobileFrontend' );
$wgMFDefaultSkinClass = 'SkinMinerva';

// ---------------------------------------------------------------- extensions --
wfLoadExtension( 'ParserFunctions' );
$wgPFEnableStringFunctions = true;

wfLoadExtension( 'Scribunto' );
$wgScribuntoDefaultEngine = 'luastandalone';

wfLoadExtension( 'Cite' );
wfLoadExtension( 'CiteThisPage' );
wfLoadExtension( 'TemplateStyles' );
wfLoadExtension( 'TemplateData' );
wfLoadExtension( 'CategoryTree' );
wfLoadExtension( 'ImageMap' );
wfLoadExtension( 'InputBox' );
wfLoadExtension( 'Poem' );
wfLoadExtension( 'WikiEditor' );
wfLoadExtension( 'SyntaxHighlight_GeSHi' );
wfLoadExtension( 'TextExtracts' );
wfLoadExtension( 'PageImages' );
wfLoadExtension( 'Popups' );      // hover previews
wfLoadExtension( 'Gadgets' );
wfLoadExtension( 'Interwiki' );
wfLoadExtension( 'Nuke' );
wfLoadExtension( 'ReplaceText' );

// Page Previews on by default, as on Wikipedia.
$wgPopupsHideOptInOnPreferencesPage = false;
$wgPopupsReferencePreviewsBetaFeature = false;

// ------------------------------------------------------- on-demand mirroring --
// Title index (blue links) plus fetch-on-first-view. See
// extensions/WikiClone/README.md.
wfLoadExtension( 'WikiClone' );
$wgWikiCloneImportNamespaces = [ NS_MAIN ];

// ------------------------------------------------------------------- debug ---
// Turn these off once the build settles.
$wgShowExceptionDetails = true;
$wgShowDBErrorBacktrace = true;
