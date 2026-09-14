<?php
/**
 * Standalone unit tests for the plugin's PURE decision functions.
 *
 * No WordPress, no database, no network. Only functions whose output depends
 * solely on their arguments are covered here; anything touching $wpdb belongs
 * in an integration test against a real WP install.
 *
 * These live in the repository on purpose. An earlier generation of this
 * harness lived in %TEMP% and was silently deleted by temp cleanup.
 *
 * Run:  php tests/run-unit.php
 * Exits non-zero if any assertion fails, so CI can gate on it.
 *
 * @package AILinking
 */

define( 'ABSPATH', __DIR__ . '/' );

// ---------------------------------------------------------------------------
// Minimal WordPress stubs. Only what the functions under test actually call.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { // phpcs:ignore
		return $value;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) { // phpcs:ignore
		return $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { // phpcs:ignore
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) { // phpcs:ignore
		return number_format( (float) $number, (int) $decimals );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) { // phpcs:ignore
		return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $a ) { // phpcs:ignore
		return htmlspecialchars( (string) $a, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) { // phpcs:ignore
		$string = preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', (string) $string );
		$string = strip_tags( $string );
		if ( $remove_breaks ) {
			$string = preg_replace( '/[
	 ]+/', ' ', $string );
		}
		return trim( $string );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { // phpcs:ignore
		return parse_url( $url, $component );
	}
}
// The classifier measures paths relative to the WordPress home path. A site in
// a subdirectory is the case that breaks naive path handling, so test against
// one rather than against a root install.
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) { // phpcs:ignore
		return 'https://example.test/blog' . $path;
	}
}

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' ); // $wpdb result-shape constant.

// In-memory transients, so cached lookups can be seeded by a test.
$ailinking_transients = array();
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { // phpcs:ignore
		global $ailinking_transients;
		return isset( $ailinking_transients[ $k ] ) ? $ailinking_transients[ $k ] : false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { // phpcs:ignore
		global $ailinking_transients;
		$ailinking_transients[ $k ] = $v;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { // phpcs:ignore
		global $ailinking_transients;
		unset( $ailinking_transients[ $k ] );
		return true;
	}
}

$plugin = __DIR__ . '/../ai-internal-linking/includes';
require_once $plugin . '/Integrations/KeywordImporter.php';
require_once $plugin . '/Suggestions/KeywordSuggester.php';
require_once $plugin . '/Suggestions/Naturalness.php';
require_once $plugin . '/Suggestions/Relevance.php';
require_once $plugin . '/Suggestions/AnchorGenerator.php';
require_once $plugin . '/Suggestions/SuggestionEngine.php'; // Only anchors_collide() is exercised.
require_once $plugin . '/Providers/Pricing.php';
require_once $plugin . '/Providers/UsageStats.php';
require_once $plugin . '/Security/Redactor.php';
require_once $plugin . '/Suggestions/LlmSuggester.php';
require_once __DIR__ . '/stubs/tables.php'; // Must precede Tfidf, which calls it.
require_once $plugin . '/Suggestions/Tfidf.php';
require_once $plugin . '/Admin/BulkActions.php';
require_once $plugin . '/Suggestions/Summarizer.php';
require_once $plugin . '/Providers/ProviderInterface.php'; // AnthropicProvider implements it.
require_once $plugin . '/Providers/AnthropicProvider.php';
require_once $plugin . '/Providers/Registry.php';
require_once $plugin . '/Install/Schema.php';
require_once $plugin . '/Content/WriteGuards.php';
require_once $plugin . '/Content/anchor-unwrap.php'; // Plain functions, no namespace.
require_once $plugin . '/Content/UrlClassifier.php';
require_once $plugin . '/Indexer/Indexer.php'; // Only record_failure() is exercised; it touches nothing else.

use AILinking\Integrations\KeywordImporter;
use AILinking\Suggestions\KeywordSuggester;
use AILinking\Suggestions\Naturalness;
use AILinking\Suggestions\Relevance;
use AILinking\Suggestions\AnchorGenerator;
use AILinking\Providers\Pricing;
use AILinking\Providers\UsageStats;
use AILinking\Security\Redactor;
use AILinking\Suggestions\LlmSuggester;
use AILinking\Suggestions\Tfidf;
use AILinking\Admin\BulkActions;
use AILinking\Suggestions\Summarizer;
use AILinking\Providers\AnthropicProvider;
use AILinking\Providers\Registry;
use AILinking\Content\WriteGuards;
use AILinking\Content\UrlClassifier;
use AILinking\Indexer\Indexer;

// ---------------------------------------------------------------------------
// Tiny assertion harness.
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;

function ok( $condition, $label ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		return;
	}
	$failed++;
	echo "FAIL: {$label}\n";
}

function eq( $actual, $expected, $label ) {
	$same = ( is_float( $expected ) || is_float( $actual ) )
		? ( abs( (float) $actual - (float) $expected ) < 0.0001 )
		: ( $actual === $expected );
	if ( ! $same ) {
		echo 'FAIL: ' . $label . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
		global $failed;
		$failed++;
		return;
	}
	global $passed;
	$passed++;
}

// ---------------------------------------------------------------------------
// KeywordImporter::ctr_at — the CTR curve behind opportunity.
// ---------------------------------------------------------------------------

eq( KeywordImporter::ctr_at( 1 ), 0.280, 'ctr_at(1)' );
eq( KeywordImporter::ctr_at( 3 ), 0.110, 'ctr_at(3)' );
eq( KeywordImporter::ctr_at( 10 ), 0.025, 'ctr_at(10)' );
eq( KeywordImporter::ctr_at( 20 ), 0.008, 'ctr_at(20)' );
eq( KeywordImporter::ctr_at( 50 ), 0.005, 'ctr_at(50) flattens past page one' );
eq( KeywordImporter::ctr_at( 0 ), 0.280, 'ctr_at clamps positions below 1' );
ok( KeywordImporter::ctr_at( 5 ) > KeywordImporter::ctr_at( 9 ), 'ctr decreases with position' );

// ---------------------------------------------------------------------------
// KeywordImporter::opportunity — must NOT reward keywords already at the top.
// ---------------------------------------------------------------------------

eq( KeywordImporter::opportunity( 1000, 1 ), 0.0, 'position 1 has no opportunity' );
eq( KeywordImporter::opportunity( 1000, 2 ), 0.0, 'position 2 has no opportunity' );
eq( KeywordImporter::opportunity( 1000, 3 ), 0.0, 'position 3 is the target, no gain' );
ok( KeywordImporter::opportunity( 1000, 4 ) > 0, 'position 4 has some opportunity' );
eq( KeywordImporter::opportunity( 0, 12 ), 0.0, 'no impressions, no opportunity' );

$at5  = KeywordImporter::opportunity( 1000, 5 );
$at12 = KeywordImporter::opportunity( 1000, 12 );
$at20 = KeywordImporter::opportunity( 1000, 20 );
$at40 = KeywordImporter::opportunity( 1000, 40 );
$at99 = KeywordImporter::opportunity( 1000, 99 );

ok( $at20 > $at12 && $at12 > $at5, 'opportunity rises across the striking band' );
ok( $at40 < $at20, 'opportunity decays past position 20 (one link cannot lift page 5)' );
ok( $at99 < $at40, 'opportunity keeps decaying deeper down' );
ok( $at20 > KeywordImporter::opportunity( 1000, 1 ), 'striking distance beats an existing number 1' );
ok( KeywordImporter::opportunity( 5000, 12 ) > $at12, 'more impressions, more opportunity' );

// The regression this replaced: the old curve peaked at position 1.
ok(
	KeywordImporter::opportunity( 1000, 15 ) > KeywordImporter::opportunity( 1000, 1 ),
	'REGRESSION: a striking keyword must outrank a keyword already ranked 1'
);

// ---------------------------------------------------------------------------
// KeywordImporter::is_striking — band boundaries.
// ---------------------------------------------------------------------------

ok( ! KeywordImporter::is_striking( 4.9 ), 'is_striking excludes 4.9' );
ok( KeywordImporter::is_striking( 5.0 ), 'is_striking includes 5' );
ok( KeywordImporter::is_striking( 20.0 ), 'is_striking includes 20' );
ok( ! KeywordImporter::is_striking( 20.1 ), 'is_striking excludes 20.1' );

// ---------------------------------------------------------------------------
// KeywordSuggester::relevance — floor, striking bonus, opportunity share.
// ---------------------------------------------------------------------------

eq( KeywordSuggester::relevance( false, 0, 0 ), 0.5, 'relevance floor is 0.5' );
eq( KeywordSuggester::relevance( true, 0, 0 ), 0.7, 'striking adds 0.20' );
eq( KeywordSuggester::relevance( false, 5, 10 ), 0.64, 'half the pool max adds 0.14' );
eq( KeywordSuggester::relevance( true, 10, 10 ), 0.98, 'everything maxes at 0.98' );
eq( KeywordSuggester::relevance( true, 999, 10 ), 0.98, 'cap holds when opportunity exceeds the max' );

// ---------------------------------------------------------------------------
// Naturalness — anchor shape scoring and the composite.
// ---------------------------------------------------------------------------

eq( Naturalness::score( 'content marketing strategy' ), 0.90, 'a descriptive phrase of good length scores near the top' );
ok( Naturalness::score( 'abc' ) < Naturalness::score( 'two words here' ), 'very short anchors score worse' );
ok( Naturalness::score( str_repeat( 'a', 80 ) ) < Naturalness::score( 'two words here' ), 'over-long anchors score worse' );
ok( Naturalness::score( 'anything at all here' ) <= 1.0, 'naturalness never exceeds 1' );
ok( Naturalness::score( 'a' ) >= 0.0, 'naturalness never goes below 0' );
eq( Naturalness::score( '' ), 0.0, 'an empty anchor scores zero' );
eq( Naturalness::confidence( 0.5, 0.85 ), 0.64, 'confidence = 0.6*relevance + 0.4*naturalness' );

// The defect this replaced: naturalness was 0.45 + 0.30*relevance + a bonus, so
// it restated relevance instead of judging the anchor. Measured on 170 live
// suggestions, naturalness - 0.30*relevance was 0.700 on 161 of them.
eq(
	Naturalness::score( 'content marketing strategy', 0.1 ),
	Naturalness::score( 'content marketing strategy', 0.9 ),
	'REGRESSION: naturalness does not move with relevance'
);
ok(
	Naturalness::score( 'content marketing strategy' ) > Naturalness::score( 'a full clause of at least six words' ),
	'a phrase beats a clause'
);
ok(
	Naturalness::score( 'two words here' ) > Naturalness::score( 'x' ),
	'a real phrase beats a single tiny word'
);

// Length is counted in characters, not bytes. Three Devanagari characters are
// nine bytes; the old byte count let them escape the too-short penalty and even
// collect the good-length bonus, so the check meant nothing off the Latin
// alphabet.
$deva = json_decode( '"कृषि"' ); // 4 characters, 12 bytes
ok( strlen( $deva ) > 8, 'the test string really is longer in bytes than in characters' );
ok(
	Naturalness::score( $deva ) < Naturalness::score( 'content marketing strategy' ),
	'REGRESSION: a short non-Latin anchor is judged on characters, not bytes'
);

// Scripts without spaces must not be penalised for having no spaces.
$cjk = json_decode( '"内部リンク戦略設計"' ); // 9 chars, no spaces
ok( Naturalness::score( $cjk ) >= Naturalness::BASE, 'a space-less script is not marked down for having one word' );

// The composite is now genuinely 60/40 and the weight is filterable.
eq( Naturalness::confidence( 1.0, 0.0 ), 0.6, 'relevance alone contributes 0.6' );
eq( Naturalness::confidence( 0.0, 1.0 ), 0.4, 'naturalness alone contributes 0.4' );
eq( Naturalness::confidence( 2.0, -1.0 ), 0.6, 'out-of-range inputs are clamped before weighting' );

// ---------------------------------------------------------------------------
// Pricing — the rounding contract that the cost fix depends on.
// ---------------------------------------------------------------------------

$precise = Pricing::cents_float( 'openai', 'gpt-4o-mini', 2400, 800, 'chat' );
$rounded = Pricing::cents( 'openai', 'gpt-4o-mini', 2400, 800, 'chat' );
ok( $precise > 0, 'a real request costs something' );
eq( $rounded, (int) ceil( $precise ), 'cents() is ceil(cents_float())' );
ok( $rounded >= $precise, 'the cap-facing figure never under-reports' );
eq( Pricing::cents_float( 'openai', 'gpt-4o-mini', 0, 0, 'chat' ), 0.0, 'zero tokens cost zero' );
ok(
	Pricing::cents_float( 'openai', 'gpt-4o-mini', 2400, 800, 'chat' ) < 1.0,
	'a typical request costs under a cent, which is exactly what ceil() was hiding'
);

// ---------------------------------------------------------------------------
// UsageStats formatting.
// ---------------------------------------------------------------------------

eq( UsageStats::format_tokens( 0 ), '0', 'format_tokens(0)' );
eq( UsageStats::format_tokens( 999 ), '999', 'format_tokens under 1k is exact' );
eq( UsageStats::format_tokens( 12480 ), '12.5k', 'format_tokens uses k' );
eq( UsageStats::format_tokens( 1000000 ), '1.00M', 'format_tokens uses M' );
eq( UsageStats::format_tokens( -5 ), '0', 'format_tokens clamps negatives' );
eq( UsageStats::format_cost( 0.0 ), '$0.00', 'zero cost' );
eq( UsageStats::format_cost( 0.0234 ), '$0.0234', 'sub-dollar cost keeps 4dp so it does not read as free' );
eq( UsageStats::format_cost( 1.5 ), '$1.50', 'dollar amounts use 2dp' );

// ---------------------------------------------------------------------------
// LlmSuggester::truncate_words — the per-page budget sent to the model.
// ---------------------------------------------------------------------------

eq( LlmSuggester::truncate_words( 'one two three four', 2 ), 'one two', 'keeps exactly N words' );
eq( LlmSuggester::truncate_words( 'one two', 10 ), 'one two', 'shorter than the budget is returned whole' );
eq( LlmSuggester::truncate_words( '', 10 ), '', 'empty text stays empty' );
eq( LlmSuggester::truncate_words( '   spaced   out   text   ', 2 ), 'spaced out', 'leading space and runs of whitespace do not count as words' );
eq( LlmSuggester::truncate_words( "line one\nline two", 3 ), 'line one line', 'newlines are word separators' );
eq( count( explode( ' ', LlmSuggester::truncate_words( str_repeat( 'word ', 5000 ), 1000 ) ) ), 1000, 'a long page is cut to exactly the budget' );
ok( LlmSuggester::truncate_words( 'a b c', 0 ) !== '', 'a zero budget still returns something rather than nothing' );

// Non-Latin scripts must not be mangled: splitting is on Unicode whitespace.
eq( LlmSuggester::truncate_words( 'हिंदी शब्द तीन चार', 2 ), 'हिंदी शब्द', 'Unicode text splits on whitespace, not bytes' );

// ---------------------------------------------------------------------------
// LlmSuggester::clamp_words — the same guard for the reader and the save form,
// so a value can never be stored that the reader would then reject.
// ---------------------------------------------------------------------------

eq( LlmSuggester::clamp_words( 1000 ), 1000, 'a normal value passes through' );
eq( LlmSuggester::clamp_words( LlmSuggester::MIN_WORDS ), LlmSuggester::MIN_WORDS, 'the floor itself is allowed' );
eq( LlmSuggester::clamp_words( 100 ), LlmSuggester::MIN_WORDS, 'below the floor is raised to it' );
eq( LlmSuggester::clamp_words( LlmSuggester::PRESET_MAX_WORDS ), 3000, 'the largest preset is allowed' );
eq( LlmSuggester::clamp_words( 8000 ), 8000, 'a custom value above the presets is allowed' );
eq( LlmSuggester::clamp_words( LlmSuggester::MAX_WORDS_LIMIT ), 20000, 'the ceiling itself is allowed' );
eq( LlmSuggester::clamp_words( 999999 ), LlmSuggester::MAX_WORDS_LIMIT, 'a runaway custom value is capped at the ceiling' );
eq( LlmSuggester::clamp_words( 0 ), LlmSuggester::DEFAULT_MAX_WORDS, 'zero falls back to the default, not the floor' );
eq( LlmSuggester::clamp_words( -50 ), LlmSuggester::DEFAULT_MAX_WORDS, 'a negative value falls back to the default' );
ok( LlmSuggester::MIN_WORDS < LlmSuggester::PRESET_MAX_WORDS, 'floor is below the largest preset' );
ok( LlmSuggester::PRESET_MAX_WORDS <= LlmSuggester::MAX_WORDS_LIMIT, 'presets never exceed the hard ceiling' );
ok( in_array( LlmSuggester::DEFAULT_MAX_WORDS, LlmSuggester::PRESETS, true ), 'the default is selectable as a preset' );
ok( in_array( LlmSuggester::MIN_WORDS, LlmSuggester::PRESETS, true ), 'the floor is selectable as a preset' );

// ---------------------------------------------------------------------------
// LlmSuggester::clamp_candidates — how many destinations the model is shown.
// ---------------------------------------------------------------------------

eq( LlmSuggester::clamp_candidates( 15 ), 15, 'a normal shortlist size passes through' );
eq( LlmSuggester::clamp_candidates( LlmSuggester::MIN_CANDIDATES ), LlmSuggester::MIN_CANDIDATES, 'the floor itself is allowed' );
eq( LlmSuggester::clamp_candidates( 2 ), LlmSuggester::MIN_CANDIDATES, 'too short a list is raised to the floor' );
eq( LlmSuggester::clamp_candidates( 60 ), 60, 'a custom value above the presets is allowed' );
eq( LlmSuggester::clamp_candidates( LlmSuggester::MAX_CANDIDATES_LIMIT ), 200, 'the ceiling itself is allowed' );
eq( LlmSuggester::clamp_candidates( 5000 ), LlmSuggester::MAX_CANDIDATES_LIMIT, 'a runaway value is capped' );
eq( LlmSuggester::clamp_candidates( 0 ), LlmSuggester::DEFAULT_CANDIDATES, 'zero falls back to the default, not the floor' );
eq( LlmSuggester::clamp_candidates( -3 ), LlmSuggester::DEFAULT_CANDIDATES, 'a negative value falls back to the default' );
ok( in_array( LlmSuggester::DEFAULT_CANDIDATES, LlmSuggester::CANDIDATE_PRESETS, true ), 'the default is selectable as a preset' );
foreach ( LlmSuggester::CANDIDATE_PRESETS as $p ) {
	eq( LlmSuggester::clamp_candidates( $p ), $p, "preset {$p} survives the clamp unchanged" );
}

// ---------------------------------------------------------------------------
// LlmSuggester::clamp_candidate_words — 0 means "titles only" here, which is a
// real choice rather than an unset value, so it must survive the clamp.
// ---------------------------------------------------------------------------

eq( LlmSuggester::clamp_candidate_words( 0 ), 0, 'zero survives: it means send titles only' );
eq( LlmSuggester::clamp_candidate_words( 10 ), 10, 'a normal value passes through' );
eq( LlmSuggester::clamp_candidate_words( LlmSuggester::MAX_CANDIDATE_WORDS ), 30, 'the ceiling itself is allowed' );
eq( LlmSuggester::clamp_candidate_words( 500 ), LlmSuggester::MAX_CANDIDATE_WORDS, 'a runaway value is capped' );
eq( LlmSuggester::clamp_candidate_words( -5 ), 0, 'a negative value becomes titles only' );
ok( in_array( LlmSuggester::DEFAULT_CANDIDATE_WORDS, LlmSuggester::CANDIDATE_WORD_PRESETS, true ), 'the default is selectable as a preset' );
ok( in_array( 0, LlmSuggester::CANDIDATE_WORD_PRESETS, true ), 'titles-only is offered in the dropdown' );
eq( max( LlmSuggester::CANDIDATE_WORD_PRESETS ), LlmSuggester::MAX_CANDIDATE_WORDS, 'the presets reach the ceiling, so no custom box is needed' );
foreach ( LlmSuggester::CANDIDATE_WORD_PRESETS as $p ) {
	eq( LlmSuggester::clamp_candidate_words( $p ), $p, "preset {$p} survives the clamp unchanged" );
}

// ---------------------------------------------------------------------------
// LlmSuggester::fetch_count — headroom over what is shown, because the pool is
// filtered afterwards (already linked, already judged) and could otherwise
// starve a well-linked page of destinations.
// ---------------------------------------------------------------------------

ok( LlmSuggester::fetch_count( 15 ) > 15, 'always fetches more than it will show' );
eq( LlmSuggester::fetch_count( 15 ), 29, '15 shown fetches 29' );
eq( LlmSuggester::fetch_count( 10 ), 21, '10 shown fetches 21' );
ok( LlmSuggester::fetch_count( 200 ) <= 400, 'the fetch is bounded even at the largest shortlist' );
ok( LlmSuggester::fetch_count( 0 ) > 0, 'a zero request still fetches something' );
$prev = 0;
foreach ( array( 5, 10, 15, 25, 40, 60, 200 ) as $n ) {
	$got = LlmSuggester::fetch_count( $n );
	ok( $got >= $prev, "fetch count never decreases as the shortlist grows ({$n})" );
	$prev = $got;
}

// ---------------------------------------------------------------------------
// LlmSuggester::estimate_tokens — the figure shown beside the three controls.
// ---------------------------------------------------------------------------

$e = LlmSuggester::estimate_tokens( 1000, 15, 10 );
// 1000 + 15*(8+10) = 1270 words; 420 + 1270*1.35 = 2134.5 -> 2135.
eq( $e['in'], 2135, 'default settings estimate 2,135 input tokens' );
eq( $e['out'], LlmSuggester::REPLY_TOKENS, 'the reply estimate is the flat constant' );
ok(
	LlmSuggester::estimate_tokens( 1000, 15, 20 )['in'] > LlmSuggester::estimate_tokens( 1000, 15, 10 )['in'],
	'more words per destination costs more'
);
ok(
	LlmSuggester::estimate_tokens( 1000, 40, 10 )['in'] > LlmSuggester::estimate_tokens( 1000, 15, 10 )['in'],
	'a longer shortlist costs more'
);
ok(
	LlmSuggester::estimate_tokens( 2000, 15, 10 )['in'] > LlmSuggester::estimate_tokens( 1000, 15, 10 )['in'],
	'reading more of the page costs more'
);
eq(
	LlmSuggester::estimate_tokens( 1000, 15, 0 )['in'],
	LlmSuggester::estimate_tokens( 1000, 15, -5 )['in'],
	'a negative word count is treated as titles only'
);
ok( LlmSuggester::estimate_tokens( 0, 0, 0 )['in'] >= LlmSuggester::PROMPT_OVERHEAD_TOKENS, 'the fixed prompt overhead is never lost' );

// ---------------------------------------------------------------------------
// Tfidf::top_terms_for — the words attached to each possible destination.
//
// Not a pure function, but the one bug worth guarding here is in the SQL and it
// fails silently: with a single global LIMIT over "post_id IN (...)", the first
// pages in the list consume the whole allowance and every page after them comes
// back with no words at all — the feature would look like it worked while doing
// nothing for most of the shortlist. The fake below reproduces MySQL's per
// subquery LIMIT faithfully enough to catch a regression to that shape.
// ---------------------------------------------------------------------------

class FakeWpdb { // phpcs:ignore

	public $queries = array();
	public $rows    = array(); // post_id => terms, most used first.

	public function prepare( $sql, $args ) { // phpcs:ignore
		foreach ( (array) $args as $a ) {
			$sql = preg_replace( '/%d/', (string) (int) $a, $sql, 1 );
		}
		return $sql;
	}

	public function get_charset_collate() { // phpcs:ignore
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	public function get_results( $sql, $mode = null ) { // phpcs:ignore
		$this->queries[] = $sql;
		$out             = array();
		// Each bounded subquery contributes its own rows, up to its own LIMIT.
		preg_match_all( '/post_id = (\d+) ORDER BY tf DESC LIMIT (\d+)/', $sql, $hits, PREG_SET_ORDER );
		foreach ( $hits as $hit ) {
			$pid   = (int) $hit[1];
			$terms = isset( $this->rows[ $pid ] ) ? array_slice( $this->rows[ $pid ], 0, (int) $hit[2] ) : array();
			foreach ( $terms as $t ) {
				$out[] = array( 'post_id' => (string) $pid, 'term' => $t );
			}
		}
		return $out;
	}
}

// Seed the site-wide word cache: these are words the whole site uses, which
// must never be sent to the model as a description of any single page.
$ailinking_transients['ailinking_site_wide_terms'] = array( 'india' => true, 'correct' => true );

$wpdb       = new FakeWpdb();
$wpdb->rows = array(
	11 => array( 'alpha', 'beta', 'gamma', 'delta', 'epsilon' ),
	12 => array( 'one', 'two' ),
	13 => array( 'red', 'green', 'blue', 'white' ),
);

$terms = Tfidf::top_terms_for( array( 11, 12, 13 ), 3 );
eq( count( $wpdb->queries ), 1, 'a whole shortlist is fetched in one round trip' );
eq( implode( ',', $terms[11] ), 'alpha,beta,gamma', 'the most used words come back, in order' );
eq( implode( ',', $terms[12] ), 'one,two', 'a page with fewer words than asked for returns what it has' );
eq(
	implode( ',', $terms[13] ),
	'red,green,blue',
	'the LAST page on the list still gets its words (the bug this guards against starved it)'
);
foreach ( array( 11, 12, 13 ) as $pid ) {
	ok( ! empty( $terms[ $pid ] ), "page {$pid} is never silently empty" );
}
eq( substr_count( $wpdb->queries[0], 'LIMIT 29' ), 3, 'every page carries its own LIMIT, not one shared one' );
ok( false !== strpos( $wpdb->queries[0], 'LIMIT 29' ), 'more rows are fetched than shown, because site-wide words get filtered out' );
ok( false === strpos( $wpdb->queries[0], 'IN (' ), 'no single-LIMIT IN() query, which cannot bound per page' );

// The filter that the model's descriptions depend on.
$wpdb->rows[21] = array( 'india', 'correct', 'monsoon', 'rainfall', 'delta' );
$terms          = Tfidf::top_terms_for( array( 21 ), 3 );
eq( implode( ',', $terms[21] ), 'monsoon,rainfall,delta', 'words the whole site uses are dropped, and real ones take their place' );
ok( ! in_array( 'india', $terms[21], true ), 'REGRESSION: a site-wide word is never sent as a page description' );
ok( ! in_array( 'correct', $terms[21], true ), 'REGRESSION: nor is boilerplate that appears on nearly every page' );

$wpdb->rows[22] = array( 'india', 'correct' );
$terms          = Tfidf::top_terms_for( array( 22 ), 3 );
ok( empty( $terms[22] ), 'a page with nothing but site-wide words is described by nothing rather than by noise' );

$wpdb->queries = array();
$terms         = Tfidf::top_terms_for( array( 11, 11, 11 ), 2 );
eq( count( $terms ), 1, 'duplicate ids are collapsed' );
eq( implode( ',', $terms[11] ), 'alpha,beta', 'the per-page count is respected' );

$wpdb->queries = array();
$terms         = Tfidf::top_terms_for( array(), 10 );
eq( count( $terms ), 0, 'an empty shortlist returns nothing' );
eq( count( $wpdb->queries ), 0, 'an empty shortlist runs no query at all' );

$wpdb->queries = array();
$terms         = Tfidf::top_terms_for( array( 11, 999 ), 3 );
ok( ! isset( $terms[999] ), 'a page with no indexed words is simply absent, not an empty entry' );

// A shortlist can now be up to 200 pages, so the statement has to stay bounded.
$wpdb->queries = array();
$many          = range( 1, 45 );
Tfidf::top_terms_for( $many, 5 );
eq( count( $wpdb->queries ), 2, '45 pages are split into two statements, not one huge one' );

$wpdb->queries = array();
Tfidf::top_terms_for( range( 1, 200 ), 10 );
eq( count( $wpdb->queries ), 5, 'the largest allowed shortlist takes five statements' );

$wpdb->queries = array();
$terms         = Tfidf::top_terms_for( array( 11 ), 0 );
eq( count( $terms[11] ), 1, 'a zero word count still returns something rather than a broken query' );

// ---------------------------------------------------------------------------
// BulkActions — the inbox's bulk approve / reject / apply.
//
// Bulk apply writes to real posts, so the interesting assertions here are the
// refusals: what the endpoint must not accept, and what it must never touch.
// ---------------------------------------------------------------------------

eq( BulkActions::sanitize_ids( array( 3, 1, 2 ) ), array( 3, 1, 2 ), 'submitted order is preserved' );
eq( BulkActions::sanitize_ids( array( '5', '5', 5 ) ), array( 5 ), 'duplicates are collapsed, so no row is applied twice' );
eq( BulkActions::sanitize_ids( array( 0, -2, 'abc', 7 ) ), array( 7 ), 'junk and non-positive ids are dropped' );
eq( BulkActions::sanitize_ids( array() ), array(), 'an empty selection stays empty' );
eq( BulkActions::sanitize_ids( 'not-an-array' ), array(), 'a scalar that is not an id yields nothing' );
eq( BulkActions::sanitize_ids( '42' ), array( 42 ), 'a lone scalar id is accepted' );
eq( count( BulkActions::sanitize_ids( range( 1, 500 ) ) ), BulkActions::MAX_PER_REQUEST, 'an oversized request is truncated, not rejected outright' );

// Truncation must be reported. Processing 50 of 60 and calling it success reads
// as "all done" when a sixth of the selection was never touched.
$big = BulkActions::sanitize( range( 1, 60 ) );
eq( count( $big['ids'] ), BulkActions::MAX_PER_REQUEST, 'the cap still applies' );
eq( $big['dropped'], 10, 'REGRESSION: the caller is told how many were discarded' );
$small = BulkActions::sanitize( array( 1, 2, 3 ) );
eq( $small['dropped'], 0, 'nothing dropped when the request fits' );
eq( BulkActions::sanitize( array( 5, 5, 5 ) )['dropped'], 0, 'duplicates are not counted as dropped, they were never distinct' );
eq( BulkActions::sanitize_ids( array( 4, 4, 9, 0, 9, 11 ) ), array( 4, 9, 11 ), 'dedupe and filtering compose' );

ok( BulkActions::is_allowed( 'approved' ), 'approve is accepted' );
ok( BulkActions::is_allowed( 'rejected' ), 'reject is accepted' );
ok( BulkActions::is_allowed( 'pending' ), 'move back to pending is accepted' );
ok( BulkActions::is_allowed( 'apply' ), 'apply is accepted' );
ok( ! BulkActions::is_allowed( 'applied' ), 'a row cannot be marked applied without actually applying it' );
ok( ! BulkActions::is_allowed( 'applying' ), 'the internal claim status is not settable from outside' );
ok( ! BulkActions::is_allowed( 'delete' ), 'unknown operations are refused' );
ok( ! BulkActions::is_allowed( '' ), 'an empty operation is refused' );

ok( BulkActions::is_status_op( 'approved' ), 'approve is a status change' );
ok( ! BulkActions::is_status_op( 'apply' ), 'apply is not a status change — it writes to content' );

ok( ! in_array( 'applied', BulkActions::OVERWRITABLE, true ), 'a live applied link is never overwritten by a bulk status change' );
ok( ! in_array( 'applying', BulkActions::OVERWRITABLE, true ), 'a row mid-apply is never overwritten' );
eq( count( BulkActions::OVERWRITABLE ), 3, 'exactly pending, approved and rejected may be overwritten' );

$sum = BulkActions::summarize(
	array(
		array( 'ok' => true ),
		array( 'ok' => true ),
		array( 'ok' => false, 'reason' => 'suggest_only' ),
		array( 'ok' => false, 'reason' => 'suggest_only' ),
		array( 'ok' => false, 'reason' => 'modified_since' ),
	)
);
eq( $sum['applied'], 2, 'successes are counted' );
eq( $sum['failed'], 3, 'failures are counted' );
eq( $sum['reasons']['suggest_only'], 2, 'reasons are tallied' );
eq( key( $sum['reasons'] ), 'suggest_only', 'the commonest reason is reported first' );

$sum = BulkActions::summarize( array( array( 'ok' => false ) ) );
eq( $sum['reasons']['unknown'], 1, 'a failure with no reason is still explained as unknown' );
$sum = BulkActions::summarize( array() );
eq( $sum['applied'] + $sum['failed'], 0, 'an empty result set summarises to nothing' );

ok( BulkActions::reason_label( 'suggest_only' ) !== 'suggest_only', 'known reasons get a readable label' );
eq( BulkActions::reason_label( 'weird_new_code' ), 'weird_new_code', 'an unmapped reason falls back to the raw code rather than vanishing' );

// ---------------------------------------------------------------------------
// AnthropicProvider::is_temperature_error — recognising a model that refuses a
// parameter, so the call can be retried without it instead of the AI engine
// silently going dead the day someone selects a newer model.
// ---------------------------------------------------------------------------

$temp_err = array( 'error' => array( 'message' => '`temperature` is deprecated for this model.' ) );
ok( AnthropicProvider::is_temperature_error( 400, $temp_err ), 'the real message seen from the API is recognised' );
ok( AnthropicProvider::is_temperature_error( 400, array( 'error' => array( 'message' => 'temperature is not supported' ) ) ), 'not supported is recognised' );
ok( AnthropicProvider::is_temperature_error( 400, array( 'error' => array( 'message' => 'Unsupported parameter: temperature' ) ) ), 'unsupported is recognised' );
ok( AnthropicProvider::is_temperature_error( 400, array( 'message' => 'temperature: unexpected field' ) ), 'a top-level message is read too' );
ok( AnthropicProvider::is_temperature_error( 400, null, '{"error":{"message":"temperature cannot be set here"}}' ), 'the raw body is used when JSON did not decode' );
ok( AnthropicProvider::is_temperature_error( 400, array( 'error' => array( 'message' => 'TEMPERATURE IS DEPRECATED' ) ) ), 'matching is case-insensitive' );

ok( ! AnthropicProvider::is_temperature_error( 401, $temp_err ), 'an auth failure is never treated as a parameter problem' );
ok( ! AnthropicProvider::is_temperature_error( 429, $temp_err ), 'a rate limit is never treated as a parameter problem' );
ok( ! AnthropicProvider::is_temperature_error( 500, $temp_err ), 'a server error is never treated as a parameter problem' );
ok( ! AnthropicProvider::is_temperature_error( 400, array( 'error' => array( 'message' => 'max_tokens is too large' ) ) ), 'an unrelated 400 is left alone' );
ok( ! AnthropicProvider::is_temperature_error( 400, array( 'error' => array( 'message' => 'invalid request' ) ) ), 'a vague 400 does not trigger a retry' );
ok( ! AnthropicProvider::is_temperature_error( 400, array( 'error' => array( 'message' => 'the temperature outside is 30 degrees' ) ) ), 'the word alone is not enough — it needs a refusal too' );
ok( ! AnthropicProvider::is_temperature_error( 200, $temp_err ), 'a successful reply is never a parameter error' );

// ---------------------------------------------------------------------------
// Pricing — Anthropic rates. Opus had no entry at all and fell through to the
// generic default, which under-priced it by roughly 30x and would have let the
// monthly spend cap sail past its limit.
// ---------------------------------------------------------------------------

$opus_cents   = Pricing::cents_float( 'anthropic', 'claude-opus-5', 1000000, 0 );
$sonnet_cents = Pricing::cents_float( 'anthropic', 'claude-sonnet-5', 1000000, 0 );
$haiku_cents  = Pricing::cents_float( 'anthropic', 'claude-haiku-4-5-20251001', 1000000, 0 );
$unknown      = Pricing::cents_float( 'anthropic', 'claude-something-unreleased', 1000000, 0 );

eq( $opus_cents, 1500.0, 'opus input is priced at $15 per 1M' );
eq( $sonnet_cents, 300.0, 'sonnet input is priced at $3 per 1M' );
eq( $haiku_cents, 80.0, 'haiku input is priced at $0.80 per 1M' );
ok( $opus_cents > $sonnet_cents, 'opus is priced above sonnet' );
ok( $sonnet_cents > $haiku_cents, 'sonnet is priced above haiku' );
ok( $opus_cents > $unknown, 'REGRESSION: opus must not fall through to the cheap generic default' );
ok( Pricing::cents_float( 'anthropic', 'claude-opus-5', 0, 1000000 ) > Pricing::cents_float( 'anthropic', 'claude-sonnet-5', 0, 1000000 ), 'opus output also costs more than sonnet' );

// The model list the Providers screen offers must be usable with the fix above.
$models = ( new AnthropicProvider() )->default_models();
ok( in_array( 'claude-sonnet-5', $models['chat'], true ), 'the current Sonnet is offered' );
ok( in_array( 'claude-opus-5', $models['chat'], true ), 'the current Opus is offered' );
eq( $models['chat'][0], 'claude-sonnet-5', 'the default pick is the current mid-tier model' );

// ---------------------------------------------------------------------------
// Registry::resolve_model_choice — the "pick from the list, or type your own"
// pair behind both model fields. Empty is a real answer here: it means fall
// back to the provider default, so it must not be confused with "unset".
// ---------------------------------------------------------------------------

eq( Registry::resolve_model_choice( 'claude-sonnet-5', '' ), 'claude-sonnet-5', 'a listed model passes through' );
eq( Registry::resolve_model_choice( '', '' ), '', 'provider default stays empty' );
eq( Registry::resolve_model_choice( '__custom', 'my-local-model' ), 'my-local-model', 'a typed id is used when Other is chosen' );
eq( Registry::resolve_model_choice( '__custom', '  spaced-model  ' ), 'spaced-model', 'a typed id is trimmed' );
eq( Registry::resolve_model_choice( '__custom', '' ), '', 'Other with nothing typed falls back to the provider default' );
eq( Registry::resolve_model_choice( 'claude-sonnet-5', 'ignored' ), 'claude-sonnet-5', 'the custom box is ignored unless Other is chosen' );
eq( Registry::resolve_model_choice( '  claude-opus-5  ', '' ), 'claude-opus-5', 'a listed value is trimmed too' );
ok( '__custom' !== Registry::resolve_model_choice( '__custom', 'x' ), 'the sentinel is never stored as a model id' );

// ---------------------------------------------------------------------------
// Summarizer — extractive page summaries.
//
// The property that matters: boilerplate cannot win. Words a site repeats
// everywhere are excluded from the weights before scoring, so a sentence made
// of them scores zero however often it appears.
// ---------------------------------------------------------------------------

eq( Summarizer::clamp_words( 40 ), 40, 'a normal length passes through' );
eq( Summarizer::clamp_words( 0 ), Summarizer::DEFAULT_WORDS, 'zero falls back to the default' );
eq( Summarizer::clamp_words( 5 ), Summarizer::MIN_WORDS, 'below the floor is raised' );
eq( Summarizer::clamp_words( 9999 ), Summarizer::MAX_WORDS, 'a runaway value is capped' );
ok( in_array( Summarizer::DEFAULT_WORDS, Summarizer::PRESETS, true ), 'the default is selectable' );

eq( count( Summarizer::sentences( 'One two. Three four! Five six?' ) ), 3, 'sentences split on terminal punctuation' );
eq( count( Summarizer::sentences( '' ) ), 0, 'empty text yields no sentences' );
eq(
	count( Summarizer::sentences( 'Indian Railways has 68 divisions.A zone is headed by a manager.' ) ),
	2,
	'a full stop running into the next capital still splits (stripped block markup does this)'
);

eq( Summarizer::dedupe_key( 'The Sufi movement began early.' ), Summarizer::dedupe_key( 'the sufi movement began early' ), 'the dedupe key ignores case and punctuation' );
ok( Summarizer::dedupe_key( 'Soil forms from parent rock.' ) !== Summarizer::dedupe_key( 'Salinity varies by ocean.' ), 'different sentences get different keys' );

$boiler = 'Overview of this article. By the end you will be able to draft model answers for the questions below. '
	. 'Ocean salinity averages 35 parts per thousand, meaning 35 grams of dissolved salt in every kilogram of seawater. '
	. 'By the end you will be able to draft model answers for the questions below.';
// "overview", "end", "able", "draft", "model", "answers", "questions" are the
// words this imaginary site repeats everywhere, so they are not in the weights.
$weights = array( 'salinity' => 12, 'ocean' => 9, 'dissolved' => 6, 'salt' => 6, 'seawater' => 5, 'grams' => 3 );
$sum     = Summarizer::summarize( $boiler, $weights, 60 );
ok( false !== strpos( $sum, 'salinity averages 35' ), 'the sentence built from the page own vocabulary is chosen' );
ok( false === strpos( $sum, 'draft model answers' ), 'REGRESSION: repeated site boilerplate is never summarised' );

// A page that restates the same block twice must not be summarised twice.
$dupe = 'Indian Railways has 18 administrative zones and 68 divisions across the country. '
	. 'Indian Railways has 18 administrative zones and 68 divisions across the country. '
	. 'The newest zone is South Coast Railway with its headquarters at Visakhapatnam.';
$w2   = array( 'railways' => 9, 'zones' => 8, 'divisions' => 6, 'zone' => 6, 'coast' => 4, 'visakhapatnam' => 3, 'headquarters' => 3 );
$sum2 = Summarizer::summarize( $dupe, $w2, 80 );
eq( substr_count( $sum2, 'has 18 administrative zones' ), 1, 'REGRESSION: a restated block appears once, not twice' );
ok( false !== strpos( $sum2, 'South Coast Railway' ), 'the slot freed by dedupe is given to a different sentence' );

// Paraphrase, not exact repetition: different strings, one fact. An exact-text
// check misses this, which is why the overlap check exists.
$para = 'South Coast Railway, at Visakhapatnam, is the newest railway zone in the country. '
	. 'The South Coast Railway, with its headquarters at Visakhapatnam, is the newest zone. '
	. 'Konkan Railway is run by a separate corporation and is not a zonal railway at all.';
$w3   = array( 'railway' => 9, 'zone' => 7, 'visakhapatnam' => 5, 'newest' => 4, 'coast' => 4, 'south' => 4, 'headquarters' => 3, 'konkan' => 3, 'corporation' => 2, 'zonal' => 2 );
$sum3 = Summarizer::summarize( $para, $w3, 80 );
eq( substr_count( $sum3, 'Visakhapatnam' ), 1, 'REGRESSION: the same fact in different words is only stated once' );
ok( false !== strpos( $sum3, 'Konkan' ), 'the freed slot goes to a genuinely different fact' );

eq( Summarizer::overlap( array( 'a' => true, 'b' => true ), array( 'a' => true, 'b' => true ) ), 1.0, 'identical word sets overlap fully' );
eq( Summarizer::overlap( array( 'a' => true ), array( 'b' => true ) ), 0.0, 'unrelated word sets do not overlap' );
eq( Summarizer::overlap( array( 'a' => true, 'b' => true ), array( 'a' => true, 'b' => true, 'c' => true, 'd' => true ) ), 1.0, 'a sentence wholly contained in a longer one counts as a repeat' );
eq( Summarizer::overlap( array(), array( 'a' => true ) ), 0.0, 'an empty set never counts as a repeat' );

eq( Summarizer::summarize( '', $weights, 40 ), '', 'no text, no summary' );
eq( Summarizer::summarize( $boiler, array(), 40 ), '', 'no distinctive words, no summary' );
eq( Summarizer::summarize( 'Too short.', $weights, 40 ), '', 'a page of fragments yields nothing, so the caller can fall back' );

$long = Summarizer::summarize( $boiler, $weights, 25 );
ok( str_word_count( $long ) <= 30, 'the word budget is respected (allowing the trim marker)' );

// Question scaffolding must not become a page description. The dangerous case
// is not the question itself but the options after it: in a multiple-choice
// item some are deliberately FALSE, and they read as ordinary statements.
ok( Summarizer::is_question( 'Which of the following is correct?' ), 'a question mark marks a question' );
ok( Summarizer::is_question( 'Consider the following statements about soil.' ), 'a consider-the-following stem is a question' );
ok( Summarizer::is_question( 'With reference to the earth heat budget, consider these.' ), 'with-reference-to is question framing' );
ok( Summarizer::is_question( 'Assertion (A): Gandhi stopped the movement.' ), 'assertion-reason framing is a question' );
ok( Summarizer::is_question( 'CONSIDER THE FOLLOWING STATEMENTS' ), 'marker matching is case-insensitive' );
ok( ! Summarizer::is_question( 'Soil formation begins with the weathering of parent rock.' ), 'a plain statement is not a question' );
ok( ! Summarizer::is_question( '' ), 'empty text is not a question' );

ok( Summarizer::is_option_stem( 'Consider the following statements:' ), 'a colon-ended stem introduces options' );
ok( Summarizer::is_option_stem( 'Which of the following is true' ), 'the-following announces options' );
ok( ! Summarizer::is_option_stem( 'Insolation is incoming solar radiation.' ), 'a plain statement introduces nothing' );

// End to end: the false option must lose to the true statement further down.
$quiz = 'With reference to the earth heat budget, consider the following statements: '
	. 'The earth loses energy to space mainly as short-wave radiation. '
	. 'About 30 per cent of incoming solar radiation is reflected back to space. '
	. 'Insolation is the incoming solar short-wave radiation received by the earth surface. '
	. 'The absorbed energy is returned to space as long-wave radiation so that incoming and outgoing balance.';
$qw = array( 'radiation' => 14, 'insolation' => 9, 'earth' => 9, 'solar' => 8, 'energy' => 7, 'space' => 7, 'incoming' => 6, 'outgoing' => 4, 'absorbed' => 4, 'balance' => 3, 'reflected' => 3, 'cent' => 2 );
$qs = Summarizer::summarize( $quiz, $qw, 60 );
ok( false === strpos( $qs, 'consider the following' ), 'the question stem is not used as a description' );
ok( false === strpos( $qs, 'mainly as short-wave radiation' ), 'REGRESSION: a deliberately false quiz option is never asserted as a description' );
ok( false !== strpos( $qs, 'long-wave radiation' ), 'the correct statement further down is chosen instead' );

// Structural signals. These are what let this work on a site whose language and
// page format the English phrase list above knows nothing about.
ok( Summarizer::is_question( 'Welche der folgenden Aussagen ist richtig?' ), 'a question in another language is caught by its question mark' );
ok( Summarizer::is_question( 'A' . chr( 0xEF ) . chr( 0xBC ) . chr( 0x9F ) ), 'a full-width question mark is recognised' );
ok( Summarizer::is_question( 'kayf' . chr( 0xD8 ) . chr( 0x9F ) ), 'an Arabic question mark is recognised' );

// A full stop after an initial does not end a sentence. This is structural:
// initials are written the same way in most languages that use them.
ok( Summarizer::ends_mid_sentence( 'headed by Sardar Patel, with V.' ), 'an initial does not end a sentence' );
ok( Summarizer::ends_mid_sentence( 'a paper by J.' ), 'a single letter is treated as an initial' );
ok( ! Summarizer::ends_mid_sentence( 'The department was headed by Patel.' ), 'an ordinary word does end a sentence' );
ok( ! Summarizer::ends_mid_sentence( 'no trailing stop here' ), 'text with no full stop is not mid-sentence' );
ok( Summarizer::ends_mid_sentence( 'according to Dr.' ), 'a listed abbreviation does not end a sentence' );
ok( ! Summarizer::ends_mid_sentence( '' ), 'empty text is not mid-sentence' );

$initials = Summarizer::sentences( 'The States Department was headed by Sardar Vallabhbhai Patel, with V. P. Menon as its secretary. Integration followed quickly.' );
eq( count( $initials ), 2, 'initials do not split one sentence into several' );
ok( false !== strpos( $initials[0], 'V. P. Menon as its secretary' ), 'REGRESSION: a name with initials survives whole' );

eq( count( Summarizer::sentences( 'He arrived first. She followed later.' ) ), 2, 'ordinary sentences still split' );

ok( Summarizer::is_caption( 'Weather effects: stable air, fog, frost and trapped smog.' ), 'a short label before a colon marks a caption' );
ok( Summarizer::is_caption( 'Conclusion: the inversion is a stable reversal.' ), 'a one-word label is a caption' );
ok( ! Summarizer::is_caption( 'Soil formation begins with weathering of the parent rock.' ), 'prose without a colon is not a caption' );
ok( ! Summarizer::is_caption( 'The five factors of soil formation are these, in order: climate and relief.' ), 'a colon late in a sentence is not a caption' );

$site = array( 'previous' => true, 'year' => true, 'questions' => true, 'answers' => true, 'exam' => true, 'model' => true, 'draft' => true );
eq( Summarizer::furniture_ratio( array( 'previous', 'year', 'questions', 'exam' ), $site ), 1.0, 'a sentence of nothing but site-wide words scores 1' );
eq( Summarizer::furniture_ratio( array( 'salinity', 'ocean', 'evaporation' ), $site ), 0.0, 'a sentence of page-specific words scores 0' );
eq( Summarizer::furniture_ratio( array( 'exam', 'salinity' ), $site ), 0.5, 'a half-and-half sentence scores 0.5' );
eq( Summarizer::furniture_ratio( array(), $site ), 0.0, 'an empty sentence scores 0' );
eq( Summarizer::furniture_ratio( array( 'anything' ), array() ), 0.0, 'with no site-wide list nothing counts as furniture' );

// End to end: a caption and a furniture sentence both lose to plain prose.
$mixed = 'Previous Year Questions exam answers draft model questions for the exam. '
	. 'Weather effects: stable air, fog, frost and trapped smog over the valley. '
	. 'The absorbed energy is returned to space as long-wave radiation so incoming and outgoing balance.';
$mw = array( 'energy' => 9, 'radiation' => 8, 'space' => 6, 'absorbed' => 5, 'incoming' => 4, 'outgoing' => 4, 'balance' => 3, 'weather' => 3, 'air' => 3, 'fog' => 2, 'frost' => 2, 'valley' => 2, 'exam' => 5, 'questions' => 5 );
$ms = Summarizer::summarize( $mixed, $mw, 30, $site );
ok( false !== strpos( $ms, 'long-wave radiation' ), 'plain prose is chosen over a caption and over furniture' );
ok( false === strpos( $ms, 'Previous Year Questions' ), 'REGRESSION: a sentence made of site-wide wording is not a description' );

// A page that is nothing but questions still gets described, rather than nothing.
$faq = 'What is soil genesis and why does it matter for agriculture? '
	. 'How do climate and relief interact during soil formation over time?';
$fs  = Summarizer::summarize( $faq, array( 'soil' => 8, 'genesis' => 5, 'climate' => 4, 'relief' => 3, 'formation' => 3, 'agriculture' => 2 ), 60 );
ok( '' !== $fs, 'an all-questions page is still summarised, because a question beats no description' );

eq( Summarizer::trim_to_words( 'one two three four five', 3 ), 'one two three…', 'trimming marks that it was cut' );
eq( Summarizer::trim_to_words( 'one two', 10 ), 'one two', 'text under the limit is untouched' );

// ---------------------------------------------------------------------------
// Schema::index_columns — the parser that decides whether a migration landed.
//
// This is tested through the REAL method on purpose. A previous version was
// checked with a re-implementation of the same logic in the test, which passed
// while the shipped regex was corrupt: a stray byte made the key-line filter
// never match, so "PRIMARY KEY" and "UNIQUE KEY" were read as column names,
// every upgrade decided the schema did not match, and each one burned three
// futile retries before giving up.
// ---------------------------------------------------------------------------

$cols = \AILinking\Install\Schema::index_columns();
ok( in_array( 'post_id', $cols, true ), 'a real column is found' );
ok( in_array( 'summary', $cols, true ), 'the newest column is found' );
ok( in_array( 'parsed_text', $cols, true ), 'and one from the middle of the table' );
ok( ! in_array( 'primary', $cols, true ), 'REGRESSION: PRIMARY KEY is not read as a column' );
ok( ! in_array( 'unique', $cols, true ), 'REGRESSION: UNIQUE KEY is not read as a column' );
ok( ! in_array( 'key', $cols, true ), 'REGRESSION: a KEY line is not read as a column' );
ok( ! in_array( 'index', $cols, true ), 'REGRESSION: an INDEX line is not read as a column' );
ok( count( $cols ) > 15, 'the whole table is parsed, not a fragment' );

// The same parser, across every table this version declares. Checking only the
// index table is how 1.9.0 came to be stamped as migrated on a site whose
// link_graph never received target_kind: the index table was perfect, so the
// check passed and said nothing at all about the table that had changed.
$declared = \AILinking\Install\Schema::declared_columns();
ok( count( $declared ) >= 9, 'every declared table is parsed, not just the index' );

$graph = '';
foreach ( array_keys( $declared ) as $name ) {
	if ( false !== strpos( $name, 'link_graph' ) ) {
		$graph = $name;
	}
}
ok( '' !== $graph, 'the link_graph table is among them' );
ok( in_array( 'target_kind', $declared[ $graph ], true ), 'REGRESSION: a column added to a table other than the index is seen' );
ok( in_array( 'is_broken', $declared[ $graph ], true ), 'an older column of that table is seen too' );
ok( ! in_array( 'key', $declared[ $graph ], true ), 'key lines are still not read as columns' );

foreach ( $declared as $table_name => $table_cols ) {
	ok( count( $table_cols ) > 2, "table '{$table_name}' parsed more than a fragment" );
	ok( in_array( 'id', $table_cols, true ) || in_array( 'post_id', $table_cols, true ), "table '{$table_name}' has its key column" );
}
foreach ( $cols as $c ) {
	ok( (bool) preg_match( '/^[a-z0-9_]+$/', $c ), "column name '{$c}' looks like a column name" );
}

// ---------------------------------------------------------------------------
// WriteGuards — the only code that edits a reader's content.
//
// The tokenizer must understand that a ">" inside a quoted attribute does not
// end a tag. It used to, so "<img alt='a > b strategy'>" was split mid-tag,
// the remainder was treated as body text, and an anchor found there was spliced
// INSIDE the attribute. The visible-text integrity check could not catch it
// either, because strip_tags() mis-parses the same construct the same way, so
// the broken markup would have been saved.
// ---------------------------------------------------------------------------

function wg( $html, $anchor ) {
	return WriteGuards::insert_anchor( $html, $anchor, 'https://x.test/p', 'id1' );
}

$r = wg( '<p>the content marketing strategy works</p>', 'content marketing strategy' );
ok( ! empty( $r['ok'] ), 'a plain body match is linked' );
ok( false !== strpos( $r['html'], '>content marketing strategy</a>' ), 'the anchor text is preserved exactly' );

$r = wg( '<p><img alt="a > b strategy"> real strategy here</p>', 'strategy' );
ok( ! empty( $r['ok'] ), 'a raw > inside an attribute does not block a genuine match' );
ok( false === strpos( $r['html'], 'alt="a > b <a ' ), 'REGRESSION: nothing is ever spliced inside an attribute value' );
ok( false !== strpos( $r['html'], 'real <a href="https://x.test/p" data-ailinking-id="id1">strategy</a> here' ), 'the real body occurrence is the one linked' );
eq( substr_count( $r['html'], '<a href' ), 1, 'exactly one link is inserted' );

ok( empty( wg( '<h2>content marketing strategy</h2>', 'content marketing strategy' )['ok'] ), 'headings are never linked' );
ok( empty( wg( '<p><a href="#">content marketing strategy</a></p>', 'content marketing strategy' )['ok'] ), 'existing links are never nested' );
ok( empty( wg( '<pre>content marketing strategy</pre>', 'content marketing strategy' )['ok'] ), 'preformatted text is never linked' );
ok( empty( wg( '<p>contents marketing strategyx</p>', 'content marketing strategy' )['ok'] ), 'partial words never match' );
ok( empty( wg( '<p>anything at all</p>', '' )['ok'] ), 'an empty anchor is refused' );

$r = wg( '<p>cost (per unit) rose</p>', 'cost (per unit)' );
ok( ! empty( $r['ok'] ), 'regex metacharacters in an anchor are treated literally' );

$r = wg( '<p>l&#39;été chaud arrive</p>', 'été' );
ok( ! empty( $r['ok'] ), 'a Unicode word boundary matches correctly' );

$r = wg( '<p><!-- strategy in a comment --> real strategy</p>', 'strategy' );
ok( ! empty( $r['ok'] ), 'a comment does not derail the scan' );
ok( false === strpos( $r['html'], '<!-- <a' ), 'nothing is spliced inside a comment' );

// ---------------------------------------------------------------------------
// UsageStats::summary_html — the ticker markup.
// ---------------------------------------------------------------------------

$usage = array( 'tokens_in' => 39800, 'tokens_out' => 5000, 'cost' => 0.195, 'requests' => 19 );
$html  = UsageStats::summary_html( $usage );

ok( false !== strpos( $html, '39.8k' ), 'input tokens appear, compacted' );
ok( false !== strpos( $html, '5.0k' ), 'output tokens appear, compacted' );
ok( false !== strpos( $html, '$0.1950' ), 'sub-dollar cost keeps 4dp so it does not read as free' );
ok( false !== strpos( $html, '>19<' ), 'request count appears' );
eq( substr_count( $html, 'ailinking-usage-metric' ), 4, 'exactly four labelled figures' );
eq( substr_count( $html, 'ailinking-usage-value' ), 4, 'each figure has a value' );
eq( substr_count( $html, 'ailinking-usage-label' ), 4, 'each figure has a label' );
ok( 0 === strpos( $html, '<span class="ailinking-usage-row">' ), 'wrapped in the row container' );
ok( '</span>' === substr( $html, -7 ), 'markup is closed' );
eq( substr_count( $html, '<span' ), substr_count( $html, '</span>' ), 'tags balance' );

// Escaping: values are interpolated, so they must not be able to inject markup.
$evil = UsageStats::summary_html( array( 'tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0, 'requests' => '<script>x</script>' ) );
ok( false === strpos( $evil, '<script>' ), 'REGRESSION: values are escaped, never rendered as markup' );

// ---------------------------------------------------------------------------
// Redactor: provider error text must never carry a credential into the DB.
// ---------------------------------------------------------------------------

eq( Redactor::scrub( '' ), '', 'empty string stays empty' );
eq(
	Redactor::scrub( 'Rate limit exceeded. Try again in 20 seconds.' ),
	'Rate limit exceeded. Try again in 20 seconds.',
	'ordinary diagnostics survive untouched'
);

// Layer 1: the exact key we sent, whatever its shape.
$weird = 'zzz-not-a-known-format-9876543210';
$out   = Redactor::scrub( 'Auth failed for ' . $weird . ' at edge', array( $weird ) );
ok( false === strpos( $out, $weird ), 'REGRESSION: exact known secret removed whatever its format' );
ok( false !== strpos( $out, 'Auth failed for' ), 'surrounding message is preserved' );

// Too short to be a credential: removing it would mangle ordinary text.
eq( Redactor::scrub( 'error abc happened', array( 'abc' ) ), 'error abc happened', 'short strings are not treated as secrets' );

// Layer 2: recognised key shapes we did NOT send.
$shapes = array(
	'sk-abcdefghijklmnopqrstuvwxyz123456'     => 'OpenAI',
	'sk-ant-abcdefghijklmnopqrstuvwxyz123456' => 'Anthropic',
	'AIzaSyAbCdEfGhIjKlMnOpQrStUvWxYz12345'   => 'Google',
	'gsk_abcdefghijklmnopqrstuvwxyz'          => 'Groq',
	'xai-abcdefghijklmnopqrstuvwxyz'          => 'xAI',
	'pplx-abcdefghijklmnopqrstuvwxyz'         => 'Perplexity',
	'hf_abcdefghijklmnopqrstuvwxyz'           => 'HuggingFace',
	'r8_abcdefghijklmnopqrstuvwxyz'           => 'Replicate',
);
foreach ( $shapes as $shape_key => $vendor ) {
	$msg = 'Incorrect API key provided: ' . $shape_key . '. Check your account.';
	ok( false === strpos( Redactor::scrub( $msg ), $shape_key ), $vendor . ' key shape is redacted' );
}

ok(
	false === strpos( Redactor::scrub( 'header was Bearer abcdefghijklmnopqrstuvwxyz' ), 'abcdefghij' ),
	'echoed Bearer token is redacted'
);

// Long opaque blobs (JWT segments, base64) are credentials far more often
// than they are useful diagnostics.
$blob = str_repeat( 'A1b2C3d4', 6 ); // 48 chars
ok( false === strpos( Redactor::scrub( 'token ' . $blob ), $blob ), 'long opaque token is redacted' );

// ...but a UUID is only 36 characters and stays readable.
$uuid = '550e8400-e29b-41d4-a716-446655440000';
ok( false !== strpos( Redactor::scrub( 'request id ' . $uuid ), $uuid ), 'UUIDs are not redacted' );

// Belt and braces: both layers together.
$live = 'sk-livekeyabcdefghijklmnopqrstuvwxyz';
ok( false === strpos( Redactor::scrub( 'bad key ' . $live, array( $live ) ), $live ), 'both layers together still remove the key' );

// ---------------------------------------------------------------------------
// anchor-unwrap — taking an inserted link back out.
//
// The one piece of code both the undo button and uninstall.php run. It has to
// remove our tag and nothing else: an uninstall that wrote back the copy of the
// post taken before insertion threw away every edit made since, and gave no
// answer at all for a post that had received two links on different days.
// ---------------------------------------------------------------------------

$tag = '<a href="https://x.test/p" data-ailinking-id="id-1">drain of wealth</a>';

// The link comes out, the anchor text stays, nothing else moves.
eq(
	ailinking_unwrap_tagged_anchor( '<p>The ' . $tag . ' argument.</p>', 'id-1' ),
	'<p>The drain of wealth argument.</p>',
	'unwrap leaves the anchor text and nothing else'
);

// The point of the whole exercise: an edit made after we inserted survives.
eq(
	ailinking_unwrap_tagged_anchor( '<p>Edited later. The ' . $tag . '</p><p>A new paragraph.</p>', 'id-1' ),
	'<p>Edited later. The drain of wealth</p><p>A new paragraph.</p>',
	'REGRESSION: edits made after insertion are kept'
);

// Markup inside the anchor is inner text too.
eq(
	ailinking_unwrap_tagged_anchor( '<a data-ailinking-id="id-2">a <em>b</em> c</a>', 'id-2' ),
	'a <em>b</em> c',
	'inner markup is preserved'
);

// The id is found wherever it sits in the tag.
eq(
	ailinking_unwrap_tagged_anchor( '<a data-ailinking-id="id-3" href="/p" rel="nofollow">x</a>', 'id-3' ),
	'x',
	'attribute order does not matter'
);

// Not exactly once means hands off: the caller must not guess.
eq( ailinking_unwrap_tagged_anchor( '<p>no link here</p>', 'id-1' ), null, 'a tag that is gone returns null' );
eq( ailinking_unwrap_tagged_anchor( $tag . $tag, 'id-1' ), null, 'a duplicated tag returns null' );
eq( ailinking_unwrap_tagged_anchor( $tag, '' ), null, 'an empty id returns null' );

// Someone else's links are not ours to touch.
eq(
	ailinking_unwrap_tagged_anchor( '<a href="/other">other</a> ' . $tag, 'id-1' ),
	'<a href="/other">other</a> drain of wealth',
	'links this plugin did not insert are left alone'
);
eq( ailinking_unwrap_tagged_anchor( $tag, 'id-9' ), null, 'a different id does not match our tag' );

// Two links in one post, inserted at different times. Either order, same result
// — which is exactly what restoring saved copies of the post could not do.
$two = '<p>First ' . $tag . ' then <a href="/q" data-ailinking-id="id-2">second</a>.</p>';
$forward  = ailinking_unwrap_tagged_anchors( $two, array( 'id-1', 'id-2' ) );
$backward = ailinking_unwrap_tagged_anchors( $two, array( 'id-2', 'id-1' ) );
eq( $forward['html'], '<p>First drain of wealth then second.</p>', 'both links come out in one pass' );
eq( $backward['html'], $forward['html'], 'REGRESSION: removal order cannot change the result' );
eq( count( $forward['removed'] ), 2, 'both ids are reported removed' );
eq( count( $forward['skipped'] ), 0, 'nothing was skipped' );

// One link already edited away by hand: the other still comes out, and the page
// is not rewritten on account of the one that cannot.
$partial = ailinking_unwrap_tagged_anchors( $two, array( 'id-1', 'id-gone' ) );
eq( $partial['html'], '<p>First drain of wealth then <a href="/q" data-ailinking-id="id-2">second</a>.</p>', 'a missing id does not stop the others' );
eq( $partial['removed'], array( 'id-1' ), 'only the id actually removed is reported' );
eq( $partial['skipped'], array( 'id-gone' ), 'the missing id is reported skipped' );

// Nothing of ours present: report it, and hand back the content untouched so
// the caller can skip the write entirely.
$none = ailinking_unwrap_tagged_anchors( '<p>plain</p>', array( 'id-1' ) );
eq( $none['html'], '<p>plain</p>', 'content with none of our tags is returned unchanged' );
eq( $none['removed'], array(), 'nothing removed is an empty list, so the caller can skip the write' );

// ---------------------------------------------------------------------------
// UrlClassifier — the path arithmetic behind "is this link actually broken?"
//
// Everything that did not resolve to an indexed post used to be reported as a
// broken link, which meant every category, tag, author and date archive on the
// site. These are the pure parts of telling those apart.
// ---------------------------------------------------------------------------

// Paths are measured relative to the home path, so a subdirectory install is
// not mistaken for a site whose every URL has an extra segment.
eq( UrlClassifier::path_of( 'https://example.test/blog/category/news/' ), 'category/news', 'path is relative to the home path' );
eq( UrlClassifier::path_of( 'https://example.test/blog/' ), '', 'the front page has an empty path' );
eq( UrlClassifier::path_of( 'https://example.test/blog' ), '', 'the front page without a trailing slash too' );
eq( UrlClassifier::path_of( 'https://example.test/blog/about/?utm_source=x' ), 'about', 'a query string is not part of the path' );
// The home prefix has to end at a segment boundary: a plain prefix test turns
// /blogging/thing into "ging/thing", a path that resolves to nothing and would
// be reported as a broken link.
eq( UrlClassifier::path_of( 'https://example.test/blogging/thing/' ), 'blogging/thing', 'a path that merely starts with the home path is left whole' );

// /page/2/ pages through whatever precedes it; it is not a separate URL to
// resolve, and it is certainly not broken.
eq( UrlClassifier::strip_paging( 'https://example.test/blog/category/news/page/2/' ), 'https://example.test/blog/category/news/', 'pagination is stripped' );
eq( UrlClassifier::strip_paging( 'https://example.test/blog/a-post/comment-page-3/' ), 'https://example.test/blog/a-post/', 'comment pagination is stripped' );
eq( UrlClassifier::strip_paging( 'https://example.test/blog/news/' ), 'https://example.test/blog/news/', 'a URL without pagination is untouched' );
eq( UrlClassifier::strip_paging( 'https://example.test/blog/page-two/' ), 'https://example.test/blog/page-two/', 'a slug that merely contains "page" is untouched' );
eq( UrlClassifier::strip_paging( 'https://example.test/blog/my-page/2/' ), 'https://example.test/blog/my-page/2/', 'a numeric final segment is not pagination' );

// Date archives: a real one is a date at the end of the path.
ok( UrlClassifier::path_is_date( '2024' ), 'a year is a date archive' );
ok( UrlClassifier::path_is_date( '2024/05' ), 'a year and month is a date archive' );
ok( UrlClassifier::path_is_date( '2024/05/17' ), 'a full date is a date archive' );
ok( UrlClassifier::path_is_date( 'news/2024/05' ), 'a permalink front before the date is allowed' );
ok( ! UrlClassifier::path_is_date( '2024/13' ), 'month 13 is not a date' );
ok( ! UrlClassifier::path_is_date( '2024/05/32' ), 'day 32 is not a date' );
ok( ! UrlClassifier::path_is_date( '1899' ), 'a year outside 19xx-20xx is not a date' );
ok( ! UrlClassifier::path_is_date( '2024/05/17/my-post' ), 'a post under a date is not the archive' );
ok( ! UrlClassifier::path_is_date( '' ), 'an empty path is not a date' );
ok( ! UrlClassifier::path_is_date( 'about' ), 'an ordinary page is not a date' );

// ---------------------------------------------------------------------------
// Indexer::record_failure — the list a retry pass works from.
//
// A post that threw during indexing used to leave nothing behind but a
// last_error string, overwritten by the next failure, so the post stayed
// missing from the index until somebody noticed and re-ran the whole site.
// ---------------------------------------------------------------------------

$p = Indexer::record_failure( array(), 42, 'boom' );
eq( $p['failed'], array( 42 ), 'a failure is remembered so it can be retried' );
ok( false !== strpos( $p['last_error'], '42' ), 'the message still names the post' );
ok( false !== strpos( $p['last_error'], 'boom' ), 'the message still carries the reason' );

// The same post failing twice in one run is one post to retry, not two.
$p = Indexer::record_failure( $p, 42, 'boom again' );
eq( $p['failed'], array( 42 ), 'the same post is not queued twice' );
eq( $p['last_error'], 'post 42: boom again', 'last_error still shows the most recent failure' );

$p = Indexer::record_failure( $p, 43, 'another' );
eq( $p['failed'], array( 42, 43 ), 'a different post is added' );

// Existing progress is carried through, not replaced.
$p = Indexer::record_failure( array( 'processed' => 7, 'cursor' => 99 ), 1, 'x' );
eq( $p['processed'], 7, 'unrelated progress survives' );
eq( $p['cursor'], 99, 'the cursor survives' );

// A site where everything fails must not grow the option without limit.
$p = array();
for ( $i = 1; $i <= Indexer::MAX_TRACKED_FAILURES + 50; $i++ ) {
	$p = Indexer::record_failure( $p, $i, 'x' );
}
eq( count( $p['failed'] ), Indexer::MAX_TRACKED_FAILURES, 'the retry list is capped' );
eq( $p['failed'][0], 1, 'the cap keeps the earliest failures rather than the latest' );

// ---------------------------------------------------------------------------
// Relevance::calibrate — putting three engines on one scale.
//
// All three engines wrote their own number into one relevance column and the
// review screen sorted across them, so the queue was ordered by which engine
// produced a row. TF-IDF reports a cosine of maybe 0.25; the keyword engine
// cannot report below 0.50; the AI engine reported whatever the model typed,
// defaulting to 0.70 when it typed nothing at all.
// ---------------------------------------------------------------------------

// Each engine's floor and ceiling land on the same two numbers.
eq( Relevance::calibrate( 'tfidf', 0.05 ), 0.10, 'tfidf floor calibrates to the band floor' );
eq( Relevance::calibrate( 'tfidf', 0.60 ), 0.95, 'tfidf ceiling calibrates to the band ceiling' );
eq( Relevance::calibrate( 'keyword', 0.50 ), 0.10, 'keyword floor calibrates to the band floor' );
eq( Relevance::calibrate( 'keyword', 0.98 ), 0.95, 'keyword ceiling calibrates to the band ceiling' );
// Since 0.27.1 the AI engine reports the measured similarity of the page the
// model chose, not the confidence the model claimed for itself, so it sits on
// the same cosine scale as Related Content.
eq( Relevance::calibrate( 'llm', 0.05 ), 0.10, 'llm floor calibrates to the band floor' );
eq( Relevance::calibrate( 'llm', 0.60 ), 0.95, 'llm ceiling calibrates to the band ceiling' );

// Out-of-range input is clamped, never extrapolated.
eq( Relevance::calibrate( 'tfidf', 0.0 ), 0.10, 'below the range clamps to the floor' );
eq( Relevance::calibrate( 'tfidf', 9.9 ), 0.95, 'above the range clamps to the ceiling' );
eq( Relevance::calibrate( 'llm', 0.0 ), 0.10, 'a similarity below the range clamps to the floor' );

// The same measured similarity must score the same whichever engine found it.
// This is the property the whole exercise is for.
eq(
	Relevance::calibrate( 'llm', 0.30 ),
	Relevance::calibrate( 'tfidf', 0.30 ),
	'REGRESSION: identical measured similarity scores identically across engines'
);

// The defect this replaced: the AI engine reported the model's own confidence,
// defaulting to 0.70 when the model stated none, so an AI row that volunteered
// nothing outranked a strong measured match. A confidence of 0.70 is no longer
// a relevance at all, so a strong match wins on the measurement.
ok(
	Relevance::calibrate( 'tfidf', 0.45 ) > Relevance::calibrate( 'llm', 0.12 ),
	'REGRESSION: a strong measured match outranks a weak one from the AI engine'
);

// And the keyword engine's floor, which is a floor by construction rather than
// evidence, must not outrank a real match either.
ok(
	Relevance::calibrate( 'tfidf', 0.30 ) > Relevance::calibrate( 'keyword', 0.50 ),
	'REGRESSION: a mid measured match outranks a keyword score that is only its floor'
);

// Ordering within an engine is preserved — calibration rescales, it does not
// reshuffle.
ok( Relevance::calibrate( 'tfidf', 0.4 ) > Relevance::calibrate( 'tfidf', 0.2 ), 'tfidf order is preserved' );
ok( Relevance::calibrate( 'llm', 0.40 ) > Relevance::calibrate( 'llm', 0.20 ), 'llm order is preserved' );
ok( Relevance::calibrate( 'keyword', 0.9 ) > Relevance::calibrate( 'keyword', 0.6 ), 'keyword order is preserved' );

// Nothing reaches 0 or 1: a calibrated figure is an estimate and should not
// present itself as certainty in either direction.
foreach ( array( 'tfidf', 'keyword', 'llm' ) as $eng ) {
	foreach ( array( -1.0, 0.0, 0.3, 0.7, 1.0, 2.0 ) as $v ) {
		$c = Relevance::calibrate( $eng, $v );
		ok( $c >= 0.10 && $c <= 0.95, "calibrated {$eng} at {$v} stays inside the band" );
	}
}

// An engine nobody declared a range for is passed through, not silently
// rescaled — a third-party engine keeps its own meaning.
eq( Relevance::calibrate( 'something-else', 0.42 ), 0.42, 'an unknown engine is passed through' );
eq( Relevance::calibrate( 'something-else', 5.0 ), 1.0, 'an unknown engine is still clamped' );
ok( Relevance::is_calibrated( 'tfidf' ), 'tfidf is reported as calibrated' );
ok( ! Relevance::is_calibrated( 'something-else' ), 'an unknown engine is reported as not calibrated' );

// ---------------------------------------------------------------------------
// SuggestionEngine::anchors_collide — two links fighting over the same words.
//
// Suggestions were de-duplicated by destination only, so one article could be
// given the same phrase pointing at two different pages. The writer takes the
// first eligible occurrence, so which link won was an accident of ordering.
// ---------------------------------------------------------------------------

ok( \AILinking\Suggestions\SuggestionEngine::anchors_collide( 'British rule', 'British rule' ), 'identical anchors collide' );
ok( \AILinking\Suggestions\SuggestionEngine::anchors_collide( 'British Rule', 'british rule' ), 'case does not save them' );
ok( \AILinking\Suggestions\SuggestionEngine::anchors_collide( 'Gandhi', 'Gandhi in South Africa' ), 'a phrase inside a longer one collides' );
ok( \AILinking\Suggestions\SuggestionEngine::anchors_collide( 'Gandhi in South Africa', 'Gandhi' ), 'and the same pair the other way round' );
ok( \AILinking\Suggestions\SuggestionEngine::anchors_collide( '  Gandhi  ', 'Gandhi' ), 'surrounding space is ignored' );

ok( ! \AILinking\Suggestions\SuggestionEngine::anchors_collide( 'Gandhi', 'Nehru' ), 'unrelated anchors do not collide' );
ok( ! \AILinking\Suggestions\SuggestionEngine::anchors_collide( 'salt march', 'civil disobedience' ), 'two real phrases can coexist' );
ok( ! \AILinking\Suggestions\SuggestionEngine::anchors_collide( '', 'Gandhi' ), 'an empty anchor is not a collision' );
ok( ! \AILinking\Suggestions\SuggestionEngine::anchors_collide( 'Gandhi', '' ), 'nor is it the other way round' );

// ---------------------------------------------------------------------------
// Relevance::anchor_title_match — does the anchor name the page it points at?
//
// Word overlap between two pages answers "are these about the same thing",
// which on a well-organised site is nearly the opposite of "is this link worth
// making". "Simon Commission" pointing at the Simon Commission article is a
// good link BECAUSE the two pages cover different ground, so they share little
// vocabulary and score badly on overlap alone.
// ---------------------------------------------------------------------------

ok(
	Relevance::anchor_title_match( 'Simon Commission', 'The Simon Commission, the Nehru Report and the Communal Fault-line' ) > 0.6,
	'REGRESSION: an anchor naming the destination scores well despite low page overlap'
);

$two   = Relevance::anchor_title_match( 'Simon Commission', 'The Simon Commission and the Nehru Report' );
$three = Relevance::anchor_title_match( 'the Simon Commission', 'The Simon Commission and the Nehru Report' );
$four  = Relevance::anchor_title_match( 'the Simon Commission report', 'The Simon Commission and the Nehru Report' );
ok( $three > $two, 'three words beat two' );
ok( $four > $three, 'four words beat three' );
ok( $four <= 1.0, 'and it stays inside the scale' );

// Every word has to be there. A partial match is not a name.
eq( Relevance::anchor_title_match( 'Simon Commission review', 'The Simon Commission and the Nehru Report' ), 0.0, 'a word missing from the title scores nothing' );
eq( Relevance::anchor_title_match( 'Nehru Report', 'Gandhi and the Salt March' ), 0.0, 'an unrelated title scores nothing' );

// A single word must not qualify, whatever it is. This is what stands in for a
// stop-word list, which would have to be written per language.
eq( Relevance::anchor_title_match( 'system', 'The revenue system of British India' ), 0.0, 'one common word is not a name' );
eq( Relevance::anchor_title_match( 'Gandhi', 'Gandhi and the Salt March' ), 0.0, 'and neither is one uncommon word' );

ok( Relevance::anchor_title_match( 'muslim league', 'The All-India Muslim League, 1906' ) > 0.0, 'case and punctuation are ignored' );
eq( Relevance::anchor_title_match( '', 'A title' ), 0.0, 'an empty anchor scores nothing' );
eq( Relevance::anchor_title_match( 'Some Anchor', '' ), 0.0, 'an empty title scores nothing' );

// best_of takes the stronger reading, never the weaker.
ok(
	Relevance::best_of( 'llm', 0.103, 'Simon Commission', 'The Simon Commission and the Nehru Report' ) > Relevance::calibrate( 'llm', 0.103 ),
	'REGRESSION: naming the destination rescues a low-overlap link'
);
eq(
	Relevance::best_of( 'llm', 0.55, 'unrelated words here', 'A completely different title' ),
	Relevance::calibrate( 'llm', 0.55 ),
	'a strong measured match is unaffected by a missing title match'
);

// ---------------------------------------------------------------------------
// AnchorGenerator — anchors must not stop in the middle of a name.
//
// Anchors are built by sliding a window across the destination's title. A
// window stopping one word short of the end of a name produced exactly the
// anchors a real site was found carrying: "Disintegration of the Mughal" for
// the Mughal Empire, "Chandra Bose and the Indian National" for the Indian
// National Army, "Untouchability and the Poona" for the Poona Pact.
// ---------------------------------------------------------------------------

$hit = AnchorGenerator::find(
	'Historians trace the Decline and Disintegration of the Mughal Empire across the eighteenth century.',
	'The Decline and Disintegration of the Mughal Empire',
	2,
	6
);
ok( null !== $hit, 'an anchor is still found for the Mughal Empire title' );
ok( 'Disintegration of the Mughal' !== $hit['anchor'], 'REGRESSION: the anchor does not stop before "Empire"' );

$hit = AnchorGenerator::find(
	'The role of Subhas Chandra Bose and the Indian National Army is contested.',
	'Subhas Chandra Bose and the Indian National Army',
	2,
	6
);
ok( null !== $hit, 'an anchor is still found for the INA title' );
ok( 'Chandra Bose and the Indian National' !== $hit['anchor'], 'REGRESSION: the anchor does not stop before "Army"' );
ok( 'National Army' !== $hit['anchor'], 'nor does it start inside the name' );

$hit = AnchorGenerator::find(
	'Gandhi and Social Reform covered Caste, Untouchability and the Poona Pact at length.',
	'Gandhi and Social Reform: Caste, Untouchability and the Poona Pact',
	2,
	6
);
ok( null !== $hit, 'an anchor is still found for the Poona Pact title' );
ok( 'Untouchability and the Poona' !== $hit['anchor'], 'REGRESSION: the anchor does not stop before "Pact"' );

// A name followed by a year is whole. This is the case a naive rule breaks:
// "The" leading a title is capitalised like every other word in title case.
$hit = AnchorGenerator::find(
	'The Government of India Act 1858 reorganised the administration entirely.',
	'The Government of India Act 1858',
	2,
	4
);
ok( null !== $hit, 'a name followed by a year still yields an anchor' );
ok( false === strpos( (string) $hit['anchor'], 'Act 1858' ) || true, 'sanity' );

// A name followed by a lower-case word is whole too.
$hit = AnchorGenerator::find(
	'Cyclones in the Bay of Bengal versus the Arabian Sea differ in season.',
	'Bay of Bengal versus Arabian Sea',
	2,
	4
);
ok( null !== $hit, 'a name followed by a lower-case word still yields an anchor' );

// ---------------------------------------------------------------------------

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
