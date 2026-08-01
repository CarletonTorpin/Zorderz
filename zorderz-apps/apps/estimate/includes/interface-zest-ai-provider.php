<?php
/**
 * ZEST_AI_Provider — the estimate module's provider seam.
 *
 * The estimate flow talks to an AI gateway through this interface so the concrete
 * client (the theme's Poe gateway today; a direct API or a local model tomorrow) is
 * swappable without touching the engine. The shipped implementation wraps the theme's
 * ZDZ_Core_Poe connection — one registered provider, not a private key.
 *
 * @package Zorderz\Estimate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ZEST_AI_Provider {

	/**
	 * Complete a chat/vision request.
	 *
	 * @param array $messages Provider-neutral message array.
	 * @param array $opts     { role:string, images:array, json:bool, timeout:int, extra:array }
	 * @return array{ ok:bool, text:string, error:string, raw:array }
	 */
	public function complete( array $messages, array $opts = array() ): array;

	/** True when the provider has usable credentials. */
	public function is_configured(): bool;
}
