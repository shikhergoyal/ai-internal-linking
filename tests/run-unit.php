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

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' ); // $wpdb result-shape constant.

$plugin = __DIR__ . '/../ai-internal-linking/includes';
require_once $plugin . '/Integrations/KeywordImporter.php';
require_once $plugin . '/Suggestions/KeywordSuggester.php';
require_once $plugin . '/Suggestions/Naturalness.php';
require_once $plugin . '/Providers/Pricing.php';
require_once $plugin . '/Providers/UsageStats.php';
require_once $plugin . '/Security/Redactor.php';
require_once $plugin . '/Suggestions/LlmSuggester.php';
require_once __DIR__ . '/stubs/tables.php'; // Must precede Tfidf, which calls it.
require_once $plugin . '/Suggestions/Tfidf.php';

use AILinking\Integrations\KeywordImporter;
use AILinking\Suggestions\KeywordSuggester;
use AILinking\Suggestions\Naturalness;
use AILinking\Providers\Pricing;
use AILinking\Providers\UsageStats;
use AILinking\Security\Redactor;
use AILinking\Suggestions\LlmSuggester;
use AILinking\Suggestions\Tfidf;

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

eq( Naturalness::score( 'two words', 0.5 ), 0.85, 'multi-word anchor of good length' );
ok( Naturalness::score( 'abc', 0.5 ) < Naturalness::score( 'two words', 0.5 ), 'very short anchors score worse' );
ok( Naturalness::score( str_repeat( 'a', 80 ), 0.5 ) < Naturalness::score( 'two words', 0.5 ), 'over-long anchors score worse' );
ok( Naturalness::score( 'anything', 1.0 ) <= 1.0, 'naturalness never exceeds 1' );
ok( Naturalness::score( 'a', 0.0 ) >= 0.0, 'naturalness never goes below 0' );
eq( Naturalness::confidence( 0.5, 0.85 ), 0.64, 'confidence = 0.6*relevance + 0.4*naturalness' );

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
eq( substr_count( $wpdb->queries[0], 'LIMIT 3' ), 3, 'every page carries its own LIMIT, not one shared one' );
ok( false === strpos( $wpdb->queries[0], 'IN (' ), 'no single-LIMIT IN() query, which cannot bound per page' );

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

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
