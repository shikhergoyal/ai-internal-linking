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
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) { // phpcs:ignore
		return number_format( (float) $number, (int) $decimals );
	}
}

$plugin = __DIR__ . '/../ai-internal-linking/includes';
require_once $plugin . '/Integrations/KeywordImporter.php';
require_once $plugin . '/Suggestions/KeywordSuggester.php';
require_once $plugin . '/Suggestions/Naturalness.php';
require_once $plugin . '/Providers/Pricing.php';
require_once $plugin . '/Providers/UsageStats.php';
require_once $plugin . '/Security/Redactor.php';

use AILinking\Integrations\KeywordImporter;
use AILinking\Suggestions\KeywordSuggester;
use AILinking\Suggestions\Naturalness;
use AILinking\Providers\Pricing;
use AILinking\Providers\UsageStats;
use AILinking\Security\Redactor;

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
