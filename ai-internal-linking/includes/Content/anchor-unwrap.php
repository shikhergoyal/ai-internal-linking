<?php
/**
 * Taking a plugin-inserted link back out, as plain functions.
 *
 * These sit outside the namespaced classes on purpose. uninstall.php runs in
 * isolation: WordPress is loaded, this plugin is not, so the autoloader and
 * every AILinking class are out of reach. The live undo path and the uninstall
 * path have to remove a link in exactly the same way, and the only way to be
 * sure they agree is for both to call the same code.
 *
 * The rule both paths follow: take out our own tag, in the content as it
 * stands now. Never write back the snapshot taken before the link went in —
 * that snapshot is the post at one moment in the past, so restoring it throws
 * away every edit made since, and where a post received two links on different
 * days there is no order in which writing both snapshots back leaves it
 * correct. Unwrapping a tag is order-free and touches nothing else on the page.
 *
 * @package AILinking
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ailinking_tagged_anchor_pattern' ) ) {
	/**
	 * Pattern matching one plugin-inserted anchor by its provenance id.
	 *
	 * Attribute order is not assumed: the id is found wherever it sits in the
	 * tag, so content passed through a filter that reordered attributes still
	 * matches.
	 *
	 * @param string $data_id Provenance id.
	 * @return string
	 */
	function ailinking_tagged_anchor_pattern( $data_id ) {
		return '#<a\b[^>]*\bdata-ailinking-id="' . preg_quote( (string) $data_id, '#' ) . '"[^>]*>(.*?)</a>#is';
	}
}

if ( ! function_exists( 'ailinking_unwrap_tagged_anchor' ) ) {
	/**
	 * Unwrap one tagged anchor back to its inner text, leaving the rest of the
	 * HTML byte for byte alone.
	 *
	 * Returns null when the tag is not present exactly once. Zero means the
	 * editor already took the link out; more than one means the content was
	 * duplicated. In both cases the caller must leave the content untouched
	 * rather than guess at what the author meant.
	 *
	 * @param string $html    Current content.
	 * @param string $data_id Provenance id.
	 * @return string|null
	 */
	function ailinking_unwrap_tagged_anchor( $html, $data_id ) {
		$data_id = (string) $data_id;
		if ( '' === $data_id ) {
			return null;
		}
		$html    = (string) $html;
		$pattern = ailinking_tagged_anchor_pattern( $data_id );
		if ( 1 !== preg_match_all( $pattern, $html, $matches ) ) {
			return null;
		}
		$new = preg_replace( $pattern, '$1', $html, 1 );
		return is_string( $new ) ? $new : null;
	}
}

if ( ! function_exists( 'ailinking_unwrap_tagged_anchors' ) ) {
	/**
	 * Unwrap every one of these ids from a single piece of content.
	 *
	 * Each unwrap runs against the result of the last, so a post that received
	 * several links comes out of one pass with all of them gone and one write
	 * to show for it. Ids that are not present exactly once are skipped and
	 * reported; the page keeps its edits and, at worst, keeps a plain working
	 * hyperlink.
	 *
	 * @param string   $html     Current content.
	 * @param string[] $data_ids Provenance ids, oldest first.
	 * @return array{html:string,removed:string[],skipped:string[]}
	 */
	function ailinking_unwrap_tagged_anchors( $html, array $data_ids ) {
		$html    = (string) $html;
		$removed = array();
		$skipped = array();

		foreach ( $data_ids as $data_id ) {
			$next = ailinking_unwrap_tagged_anchor( $html, $data_id );
			if ( null === $next ) {
				$skipped[] = (string) $data_id;
				continue;
			}
			$html      = $next;
			$removed[] = (string) $data_id;
		}

		return array(
			'html'    => $html,
			'removed' => $removed,
			'skipped' => $skipped,
		);
	}
}
