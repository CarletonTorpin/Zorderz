<?php
/**
 * ZAGR_Strings - every word the customer reads that is not in the agreement template, in one place. [CORE defaults]
 *
 * The template carries the contract's own words and the legal pack its statutory notices; these are the page's and
 * the emails' words around them: steps, buttons, messages, status. Plain words, Oxford commas. t() returns plain text;
 * the caller escapes. Values are filled from {name} tokens; an unknown token stays visible so a test notices it.
 * {company} is the business's legal name and {refund} the refund time the legal pack promises, filled in by t()
 * when the caller doesn't.
 *
 * A business may reword any of these (its own steps, its own tone) on the zagr_strings filter: key => words. Words
 * that state the law come from the legal pack instead, so a reworded string can't change what the buyer is told
 * about their rights.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Strings {

	/** @var array|null this request's words */
	private static $words = null;

	private static function all(): array {
		return array(
			/* ---- the page frame ---- */
			'title'              => __( 'Your agreement', 'zorderz' ),
			'h1'                 => __( 'Your Agreement', 'zorderz' ),
			'test_banner'        => __( 'TEST AGREEMENT for test customers only. This is not a real contract.', 'zorderz' ),
			'not_found_title'    => __( 'Page not found', 'zorderz' ),
			'not_found_body'     => __( 'We couldn\'t find that page. Please check the link in your email or on your estimate.', 'zorderz' ),
			'too_many'           => __( 'Too many requests. Please wait a minute and open your link again.', 'zorderz' ),
			'not_ready_title'    => __( 'This agreement isn\'t ready yet.', 'zorderz' ),
			'not_ready_body'     => __( 'Questions? Call {phone}.', 'zorderz' ),
			'no_key_title'       => __( 'This agreement isn\'t ready to sign yet.', 'zorderz' ),
			'no_key_body'        => __( 'Please try again later, or call us at {phone}.', 'zorderz' ),
			'not_language_title'  => __( 'Please call our office about your agreement.', 'zorderz' ),
			'not_language_body'   => __( 'Call {phone}, and we\'ll help you from there.', 'zorderz' ),
			'help_line'          => __( 'Questions? Call {phone}.', 'zorderz' ),
			'unavailable_title'  => __( 'This agreement can\'t be shown right now.', 'zorderz' ),
			'unavailable_body'   => __( 'Please call us at {phone}, and we\'ll help you from there.', 'zorderz' ),
			'too_many_sessions'  => __( 'This page was opened too many times from your connection. Please try again in an hour, or call {phone}.', 'zorderz' ),

			/* ---- before signing: the age question ---- */
			'age_heading'        => __( 'Is any buyer 65 or older?', 'zorderz' ),
			'age_why'            => __( 'We ask because the answer decides which notice your agreement includes and how many business days you have to cancel.', 'zorderz' ),
			'age_no'             => __( 'No. No buyer is 65 or older.', 'zorderz' ),
			'age_yes'            => __( 'Yes. A buyer is 65 or older.', 'zorderz' ),
			'age_answered'       => __( 'You told us: {answer}. You have {days} business days to cancel.', 'zorderz' ),
			'age_change_note'    => __( 'You can change your answer until you sign. Changing it redraws the agreement, so you will open it and agree again.', 'zorderz' ),
			'locked_age'         => __( 'First, answer “Is any buyer 65 or older?” above.', 'zorderz' ),
			'dates_after_age'    => __( 'Shown once you answer “Is any buyer 65 or older?” above.', 'zorderz' ),
			'wait_right-to-cancel_h' => __( 'Your right to cancel', 'zorderz' ),
			'wait_right-to-cancel'   => __( 'Once you answer the question above, your notice, how long you have to cancel, and your dates appear here.', 'zorderz' ),
			'wait_notice-of-cancellation_h' => __( 'Notice of Cancellation', 'zorderz' ),
			'wait_notice-of-cancellation'   => __( 'The form you can use to cancel appears here once you answer the question above.', 'zorderz' ),
			'wait_project_h'     => __( 'Your project', 'zorderz' ),
			'wait_project'       => __( 'Your project, with its start and completion dates, appears here once you answer the question above.', 'zorderz' ),

			/* ---- the page's band and page one's steps ---- */
			'logo_alt'           => __( '{company}', 'zorderz' ),
			'band_title'         => __( 'Agreement', 'zorderz' ),
			'band_sub'           => __( 'Your agreement with {company}', 'zorderz' ),
			'hello'              => __( 'Thanks for choosing {company}.', 'zorderz' ),
			'next_heading'       => __( 'What happens next', 'zorderz' ),
			'next_1'             => __( 'You sign', 'zorderz' ),
			'next_1_d'           => __( 'We both sign, and you get your copy.', 'zorderz' ),
			'next_2'             => __( 'Deposit', 'zorderz' ),
			'next_2_d'           => __( 'We email you an invoice for it.', 'zorderz' ),
			'next_3'             => __( 'We prepare', 'zorderz' ),
			'next_3_d'           => __( 'After your cancellation period ends, we get everything ready.', 'zorderz' ),
			'next_4'             => __( 'We do the work', 'zorderz' ),
			'next_4_d'           => __( 'We confirm your date with you.', 'zorderz' ),
			'next_5'             => __( 'All done', 'zorderz' ),
			'next_5_d'           => __( 'Final payment when the work is done.', 'zorderz' ),
			'next_done'          => __( 'Done', 'zorderz' ),
			'next_signed'        => __( 'Signed', 'zorderz' ),

			/* ---- before signing ---- */
			'review_title'       => __( 'Please review, then sign at the bottom.', 'zorderz' ),
			'review_sub'         => __( 'Nothing is ordered until you sign and your cancellation period ends.', 'zorderz' ),
			'steps_label'        => __( 'Where your agreement is', 'zorderz' ),
			'step_review'        => __( 'Review', 'zorderz' ),
			'step_sign'          => __( 'Sign', 'zorderz' ),
			'step_window'        => __( 'Cancellation period', 'zorderz' ),
			'step_order'         => __( 'We place your order', 'zorderz' ),
			'sign_heading'       => __( 'Sign your agreement', 'zorderz' ),
			's1_heading'         => __( '1. Open the agreement', 'zorderz' ),
			'pdf_button'         => __( 'Open the agreement (PDF)', 'zorderz' ),
			'pdf_help'           => __( 'Open it once to show it opens on your device. Signing stays off until it has.', 'zorderz' ),
			'pdf_done'           => __( 'Opened. You can come back to this page now.', 'zorderz' ),
			'pdf_continue'       => __( 'I opened it. Continue', 'zorderz' ),
			's2_heading'         => __( '2. Agree to sign electronically', 'zorderz' ),
			'consent_label'      => __( 'I have read this agreement, the payment schedule, and the Notice of Cancellation, and I agree to receive and sign this agreement, its notices, and any change orders to it electronically.', 'zorderz' ),
			'consent_label_change' => __( 'I have read this change order, and I agree to receive and sign it electronically.', 'zorderz' ),
			'consent_send'       => __( 'Email me my code', 'zorderz' ),
			'consent_where'      => __( 'When you press it, we email a 6-digit code to {email}, the address on your estimate.', 'zorderz' ),
			'continue'           => __( 'Continue', 'zorderz' ),
			'consent_done'       => __( 'You agreed to sign electronically.', 'zorderz' ),
			's3_heading'         => __( '3. Sign', 'zorderz' ),
			'code_where'         => __( 'We\'ll email a 6-digit code to {email}, the address on your estimate.', 'zorderz' ),
			'code_send'          => __( 'Email me a code', 'zorderz' ),
			'code_resend'        => __( 'Email me a new code', 'zorderz' ),
			'code_sent_to'       => __( 'We sent a 6-digit code to {email}. It works for 10 minutes.', 'zorderz' ),
			'code_label'         => __( '6-digit code', 'zorderz' ),
			'code_in_label'      => __( '6-digit code from your email', 'zorderz' ),
			'code_check'         => __( 'Check code', 'zorderz' ),
			'code_done'          => __( 'Code accepted.', 'zorderz' ),
			'sign_name_label'    => __( 'Type your full name to sign', 'zorderz' ),
			'sign_name_help'     => __( 'Your typed name is your signature in both places below. Tick the box at each one, then press Sign.', 'zorderz' ),
			'tick_agreement'     => __( 'I sign this agreement.', 'zorderz' ),
			'tick_notice'        => __( 'I acknowledge that I have received this notice.', 'zorderz' ),
			'signed_as'          => __( 'Signature', 'zorderz' ),
			'date_line'          => __( 'Date: {date}', 'zorderz' ),
			'sign_button'        => __( 'Sign', 'zorderz' ),
			'full_name'          => __( 'Full name', 'zorderz' ),
			'locked_note'        => __( 'Finish the step above first.', 'zorderz' ),

			/* ---- messages after a step (?m=) ---- */
			'm_pdf_first'        => __( 'Please open the agreement first. If it doesn\'t open on your device, call {phone}.', 'zorderz' ),
			'm_consent_needed'   => __( 'Please tick the box to continue.', 'zorderz' ),
			'm_consent_first'    => __( 'Please agree to sign electronically first.', 'zorderz' ),
			'm_code_wait'        => __( 'Please wait a minute before asking for another code.', 'zorderz' ),
			'm_code_limit'       => __( 'We\'ve sent several codes already. Please try again later, or call {phone}.', 'zorderz' ),
			'm_code_paused'      => __( 'Too many wrong codes were typed for this agreement today, so codes are paused for now. Please call {phone}.', 'zorderz' ),
			'm_code_locked'      => __( 'Too many wrong codes were typed here, so codes are paused on this connection for up to 24 hours. Please call {phone}.', 'zorderz' ),
			'm_manage_limit'     => __( 'We\'ve sent several codes already, so please try again later. You can still cancel without a code: email {email}, or mail a letter to {address}. Questions? Call {phone}.', 'zorderz' ),
			'm_manage_paused'    => __( 'Too many wrong codes were typed for this agreement today, so codes are paused for now. You can still cancel without a code: email {email}, or mail a letter to {address}. Questions? Call {phone}.', 'zorderz' ),
			'm_manage_wait'      => __( 'Please wait a minute before asking for another code. You can still cancel without a code: email {email}, or mail a letter to {address}.', 'zorderz' ),
			'm_manage_mail'      => __( 'We couldn\'t send the email. Please try again. You can still cancel without a code: email {email}, or mail a letter to {address}. Questions? Call {phone}.', 'zorderz' ),
			'm_manage_busy'      => __( 'Something went wrong. Please try again. You can still cancel without a code: email {email}, or mail a letter to {address}. Questions? Call {phone}.', 'zorderz' ),
			'm_manage_locked'    => __( 'Too many wrong codes were typed here, so codes are paused on this connection for up to 24 hours. You can still cancel without a code: email {email}, or mail a letter to {address}. Questions? Call {phone}.', 'zorderz' ),
			'm_age_needed'       => __( 'Please choose Yes or No.', 'zorderz' ),
			'm_age_first'        => __( 'Please answer whether any buyer is 65 or older first.', 'zorderz' ),
			'm_code_mail'        => __( 'We couldn\'t send the email. Please try again, or call {phone}.', 'zorderz' ),
			'm_code_wrong'       => __( 'That code isn\'t right. Please check it and try again.', 'zorderz' ),
			'm_code_expired'     => __( 'That code has expired. Please ask for a new one.', 'zorderz' ),
			'm_code_tries'       => __( 'Too many tries. Please ask for a new code.', 'zorderz' ),
			'm_code_first'       => __( 'Please get a code first.', 'zorderz' ),
			'm_code_type'        => __( 'Please type the 6-digit code from your email. If it didn’t arrive, press “{button}.”', 'zorderz' ),
			'm_code_sent'        => __( 'We sent you a code. Please check your email.', 'zorderz' ),
			'm_code_ok'          => __( 'Code accepted.', 'zorderz' ),
			'm_names_needed'     => __( 'Please type your full name and tick both boxes.', 'zorderz' ),
			'm_name_needed'      => __( 'Please type your full name and tick the box.', 'zorderz' ),
			'm_changed'          => __( 'This agreement changed since you opened the page. Please read it again before you sign.', 'zorderz' ),
			'm_sign_failed'      => __( 'We couldn\'t save your signed copy, so nothing was signed. Please try again, or call {phone}.', 'zorderz' ),
			'm_signed'           => __( 'You signed. Your copy, signed by you and {company}, is ready below and on its way to your email.', 'zorderz' ),
			'm_signed_nomail'    => __( 'You signed, but we couldn\'t email your signed copy. Please open it below and keep it. Your cancellation period starts when you open it.', 'zorderz' ),
			'm_signed_unrecorded' => __( 'You signed. We emailed your signed copy, but couldn\'t record that it was sent. Please open it below and keep it. Your cancellation period starts when you open it.', 'zorderz' ),
			'm_change_signed'    => __( 'You signed the change order. Your copy is on its way to your email.', 'zorderz' ),
			'm_change_signed_nomail' => __( 'You signed the change order, but we couldn\'t email your copy. Please open it below and keep it.', 'zorderz' ),
			'm_done'             => __( 'You\'re done here. To open your signed copy again, we\'ll email you a code.', 'zorderz' ),
			'm_cancel_tick'      => __( 'Please tick the box to confirm that you want to cancel.', 'zorderz' ),
			'm_cancelled'        => __( 'You cancelled this agreement. We emailed you a confirmation.', 'zorderz' ),
			'm_window_over'      => __( 'The cancellation period has ended, so this agreement can no longer be cancelled on this page. Please call {phone}.', 'zorderz' ),
			'm_session'          => __( 'Your session ended. Please start that step again.', 'zorderz' ),
			'm_busy'             => __( 'Something went wrong. Please try again, or call {phone}.', 'zorderz' ),
			'm_not_now'          => __( 'That isn\'t available right now.', 'zorderz' ),
			'm_manage_ok'        => __( 'Code accepted. You can open your signed copy or cancel for the next 15 minutes.', 'zorderz' ),
			/* an agreement signed on paper has no copy online: the customer's paper copy is the agreement */
			'm_manage_ok_paper'  => __( 'Code accepted. You can cancel here for the next 15 minutes.', 'zorderz' ),
			'm_done_paper'       => __( 'You\'re done here. To cancel here later, we\'ll email you a code.', 'zorderz' ),
			'm_paper_no_copy'    => __( 'You signed your agreement on paper, so there is no copy to open here. Your paper copy is your agreement: please keep it.', 'zorderz' ),

			/* ---- after signing ---- */
			'signed_title'       => __( 'Signed on {date}.', 'zorderz' ),
			'status_h1'          => __( 'Your agreement is signed.', 'zorderz' ),
			'status_h1_cancelled' => __( 'This agreement is cancelled.', 'zorderz' ),
			'copy_emailed'       => __( 'Your copy, signed by you and {company}, was emailed to the address on your estimate.', 'zorderz' ),
			'copy_opened'        => __( 'You opened your signed copy on {date}.', 'zorderz' ),
			'copy_not_received'  => __( 'We couldn\'t email your signed copy. Please open it below and keep it. Your cancellation period starts when you do.', 'zorderz' ),
			'cancel_not_started' => __( 'Your cancellation period starts when you receive your signed copy. You may cancel for any reason, without penalty, until midnight on the {ordinal} business day after that.', 'zorderz' ),
			'done_button'        => __( 'Done', 'zorderz' ),
			'done_help'          => __( 'On a shared phone or computer, press Done when you finish.', 'zorderz' ),
			'copy_here'          => __( 'Open your signed copy (PDF)', 'zorderz' ),
			'cancel_heading'     => __( 'Your right to cancel', 'zorderz' ),
			'cancel_until'       => __( 'You may cancel for any reason, without penalty, until', 'zorderz' ),
			'deadline'           => __( 'midnight on {date}', 'zorderz' ),
			'left_days'          => __( '{days} and {hours} left.', 'zorderz' ),
			'order_after'        => __( 'We place your order after this date.', 'zorderz' ),
			'ways_to_cancel'     => __( 'You can cancel with the button below, by email to {email}, or by mail to {address}. A cancellation by email or letter counts just the same.', 'zorderz' ),
			'ways_to_cancel_nb'  => __( 'You can cancel by email to {email}, or by mail to {address}. A cancellation by email or letter counts just the same.', 'zorderz' ),
			'window_closed'      => __( 'Your cancellation period ended at midnight on {date}.', 'zorderz' ),
			'window_closed_sub'  => __( 'We can now place your order.', 'zorderz' ),
			'manage_heading'     => __( 'Your signed copy and cancelling', 'zorderz' ),
			'manage_help'        => __( 'To open your signed copy or cancel, we\'ll email you a code first.', 'zorderz' ),
			'manage_help_closed' => __( 'To open your signed copy, we\'ll email you a code first.', 'zorderz' ),
			'manage_help_cancel' => __( 'To cancel, we\'ll email you a code first.', 'zorderz' ),
			'manage_help_more'   => __( 'To open your signed copy again later, we\'ll email you a code first.', 'zorderz' ),
			'cancel_button'      => __( 'Cancel this agreement', 'zorderz' ),
			'cancel_tick'        => __( 'Yes, I want to cancel this agreement.', 'zorderz' ),
			'cancel_help'        => __( 'We\'ll email you a confirmation. Any deposit you paid will be refunded within {refund}.', 'zorderz' ),
			'manage_no_key'      => __( 'To get a copy of your signed agreement or to cancel, email {email} or call {phone}.', 'zorderz' ),
			/* signed on paper */
			'paper_signed_title' => __( 'Signed on paper on {date}.', 'zorderz' ),
			'paper_signed_sub'   => __( 'You signed your agreement on paper. Your paper copy is your agreement: please keep it. There is nothing to sign here.', 'zorderz' ),
			'paper_ways_to_cancel' => __( 'You can cancel with the button below, or by sending us any written notice: the Notice of Cancellation in your paper copy, an email to {email}, or a letter to {address}. Any written notice counts.', 'zorderz' ),
			'paper_ways_to_cancel_nb' => __( 'You can cancel by sending us any written notice: the Notice of Cancellation in your paper copy, an email to {email}, or a letter to {address}. Any written notice counts.', 'zorderz' ),
			'paper_manage_heading' => __( 'Cancel here', 'zorderz' ),
			'paper_manage_help'  => __( 'To cancel here, we\'ll email you a code first.', 'zorderz' ),
			'payments_heading'   => __( 'Your payments', 'zorderz' ),
			'payments_signed'    => __( 'As set out in your signed agreement:', 'zorderz' ),
			'payments_offered'   => __( 'As offered in your agreement:', 'zorderz' ),
			'pay_deposit'        => __( 'Deposit', 'zorderz' ),
			'pay_deposit_due'    => __( 'When you sign your agreement', 'zorderz' ),
			'pay_final'          => __( 'Final payment', 'zorderz' ),
			'pay_final_due'      => __( 'When the work is done', 'zorderz' ),
			'pay_total'          => __( 'Total', 'zorderz' ),
			'promise'            => __( 'You will never be asked to pay for work we haven\'t done.', 'zorderz' ),
			'cancelled_title'    => __( 'You cancelled this agreement on {date}.', 'zorderz' ),
			'cancelled_sub'      => __( 'Any deposit you paid will be refunded within {refund}. Nothing will be ordered.', 'zorderz' ),

			/* ---- change orders ---- */
			'change_preparing'   => __( 'A change to your agreement is being prepared. Questions? Call {phone}.', 'zorderz' ),
			'change_code_help'   => __( 'To see the change order and sign it, we\'ll email a 6-digit code to {email}, the address on your estimate.', 'zorderz' ),
			'c1_heading'         => __( '1. Get your code', 'zorderz' ),
			'c2_heading'         => __( '2. Open the change order', 'zorderz' ),
			'c3_heading'         => __( '3. Agree to sign electronically', 'zorderz' ),
			'c4_heading'         => __( '4. Sign', 'zorderz' ),
			'change_ready'       => __( 'Your agreement has a change for you to sign.', 'zorderz' ),
			'change_window_note' => __( 'Signing this change order does not start a new cancellation period. Your cancellation date stays the same, and we wait to order until you sign it.', 'zorderz' ),
			'change_heading'     => __( 'Change order', 'zorderz' ),
			'change_sign_heading'=> __( 'Sign the change order', 'zorderz' ),
			'change_sig_label'   => __( 'Type your full name to sign', 'zorderz' ),
			'change_sig_tick'    => __( 'By typing my name and pressing Sign, I sign this change order.', 'zorderz' ),
			'change_signed_line' => __( 'Change order {n} signed on {date}.', 'zorderz' ),
			'change_copy'        => __( 'Open the signed change order (PDF)', 'zorderz' ),
			'change_pdf_button'  => __( 'Open the change order (PDF)', 'zorderz' ),

			/* ---- emails ---- */
			'mail_code_subject'      => __( 'Your code for your agreement with {company}', 'zorderz' ),
			'mail_code_body'         => __( "Your code is: {code}\n\nIt works for 10 minutes. Please type it on your agreement page.\n\nIf you didn't ask for a code, you can ignore this email.\n\nQuestions? Call {phone}.\n\n{company}", 'zorderz' ),
			'mail_signed_subject'    => __( 'Your signed agreement with {company}', 'zorderz' ),
			'mail_signed_body'       => __( "Thank you. Your agreement, signed by you and by {company}, is attached as a PDF. Please keep it.\n\nSigned on: {signed}\nYou may cancel for any reason, without penalty, until midnight on {cancel_by}.\n\nTo cancel, email {cancel_email}, mail a letter to {address}, or use the Cancel button on your agreement page:\n{url}\n\nWe place your order after your cancellation period ends.\n\nQuestions? Call {phone}.\n\n{company}", 'zorderz' ),
			'mail_change_subject'    => __( 'Your signed change order with {company}', 'zorderz' ),
			'mail_change_body'       => __( "Your change order, signed by you and by {company}, is attached as a PDF. Please keep it with your agreement.\n\nSigned on: {signed}\nThis change order does not start a new cancellation period.\n\nYour agreement page:\n{url}\n\nQuestions? Call {phone}.\n\n{company}", 'zorderz' ),
			'mail_cancel_subject'    => __( 'You cancelled your agreement with {company}', 'zorderz' ),
			'mail_cancel_body'       => __( "We received your cancellation on {when}. Your agreement for estimate #{number} is cancelled.\n\nAny deposit you paid will be refunded within {refund}. Nothing will be ordered.\n\nQuestions? Call {phone}.\n\n{company}", 'zorderz' ),
			'mail_staff_subject'     => __( 'Cancelled: agreement for estimate #{number}', 'zorderz' ),
			'mail_staff_body'        => __( "The customer cancelled the agreement for estimate #{number} (billing client {client}) on {when}, {how}.\n\nWhat we must do now:\n1. Refund everything the customer paid, the deposit included, within {refund} of the cancellation.\n2. If anything was delivered to the home, ask for it back within 20 days, or it becomes the customer's to keep.\n3. Don't charge for any work done before the cancellation.\n4. Put back anything we changed at the home the way it was.\n\nDon't place any order for this estimate. The record is in Settings, Agreements.\n\nRecord fingerprint: {head} (after step {steps}). Keep this email: the fingerprint shows the record later has not been changed.{test}", 'zorderz' ),
			'mail_codes_locked_subject'  => __( 'Codes paused: agreement for estimate #{number}', 'zorderz' ),
			'mail_codes_locked_body'     => __( "Wrong codes were typed {n} times from one connection on the agreement page for estimate #{number} (billing client {client}), so codes for {what} are paused for that connection until {until}. Codes still work for everyone else, the customer included.\n\nIt may be someone with the link guessing, or the customer having trouble. If it was the customer, you can turn codes back on in Settings, Agreements.{test}", 'zorderz' ),
			'mail_codes_limited_subject' => __( 'Many codes asked for: agreement for estimate #{number}', 'zorderz' ),
			'mail_codes_limited_body'    => __( "Codes for the agreement for estimate #{number} (billing client {client}) were asked for many times, from more than one place, so no more are sent for now (at most {per_hour} an hour and {per_day} a day), except to a connection a code was already used from. We emailed the customer that codes are paused, with how to cancel by email or letter.{test}", 'zorderz' ),
			'mail_codes_ceiling_subject' => __( 'Many wrong codes typed: agreement for estimate #{number}', 'zorderz' ),
			'mail_codes_ceiling_body'    => __( "Wrong codes were typed {n} times today on the agreement page for estimate #{number} (billing client {client}), from places no code was ever used from, so codes for {what} are paused for those places for the rest of the day. A connection the customer already used a code from still works. We emailed the customer that codes are paused, with how to cancel by email or letter. If it was the customer, you can turn codes back on in Settings, Agreements.{test}", 'zorderz' ),
			'mail_paused_subject'        => __( 'Codes for your agreement with {company} are paused for now', 'zorderz' ),
			'mail_paused_signed'         => __( "Codes for your agreement page were asked for, or typed wrong, many times in a short time, so we've stopped sending them for now. If you didn't ask for them, someone else may have the link to your agreement.\n\nYou can still cancel your agreement without a code: email {cancel_email}, or mail a letter to {address}. A cancellation by email or letter counts just the same.{deadline}\n\nQuestions? Call {phone}.\n\n{company}", 'zorderz' ),
			'mail_paused_deadline'       => __( ' Your cancellation period ends at midnight on {cancel_by}.', 'zorderz' ),
			'mail_paused_unsigned'       => __( "Codes for your agreement page were asked for, or typed wrong, many times in a short time, so we've stopped sending them for now. If you didn't ask for them, someone else may have the link to your agreement.\n\nPlease try again later, or call us at {phone}.\n\n{company}", 'zorderz' ),
			/* ---- staff alerts: the estimate number only, never the customer's name. A title
			   shows on a lock screen. The cancellation duties are the staff email's own words. */
			'alert_estimate'          => __( 'Estimate #{number}.', 'zorderz' ),
			'alert_estimate_none'     => __( 'An estimate (its number could not be read).', 'zorderz' ),
			'alert_signed_title'      => __( 'An agreement was signed', 'zorderz' ),
			'alert_signed_body'       => __( 'The customer signed the agreement and has their signed copy. They can cancel until midnight on {cancel_by}.', 'zorderz' ),
			'alert_signed_next'       => __( 'Next step: send the deposit invoice for {deposit}.', 'zorderz' ),
			'alert_signed_no_deposit' => __( 'There is no deposit on this agreement, so there is no deposit invoice to send.', 'zorderz' ),
			'alert_ok_title'          => __( 'OK to order', 'zorderz' ),
			'alert_ok_body'           => __( 'The cancellation period has ended, so work on the order can go ahead now.', 'zorderz' ),
			'alert_ok_again_signed'   => __( 'The customer signed the change order, and the cancellation period has ended, so work on the order can go ahead now.', 'zorderz' ),
			'alert_ok_again_withdrawn'=> __( 'The change order was withdrawn, and the cancellation period has ended, so work on the order can go ahead now.', 'zorderz' ),
			'alert_cancelled_title'   => __( 'An agreement was cancelled', 'zorderz' ),
			'alert_cancelled_body'    => __( 'The customer cancelled the agreement. Refund everything the customer paid, the deposit included, within {refund} of the cancellation. Don\'t place any order for this estimate.', 'zorderz' ),
			'alert_test'              => __( 'This is a TEST agreement ({fixture}).', 'zorderz' ),
			/* to the administrators; the estimate number, the dates and who recorded it (staff), never the customer */
			'alert_paper_title'       => __( 'Signed on paper', 'zorderz' ),
			'alert_paper_body'        => __( 'Signed on paper on {date}, recorded by {who} {where}. The customer can cancel until midnight on {cancel_by}. The photo of the signature page is in Settings, Agreements.', 'zorderz' ),
			'alert_paper_body_ended'  => __( 'Signed on paper on {date}, recorded by {who} {where}. Their cancellation period ended at midnight on {cancel_by}. The photo of the signature page is in Settings, Agreements.', 'zorderz' ),
			'alert_ok_confirmed'      => __( 'An administrator confirmed the late paper signing, and the cancellation period has ended, so work on the order can go ahead now.', 'zorderz' ),
			'alert_paper_staff'       => __( 'staff', 'zorderz' ),
			'alert_paper_in_app'      => __( 'in the app', 'zorderz' ),
			'alert_paper_in_admin'    => __( 'in Settings', 'zorderz' ),
			'how_online'             => __( 'with the Cancel button on the agreement page', 'zorderz' ),
			'how_email'              => __( 'by email (recorded by staff)', 'zorderz' ),
			'how_mail'               => __( 'by mail (recorded by staff)', 'zorderz' ),

			/* ---- the PDF ---- */
			'pdf_preview_banner'     => __( 'PREVIEW, NOT SIGNED: the agreement as it will read if you sign today.', 'zorderz' ),
			'pdf_sig_heading'        => __( 'Signatures and record', 'zorderz' ),
			'pdf_sig_agreement'      => __( 'The agreement, signed by the buyer', 'zorderz' ),
			'pdf_sig_notice'         => __( 'The Notice of Cancellation, signed and dated by the buyer', 'zorderz' ),
			'pdf_sig_notice_ack'     => __( 'The right-to-cancel notice, acknowledged and dated by the buyer', 'zorderz' ),
			'pdf_one_name'           => __( 'The buyer typed one name, applied to both signatures, and ticked the box beside each.', 'zorderz' ),
			'pdf_sig_caption'        => __( 'Buyer’s signature (typed, signed electronically)', 'zorderz' ),
			'pdf_date_caption'       => __( 'Date', 'zorderz' ),
			'pdf_change_title'       => __( 'Change Order', 'zorderz' ),
			'pdf_running_change'     => __( 'Change Order', 'zorderz' ),
			'pdf_sig_contractor'     => __( 'Signed for the contractor, {company}', 'zorderz' ),
			'pdf_record'             => __( 'Record of this signing', 'zorderz' ),
			'pdf_steps'              => __( 'The steps of this signing, in order (times in UTC)', 'zorderz' ),
			'pdf_steps_note'         => __( 'This list shows the agreement\'s own steps and the steps taken where it was signed. Each fingerprint is the start of a keyed SHA-256 over that step and the one before it, so a changed step shows. The full record is kept by {company}.', 'zorderz' ),
			'pdf_record_fp'          => __( 'Record fingerprint', 'zorderz' ),
			'pdf_age_label'          => __( 'Any buyer 65 or older', 'zorderz' ),
			'pdf_age_yes'            => __( 'Yes ({days} business days to cancel)', 'zorderz' ),
			'pdf_age_no'             => __( 'No ({days} business days to cancel)', 'zorderz' ),
			'pdf_unsigned_name'      => __( '(not signed yet)', 'zorderz' ),
			'pdf_pack_label'         => __( 'Legal rules applied', 'zorderz' ),
			'pdf_window_label'       => __( 'Time to cancel', 'zorderz' ),
			'pdf_window_days'        => __( '{days} business days', 'zorderz' ),
		);
	}

	/** The words, with a business's own wording applied. */
	private static function words(): array {
		if ( null === self::$words ) {
			$w = self::all();
			if ( function_exists( 'apply_filters' ) ) {
				$f = apply_filters( 'zagr_strings', array() );
				foreach ( is_array( $f ) ? $f : array() as $k => $v ) {
					if ( isset( $w[ $k ] ) && is_string( $v ) && '' !== trim( $v ) ) {
						$w[ $k ] = $v;
					}
				}
			}
			self::$words = $w;
		}
		return self::$words;
	}

	/** Forget the words (after a filter changes, and in the suites). */
	public static function forget(): void {
		self::$words = null;
	}

	/** The words for $key with {tokens} filled from $vars. */
	public static function t( string $key, array $vars = array() ): string {
		$s = self::words()[ $key ] ?? $key;
		if ( false !== strpos( $s, '{company}' ) && ! array_key_exists( 'company', $vars ) ) {
			$vars['company'] = self::company();
		}
		if ( false !== strpos( $s, '{refund}' ) && ! array_key_exists( 'refund', $vars ) ) {
			$vars['refund'] = self::refund_words( array() );
		}
		if ( false !== strpos( $s, '{fixture}' ) && ! array_key_exists( 'fixture', $vars ) ) {
			$vars['fixture'] = class_exists( 'ZAGR_Settings' ) ? ZAGR_Settings::test_fixture() : '';
		}
		foreach ( $vars as $k => $v ) {
			$s = str_replace( '{' . $k . '}', (string) $v, $s );
		}
		return $s;
	}

	/** The business's name for these words: the legal name in Settings, else the Business Profile's name. */
	public static function company(): string {
		$n = class_exists( 'ZAGR_Settings' ) ? (string) ( ZAGR_Settings::facts()['legal_name'] ?? '' ) : '';
		if ( '' === $n && class_exists( 'ZDZ_Business_Profile' ) ) {
			$n = (string) ZDZ_Business_Profile::name();
		}
		return '' !== $n ? $n : __( 'us', 'zorderz' );
	}

	/** "10 days", "10 business days": the refund time the packs promise; "the time the law allows" without one. */
	public static function refund_words( array $stack ): string {
		$stack = $stack ? $stack : ( class_exists( 'ZAGR_Settings' ) ? ZAGR_Settings::home_stack() : array() );
		$r     = $stack ? ZAGR_Pack::refund( $stack ) : null;
		return null === $r ? __( 'the time the law allows', 'zorderz' ) : (int) $r['days'] . ' ' . (string) ( $r['unit'] ?? 'days' );
	}

	/** Is there a message for $key? */
	public static function has( string $key ): bool {
		return isset( self::words()[ $key ] );
	}

	/** Every key, for the suites (no message may be missing). */
	public static function keys(): array {
		return array_keys( self::all() );
	}
}
