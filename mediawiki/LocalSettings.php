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

// Scribunto keeps its own CPU budget, separate from PHP's max_execution_time.
// The 7-second default is Wikimedia's, set for Wikimedia's hardware; on a
// shared 2-vCPU VPS a long article's module tree blows through it, the
// interpreter is killed with SIGXCPU, and every later module call on the page
// fails — one article came back with 538 Lua errors from a single timeout.
$wgScribuntoEngineConf['luastandalone']['cpuLimit'] = 60;

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

// Module:Effective protection level calls into this; without it, every page
// that checks its own protection level throws.
wfLoadExtension( 'TitleBlacklist' );

// Provides {{SHORTDESC:}}; absent, the magic word is parsed as a transclusion
// and renders as a red link to "Template:SHORTDESC:...". Cosmetic, and the
// image may not have been able to fetch it, so load it only if it is here.
if ( is_file( "$IP/extensions/ShortDescription/extension.json" ) ) {
	wfLoadExtension( 'ShortDescription' );
}

// Page Previews on by default, as on Wikipedia.
$wgPopupsHideOptInOnPreferencesPage = false;
$wgPopupsReferencePreviewsBetaFeature = false;

// --------------------------------------------------------------- namespaces --
// English Wikipedia defines namespaces core does not. Templates reach into
// them through Scribunto — mw.title.new( 'Portal:Foo' ) throws
// "unrecognized namespace name" if the namespace is absent — so a navbox
// referencing a portal takes the whole box down with a Lua error.
define( 'NS_PORTAL', 100 );
define( 'NS_PORTAL_TALK', 101 );
define( 'NS_DRAFT', 118 );
define( 'NS_DRAFT_TALK', 119 );
define( 'NS_TIMEDTEXT', 710 );
define( 'NS_TIMEDTEXT_TALK', 711 );

$wgExtraNamespaces[NS_PORTAL] = 'Portal';
$wgExtraNamespaces[NS_PORTAL_TALK] = 'Portal_talk';
$wgExtraNamespaces[NS_DRAFT] = 'Draft';
$wgExtraNamespaces[NS_DRAFT_TALK] = 'Draft_talk';
$wgExtraNamespaces[NS_TIMEDTEXT] = 'TimedText';
$wgExtraNamespaces[NS_TIMEDTEXT_TALK] = 'TimedText_talk';

$wgNamespaceAliases['WP'] = NS_PROJECT;
$wgNamespaceAliases['WT'] = NS_PROJECT_TALK;
$wgNamespaceAliases['Project'] = NS_PROJECT;
$wgNamespaceAliases['Image'] = NS_FILE;
$wgNamespaceAliases['Image_talk'] = NS_FILE_TALK;

// Subpages, as enwiki has them. Notably NOT in main space.
foreach ( [
	NS_USER, NS_USER_TALK, NS_PROJECT, NS_PROJECT_TALK, NS_TALK,
	NS_TEMPLATE, NS_TEMPLATE_TALK, NS_HELP, NS_HELP_TALK,
	NS_PORTAL, NS_PORTAL_TALK, NS_DRAFT, NS_DRAFT_TALK,
] as $ns ) {
	$wgNamespacesWithSubpages[$ns] = true;
}

// ---------------------------------------------------------------- branding --
// Vector 2022 draws the icon, wordmark and tagline separately.
$wgLogos = [
	'icon' => 'https://en.wikipedia.org/static/images/icons/wikipedia.png',
	'wordmark' => [
		'src' => 'https://en.wikipedia.org/static/images/mobile/copyright/wikipedia-wordmark-en.svg',
		'width' => 116,
		'height' => 18,
	],
	'tagline' => [
		'src' => 'https://en.wikipedia.org/static/images/mobile/copyright/wikipedia-tagline-en.svg',
		'width' => 117,
		'height' => 13,
	],
];

// ------------------------------------------------------------ TemplateStyles --
// Wikipedia's stylesheets reference Commons with protocol-relative URLs —
// url(//upload.wikimedia.org/...) — while TemplateStyles' default allow-list is
// anchored on https://. The sanitiser therefore rejects those declarations and
// refuses the whole page save, so Module:Citation/CS1/styles.css never lands
// and every article with citations renders errors instead of a reference list.
//
// Declared in full rather than appended to, so the result does not depend on
// whether extension defaults have been merged in by this point.
$wgTemplateStylesAllowedUrls = [
	'audio' => [ '<^(?:https:)?//upload\.wikimedia\.org/wikipedia/commons/>' ],
	'image' => [ '<^(?:https:)?//upload\.wikimedia\.org/wikipedia/commons/>' ],
	'svg' => [ '<^(?:https:)?//upload\.wikimedia\.org/wikipedia/commons/[^?#]*\.svg(?:[?#]|$)>' ],
	'font' => [],
	'namespace' => [ '<.>' ],
	'css' => [],
];

// ------------------------------------------------------- on-demand mirroring --
// Title index (blue links) plus fetch-on-first-view. See
// extensions/WikiClone/README.md.
wfLoadExtension( 'WikiClone' );
// Wikipedia: and Help: pages are linked from article maintenance templates and
// from the sidebar; leaving them out is what makes "Wikipedia:Verifiability"
// render red on an otherwise perfect article.
$wgWikiCloneImportNamespaces = [ NS_MAIN, NS_TALK, NS_PROJECT, NS_HELP, NS_PORTAL ];

// ------------------------------------------------------------------- debug ---
// Turn these off once the build settles.
$wgShowExceptionDetails = true;
$wgShowDBErrorBacktrace = true;
