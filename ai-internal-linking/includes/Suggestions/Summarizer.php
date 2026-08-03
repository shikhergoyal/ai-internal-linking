<?php
/**
 * Extractive page summaries: pick the sentences that best represent a page,
 * using its own indexed vocabulary.
 *
 * Why extract rather than take the opening, or read a meta description:
 *
 * - The opening of a page is usually throat-clearing or template furniture.
 *   On one real site every page began "Overview. <title>. <site name> Previous
 *   Year Questions. By the end you will be able to..." — identical across the
 *   whole site, which is worse than useless as a description.
 * - A meta description is often absent, and a plugin cannot require one. Where
 *   it exists it may be promotional or templated rather than descriptive.
 *
 * Scoring by the page's own distinctive words has a useful property that falls
 * out for free: boilerplate cannot win. Words a site repeats everywhere are
 * excluded as site-wide (see Tfidf::site_wide_terms), so a sentence built from
 * them scores zero no matter how often it appears.
 *
 * @package AILinking
 */

namespace AILinking\Suggestions;

defined( 'ABSPATH' ) || exit;

class Summarizer {

	/** Default words in a stored summary. Two solid sentences on most pages. */
	const DEFAULT_WORDS = 40;

	/** Below this a summary says less than a keyword list would. */
	const MIN_WORDS = 25;

	/** Above this it stops being a summary and starts being the page. */
	const MAX_WORDS = 200;

	/** Values offered in the Setup screen dropdown. */
	const PRESETS = array( 25, 40, 60, 100, 200 );

	/** Sentences shorter than this are fragments, not descriptions. */
	const MIN_SENTENCE_WORDS = 8;

	/** Longer than this and one sentence would eat the whole budget. */
	const MAX_SENTENCE_WORDS = 45;

	/** Never take more than this many sentences, however small the budget. */
	const MAX_SENTENCES = 4;

	/**
	 * Reject a sentence sharing more than this share of its distinctive words
	 * with one already taken.
	 *
	 * An exact-text check is not enough. Pages restate a point in different
	 * words — "South Coast Railway, at Visakhapatnam, is the newest zone" and
	 * "The South Coast Railway, with its headquarters at Visakhapatnam, is the
	 * newest zone" are different strings saying one thing, and both score alike,
	 * so both get picked. Comparing the distinctive words catches the paraphrase.
	 */
	const OVERLAP_LIMIT = 0.7;

	/**
	 * Score multiplier for a sentence that asks rather than tells.
	 *
	 * A penalty, not a ban. On a page that is genuinely all questions — an FAQ,
	 * a quiz — banning them would leave nothing to summarise with, and a
	 * question is still a better description than no description. Weighted this
	 * low, a question is only ever used when the page offers nothing else.
	 */
	const QUESTION_PENALTY = 0.15;

	/**
	 * Score multiplier for the sentences immediately after a question stem.
	 *
	 * "Consider the following statements:" is followed by the options, and in a
	 * multiple-choice question some of those options are deliberately FALSE. One
	 * such line was picked as a page description on a live site, leaving the
	 * summary asserting the very thing the page exists to correct. They read as
	 * ordinary declarative sentences, so nothing about the sentence itself gives
	 * them away — only their position after the stem does.
	 */
	const OPTION_PENALTY = 0.3;

	/** How many sentences after a stem are treated as its options. */
	const OPTION_REACH = 3;

	/**
	 * Score multiplier for a caption or label rather than a sentence.
	 *
	 * "Weather effects: stable air, fog, frost and trapped smog." is a label and
	 * its list, not a statement about the page. A colon within the opening few
	 * words is what gives it away, and that is structural rather than linguistic,
	 * so it holds for a site in any language.
	 */
	const CAPTION_PENALTY = 0.4;

	/** A colon this early in a sentence marks it as a label, not prose. */
	const CAPTION_COLON_WITHIN = 4;

	/**
	 * Score multiplier for a sentence built mostly from site-wide wording.
	 *
	 * Template furniture reuses the site's own frame with a few topical words
	 * dropped in. Measured on a real site, sentences that were pure furniture
	 * scored 0.86 to 1.00 on this ratio while genuine prose scored 0.00 to 0.14,
	 * so the two separate cleanly. It needs no phrase list and no knowledge of
	 * the language, which is the point: the phrase list below cannot help a site
	 * that is not in English.
	 */
	const FURNITURE_PENALTY = 0.2;

	/** Share of a sentence's words that must be site-wide before it counts as furniture. */
	const FURNITURE_RATIO = 0.5;

	/**
	 * Question marks, including the forms used outside Latin scripts.
	 *
	 * @return string[]
	 */
	public static function question_marks() {
		return array( '?', "\xEF\xBC\x9F", "\xD8\x9F", "\xE2\x81\x87", "\xE0\xB9\x96" );
	}

	/**
	 * Phrases that mark a sentence as part of a question rather than a
	 * description of the page. Lowercase, matched as substrings.
	 *
	 * These are English, and deliberately a secondary signal: a phrase list can
	 * only ever cover the language and the page format it was written for. The
	 * checks that carry the weight — a question mark, an opening colon, and a
	 * sentence made of site-wide wording — are structural and work anywhere.
	 * Sites with differently worded questions can replace this list through the
	 * ailinking_question_markers filter.
	 *
	 * @return string[]
	 */
	public static function question_markers() {
		$markers = array(
			'consider the following',
			'which of the following',
			'which one of the following',
			'select the correct',
			'choose the correct',
			'with reference to',
			'assertion (a)',
			'reason (r)',
			'how many of the above',
			'the correct answer is',
			'statement 1',
			'statement i:',
			'codes:',
			'match list',
			'given below',
			'answer:',
			'explanation:',
		);
		/**
		 * Filter the phrases that mark a sentence as question scaffolding.
		 *
		 * @param string[] $markers Lowercase substrings.
		 */
		return (array) apply_filters( 'ailinking_question_markers', $markers );
	}

	/**
	 * Whether a sentence asks rather than tells. (pure)
	 *
	 * @param string $sentence Sentence.
	 * @return bool
	 */
	public static function is_question( $sentence ) {
		$s = trim( (string) $sentence );
		if ( '' === $s ) {
			return false;
		}
		foreach ( self::question_marks() as $mark ) {
			if ( false !== strpos( $s, $mark ) ) {
				return true;
			}
		}

		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		foreach ( self::question_markers() as $marker ) {
			if ( false !== strpos( $lower, $marker ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a sentence is a label with its content, rather than prose. (pure)
	 *
	 * @param string $sentence Sentence.
	 * @return bool
	 */
	public static function is_caption( $sentence ) {
		$s = trim( (string) $sentence );
		$pos = strpos( $s, ':' );
		if ( false === $pos ) {
			return false;
		}
		$before = substr( $s, 0, $pos );
		$words  = preg_split( '/\s+/u', trim( $before ) );
		return is_array( $words ) && count( $words ) <= self::CAPTION_COLON_WITHIN;
	}

	/**
	 * Share of a sentence's words that the whole site uses. (pure)
	 *
	 * @param string[]            $words     Tokenised sentence words.
	 * @param array<string,true>  $site_wide Site-wide word set.
	 * @return float 0 to 1.
	 */
	public static function furniture_ratio( array $words, array $site_wide ) {
		$total = count( $words );
		if ( $total < 1 || empty( $site_wide ) ) {
			return 0.0;
		}
		$hits = 0;
		foreach ( $words as $w ) {
			if ( isset( $site_wide[ $w ] ) ) {
				$hits++;
			}
		}
		return $hits / $total;
	}

	/**
	 * Whether a sentence introduces a list of options. (pure)
	 *
	 * @param string $sentence Sentence.
	 * @return bool
	 */
	public static function is_option_stem( $sentence ) {
		$s = rtrim( trim( (string) $sentence ) );
		if ( '' === $s ) {
			return false;
		}
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );

		// A stem either trails into its options with a colon, or announces them.
		return ':' === substr( $s, -1 )
			|| false !== strpos( $lower, 'the following' )
			|| false !== strpos( $lower, 'given below' );
	}

	/**
	 * Clamp a summary length into the supported range. (pure)
	 *
	 * @param int $words Requested length.
	 * @return int
	 */
	public static function clamp_words( $words ) {
		$words = (int) $words;
		if ( $words <= 0 ) {
			$words = self::DEFAULT_WORDS;
		}
		return max( self::MIN_WORDS, min( self::MAX_WORDS, $words ) );
	}

	/**
	 * Split plain text into sentences. (pure)
	 *
	 * Splits on terminal punctuation, and also on a full stop that runs
	 * straight into the next capital with no space. Stripped block markup
	 * routinely produces "...68 divisions.A zone is headed by...", and without
	 * this both halves would be treated as one unreadable sentence.
	 *
	 * @param string $text Plain text.
	 * @return string[]
	 */
	public static function sentences( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return array();
		}

		$text = preg_replace( '/\s+/u', ' ', $text );
		// Insert the missing space so the split below can see the boundary.
		$text = preg_replace( '/([.!?])(\p{Lu})/u', '$1 $2', $text );

		$parts = preg_split( '/(?<=[.!?])\s+/u', $text );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$out = array();
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * A comparison key for spotting a sentence that repeats one already taken.
	 *
	 * Pages often restate a summary block ("Current relevance" panels, key-points
	 * boxes). Both copies score identically, so without this the top two picks
	 * are frequently the same sentence twice. (pure)
	 *
	 * @param string $sentence Sentence.
	 * @return string
	 */
	public static function dedupe_key( $sentence ) {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $sentence, 'UTF-8' ) : strtolower( $sentence );
		$s = preg_replace( '/[^\p{L}\p{Nd}\s]/u', '', $s );
		$s = trim( preg_replace( '/\s+/u', ' ', $s ) );

		// First twelve words: enough to catch a restatement, loose enough that a
		// genuinely different sentence sharing an opening clause still counts.
		$words = explode( ' ', $s );
		return implode( ' ', array_slice( $words, 0, 12 ) );
	}

	/**
	 * Build a summary from a page's text and its own term weights. (pure)
	 *
	 * @param string             $text      Plain page text (headings already stripped).
	 * @param array<string,int>  $weights   term => frequency, site-wide words already removed.
	 * @param int                $max_words Word budget.
	 * @return string Summary, or '' when the page yields nothing usable.
	 */
	public static function summarize( $text, array $weights, $max_words = self::DEFAULT_WORDS, array $site_wide = array() ) {
		$max_words = self::clamp_words( $max_words );
		$sentences = self::sentences( $text );
		if ( empty( $sentences ) || empty( $weights ) ) {
			return '';
		}

		// Sentences following a question stem are its options, and in a
		// multiple-choice question some are deliberately false.
		$option_until = -1;

		$scored = array();
		foreach ( $sentences as $i => $sentence ) {
			$is_question = self::is_question( $sentence );
			$is_option   = ( $i <= $option_until );
			if ( $is_question && self::is_option_stem( $sentence ) ) {
				$option_until = $i + self::OPTION_REACH;
			}

			$words = preg_split( '/\s+/u', $sentence );
			$len   = is_array( $words ) ? count( $words ) : 0;
			if ( $len < self::MIN_SENTENCE_WORDS || $len > self::MAX_SENTENCE_WORDS ) {
				continue;
			}

			$score = 0;
			$seen  = array();
			foreach ( $words as $word ) {
				$k = self::normalise_word( $word );
				if ( '' === $k || isset( $seen[ $k ] ) || ! isset( $weights[ $k ] ) ) {
					continue;
				}
				$seen[ $k ] = true;
				$score     += $weights[ $k ];
			}
			if ( $score <= 0 ) {
				continue; // Nothing distinctive in it. Boilerplate lands here.
			}

			// Penalised, not removed: a page that is nothing but questions still
			// deserves a description, and these only win when nothing else is
			// available.
			if ( $is_question ) {
				$score *= self::QUESTION_PENALTY;
			} elseif ( $is_option ) {
				$score *= self::OPTION_PENALTY;
			}

			// Structural, language-independent penalties. These are what make
			// this work on a site the phrase list above knows nothing about.
			if ( self::is_caption( $sentence ) ) {
				$score *= self::CAPTION_PENALTY;
			}
			$lower_words = array();
			foreach ( $words as $word ) {
				$k = self::normalise_word( $word );
				if ( '' !== $k ) {
					$lower_words[] = $k;
				}
			}
			if ( self::furniture_ratio( $lower_words, $site_wide ) >= self::FURNITURE_RATIO ) {
				$score *= self::FURNITURE_PENALTY;
			}

			$scored[] = array(
				'i'     => $i,
				// Divide by length so a dense sentence beats a rambling one that
				// merely mentions more words.
				'score' => $score / sqrt( $len ),
				'len'   => $len,
				'text'  => $sentence,
				'words' => $seen, // distinctive words, for the overlap check
			);
		}

		if ( empty( $scored ) ) {
			return '';
		}

		usort(
			$scored,
			function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return $a['i'] - $b['i']; // stable: earlier sentence wins ties
				}
				return ( $a['score'] < $b['score'] ) ? 1 : -1;
			}
		);

		$taken = array();
		$used  = 0;
		$keys  = array();
		foreach ( $scored as $cand ) {
			if ( count( $taken ) >= self::MAX_SENTENCES || $used >= $max_words ) {
				break;
			}
			$key = self::dedupe_key( $cand['text'] );
			if ( '' !== $key && isset( $keys[ $key ] ) ) {
				continue; // an exact restatement of one already taken
			}
			$repeat = false;
			foreach ( $taken as $t ) {
				if ( self::overlap( $cand['words'], $t['words'] ) > self::OVERLAP_LIMIT ) {
					$repeat = true;
					break;
				}
			}
			if ( $repeat ) {
				continue; // the same point in different words
			}
			// Allow the first sentence through even if it alone exceeds the
			// budget, otherwise a page of long sentences summarises to nothing.
			if ( $taken && ( $used + $cand['len'] ) > $max_words ) {
				continue;
			}
			$keys[ $key ] = true;
			$taken[]      = $cand;
			$used        += $cand['len'];
		}

		if ( empty( $taken ) ) {
			return '';
		}

		// Back into reading order; a summary that jumps around reads badly.
		usort(
			$taken,
			function ( $a, $b ) {
				return $a['i'] - $b['i'];
			}
		);

		$out = array();
		foreach ( $taken as $t ) {
			$out[] = $t['text'];
		}

		return self::trim_to_words( implode( ' ', $out ), $max_words );
	}

	/**
	 * How much two sentences' distinctive-word sets have in common, 0 to 1. (pure)
	 *
	 * Measured against the smaller set, so a short sentence wholly contained in
	 * a longer one counts as a repeat rather than as merely similar.
	 *
	 * @param array<string,bool> $a First word set.
	 * @param array<string,bool> $b Second word set.
	 * @return float
	 */
	public static function overlap( array $a, array $b ) {
		$smaller = min( count( $a ), count( $b ) );
		if ( $smaller < 1 ) {
			return 0.0;
		}
		$shared = count( array_intersect_key( $a, $b ) );
		return $shared / $smaller;
	}

	/**
	 * Lowercase a word and strip surrounding punctuation, to match index terms. (pure)
	 *
	 * @param string $word Raw word.
	 * @return string
	 */
	private static function normalise_word( $word ) {
		$w = function_exists( 'mb_strtolower' ) ? mb_strtolower( $word, 'UTF-8' ) : strtolower( $word );
		$w = preg_replace( '/^[^\p{L}\p{Nd}]+|[^\p{L}\p{Nd}]+$/u', '', $w );
		return (string) $w;
	}

	/**
	 * Hard cap on words, ending cleanly rather than mid-sentence. (pure)
	 *
	 * @param string $text  Text.
	 * @param int    $limit Word limit.
	 * @return string
	 */
	public static function trim_to_words( $text, $limit ) {
		$limit = max( 1, (int) $limit );
		$words = preg_split( '/\s+/u', trim( (string) $text ) );
		if ( ! is_array( $words ) || count( $words ) <= $limit ) {
			return trim( (string) $text );
		}
		return rtrim( implode( ' ', array_slice( $words, 0, $limit ) ), ' ,;:' ) . '…';
	}
}
