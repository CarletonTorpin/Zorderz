<?php
/**
 * ZANA_Web_Note — anything the chat found on the web is a first-pass finding, and the answer says so.
 *
 * A fact from one web search is not yet a fact: it becomes one only when several independent sources agree. The chat
 * does one search (the Ai router's `web` role) and never checks the pages itself, so a turn that used the web gets:
 *   - a rule in its instructions (prompt_rule()): name the source of every outside figure in the same sentence, say
 *     when only one source had it or sources disagree, never present an outside figure as settled, never invent one,
 *     never add an outside figure into a total with the business's own records;
 *   - a short note under the answer (disclosure()) that the web findings above were not checked against independent
 *     sources.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Web_Note {

	public static function prompt_rule(): string {
		return 'OUTSIDE FACTS: anything you take from the web is a FIRST-PASS finding, not an established fact. For every outside '
			. 'figure or claim, name where it came from (site name and address) in the same sentence; if you found it in only '
			. 'one place, or sources disagree, say so there and give each source\'s figure. Never present an outside figure as '
			. 'settled, never invent one to fill a gap (say it could not be found), and never add an outside figure into a total '
			. 'with the business\'s own records.';
	}

	/** The note under an answer that used the web ('' when it did not). */
	public static function disclosure( bool $web_on ): string {
		if ( ! $web_on ) {
			return '';
		}
		return "\n\n<sub>_\u{1F310} " . __( 'Anything above that came from the web is a first-pass finding and has not been checked against independent sources. Treat outside figures as unverified.', 'zorderz' ) . '_</sub>';
	}
}
