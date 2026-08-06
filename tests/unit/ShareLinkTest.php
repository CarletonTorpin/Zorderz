<?php
/**
 * The share-link primitive is an authentication primitive: a forged signature is unauthorized
 * access to a private artifact. These tests pin its guarantees so a disguised change cannot
 * quietly weaken them: signatures are deterministic, domain-separated per namespace, bound to
 * the id, verified constant-time, and rejected on any tampering. Opaque-token compare and the
 * word-token entropy floor are pinned too.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class ShareLinkTest extends TestCase {

	public function test_mint_opaque_shape(): void {
		$t = ZDZ_Share_Link::mint_opaque();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $t, '128-bit opaque token = 32 hex chars.' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', ZDZ_Share_Link::mint_opaque( 32 ) );
		$this->assertNotSame( ZDZ_Share_Link::mint_opaque(), ZDZ_Share_Link::mint_opaque(), 'Independent draws.' );
	}

	public function test_is_valid_opaque_is_strict_and_nonempty(): void {
		$this->assertTrue( ZDZ_Share_Link::is_valid_opaque( 'abc123', 'abc123' ) );
		$this->assertFalse( ZDZ_Share_Link::is_valid_opaque( 'abc123', 'abc124' ) );
		$this->assertFalse( ZDZ_Share_Link::is_valid_opaque( '', '' ), 'Empty stored must never validate.' );
		$this->assertFalse( ZDZ_Share_Link::is_valid_opaque( 'abc', '' ) );
	}

	public function test_normalize_canonicalizes(): void {
		$this->assertSame( 'maple-otter-canyon', ZDZ_Share_Link::normalize( '  Maple_Otter  Canyon ' ) );
		$this->assertSame( 'a-b', ZDZ_Share_Link::normalize( 'a--__  b!!' ) );
		$this->assertSame( '', ZDZ_Share_Link::normalize( '   ' ) );
	}

	public function test_sign_is_deterministic_domain_separated_and_id_bound(): void {
		$a = ZDZ_Share_Link::sign( 'receipt', 5 );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $a, 'Signature is 128-bit hex.' );
		$this->assertSame( $a, ZDZ_Share_Link::sign( 'receipt', 5 ), 'Deterministic for same namespace+id.' );
		$this->assertNotSame( $a, ZDZ_Share_Link::sign( 'user-media', 5 ), 'Domain separation: different namespace, different sig.' );
		$this->assertNotSame( $a, ZDZ_Share_Link::sign( 'receipt', 6 ), 'Id-bound: different id, different sig.' );
	}

	public function test_verify_signed_accepts_valid_and_rejects_tampering(): void {
		$sig = ZDZ_Share_Link::sign( 'receipt', 42 );

		$this->assertTrue( ZDZ_Share_Link::verify_signed( 'receipt', 42, $sig ), 'A valid signature verifies.' );

		// Tampered signature.
		$bad = substr( $sig, 0, -1 ) . ( $sig[-1] === 'a' ? 'b' : 'a' );
		$this->assertFalse( ZDZ_Share_Link::verify_signed( 'receipt', 42, $bad ), 'A tampered signature is rejected.' );

		// Cross-namespace: a receipt signature must not validate as user-media (the CT rule).
		$this->assertFalse( ZDZ_Share_Link::verify_signed( 'user-media', 42, $sig ), 'Cross-namespace forgery is rejected.' );

		// Wrong id.
		$this->assertFalse( ZDZ_Share_Link::verify_signed( 'receipt', 43, $sig ), 'A signature for another id is rejected.' );

		// Empty.
		$this->assertFalse( ZDZ_Share_Link::verify_signed( 'receipt', 42, '' ), 'An empty signature is rejected.' );
	}

	public function test_mint_words_enforces_entropy_floor(): void {
		// Build a clean, unique [a-z]{4} word list well above and below the 1000-word floor.
		$words = array();
		for ( $i = 0; $i < 1300; $i++ ) {
			$w = ''; $n = $i;
			for ( $j = 0; $j < 4; $j++ ) { $w .= chr( 97 + ( $n % 26 ) ); $n = intdiv( $n, 26 ); }
			$words[] = $w;
		}
		$tok = ZDZ_Share_Link::mint_words( $words, 4 );
		$this->assertMatchesRegularExpression( '/^[a-z]{3,8}(-[a-z]{3,8}){3}$/', $tok, 'Four hyphen-joined words.' );

		$this->assertSame( '', ZDZ_Share_Link::mint_words( array_slice( $words, 0, 500 ), 4 ),
			'Below the entropy floor, mint_words must refuse (return empty), never a weak token.' );
	}
}
