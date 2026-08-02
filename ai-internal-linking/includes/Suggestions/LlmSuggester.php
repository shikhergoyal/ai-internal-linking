<?php
/**
 * Generative link suggestions via a user-supplied chat model (any provider in the
 * key pool: OpenAI, Claude, Gemini, Groq, local, …).
 *
 * Grounding + safety: the model never invents targets or anchors. Candidate
 * targets are constrained to a real TF-IDF recall pool (existing pages), and every
 * anchor the model proposes is verified to appear verbatim in the source body via
 * AnchorGenerator::locate_phrase() (whole-word, case-insensitive, never inside a
 * heading). Anything the model fabricates is simply dropped, so the wrap-first
 * guarantee holds regardless of what the model returns.
 *
 * @package AILinking
 */

namespace AILinking\Suggestions;

use AILinking\Providers\Gateway;
use AILinking\Support\Settings;

defined( 'ABSPATH' ) || exit;

class LlmSuggester {

	/** Max candidates shown to the model. */
	const MAX_CANDIDATES = 15;

	/**
	 * Words of each page sent to the model when no setting is stored.
	 * Roughly 6,000 characters, which is what this used to hard-code.
	 */
	const DEFAULT_MAX_WORDS = 1000;

	/** Ceiling for the setting, so a typo cannot bill for a novel per page. */
	const MAX_WORDS_LIMIT = 2500;

	/**
	 * Ask the model to choose links from the candidate pool.
	 *
	 * @param int    $source_id Source post id.
	 * @param string $text      Source plain text.
	 * @param string $title     Source title.
	 * @param array  $pool      TF-IDF candidates: each [post_id,title,url,score].
	 * @param int    $min_words Preferred minimum anchor words.
	 * @param int    $max_words Preferred maximum anchor words.
	 * @param int    $limit     Max picks to return.
	 * @return array[] Each: [post_id,url,anchor,context,score,reason].
	 */
	public static function find( $source_id, $text, $title, array $pool, $min_words, $max_words, $limit ) {
		$limit = max( 1, (int) $limit );

		// Only real candidates with a title; keep the numbering stable.
		$candidates = array();
		foreach ( $pool as $c ) {
			$t = isset( $c['title'] ) ? trim( (string) $c['title'] ) : '';
			if ( '' === $t || empty( $c['post_id'] ) ) {
				continue;
			}
			$candidates[] = $c;
			if ( count( $candidates ) >= self::MAX_CANDIDATES ) {
				break;
			}
		}
		if ( empty( $candidates ) ) {
			return array();
		}

		$response = Gateway::chat(
			array(
				'system'          => self::system_prompt( $min_words, $max_words, $limit ),
				'messages'        => array(
					array(
						'role'    => 'user',
						'content' => self::user_prompt( $title, $text, $candidates ),
					),
				),
				'max_tokens'      => 800,
				'temperature'     => 0,
				'response_format' => 'json',
				'purpose'         => 'link_suggestions',
			)
		);

		if ( empty( $response['ok'] ) || empty( $response['text'] ) ) {
			return array(); // Degrade silently; TF-IDF still runs.
		}

		$links = self::parse_links( (string) $response['text'] );
		if ( empty( $links ) ) {
			return array();
		}

		$picks = array();
		$seen  = array();
		foreach ( $links as $link ) {
			if ( count( $picks ) >= $limit ) {
				break;
			}
			$n = isset( $link['n'] ) ? (int) $link['n'] : 0;
			if ( $n < 1 || $n > count( $candidates ) ) {
				continue; // Out of range — model must pick from the list.
			}
			$cand = $candidates[ $n - 1 ];
			$tid  = (int) $cand['post_id'];
			if ( isset( $seen[ $tid ] ) || $tid === (int) $source_id ) {
				continue;
			}

			$anchor = isset( $link['anchor'] ) ? trim( (string) $link['anchor'] ) : '';
			if ( '' === $anchor ) {
				continue;
			}

			// Wrap-first: the anchor must already exist in the body, verbatim.
			$hit = AnchorGenerator::locate_phrase( $text, $anchor );
			if ( null === $hit ) {
				continue;
			}

			$conf = isset( $link['confidence'] ) ? (float) $link['confidence'] : 0.7;
			$conf = max( 0.0, min( 1.0, $conf ) );

			$picks[]      = array(
				'post_id' => $tid,
				'url'     => isset( $cand['url'] ) ? (string) $cand['url'] : '',
				'anchor'  => $hit['anchor'],
				'context' => $hit['context'],
				'score'   => $conf,
				'reason'  => isset( $link['reason'] ) ? sanitize_text_field( (string) $link['reason'] ) : '',
			);
			$seen[ $tid ] = true;
		}

		return $picks;
	}

	/**
	 * System instruction.
	 *
	 * @param int $min_words Min anchor words.
	 * @param int $max_words Max anchor words.
	 * @param int $limit     Max links.
	 * @return string
	 */
	private static function system_prompt( $min_words, $max_words, $limit ) {
		return 'You are an internal-linking assistant for a website. You receive one article and a numbered list of OTHER existing pages on the same site. '
			. 'Decide which of those pages the article should link to, and for each pick a short anchor phrase that ALREADY appears word-for-word in the article body. '
			. 'Rules: '
			. '(1) Only choose from the numbered candidate pages; never invent a page or a number outside the list. '
			. '(2) The anchor MUST be copied verbatim from the article text: do not invent, rephrase, translate, pluralize, or fix its casing. If no natural anchor for a candidate exists in the text, skip that candidate. '
			. '(3) Prefer descriptive phrases of ' . (int) $min_words . ' to ' . (int) $max_words . ' words; never use a whole sentence, a heading, or the article title. '
			. '(4) Only include a link that is genuinely topically relevant and reads naturally where the anchor sits. '
			. '(5) Return at most ' . (int) $limit . ' links, best first. It is fine to return fewer, or none. '
			. 'Respond with JSON only, no prose, in exactly this shape: {"links":[{"n":<candidate number>,"anchor":"<verbatim phrase from the article>","reason":"<short reason>","confidence":<number 0-1>}]}';
	}

	/**
	 * User message: the article and the candidate list.
	 *
	 * @param string $title      Source title.
	 * @param string $text       Source text.
	 * @param array  $candidates Filtered candidates (1-indexed to the model).
	 * @return string
	 */
	private static function user_prompt( $title, $text, array $candidates ) {
		$excerpt = self::truncate_words( $text, self::max_words() );

		$lines = array();
		$i     = 1;
		foreach ( $candidates as $c ) {
			$lines[] = $i . '. ' . trim( (string) $c['title'] );
			$i++;
		}

		return "ARTICLE TITLE: " . trim( (string) $title ) . "\n\n"
			. "ARTICLE TEXT:\n" . $excerpt . "\n\n"
			. "CANDIDATE PAGES:\n" . implode( "\n", $lines );
	}

	/**
	 * Extract the links array from a model response (tolerant of code fences /
	 * leading prose).
	 *
	 * @param string $raw Model text.
	 * @return array
	 */
	private static function parse_links( $raw ) {
		$raw = trim( $raw );

		// Strip ```json ... ``` fences if present.
		if ( 0 === strpos( $raw, '```' ) ) {
			$raw = preg_replace( '/^```[a-zA-Z]*\s*/', '', $raw );
			$raw = preg_replace( '/\s*```$/', '', $raw );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			// Fall back to the first {...} block.
			$start = strpos( $raw, '{' );
			$end   = strrpos( $raw, '}' );
			if ( false === $start || false === $end || $end <= $start ) {
				return array();
			}
			$data = json_decode( substr( $raw, $start, $end - $start + 1 ), true );
			if ( ! is_array( $data ) ) {
				return array();
			}
		}

		if ( isset( $data['links'] ) && is_array( $data['links'] ) ) {
			return $data['links'];
		}
		// Some models return a bare array.
		return isset( $data[0] ) ? $data : array();
	}

	/**
	 * Truncate to a character budget on a word boundary.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max chars.
	 * @return string
	 */
	/**
	 * How many words of each page to send, from the Setup screen setting.
	 *
	 * Clamped rather than trusted: this multiplies directly into the token
	 * bill on every post of every scan.
	 *
	 * @return int
	 */
	public static function max_words() {
		$words = (int) Settings::get( 'llm_max_words', self::DEFAULT_MAX_WORDS );
		if ( $words <= 0 ) {
			$words = self::DEFAULT_MAX_WORDS;
		}
		/**
		 * Filter the per-page word budget sent to the chat model.
		 *
		 * @param int $words Words per page.
		 */
		$words = (int) apply_filters( 'ailinking_llm_max_words', $words );
		return max( 100, min( self::MAX_WORDS_LIMIT, $words ) );
	}

	/**
	 * Keep the first N words of a text. (pure)
	 *
	 * Words, not characters, because that is the unit the setting is expressed
	 * in and the unit a person can reason about. Splitting on Unicode
	 * whitespace keeps this correct for non-Latin scripts.
	 *
	 * @param string $text      Source text.
	 * @param int    $max_words Word budget.
	 * @return string
	 */
	public static function truncate_words( $text, $max_words ) {
		$text      = trim( (string) $text );
		$max_words = max( 1, (int) $max_words );
		if ( '' === $text ) {
			return '';
		}

		// limit+1: the final element holds the untaken remainder, so dropping
		// it leaves exactly $max_words words without walking the whole text.
		$parts = preg_split( '/\s+/u', $text, $max_words + 1 );
		if ( ! is_array( $parts ) ) {
			return $text; // pathological input: send it rather than nothing.
		}
		if ( count( $parts ) > $max_words ) {
			array_pop( $parts );
		}
		return implode( ' ', $parts );
	}
}
