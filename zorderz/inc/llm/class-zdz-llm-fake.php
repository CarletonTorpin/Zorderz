<?php
/**
 * ZDZ_LLM_Fake -- scripted answers, for the suites only.
 *
 * functions.php loads it only when the constant ZDZ_LLM_FAKE is defined and true; on a live site it is never loaded.
 * Every neutral request it is given is kept in $requests, so a suite can look at exactly what the router sent.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_LLM_Fake implements ZDZ_LLM_Provider {

	public $script;
	public $requests = array();
	private $caps;
	private $advance;
	private $prices;

	/**
	 * @param array         $script  answers in order: a neutral answer array, a WP_Error, or a callable( $neutral ) giving one
	 * @param array         $caps    capabilities() to report (default: a native provider's)
	 * @param callable|null $advance called with each answer's 'secs' (tests move a fake clock with it)
	 * @param array|null    $prices  model => [ in, w5m, w1h, read, out ]; null reads the router's configured prices
	 */
	public function __construct( array $script, array $caps = array(), $advance = null, array $prices = null ) {
		$this->script  = $script;
		$this->caps    = $caps + array( 'images' => true, 'pdf' => 'native', 'cache' => true, 'json_schema' => 'native', 'effort' => true, 'web' => true, 'zdr' => true );
		$this->advance = $advance;
		$this->prices  = $prices;
	}

	public function name() {
		return 'fake';
	}

	public function capabilities() {
		return $this->caps;
	}

	public function prices( $model ) {
		if ( is_array( $this->prices ) ) {
			return $this->prices[ $model ] ?? null;
		}
		return class_exists( 'ZDZ_Core_LLM' ) ? ZDZ_Core_LLM::prices_for( (string) $model ) : null;
	}

	public function send( array $neutral, $deadline ) {
		$this->requests[] = $neutral;
		if ( ! $this->script ) {
			return ZDZ_LLM_Wire::error( 'other' );
		}
		$next = array_shift( $this->script );
		if ( is_callable( $next ) ) {
			$next = call_user_func( $next, $neutral );
		}
		if ( is_array( $next ) && $this->advance ) {
			call_user_func( $this->advance, (float) ( $next['secs'] ?? 1 ) );
		}
		if ( $next instanceof WP_Error ) {
			if ( $this->advance ) {
				$d = $next->get_error_data();
				call_user_func( $this->advance, (float) ( is_array( $d ) ? ( $d['secs'] ?? 1 ) : 1 ) );
			}
			return $next;
		}
		return (array) $next + array( 'text' => '', 'json' => null, 'citations' => array(), 'usage' => ZDZ_LLM_Wire::zero_usage(), 'model' => $neutral['model'], 'http' => 200, 'stop' => 'end_turn', 'usd' => null );
	}

	/** An answer for a script. */
	public static function answer( $text, array $usage = array(), $secs = 1 ) {
		return array( 'text' => $text, 'usage' => $usage + ZDZ_LLM_Wire::zero_usage(), 'secs' => $secs );
	}
}
