<?php
/**
 * DCF_REST_API — REST endpoint
 *
 * Registers:  POST /wp-json/dcf/v1/contact
 *
 * The React frontend posts to /api/contact which should be proxied
 * (via .htaccess or Vite proxy) to this WP REST route.
 * Alternatively update the React fetch URL to the full WP REST path.
 */

defined( 'ABSPATH' ) || exit;

class DCF_REST_API {

    public static function register_routes() {
        register_rest_route(
            'dcf/v1',
            '/contact',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'handle_submission' ],
                'permission_callback' => '__return_true',   // public endpoint
                'args'                => self::endpoint_args(),
            ]
        );
    }

    /* ─────────────────────────────────────────────────────────────────
       Argument schema + sanitisation / validation
    ───────────────────────────────────────────────────────────────── */
    private static function endpoint_args() {
        return [
            'firstName' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function( $v ) { return ! empty( trim( $v ) ); },
            ],
            'email' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_email',
                'validate_callback' => function( $v ) { return is_email( $v ); },
            ],
            'phone' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'subject' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'message' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
                'validate_callback' => function( $v ) { return ! empty( trim( $v ) ); },
            ],
        ];
    }

    /* ─────────────────────────────────────────────────────────────────
       Handle the POST request
    ───────────────────────────────────────────────────────────────── */
    public static function handle_submission( WP_REST_Request $request ) {

        /* Basic honeypot / rate-limit check (optional but recommended) */
        if ( self::is_rate_limited() ) {
            return new WP_Error(
                'rate_limited',
                __( 'Too many requests. Please try again later.', 'datainitial-cf' ),
                [ 'status' => 429 ]
            );
        }

        $data = [
            'firstName' => $request->get_param( 'firstName' ),
            'email'     => $request->get_param( 'email' ),
            'phone'     => $request->get_param( 'phone' )   ?? '',
            'subject'   => $request->get_param( 'subject' ) ?? '',
            'message'   => $request->get_param( 'message' ),
        ];

        $id = DCF_DB::insert( $data );

        if ( is_wp_error( $id ) ) {
            return new WP_Error(
                'db_error',
                __( 'Could not save your message. Please try again.', 'datainitial-cf' ),
                [ 'status' => 500 ]
            );
        }

        /* Send notification email to admin + confirmation to user */
        self::notify_admin( $id, $data );
        self::notify_user( $data );

        return new WP_REST_Response(
            [
                'success' => true,
                'message' => __( 'Thank you! Your message has been received.', 'datainitial-cf' ),
                'id'      => $id,
            ],
            201
        );
    }

    /* ─────────────────────────────────────────────────────────────────
       Simple transient-based rate limit: max 5 submissions per IP / 10 min
    ───────────────────────────────────────────────────────────────── */
    private static function is_rate_limited() {
        $ip  = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        $key = 'dcf_rl_' . md5( $ip );
        $count = (int) get_transient( $key );

        if ( $count >= 5 ) {
            return true;
        }

        set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
        return false;
    }

    /* ─────────────────────────────────────────────────────────────────
       Send admin notification email
    ───────────────────────────────────────────────────────────────── */
    private static function notify_admin( $id, array $data ) {
        $admin_email = get_option( 'admin_email' );
        $site_name   = get_bloginfo( 'name' );

        $subject = sprintf(
            /* translators: %1$s site name, %2$s submitter name */
            __( '[%1$s] New contact form submission from %2$s', 'datainitial-cf' ),
            $site_name,
            $data['firstName']
        );

        $body  = sprintf( "New contact form submission (#%d)\n\n", $id );
        $body .= sprintf( "Name:    %s\n", $data['firstName'] );
        $body .= sprintf( "Email:   %s\n", $data['email'] );
        $body .= sprintf( "Phone:   %s\n", $data['phone'] );
        $body .= sprintf( "Subject: %s\n", $data['subject'] );
        $body .= sprintf( "Message:\n%s\n\n", $data['message'] );
        $body .= admin_url( 'admin.php?page=dcf-submissions&action=view&id=' . $id );

        wp_mail( $admin_email, $subject, $body );
    }

    /* ─────────────────────────────────────────────────────────────────
       Send confirmation email to the user (HTML)
    ───────────────────────────────────────────────────────────────── */
    private static function notify_user( array $data ) {
        $site_name   = get_bloginfo( 'name' );
        $site_url    = home_url();
        $admin_email = get_option( 'admin_email' );
        $year        = gmdate( 'Y' );

        $to      = $data['email'];
        $subject = sprintf(
            /* translators: %s site name */
            __( 'We received your message — %s', 'datainitial-cf' ),
            $site_name
        );

        /* ── HTML body ── */
        $html = '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>' . esc_html( $subject ) . '</title>
</head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:\'Nunito\',Arial,sans-serif;">

  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:40px 0;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0"
               style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;
                      overflow:hidden;box-shadow:0 4px 24px rgba(46,42,140,0.08);">

          <!-- Header -->
          <tr>
            <td style="background:linear-gradient(135deg,#2E2A8C 0%,#3A4FB7 100%);
                       padding:36px 40px;text-align:center;">
              <h1 style="margin:0;font-size:26px;font-weight:800;color:#ffffff;
                         letter-spacing:-0.3px;">
                ' . esc_html( $site_name ) . '
              </h1>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:40px 40px 32px;">

              <!-- Greeting -->
              <p style="margin:0 0 8px;font-size:22px;font-weight:700;color:#111111;">
                Hi ' . esc_html( $data['firstName'] ) . ' 👋
              </p>
              <p style="margin:0 0 28px;font-size:15px;color:#555555;line-height:1.7;">
                Thank you for reaching out! We have successfully received your message
                and our team will get back to you as soon as possible.
              </p>

              <!-- Confirmation box -->
              <table width="100%" cellpadding="0" cellspacing="0"
                     style="background:#f7f8fa;border:1px solid #e8eaf0;
                            border-radius:12px;margin-bottom:28px;">
                <tr>
                  <td style="padding:24px 28px;">
                    <p style="margin:0 0 16px;font-size:13px;font-weight:700;
                               color:#3A4FB7;text-transform:uppercase;letter-spacing:0.06em;">
                      Your Submission Summary
                    </p>

                    ' . self::email_row( 'Name',    esc_html( $data['firstName'] ) ) . '
                    ' . self::email_row( 'Email',   esc_html( $data['email'] ) ) . '
                    ' . ( ! empty( $data['phone'] )   ? self::email_row( 'Phone',   esc_html( $data['phone'] ) )   : '' ) . '
                    ' . ( ! empty( $data['subject'] ) ? self::email_row( 'Subject', esc_html( $data['subject'] ) ) : '' ) . '

                    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;">
                      <tr>
                        <td style="width:90px;padding:6px 0;font-size:13px;
                                   font-weight:700;color:#6b7280;vertical-align:top;">
                          Message
                        </td>
                        <td style="padding:6px 0;font-size:13px;color:#374151;
                                   line-height:1.65;vertical-align:top;">
                          ' . nl2br( esc_html( $data['message'] ) ) . '
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>

              <!-- What happens next -->
              <p style="margin:0 0 10px;font-size:15px;font-weight:700;color:#111111;">
                What happens next?
              </p>
              <ul style="margin:0 0 28px;padding-left:20px;font-size:14px;
                         color:#555555;line-height:1.9;">
                <li>Our team reviews your message within <strong>1–2 business days</strong>.</li>
                <li>We will reply directly to <strong>' . esc_html( $data['email'] ) . '</strong>.</li>
                <li>For urgent matters, feel free to call or email us directly.</li>
              </ul>

              <!-- CTA button -->
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center" style="padding-bottom:8px;">
                    <a href="' . esc_url( $site_url ) . '"
                       style="display:inline-block;padding:14px 36px;
                              background:#3A4FB7;color:#ffffff;
                              font-size:15px;font-weight:700;
                              text-decoration:none;border-radius:100px;
                              letter-spacing:0.02em;">
                      Visit Our Website
                    </a>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

          <!-- Divider -->
          <tr>
            <td style="padding:0 40px;">
              <hr style="border:none;border-top:1px solid #e8eaf0;margin:0;" />
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="padding:24px 40px;text-align:center;">
              <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">
                This is an automated confirmation. Please do not reply to this email.
              </p>
              <p style="margin:0;font-size:12px;color:#9ca3af;">
                &copy; ' . esc_html( $year ) . ' ' . esc_html( $site_name ) . ' &nbsp;&middot;&nbsp;
                <a href="' . esc_url( $site_url ) . '"
                   style="color:#3A4FB7;text-decoration:none;">
                  ' . esc_html( preg_replace( '#^https?://#', '', $site_url ) ) . '
                </a>
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>';

        /* ── Plain-text fallback ── */
        $plain  = sprintf( "Hi %s,\n\n", $data['firstName'] );
        $plain .= "Thank you for reaching out! We have successfully received your message.\n";
        $plain .= "Our team will get back to you within 1-2 business days.\n\n";
        $plain .= "--- Your Submission ---\n";
        $plain .= sprintf( "Name:    %s\n", $data['firstName'] );
        $plain .= sprintf( "Email:   %s\n", $data['email'] );
        if ( ! empty( $data['phone'] ) )   $plain .= sprintf( "Phone:   %s\n", $data['phone'] );
        if ( ! empty( $data['subject'] ) ) $plain .= sprintf( "Subject: %s\n", $data['subject'] );
        $plain .= sprintf( "Message:\n%s\n\n", $data['message'] );
        $plain .= sprintf( "Visit us: %s\n\n", $site_url );
        $plain .= sprintf( "— The %s Team\n", $site_name );

        /* ── Headers: HTML email + From address ── */
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            sprintf( 'From: %s <%s>', $site_name, $admin_email ),
        ];

        wp_mail( $to, $subject, $html, $headers );
    }

    /* ─────────────────────────────────────────────────────────────────
       Helper: single label/value row inside the summary box
    ───────────────────────────────────────────────────────────────── */
    private static function email_row( $label, $value ) {
        return '
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td style="width:90px;padding:6px 0;font-size:13px;
                       font-weight:700;color:#6b7280;vertical-align:top;">
              ' . esc_html( $label ) . '
            </td>
            <td style="padding:6px 0;font-size:13px;color:#374151;vertical-align:top;">
              ' . $value . '
            </td>
          </tr>
        </table>';
    }
}
