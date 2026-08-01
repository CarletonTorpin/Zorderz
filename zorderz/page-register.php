<?php
/**
 * Template Name: Zorderz Register
 *
 * @package Zorderz
 */

// Redirect if already logged in.
if ( is_user_logged_in() ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$errors = [];
$success = false;

if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['zdz_register_nonce'] ) ) {
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zdz_register_nonce'] ) ), 'zdz_register_action' ) ) {
		$errors[] = __( 'Security check failed. Please try again.', 'zorderz' );
	} else {
		$username = isset( $_POST['zdz_username'] ) ? sanitize_user( wp_unslash( $_POST['zdz_username'] ) ) : '';
		$email    = isset( $_POST['zdz_email'] ) ? sanitize_email( wp_unslash( $_POST['zdz_email'] ) ) : '';
		$password = isset( $_POST['zdz_password'] ) ? $_POST['zdz_password'] : '';
		$confirm  = isset( $_POST['zdz_password_confirm'] ) ? $_POST['zdz_password_confirm'] : '';

		if ( empty( $username ) || empty( $email ) || empty( $password ) ) {
			$errors[] = __( 'Please fill in all required fields.', 'zorderz' );
		} elseif ( username_exists( $username ) ) {
			$errors[] = __( 'Username already exists.', 'zorderz' );
		} elseif ( ! is_email( $email ) ) {
			$errors[] = __( 'Invalid email address.', 'zorderz' );
		} elseif ( email_exists( $email ) ) {
			$errors[] = __( 'Email already registered.', 'zorderz' );
		} elseif ( $password !== $confirm ) {
			$errors[] = __( 'Passwords do not match.', 'zorderz' );
		} else {
			$user_id = wp_insert_user( [
				'user_login' => $username,
				'user_pass'  => $password,
				'user_email' => $email,
				'role'       => 'zdz_tech',
			] );

			if ( is_wp_error( $user_id ) ) {
				$errors[] = $user_id->get_error_message();
			} else {
				$success = true;
			}
		}
	}
}

get_header(); ?>

<style>
	/* Full screen takeover to hide theme header/footer chrome */
	.zdz-login-takeover {
		position: fixed;
		top: 0;
		left: 0;
		width: 100%;
		height: 100%;
		z-index: 999999;
		background: linear-gradient(145deg, var(--ref-brand-600, #2563eb) 0%, var(--ref-brand-900, #1e3a8a) 50%, var(--ref-brand-950, #172554) 100%);
		display: flex;
		align-items: center;
		justify-content: center;
		font-family: system-ui, -apple-system, sans-serif;
		overflow-y: auto;
		padding: 20px;
		box-sizing: border-box;
	}
	.login-card {
		background: #ffffff;
		border-radius: 12px;
		padding: 40px;
		width: 100%;
		max-width: 400px;
		box-shadow: 0 10px 25px rgba(0,0,0,0.2);
		text-align: center;
		margin: auto;
	}
	.login-logo {
		margin-bottom: 24px;
	}
	.logo-mark {
		width: 48px;
		height: 48px;
		color: var(--ref-brand-600, #2563eb);
		margin-bottom: 16px;
	}
	.login-logo h1 {
		font-size: 24px;
		font-weight: 700;
		margin: 0 0 8px;
		color: #111827;
	}
	.login-logo p {
		font-size: 14px;
		color: #6b7280;
		margin: 0;
	}
	.fg {
		margin-bottom: 16px;
		text-align: left;
	}
	.fg label {
		display: block;
		font-size: 14px;
		font-weight: 500;
		margin-bottom: 6px;
		color: #374151;
	}
	.fg input {
		width: 100%;
		padding: 10px 12px;
		border: 1px solid #d1d5db;
		border-radius: 6px;
		font-size: 16px;
		box-sizing: border-box;
	}
	.btn {
		display: inline-block;
		width: 100%;
		padding: 12px;
		border: none;
		border-radius: 6px;
		font-size: 16px;
		font-weight: 600;
		cursor: pointer;
		text-align: center;
		text-decoration: none;
		margin-top: 10px;
	}
	.btn-brand {
		background-color: var(--ref-brand-600, #2563eb);
		color: #ffffff;
	}
	.btn-brand:hover {
		background-color: var(--ref-brand-900, #1e3a8a);
	}
	.login-version {
		margin-top: 24px;
		font-size: 12px;
		color: #9ca3af;
	}
	.login-error {
		background: #fee2e2;
		color: #991b1b;
		padding: 10px;
		border-radius: 6px;
		margin-bottom: 20px;
		font-size: 14px;
		text-align: left;
	}
	.login-success {
		background: #dcfce7;
		color: #166534;
		padding: 10px;
		border-radius: 6px;
		margin-bottom: 20px;
		font-size: 14px;
	}
	.register-link {
		display: block;
		margin-top: 16px;
		font-size: 14px;
		color: var(--ref-brand-600, #2563eb);
		text-decoration: none;
	}
</style>

<div class="zdz-login-takeover">
	<div class="login-card">
		<div class="login-logo">
			<svg class="logo-mark" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
				<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
			</svg>
			<h1><?php esc_html_e( 'Zorderz', 'zorderz' ); ?></h1>
			<p><?php esc_html_e( 'Register Account', 'zorderz' ); ?></p>
		</div>

		<?php if ( ! get_option( 'users_can_register' ) ) : ?>
			<div class="login-error">
				<?php esc_html_e( 'User registration is currently disabled.', 'zorderz' ); ?>
			</div>
			<a href="<?php echo esc_url( wp_login_url() ); ?>" class="btn btn-brand">
				<?php esc_html_e( 'Back to Login', 'zorderz' ); ?>
			</a>
		<?php else : ?>

			<?php if ( $success ) : ?>
				<div class="login-success">
					<?php esc_html_e( 'Registration successful! You can now log in.', 'zorderz' ); ?>
				</div>
				<a href="<?php echo esc_url( wp_login_url() ); ?>" class="btn btn-brand">
					<?php esc_html_e( 'Go to Login', 'zorderz' ); ?>
				</a>
			<?php else : ?>

				<?php if ( ! empty( $errors ) ) : ?>
					<div class="login-error">
						<?php foreach ( $errors as $error ) {
							echo esc_html( $error ) . '<br>';
						} ?>
					</div>
				<?php endif; ?>

				<form action="<?php echo esc_url( $_SERVER['REQUEST_URI'] ); ?>" method="post">
					<?php wp_nonce_field( 'zdz_register_action', 'zdz_register_nonce' ); ?>
					
					<div class="fg">
						<label for="zdz_username"><?php esc_html_e( 'Username', 'zorderz' ); ?></label>
						<input type="text" name="zdz_username" id="zdz_username" value="<?php echo isset( $_POST['zdz_username'] ) ? esc_attr( wp_unslash( $_POST['zdz_username'] ) ) : ''; ?>" required />
					</div>
					<div class="fg">
						<label for="zdz_email"><?php esc_html_e( 'Email Address', 'zorderz' ); ?></label>
						<input type="email" name="zdz_email" id="zdz_email" value="<?php echo isset( $_POST['zdz_email'] ) ? esc_attr( wp_unslash( $_POST['zdz_email'] ) ) : ''; ?>" required />
					</div>
					<div class="fg">
						<label for="zdz_password"><?php esc_html_e( 'Password', 'zorderz' ); ?></label>
						<input type="password" name="zdz_password" id="zdz_password" required />
					</div>
					<div class="fg">
						<label for="zdz_password_confirm"><?php esc_html_e( 'Confirm Password', 'zorderz' ); ?></label>
						<input type="password" name="zdz_password_confirm" id="zdz_password_confirm" required />
					</div>
					
					<button type="submit" class="btn btn-brand">
						<?php esc_html_e( 'Register', 'zorderz' ); ?>
					</button>
				</form>

				<a href="<?php echo esc_url( wp_login_url() ); ?>" class="register-link">
					<?php esc_html_e( 'Already have an account? Sign in', 'zorderz' ); ?>
				</a>

			<?php endif; ?>

		<?php endif; ?>

		<div class="login-version">
			<?php esc_html_e( 'Zorderz v1.0', 'zorderz' ); ?>
		</div>
	</div>
</div>

<?php get_footer(); ?>