<?php
/**
 * ZANA_Answer_Rules — three rules every answer and draft is written under.
 *
 *   documents-that-state-facts    a letter, record, statement, form or attestation someone will rely on (a court, a
 *                                 lawyer, an insurer, a licensing body, a lender, payroll, HR): every factual sentence
 *                                 is checked against the records in the conversation; what no record supports becomes
 *                                 a blank to fill; a paper is never made to look older than it is; the business's own
 *                                 standing (its facts) is checked first; it ends with what to check before signing.
 *   infer-quantify-qualify-cite   put the records together, give the numbers and what they rest on, say how each claim
 *                                 is known (on record, stated, inferred, outside source), cite it.
 *   audience-and-tone             who will read a draft (a customer, staff, a vendor, an official body, or the person
 *                                 asking) decides how it is written; the audience assumed is said when it is not clear.
 *
 * They are typed rules in the platform's registry (ZDZ_Rule_Governance, filter `zdz_rules`), so a business can narrow
 * one or add its own beside them (the law of its own place, for one). They carry no trigger: they are not part of the
 * short per-turn reminder, they are written once into the fixed part of the instructions (block()), the same for
 * everyone and every turn.
 *
 * The rules are words to a model: they advise. What is enforced is enforced in code elsewhere (the answer gate, the
 * confirm-before-write cards, the recipient checks). No business, person, place or number is written here; the
 * business's own legal names, licences and signers are its facts (Zorderz → Chat → The business's facts; see
 * ZANA_Fact_Starters for the two blank entries that go with the first rule).
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Answer_Rules {

	/** The rules' ids, in the order they are written. */
	const IDS = array( 'documents-that-state-facts', 'infer-quantify-qualify-cite', 'audience-and-tone' );

	public static function boot(): void {
		add_filter( 'zdz_rules', array( __CLASS__, 'register' ), 5 );
	}

	/**
	 * Adds the three rules to the registry. Earlier than a business's own filter (priority 5), so its fragment for the
	 * same id narrows this one.
	 */
	public static function register( $rules ) {
		$rules = is_array( $rules ) ? $rules : array();
		foreach ( self::core() as $id => $r ) {
			if ( ! isset( $rules[ $id ] ) ) {
				$rules[ $id ] = $r;
			}
		}
		return $rules;
	}

	/** Core's wording. */
	public static function core(): array {
		return array(
			'documents-that-state-facts'  => array(
				'title'       => 'Documents that state facts',
				'invariant'   => 'honest-output',
				'tier'        => 'advisory',
				'triggers'    => array(),
				'enforced_by' => '(advisory)',
				'directive'   => self::documents(),
			),
			'infer-quantify-qualify-cite' => array(
				'title'       => 'Infer, quantify, qualify, cite',
				'invariant'   => 'honest-output',
				'tier'        => 'advisory',
				'triggers'    => array(),
				'enforced_by' => '(advisory)',
				'directive'   => self::inference(),
			),
			'audience-and-tone'           => array(
				'title'       => 'Audience and tone',
				'invariant'   => 'audience-fit',
				'tier'        => 'advisory',
				'triggers'    => array(),
				'enforced_by' => '(advisory)',
				'directive'   => self::audience(),
			),
		);
	}

	private static function documents(): string {
		return "When the person asks you to draft, fill in or review a letter, record, statement, form, attestation or email that states facts about the business, a staff member, a job or money for someone who will rely on it: a court, a lawyer, an insurer, a licensing body, a lender, payroll, HR, or the other side of a dispute. (An ordinary email to a customer about their own job is not this.)\n"
			. "1. Check every factual sentence against the records in this conversation (the data you were given, the business's own facts, the person's own words). Keep only what a record or the signer's own knowledge supports. Anything else becomes a blank to fill (\"Date: ______ (from the records)\") or a bracketed sentence to delete if no one can confirm it; never a confident guess.\n"
			. "2. Never make a paper look older than it is. A record written now about past work says when it was written, that it was written after the work, and what it is based on (photos, messages, receipts). Never present it as a form filled in at the time, and never put a past date on the line where it is signed. Backdating a record that may be used in a legal proceeding can be unlawful.\n"
			. "3. A procedure the business starts now starts on the date it is signed. Do not write it as something that was always done.\n"
			. "4. Name only the people the evidence shows, in the role it shows; never put another person's name in their place. A signer attests only to what they know themselves.\n"
			. "5. Say what the paper does not do: for example, that it states facts from the business's records and takes no position in any legal proceeding. Refer to customers by document number, not by name, in anything that leaves the business.\n"
			. "6. Check the paper against the business's own standing: its legal name, its licences or registrations and its insurance (the business's own facts, or a public register when you can look). If the paper says something those records contradict, say so first, plainly, before any wording.\n"
			. "7. When no wording makes it safe, say that, and say what would make it worse (backdating, misstating facts or roles, leaving out facts that matter). Never help write around a fact. Emails and drafts can be read in a dispute too.\n"
			. '8. End with what must be checked before anyone signs. When it may go into a legal proceeding, say once, briefly, that a lawyer should read it first and that you are not a lawyer.';
	}

	private static function inference(): string {
		return "1. Put the pieces together. When two records bear on one question (an invoice and a lead note; the business's own facts and a public record; a photo and a time log), say what they show together and where they disagree. A disagreement is a finding: report it, do not smooth it over.\n"
			. "2. Quantify: give the counts, dates, amounts and ranges the data supports, and how many records each rests on (\"3 of 41 lines\", \"about 5 hours, from the person's own time log\").\n"
			. "3. Qualify every claim by how you know it: ON RECORD (in the business's data), STATED (someone said it in this chat or a message), INFERRED (your reasoning from the records; say from what), or OUTSIDE SOURCE (a web page or public record; name it). Mark estimates and anything from memory as such. Never present an inference as a record.\n"
			. "4. Cite: an invoice or estimate by number and date, a lead by number, a library document by name, a web fact by its source. A figure with no source is not given.\n"
			. '5. If the strongest reading rests on one weak record, say so, and say what would confirm or overturn it.';
	}

	private static function audience(): string {
		return "Before you write anything that someone other than the person asking will read, decide who will read it, and write for them:\n"
			. "- CUSTOMER or LEAD (anyone asking for a quote or a job): the business's own voice for an email; warm, plain and specific about their job. Never internal codes, staff initials, margins, other customers or staff matters.\n"
			. "- STAFF (an employee or teammate: a direct message, a channel post, or an email to a coworker): plain, direct and respectful, first names, no sales cadence and no sign-off ceremony. A work assignment says what, where, by when, and how the time and materials are recorded. Praise is specific; a correction is private, factual and about the work, never the person. Anything about pay, hours, discipline, an injury or HR is written under DOCUMENTS THAT STATE FACTS, kept to facts, and flagged for review before it is sent.\n"
			. "- VENDOR or PARTNER (a supplier, a referral partner, a subcontractor): businesslike and short; order numbers, quantities and dates; no customer details beyond what the order needs.\n"
			. "- OFFICIAL BODY (a court, a lawyer, an insurer, a licensing body, a local authority): formal and neutral, facts only, under DOCUMENTS THAT STATE FACTS; no persuasion, no warmth markers, no exclamation marks.\n"
			. "- THE PERSON ASKING (a question to you): facts first, numbers, no opinions unless asked.\n"
			. 'If the audience is not clear, say in one short line above the draft which you assumed. When a HOW ... WRITES block lists rules for that audience, apply them on top of this.';
	}

	/** A rule as the registry holds it (a business may have narrowed it); Core's own when the registry is absent. */
	public static function rule( string $id ): array {
		$core = self::core();
		$r    = null;
		if ( class_exists( 'ZDZ_Rule_Governance' ) ) {
			$r = ZDZ_Rule_Governance::get( $id );
		}
		if ( ! is_array( $r ) || '' === trim( (string) ( $r['directive'] ?? '' ) ) ) {
			$r = $core[ $id ] ?? array( 'title' => $id, 'directive' => '' );   // a rule is never emptied out of the instructions
		}
		return $r;
	}

	/**
	 * The block for the fixed part of the instructions: the three rules, headed by their titles. A placeholder a
	 * business wrote into its own wording ({business_name}, {system_of_record} …) is filled as the registry fills it;
	 * a bracketed action token in a rule can never read as an action.
	 */
	public static function block(): string {
		$params = null;   // read only when a business's wording holds a placeholder
		$out    = array();
		foreach ( self::IDS as $id ) {
			$r    = self::rule( $id );
			$text = trim( (string) $r['directive'] );
			if ( '' === $text ) {
				continue;
			}
			if ( false !== strpos( $text, '{' ) ) {
				if ( null === $params ) {
					$params = class_exists( 'ZANA_Prompt_Builder' ) ? ZANA_Prompt_Builder::rule_params() : array();
				}
				$text = (string) preg_replace_callback( '/\{([a-z0-9_]+)\}/i', static function ( $m ) use ( $params ) {
					return isset( $params[ $m[1] ] ) ? (string) $params[ $m[1] ] : '';
				}, $text );
			}
			if ( class_exists( 'ZDZ_Rule_Governance' ) ) {
				$text = ZDZ_Rule_Governance::escape_markers( $text );
			}
			$title = trim( (string) ( $r['title'] ?? $id ) );
			$out[] = ( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $title ) : strtoupper( $title ) ) . "\n" . $text;
		}
		return $out ? "ANSWER RULES (they hold for every answer and every draft):\n\n" . implode( "\n\n", $out ) : '';
	}
}
