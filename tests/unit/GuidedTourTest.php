<?php
/**
 * The guided tour (ZDZ_Tour): an administrator's sample tour once the sample company is loaded, everyone else's own
 * tour written for their role, steps that are whole and plain, only the steps a person's apps and abilities fit, a
 * place in the tour that is checked before it is kept (never for a preview), and step data that can only point at
 * this page or at this site's own wp-admin.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { return 'https://site.test/wp-admin/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) { return 'https://site.test/wp-json/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) { return (string) $u; }
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key = '', $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $uid, $key, $value ) { $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}

require_once __DIR__ . '/support/privacy-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-privacy-view.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-user-roles.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-view-as.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-tour.php';

final class GuidedTourTest extends TestCase {

	private $saved = array();

	protected function setUp(): void {
		foreach ( array( 'zdz_test_options', 'zdz_test_filters', 'zdz_test_users', 'zdz_test_user_meta', 'zdz_test_user_roles', 'zdz_test_user_caps', 'zdz_test_current_user', 'zdz_test_roles' ) as $g ) {
			$this->saved[ $g ] = $GLOBALS[ $g ] ?? null;
		}
		$GLOBALS['zdz_test_options']      = array();
		$GLOBALS['zdz_test_filters']      = array();
		$GLOBALS['zdz_test_users']        = array();
		$GLOBALS['zdz_test_user_meta']    = array();
		$GLOBALS['zdz_test_user_roles']   = array();
		$GLOBALS['zdz_test_user_caps']    = array();
		$GLOBALS['zdz_test_current_user'] = 0;
		// The site's roles, for a get_role() another test file may have stood in.
		$GLOBALS['zdz_test_roles'] = array();
		foreach ( array( 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech', 'zdz_general' ) as $r ) {
			$GLOBALS['zdz_test_roles'][ $r ] = new class() {
				public function has_cap( $cap ) { return false; }
			};
		}
		$this->forget_sample();
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $g => $v ) {
			if ( null === $v ) {
				unset( $GLOBALS[ $g ] );
			} else {
				$GLOBALS[ $g ] = $v;
			}
		}
		$this->forget_sample();
	}

	/** The class keeps sample_loaded() and each profile for the request; each test is a new request. */
	private function forget_sample(): void {
		$p = new ReflectionProperty( 'ZDZ_Tour', 'sample' );
		$p->setAccessible( true );
		$p->setValue( null, null );
		$q = new ReflectionProperty( 'ZDZ_Tour', 'profiles' );
		$q->setAccessible( true );
		$q->setValue( null, array() );
	}

	/** A person as the tour would read them: the five apps the sample company's people have, and a role's rules. */
	private function person( array $over = array() ): array {
		$apps = array( 'internal-messaging', 'scheduler', 'jobs', 'estimate-creator', 'sales-analytics' );
		$base = array(
			'uid'        => 3,
			'role'       => 'zdz_sales',
			'role_label' => 'Salesperson',
			'first_name' => 'Priya',
			'apps'       => $apps,
			'names'      => array(
				'internal-messaging' => 'Messages',
				'scheduler'          => 'Schedule',
				'jobs'               => 'Jobs',
				'estimate-creator'   => 'Estimates',
				'sales-analytics'    => 'Chat',
			),
			'tiles'      => 'sales',
			'jobs'       => 'own',
			'can'        => array(
				'handoff_jobs'        => true,
				'view_own_commission' => true,
				'access_web_research' => true,
				'view_team_projects'  => true,
			),
		);
		return ZDZ_Tour::normalize_profile( array_replace( $base, $over ) );
	}

	/** The person's tour, as step id => step. */
	private function tour( array $p ): array {
		$out = array();
		foreach ( ZDZ_Tour::steps_for_profile( $p, (int) $p['uid'] ) as $s ) {
			$out[ $s['id'] ] = $s;
		}
		return $out;
	}

	/** Make the account $uid read as $p (the profile filter). */
	private function as_profile( int $uid, array $p ): void {
		$GLOBALS['zdz_test_filters']['zdz_tour_profile'] = function ( $raw, $who ) use ( $uid, $p ) {
			return $who === $uid ? $p : $raw;
		};
	}

	private function raw_builtin(): array {
		$m = new ReflectionMethod( 'ZDZ_Tour', 'builtin' );
		$m->setAccessible( true );
		return $m->invoke( null );
	}

	/* ── The steps ────────────────────────────────────────────────────────── */

	public function test_the_shipped_steps_are_whole_and_in_order(): void {
		$steps = ZDZ_Tour::steps();
		$this->assertCount( count( $this->raw_builtin() ), $steps, 'no shipped step is dropped by the cleaning' );
		$this->assertGreaterThanOrEqual( 20, count( $steps ) );
		$ids = array_column( $steps, 'id' );
		$this->assertSame( $ids, array_values( array_unique( $ids ) ), 'ids are unique' );
		$this->assertSame( 'welcome', $ids[0] );
		$this->assertSame( 'done', end( $ids ) );
		$order = array_keys( ZDZ_Tour::chapters() );
		$last  = 0;
		foreach ( $steps as $s ) {
			$at = array_search( $s['chapter'], $order, true );
			$this->assertTrue( false !== $at, $s['id'] . ' names a chapter' );
			$this->assertGreaterThanOrEqual( $last, $at, $s['id'] . ': chapters run in order' );
			$last = $at;
			if ( '' !== $s['screen'] && ! in_array( $s['screen'], array( 'home', 'settings' ), true ) ) {
				$this->assertContains( $s['screen'], $s['apps'], $s['id'] . ' needs the app it opens' );
			}
		}
		$job = array_column( array_filter( $steps, function ( $s ) { return 'job' === $s['chapter']; } ), 'id' );
		$this->assertLessThan( array_search( 'invoice', $job, true ), array_search( 'estimate_new', $job, true ), 'the estimate comes before the invoice' );
	}

	/** Every team tour the tests know: each role's rules, a crew lead, and an administrator's preview. */
	private function team_tours(): array {
		$every = array( 'internal-messaging', 'scheduler', 'jobs', 'estimate-creator', 'sales-analytics', 'projects', 'leads', 'commission-calculator', 'hours', 'knowledge-vault', 'zdz-camera', 'receipts', 'charts', 'stock' );
		return array(
			'sales'    => $this->person(),
			'operator' => $this->person( array( 'role' => 'zdz_operator', 'role_label' => 'Operator', 'stub' => true, 'jobs' => 'crew', 'tiles' => 'operator', 'can' => array( 'handoff_jobs' => true, 'lead_crew' => true, 'view_others_data' => true ) ) ),
			'tech'     => $this->person( array( 'role' => 'zdz_tech', 'role_label' => 'Field Tech', 'tiles' => 'tech', 'apps' => array( 'jobs', 'scheduler', 'internal-messaging', 'hours', 'zdz-camera' ), 'names' => array( 'jobs' => 'Jobs', 'hours' => 'Hours', 'zdz-camera' => 'Camera' ), 'descs' => array( 'zdz-camera' => 'Take photos and manage your photo library' ), 'can' => array( 'view_others_data' => true, 'access_web_research' => true, 'upload_to_knowledge_vault' => true ) ) ),
			'shop'     => $this->person( array( 'role' => 'zdz_mfg', 'role_label' => 'Shop Foreman', 'tiles' => 'shop', 'jobs' => 'shop', 'can' => array( 'lead_crew' => true ) ) ),
			'lead'     => $this->person( array( 'jobs' => 'crew', 'crew' => 2, 'self_schedule' => true ) ),
			'every'    => $this->person( array( 'apps' => $every, 'names' => array_combine( $every, array_map( 'ucfirst', $every ) ), 'descs' => array_fill_keys( $every, 'What it is for' ), 'can' => array_fill_keys( array( 'handoff_jobs', 'view_own_commission', 'view_company_revenue', 'view_others_data', 'access_web_research', 'upload_to_knowledge_vault', 'view_team_projects' ), true ) ) ),
			'preview'  => $this->person( array( 'preview' => 'Salesperson', 'first_name' => '' ) ),
			'nothing'  => ZDZ_Tour::normalize_profile( array( 'uid' => 9 ) ),
		);
	}

	public function test_the_words_keep_the_house_style(): void {
		$texts = array();
		$steps = $this->raw_builtin();
		foreach ( $this->team_tours() as $who => $p ) {
			foreach ( ZDZ_Tour::steps( 'team', $p ) as $s ) {
				$s['id'] = $who . '.' . $s['id'];
				$steps[] = $s;
			}
		}
		foreach ( $steps as $s ) {
			$this->assertLessThanOrEqual( ZDZ_Tour::TITLE_MAX, mb_strlen( $s['title'] ), $s['id'] . ' title fits' );
			$this->assertLessThanOrEqual( ZDZ_Tour::BODY_MAX, mb_strlen( $s['body'] ), $s['id'] . ' text fits, never cut' );
			$this->assertStringNotContainsString( '…', $s['body'], $s['id'] . ' text is not cut short' );
			foreach ( array( 'title', 'body', 'do', 'note' ) as $k ) {
				if ( isset( $s[ $k ] ) && '' !== $s[ $k ] ) {
					$this->assertLessThanOrEqual( 'title' === $k ? ZDZ_Tour::TITLE_MAX : ( 'body' === $k ? ZDZ_Tour::BODY_MAX : 200 ), mb_strlen( $s[ $k ] ), $s['id'] . '.' . $k . ' fits' );
					$texts[ $s['id'] . '.' . $k ] = $s[ $k ];
				}
			}
			foreach ( (array) ( $s['list'] ?? array() ) as $i => $line ) {
				$this->assertLessThanOrEqual( ZDZ_Tour::ITEM_MAX, mb_strlen( $line ), $s['id'] . ' list line fits' );
				$texts[ $s['id'] . '.list' . $i ] = $line;
			}
			if ( isset( $s['show']['label'] ) ) {
				$texts[ $s['id'] . '.show' ] = $s['show']['label'];
			}
		}
		foreach ( array( ZDZ_Tour::labels(), ZDZ_Tour::labels( 'team', $this->person( array( 'preview' => 'Salesperson' ) ) ) ) as $set ) {
			foreach ( $set as $k => $v ) {
				$texts[ 'label.' . $k . '.' . md5( $v ) ] = $v;
			}
		}
		foreach ( ZDZ_Tour::chapters( 'team' ) + ZDZ_Tour::chapters() as $k => $v ) {
			$texts[ 'chapter.' . $k ] = $v;
		}
		foreach ( $texts as $where => $t ) {
			$this->assertDoesNotMatchRegularExpression( '/[\x{2014}\x{2013}]/u', $t, $where . ': no long dashes' );
			$this->assertDoesNotMatchRegularExpression( '/\bAI\b/', $t, $where . ': Ai, not AI' );
			$this->assertDoesNotMatchRegularExpression( '/\bCRM\b/', $t, $where . ': words, not initials' );
		}
	}

	public function test_a_step_is_cleaned_before_the_page_gets_it(): void {
		$this->assertNull( ZDZ_Tour::clean_step( 'step' ) );
		$this->assertNull( ZDZ_Tour::clean_step( array( 'id' => 'x', 'title' => 'Hi' ) ), 'no text: not shown' );
		$s = ZDZ_Tour::clean_step(
			array(
				'id'      => 'My Step!',
				'chapter' => 'nowhere',
				'title'   => '<b>Hello</b>',
				'body'    => "Line one\n\n<script>bad()</script>line two",
				'target'  => '#a <img onerror=x>',
				'reach'   => 'a{color:red}',
				'screen'  => 'jobs',
				'next_on' => 'hover',
			)
		);
		$this->assertSame( 'mystep', $s['id'] );
		$this->assertSame( 'find', $s['chapter'], 'an unknown chapter falls back to the first' );
		$this->assertSame( 'Hello', $s['title'] );
		$this->assertStringNotContainsString( '<', $s['body'] );
		$this->assertSame( '', $s['target'], 'markup in a selector: dropped' );
		$this->assertSame( '', $s['reach'] );
		$this->assertSame( array( 'jobs' ), $s['apps'] );
		$this->assertSame( '', $s['next_on'] );
		$this->assertSame( array(), $s['list'] );
		$this->assertSame( array(), $s['needs'] );
		$t = ZDZ_Tour::clean_step(
			array(
				'id'      => 'b',
				'title'   => 'T',
				'body'    => 'B',
				'chapter' => 'work',
				'list'    => array( '<i>One</i>', '', array( 'no' ), 'Two', 3, '4', '5', '6', '7', '8', '9' ),
				'needs'   => array( 'Sends jobs', 'stub', 'stub' ),
				'unless'  => 'office',
			),
			'team'
		);
		$this->assertSame( 'work', $t['chapter'], 'a team chapter in the team tour' );
		$this->assertSame( array( 'One', 'Two', '3', '4', '5', '6', '7', '8' ), $t['list'], 'plain lines, none empty, at most eight' );
		$this->assertSame( array( 'sendsjobs', 'stub' ), $t['needs'] );
		$this->assertSame( array( 'office' ), $t['unless'] );
		$this->assertSame( 'find', ZDZ_Tour::clean_step( array( 'id' => 'c', 'title' => 'T', 'body' => 'B', 'chapter' => 'work' ) )['chapter'], 'not a chapter of the sample tour' );
		$this->assertSame( '#zjob-list .zjob-card', ZDZ_Tour::clean_step( array( 'id' => 'a', 'title' => 'T', 'body' => 'B', 'target' => '#zjob-list .zjob-card' ) )['target'] );
		$this->assertSame( '', ZDZ_Tour::clean_step( array( 'id' => 'a', 'title' => 'T', 'body' => 'B', 'target' => 'a[href="javascript:x"]' ) )['target'] );
	}

	public function test_show_me_opens_only_this_page_or_this_sites_admin(): void {
		$step = function ( $show ) {
			return ZDZ_Tour::clean_step( array( 'id' => 'a', 'title' => 'T', 'body' => 'B', 'show' => $show ) )['show'];
		};
		$this->assertSame( array( 'open_app' => 'jobs' ), $step( array( 'open_app' => 'Jobs' ) ) );
		$this->assertSame( array( 'settings' => true ), $step( array( 'settings' => 1 ) ) );
		$this->assertMatchesRegularExpression( '#/wp-admin/admin\.php\?page=zdz-business-profile$#', $step( array( 'admin' => 'admin.php?page=zdz-business-profile', 'label' => 'Open' ) )['admin'] );
		$this->assertNull( $step( array( 'admin' => 'https://elsewhere.test/x.php' ) ), 'never an outside address' );
		$this->assertNull( $step( array( 'admin' => '../../wp-config.php' ) ) );
		$this->assertNull( $step( array( 'admin' => 'admin.php?page=x"><script>' ) ) );
		$this->assertNull( $step( array( 'run' => 'alert(1)' ) ), 'nothing else' );
	}

	public function test_a_person_sees_only_the_steps_whose_apps_they_can_open(): void {
		$this->as_profile( 7, array( 'admin' => true, 'office' => true, 'apps' => array( 'jobs', 'scheduler', 'projects', 'receipts', 'invoice', 'internal-messaging', 'sales-analytics', 'estimate-creator' ) ) );
		$GLOBALS['zdz_test_filters']['zdz_tour_can_open'] = function ( $ok, $uid, $app ) {
			return $ok && 'estimate-creator' !== $app;
		};
		$ids = array_column( ZDZ_Tour::steps_for( 7 ), 'id' );
		$this->assertNotContains( 'estimate_new', $ids );
		$this->assertNotContains( 'job_pick', $ids );
		$this->assertContains( 'job_list', $ids, 'Jobs is still theirs' );
		$this->assertContains( 'welcome', $ids, 'Home Screen steps need no app' );
	}

	public function test_a_step_added_by_a_filter_joins_the_tour(): void {
		$GLOBALS['zdz_test_filters']['zdz_tour_steps'] = function ( $steps ) {
			$steps[] = array( 'id' => 'extra', 'chapter' => 'own', 'title' => 'Our way', 'body' => 'How we do things here.' );
			$steps[] = array( 'id' => 'welcome', 'title' => 'Twice', 'body' => 'A second welcome is ignored.' );
			return $steps;
		};
		$ids = array_column( ZDZ_Tour::steps(), 'id' );
		$this->assertContains( 'extra', $ids );
		$this->assertSame( 1, count( array_keys( $ids, 'welcome', true ) ), 'the first of two same ids wins' );
	}

	/* ── A person's place ─────────────────────────────────────────────────── */

	public function test_a_place_is_checked_before_it_is_kept(): void {
		$ids = array( 'welcome', 'apps' );
		$this->assertNull( ZDZ_Tour::clean_state( 'apps', $ids ) );
		$this->assertNull( ZDZ_Tour::clean_state( array( 'step' => 'apps', 'status' => 'flying' ), $ids ) );
		$this->assertNull( ZDZ_Tour::clean_state( array( 'step' => 'elsewhere', 'status' => 'running' ), $ids ) );
		$st = ZDZ_Tour::clean_state( array( 'step' => 'apps', 'status' => 'paused' ), $ids );
		$this->assertSame( 'apps', $st['step'] );
		$this->assertSame( 'paused', $st['status'] );
		$this->assertSame( '', ZDZ_Tour::clean_state( array( 'step' => '', 'status' => 'hidden' ), $ids )['step'] );

		$this->assertSame( 'new', ZDZ_Tour::state( 3 )['status'], 'never started' );
		$GLOBALS['zdz_test_user_meta'][3][ ZDZ_Tour::META ] = array( 'step' => 'apps', 'status' => 'weird' );
		$this->assertSame( 'new', ZDZ_Tour::state( 3 )['status'], 'a stored status that is not one reads as new' );
	}

	/* ── When it is offered ───────────────────────────────────────────────── */

	public function test_an_administrator_is_offered_the_sample_tour_once_the_sample_is_loaded(): void {
		$GLOBALS['zdz_test_user_roles'][5] = array( 'administrator' );
		$this->assertSame( 'sample', ZDZ_Tour::audience( ZDZ_Tour::profile( 5 ) ) );
		$this->assertFalse( ZDZ_Tour::available_for( 5 ), 'no sample: no tour' );
		$js = ZDZ_Tour::for_js( 5 );
		$this->assertFalse( $js['available'] );
		$this->assertArrayHasKey( 'team', $js, 'the switch for the team tours is there all the same' );
		$this->assertTrue( ZDZ_Tour::page_needs( 5 ), 'so the page loads the script for it' );

		$this->forget_sample();
		ZDZ_Tour::mark_sample_loaded( 1 );
		$this->assertTrue( ZDZ_Tour::available_for( 5 ) );
		$this->assertFalse( ZDZ_Tour::available_for( 0 ), 'signed in only' );
		$this->assertSame( 'TestCo', $GLOBALS['zdz_test_options'][ ZDZ_Tour::SAMPLE_OPTION ]['name'] );

		if ( class_exists( 'ZDZ_Privacy_View' ) ) {
			$GLOBALS['zdz_test_user_roles'][6] = array( 'administrator' );
			$GLOBALS['zdz_test_user_meta'][6][ ZDZ_Privacy_View::META ] = '1';
			$this->assertFalse( ZDZ_Tour::available_for( 6 ), 'a look-only Privacy View account is not offered the tour' );
			$this->assertArrayNotHasKey( 'team', ZDZ_Tour::for_js( 6 ), 'nor the switch' );
		}

		$GLOBALS['zdz_test_filters']['zdz_tour_available'] = function () { return false; };
		$this->assertFalse( ZDZ_Tour::available_for( 5 ), 'a site can switch it off' );
	}

	public function test_everyone_else_is_offered_their_own_tour_on_any_site(): void {
		$this->as_profile( 3, $this->person() );
		$this->assertSame( 'team', ZDZ_Tour::audience( ZDZ_Tour::profile( 3 ) ) );
		$this->assertTrue( ZDZ_Tour::available_for( 3 ), 'no sample company needed' );
		$js = ZDZ_Tour::for_js( 3 );
		$this->assertSame( 'team', $js['audience'] );
		$this->assertArrayNotHasKey( 'team', $js, 'only someone who can change the settings gets the switch' );
		$this->assertSame( array_keys( ZDZ_Tour::chapters( 'team' ) ), array_keys( $js['chapters'] ) );
		$this->assertSame( 'team_welcome', $js['steps'][0]['id'] );
		$this->assertSame( 'Welcome, Priya', $js['steps'][0]['title'] );
		$this->assertStringContainsString( 'Your role here is Salesperson.', $js['steps'][0]['body'] );
		$this->assertSame( 'A click-through of your apps and what your role can do.', $js['labels']['settings_hint'] );

		update_option( ZDZ_Tour::TEAM_OPTION, '0' );
		$this->assertFalse( ZDZ_Tour::available_for( 3 ), 'an administrator stopped offering them' );
		$this->assertFalse( ZDZ_Tour::page_needs( 3 ) );

		$this->forget_sample();
		$this->as_profile( 3, $this->person( array( 'preview' => 'Salesperson' ) ) );
		$this->assertTrue( ZDZ_Tour::available_for( 3 ), 'a preview still shows, so an administrator can look first' );

		$this->forget_sample();
		update_option( ZDZ_Tour::TEAM_OPTION, '1' );
		$this->as_profile( 4, $this->person( array( 'uid' => 4, 'kiosk' => true ) ) );
		$this->assertFalse( ZDZ_Tour::available_for( 4 ), 'never the shared device' );
	}

	public function test_the_page_gets_everything_the_player_needs(): void {
		$GLOBALS['zdz_test_user_roles'][5] = array( 'administrator' );
		$this->as_profile( 5, $this->person( array( 'uid' => 5, 'admin' => true, 'office' => true, 'role' => 'administrator', 'role_label' => 'Administrator' ) ) );
		ZDZ_Tour::mark_sample_loaded( 1 );
		$js = ZDZ_Tour::for_js( 5 );
		$this->assertTrue( $js['available'] );
		$this->assertSame( 'sample', $js['audience'] );
		$this->assertFalse( $js['preview'] );
		$this->assertSame( '/tour/state', substr( $js['rest'], -11 ) );
		$this->assertSame( 'new', $js['state']['status'] );
		$this->assertSame( array_keys( ZDZ_Tour::chapters() ), array_keys( $js['chapters'] ) );
		$this->assertContains( 'guided tour', $js['phrases'] );
		$this->assertArrayHasKey( 'step_of', $js['labels'] );
		$this->assertTrue( false !== json_encode( $js ), 'plain data' );
		foreach ( $js['steps'] as $s ) {
			$this->assertSame( array( 'id', 'chapter', 'title', 'body', 'do', 'note', 'list', 'target', 'reach', 'apps', 'screen', 'show', 'next_on' ), array_keys( $s ), 'what to show, not who it is for' );
		}
		$this->assertTrue( $js['team']['on'] );
		$this->assertSame( '/tour/team', substr( $js['team']['rest'], -10 ) );
		$this->assertSame( array( 'Salesperson', 'Operator', 'Shop Foreman', 'Field Tech' ), array_column( $js['team']['roles'], 'label' ), 'never an administrator\'s role, never the shared device' );
		$this->assertStringContainsString( 'zdz_view_as=zdz_sales', $js['team']['roles'][0]['url'] );
		$this->assertStringContainsString( 'zdz_tour=start', $js['team']['roles'][0]['url'] );
		$this->assertStringContainsString( 'zdz_view_as_user=0', $js['team']['roles'][0]['url'], 'a person previewed before does not win over the role' );
	}

	public function test_a_preview_is_shown_but_never_saved(): void {
		$GLOBALS['zdz_test_user_roles'][5]    = array( 'administrator' );
		$GLOBALS['zdz_test_current_user']     = 5;
		$GLOBALS['zdz_test_user_meta'][5][ ZDZ_Tour::TEAM_META ] = array( 'step' => 'team_apps', 'status' => 'paused' );
		$this->as_profile( 5, $this->person( array( 'uid' => 5, 'preview' => 'Salesperson', 'first_name' => '' ) ) );
		$js = ZDZ_Tour::for_js( 5 );
		$this->assertTrue( $js['available'] );
		$this->assertTrue( $js['preview'] );
		$this->assertSame( '', $js['rest'], 'nothing to save to' );
		$this->assertSame( 'new', $js['state']['status'], 'a preview starts fresh' );
		$this->assertArrayNotHasKey( 'team', $js, 'no switch while previewing' );
		$this->assertSame( 'Preview of the Salesperson tour.', $js['labels']['settings_preview'] );
		$this->assertSame( 'Welcome to Zorderz', $js['steps'][0]['title'] );
		$this->assertSame( 0, strpos( $js['steps'][0]['note'], 'Preview of the Salesperson tour.' ) );

		$req = new class() {
			public function get_json_params() { return array( 'step' => 'team_apps', 'status' => 'done' ); }
		};
		$this->assertInstanceOf( 'WP_Error', ZDZ_Tour::rest_save( $req ) );
		$this->assertSame( 'paused', $GLOBALS['zdz_test_user_meta'][5][ ZDZ_Tour::TEAM_META ]['status'], 'the place kept is untouched' );
	}

	public function test_each_tour_keeps_its_own_place(): void {
		$GLOBALS['zdz_test_current_user'] = 3;
		$this->as_profile( 3, $this->person() );
		$req = new class() {
			public function get_json_params() { return array( 'step' => 'team_apps', 'status' => 'paused' ); }
		};
		$st = ZDZ_Tour::rest_save( $req );
		$this->assertSame( 'team_apps', $st['step'] );
		$this->assertSame( 'paused', ZDZ_Tour::state( 3, 'team' )['status'] );
		$this->assertSame( 'new', ZDZ_Tour::state( 3 )['status'], 'the sample tour is another place' );
		$bad = new class() {
			public function get_json_params() { return array( 'step' => 'estimate_new', 'status' => 'running' ); }
		};
		$this->assertInstanceOf( 'WP_Error', ZDZ_Tour::rest_save( $bad ), 'a step of the other tour is not one of this tour' );
	}

	public function test_only_an_administrator_switches_the_team_tours(): void {
		$GLOBALS['zdz_test_current_user'] = 3;
		$this->assertFalse( ZDZ_Tour::rest_team_can() );
		$GLOBALS['zdz_test_user_roles'][5] = array( 'administrator' );
		$GLOBALS['zdz_test_current_user']  = 5;
		$this->assertTrue( ZDZ_Tour::rest_team_can() );
		$req = function ( $body ) {
			return new class( $body ) {
				private $b;
				public function __construct( $b ) { $this->b = $b; }
				public function get_json_params() { return $this->b; }
			};
		};
		$this->assertInstanceOf( 'WP_Error', ZDZ_Tour::rest_team( $req( array( 'on' => 'no' ) ) ), 'on or off, nothing else' );
		$this->assertSame( array( 'on' => false ), ZDZ_Tour::rest_team( $req( array( 'on' => false ) ) ) );
		$this->assertFalse( ZDZ_Tour::team_on() );
		$this->assertSame( array( 'on' => true ), ZDZ_Tour::rest_team( $req( array( 'on' => true ) ) ) );
	}

	/* ── Each role's own tour ─────────────────────────────────────────────── */

	public function test_the_team_tours_are_whole_and_in_order(): void {
		foreach ( $this->team_tours() as $who => $p ) {
			$steps = ZDZ_Tour::steps_for_profile( $p );
			$ids   = array_column( $steps, 'id' );
			$this->assertSame( $ids, array_values( array_unique( $ids ) ), $who . ': ids are unique' );
			$this->assertSame( 'team_welcome', $ids[0], $who );
			$this->assertSame( 'team_role', $ids[1], $who );
			$this->assertSame( 'team_done', end( $ids ), $who );
			$order = array_keys( ZDZ_Tour::chapters( 'team' ) );
			$last  = 0;
			foreach ( $steps as $s ) {
				$at = array_search( $s['chapter'], $order, true );
				$this->assertTrue( false !== $at, $who . '.' . $s['id'] . ' names a chapter' );
				$this->assertGreaterThanOrEqual( $last, $at, $who . '.' . $s['id'] . ': chapters run in order' );
				$last = $at;
				foreach ( $s['apps'] as $app ) {
					$this->assertContains( $app, $p['apps'], $who . '.' . $s['id'] . ' only for an app they have' );
				}
				// It only points and explains: the one thing it asks for is a look at a list.
				if ( '' !== $s['do'] ) {
					$this->assertMatchesRegularExpression( '/^Click (History|Present) to see /', $s['do'], $who . '.' . $s['id'] . ' asks for nothing that saves' );
				}
				$this->assertNull( $s['show'] && isset( $s['show']['admin'] ) ? 'admin' : null, $who . '.' . $s['id'] . ': no settings screen for a team member' );
			}
			$list = $steps[1]['list'];
			$this->assertNotEmpty( $list, $who . ': what their role can do' );
			$this->assertLessThanOrEqual( ZDZ_Tour::LIST_MAX, count( $list ) );
			$this->assertSame( 'Business settings, people, apps and prices are looked after by an administrator.', end( $list ), $who . ': who looks after the rest' );
		}
	}

	public function test_a_salesperson_writes_estimates_and_sends_jobs(): void {
		$p    = $this->person();
		$tour = $this->tour( $p );
		foreach ( array( 'team_figures_sales', 'team_estimates', 'team_send_work', 'team_jobs_own', 'team_jobs_given', 'team_jobs_sent', 'team_schedule', 'team_chat', 'team_messages', 'team_nav' ) as $id ) {
			$this->assertArrayHasKey( $id, $tour, $id );
		}
		foreach ( array( 'team_stubs', 'team_crew', 'team_jobs_crew', 'team_figures_operator', 'team_projects', 'team_more' ) as $id ) {
			$this->assertArrayNotHasKey( $id, $tour, $id );
		}
		$this->assertSame( 'The bar along the bottom holds Apps, Jobs, Team (your messages), Chat and Notices. It tucks away while an app is open, and Back brings it back.', $tour['team_nav']['body'] );
		$this->assertStringContainsString( 'whoever sent it', $tour['team_jobs_given']['note'] );
		$this->assertStringContainsString( 'your own work, not other people', $tour['team_chat']['body'] );
		$this->assertStringContainsString( 'web too', $tour['team_chat']['body'] );
		$list = $tour['team_role']['list'];
		$this->assertSame( 'Your apps: Messages, Schedule, Jobs, Estimates and Chat.', $list[0] );
		$this->assertContains( 'Write estimates priced from the catalog. You see your own.', $list );
		$this->assertContains( 'See the jobs you are doing and the ones you sent.', $list );
		$this->assertContains( 'Send estimate lines to teammates as jobs, and set their times.', $list );
		$this->assertContains( 'The company\'s revenue is not shown to you.', $list );
		$this->assertContains( 'Ask Chat about your own work. It can search the web too.', $list );
	}

	public function test_an_operator_writes_stubs_and_may_lead_a_crew(): void {
		$tour = $this->tour( $this->team_tours()['operator'] );
		$this->assertArrayHasKey( 'team_stubs', $tour );
		$this->assertArrayNotHasKey( 'team_estimates', $tour, 'a stub writer does not price' );
		$this->assertArrayHasKey( 'team_jobs_own', $tour, 'a crew lead with no crew yet sees their own jobs' );
		$this->assertArrayNotHasKey( 'team_crew', $tour );
		$this->assertArrayHasKey( 'team_figures_operator', $tour );
		$list = $tour['team_role']['list'];
		$this->assertContains( 'Write estimate stubs without prices, for a salesperson to price.', $list );
		$this->assertContains( 'Lead a crew: once an administrator gives you one, you see its jobs too.', $list );
		$this->assertContains( 'Ask Chat about the whole team\'s work.', $list );
	}

	public function test_a_field_tech_does_jobs_and_logs_hours(): void {
		$tour = $this->tour( $this->team_tours()['tech'] );
		foreach ( array( 'team_figures_tech', 'team_jobs_own', 'team_jobs_given', 'team_hours', 'team_schedule', 'team_messages', 'team_more' ) as $id ) {
			$this->assertArrayHasKey( $id, $tour, $id );
		}
		foreach ( array( 'team_send_work', 'team_jobs_sent', 'team_estimates', 'team_stubs', 'team_chat' ) as $id ) {
			$this->assertArrayNotHasKey( $id, $tour, $id . ': not theirs' );
		}
		$this->assertSame( array( 'Camera: Take photos and manage your photo library' ), $tour['team_more']['list'] );
		$this->assertNotContains( 'Send estimate lines to teammates as jobs, and set their times.', $tour['team_role']['list'] );
	}

	public function test_the_shop_sees_every_job_and_a_lead_their_crew(): void {
		$shop = $this->tour( $this->team_tours()['shop'] );
		$this->assertArrayHasKey( 'team_jobs_shop', $shop );
		$this->assertArrayHasKey( 'team_figures_shop', $shop );
		$this->assertArrayNotHasKey( 'team_send_work', $shop, 'the shop does not hand work off' );
		$this->assertContains( 'See every job, so the shop can plan its work.', $shop['team_role']['list'] );

		$lead = $this->tour( $this->team_tours()['lead'] );
		$this->assertArrayHasKey( 'team_jobs_crew', $lead );
		$this->assertArrayHasKey( 'team_crew', $lead );
		$this->assertArrayNotHasKey( 'team_jobs_own', $lead );
		$this->assertStringContainsString( 'set its time yourself', $lead['team_jobs_given']['note'], 'where workers set their own times' );
		$this->assertContains( 'See your own jobs and your crew\'s, and set their times.', $lead['team_role']['list'] );
	}

	public function test_every_app_is_covered_and_the_list_stays_short(): void {
		$tour = $this->tour( $this->team_tours()['every'] );
		foreach ( array( 'team_projects', 'team_leads', 'team_commission', 'team_hours', 'team_more' ) as $id ) {
			$this->assertArrayHasKey( $id, $tour, $id );
		}
		$this->assertStringContainsString( 'read every Project', $tour['team_projects']['body'] );
		$names = array_map(
			function ( $line ) {
				return strtok( $line, ':' );
			},
			$tour['team_more']['list']
		);
		$this->assertSame( array( 'Knowledge-vault', 'Zdz-camera', 'Receipts', 'Charts', 'Stock' ), $names, 'only the apps with no step of their own' );
		$this->assertCount( ZDZ_Tour::LIST_MAX, $tour['team_role']['list'] );
		$this->assertStringContainsString( 'and 8 more', $tour['team_role']['list'][0] );
		$this->assertContains( 'See the company\'s revenue.', $tour['team_role']['list'] );
	}

	public function test_an_administrators_settings_screen_steps_need_those_screens(): void {
		$admin  = $this->person( array( 'admin' => true, 'office' => true, 'apps' => array( 'invoice' ) ) );
		$office = $this->tour( $admin );
		foreach ( array( 'invoice', 'profile', 'credentials', 'team_tours' ) as $id ) {
			$this->assertArrayHasKey( $id, $office, $id );
		}
		$this->assertArrayNotHasKey( 'office_elsewhere', $office );

		$none = $this->tour( $this->person( array( 'admin' => true, 'office' => false, 'apps' => array( 'invoice' ) ) ) );
		foreach ( array( 'invoice', 'profile', 'credentials' ) as $id ) {
			$this->assertArrayNotHasKey( $id, $none, $id . ': a page this account cannot open' );
		}
		$this->assertArrayHasKey( 'office_elsewhere', $none );

		$preview = $this->tour( $this->person( array( 'admin' => true, 'office' => true, 'preview' => 'Owner' ) ) );
		$this->assertArrayNotHasKey( 'team_tours', $preview, 'nothing about switching while previewing' );
	}

	public function test_a_step_from_a_filter_can_ask_for_an_ability(): void {
		$GLOBALS['zdz_test_filters']['zdz_tour_team_steps'] = function ( $steps, $p ) {
			$steps[] = array( 'id' => 'our_crews', 'chapter' => 'team', 'needs' => array( 'lead' ), 'title' => 'Our crews', 'body' => 'How crews work here.' );
			$steps[] = array( 'id' => 'odd', 'chapter' => 'team', 'needs' => array( 'flying' ), 'title' => 'Odd', 'body' => 'Nobody has this.' );
			return $steps;
		};
		$this->assertArrayHasKey( 'our_crews', $this->tour( $this->team_tours()['lead'] ) );
		$this->assertArrayNotHasKey( 'our_crews', $this->tour( $this->person() ) );
		$this->assertArrayNotHasKey( 'odd', $this->tour( $this->team_tours()['lead'] ), 'an ability nobody knows is one nobody has' );
	}
}
