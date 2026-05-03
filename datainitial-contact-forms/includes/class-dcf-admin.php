<?php
/**
 * DCF_Admin — WordPress admin UI
 *
 * Menu:  Contact Forms → Submissions
 *        Contact Forms → Settings  (future)
 *
 * Features:
 *  - Paginated submissions table
 *  - Search by name / email / subject / phone
 *  - Filter by status
 *  - View single submission (modal-style detail page)
 *  - Mark as read / replied / spam
 *  - Delete submission
 *  - Export filtered results to CSV
 */

defined( 'ABSPATH' ) || exit;

class DCF_Admin {

    /* ─────────────────────────────────────────────────────────────────
       Register admin menu
    ───────────────────────────────────────────────────────────────── */
    public static function register_menu() {
        add_menu_page(
            __( 'Contact Forms Data', 'datainitial-cf' ),
            __( 'Contact Forms Data', 'datainitial-cf' ),
            'manage_options',
            'dcf-submissions',
            [ __CLASS__, 'render_page' ],
            'dashicons-email-alt',
            30
        );
    }

    /* ─────────────────────────────────────────────────────────────────
       Enqueue admin styles (only on our page)
    ───────────────────────────────────────────────────────────────── */
    public static function enqueue_assets( $hook ) {
        if ( 'toplevel_page_dcf-submissions' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'dcf-admin',
            plugin_dir_url( DCF_PLUGIN_FILE ) . 'assets/admin.css',
            [],
            DCF_VERSION
        );
    }

    /* ─────────────────────────────────────────────────────────────────
       Main page router
    ───────────────────────────────────────────────────────────────── */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'datainitial-cf' ) );
        }

        $action = sanitize_key( isset( $_GET['action'] ) ? $_GET['action'] : '' );

        /* ── Handle POST actions ── */
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
            self::handle_post_action();
        }

        /* ── View single submission ── */
        if ( 'view' === $action && ! empty( $_GET['id'] ) ) {
            self::render_detail_page( (int) $_GET['id'] );
            return;
        }

        /* ── Default: list page ── */
        self::render_list_page();
    }

    /* ─────────────────────────────────────────────────────────────────
       CSV Export — hooked on admin_init (before any output)
    ───────────────────────────────────────────────────────────────── */
    public static function maybe_export_csv() {
        /* Only fire on our admin page with the export action */
        if ( ! isset( $_GET['page'] ) || 'dcf-submissions' !== $_GET['page'] ) {
            return;
        }
        if ( ! isset( $_GET['action'] ) || 'export_csv' !== $_GET['action'] ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'datainitial-cf' ) );
        }
        if ( ! isset( $_GET['_wpnonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'dcf_export_csv' ) ) {
            wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'datainitial-cf' ) );
        }

        $search = sanitize_text_field( wp_unslash( isset( $_GET['s'] )      ? $_GET['s']      : '' ) );
        $status = sanitize_key(                    isset( $_GET['status'] )  ? $_GET['status'] : '' );

        $rows     = DCF_DB::get_all_for_export( $search, $status );
        $filename = 'contact-submissions-' . gmdate( 'Y-m-d-His' ) . '.csv';

        /* Kill any buffered output so headers can be sent cleanly */
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );

        /* UTF-8 BOM — Excel needs this to open UTF-8 CSV correctly */
        fwrite( $out, "\xEF\xBB\xBF" );

        /* Header row */
        fputcsv( $out, [
            'ID',
            'First Name',
            'Email',
            'Phone',
            'Subject',
            'Message',
            'IP Address',
            'Status',
            'Submitted At',
        ] );

        /* Data rows */
        foreach ( $rows as $row ) {
            fputcsv( $out, [
                $row['id'],
                $row['first_name'],
                $row['email'],
                $row['phone'],
                $row['subject'],
                $row['message'],
                $row['ip_address'],
                $row['status'],
                $row['created_at'],
            ] );
        }

        fclose( $out );
        exit;
    }

    /* ─────────────────────────────────────────────────────────────────
       Handle POST actions (status change, delete)
    ───────────────────────────────────────────────────────────────── */
    private static function handle_post_action() {
        if ( ! isset( $_POST['dcf_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dcf_nonce'] ) ), 'dcf_action' ) ) {
            return;
        }

        $post_action = sanitize_key( isset( $_POST['dcf_action'] ) ? $_POST['dcf_action'] : '' );
        $id          = (int) ( isset( $_POST['submission_id'] ) ? $_POST['submission_id'] : 0 );

        if ( ! $id ) return;

        switch ( $post_action ) {
            case 'update_status':
                $status = sanitize_key( isset( $_POST['status'] ) ? $_POST['status'] : '' );
                DCF_DB::update_status( $id, $status );
                wp_safe_redirect( add_query_arg( [ 'page' => 'dcf-submissions', 'updated' => '1' ], admin_url( 'admin.php' ) ) );
                exit;

            case 'delete':
                DCF_DB::delete( $id );
                wp_safe_redirect( add_query_arg( [ 'page' => 'dcf-submissions', 'deleted' => '1' ], admin_url( 'admin.php' ) ) );
                exit;
        }
    }

    /* ─────────────────────────────────────────────────────────────────
       Render: submissions list
    ───────────────────────────────────────────────────────────────── */
    private static function render_list_page() {
        $search   = sanitize_text_field( wp_unslash( isset( $_GET['s'] )      ? $_GET['s']      : '' ) );
        $status   = sanitize_key(                    isset( $_GET['status'] )  ? $_GET['status'] : '' );
        $page     = max( 1, (int) ( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );
        $per_page = 20;

        $result = DCF_DB::get_submissions( [
            'search'   => $search,
            'status'   => $status,
            'per_page' => $per_page,
            'page'     => $page,
        ] );

        $rows        = $result['rows'];
        $total       = $result['total'];
        $total_pages = $result['total_pages'];

        /* Build export URL preserving current filters */
        $export_url = add_query_arg( [
            'page'   => 'dcf-submissions',
            'action' => 'export_csv',
            's'      => $search,
            'status' => $status,
        ], admin_url( 'admin.php' ) );
        $export_url = wp_nonce_url( $export_url, 'dcf_export_csv' );

        ?>
        <div class="wrap dcf-wrap">
            <h1 class="wp-heading-inline">
                <?php esc_html_e( 'Contact Form Submissions', 'datainitial-cf' ); ?>
            </h1>
            <span class="dcf-total-badge"><?php echo esc_html( $total ); ?></span>

            <?php if ( ! empty( $_GET['updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Status updated.', 'datainitial-cf' ); ?></p></div>
            <?php endif; ?>
            <?php if ( ! empty( $_GET['deleted'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Submission deleted.', 'datainitial-cf' ); ?></p></div>
            <?php endif; ?>

            <!-- Search + Filter bar -->
            <div class="dcf-toolbar">
                <form method="get" class="dcf-search-form">
                    <input type="hidden" name="page" value="dcf-submissions" />
                    <input
                        type="search"
                        name="s"
                        value="<?php echo esc_attr( $search ); ?>"
                        placeholder="<?php esc_attr_e( 'Search name, email, subject…', 'datainitial-cf' ); ?>"
                        class="dcf-search-input"
                    />
                    <select name="status" class="dcf-status-filter">
                        <option value=""><?php esc_html_e( 'All Statuses', 'datainitial-cf' ); ?></option>
                        <?php foreach ( self::statuses() as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'datainitial-cf' ); ?></button>
                    <?php if ( $search || $status ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=dcf-submissions' ) ); ?>" class="button">
                            <?php esc_html_e( 'Clear', 'datainitial-cf' ); ?>
                        </a>
                    <?php endif; ?>
                </form>

                <a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary dcf-export-btn">
                    <span class="dashicons dashicons-download" style="margin-top:3px;"></span>
                    <?php esc_html_e( 'Export CSV', 'datainitial-cf' ); ?>
                </a>
            </div>

            <!-- Table -->
            <table class="wp-list-table widefat fixed striped dcf-table">
                <thead>
                    <tr>
                        <th class="dcf-col-id"><?php esc_html_e( '#', 'datainitial-cf' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'datainitial-cf' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'datainitial-cf' ); ?></th>
                        <th><?php esc_html_e( 'Phone', 'datainitial-cf' ); ?></th>
                        <th><?php esc_html_e( 'Subject', 'datainitial-cf' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'datainitial-cf' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'datainitial-cf' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'datainitial-cf' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr>
                        <td colspan="8" class="dcf-empty">
                            <?php esc_html_e( 'No submissions found.', 'datainitial-cf' ); ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <?php
                        $view_url = add_query_arg( [
                            'page'   => 'dcf-submissions',
                            'action' => 'view',
                            'id'     => $row['id'],
                        ], admin_url( 'admin.php' ) );
                        ?>
                        <tr class="dcf-row dcf-status-<?php echo esc_attr( $row['status'] ); ?>">
                            <td><?php echo esc_html( $row['id'] ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( $view_url ); ?>" class="dcf-name-link">
                                    <?php echo esc_html( $row['first_name'] ); ?>
                                </a>
                            </td>
                            <td><a href="mailto:<?php echo esc_attr( $row['email'] ); ?>"><?php echo esc_html( $row['email'] ); ?></a></td>
                            <td><?php echo esc_html( $row['phone'] ?: '—' ); ?></td>
                            <td class="dcf-subject"><?php echo esc_html( $row['subject'] ?: '—' ); ?></td>
                            <td>
                                <span class="dcf-badge dcf-badge--<?php echo esc_attr( $row['status'] ); ?>">
                                    <?php echo esc_html( self::statuses()[ $row['status'] ] ?? $row['status'] ); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html( wp_date( 'd M Y, H:i', strtotime( $row['created_at'] ) ) ); ?></td>
                            <td class="dcf-actions">
                                <a href="<?php echo esc_url( $view_url ); ?>" class="button button-small">
                                    <?php esc_html_e( 'View', 'datainitial-cf' ); ?>
                                </a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this submission?');">
                                    <?php wp_nonce_field( 'dcf_action', 'dcf_nonce' ); ?>
                                    <input type="hidden" name="dcf_action"     value="delete" />
                                    <input type="hidden" name="submission_id"  value="<?php echo esc_attr( $row['id'] ); ?>" />
                                    <button type="submit" class="button button-small dcf-btn-delete">
                                        <?php esc_html_e( 'Delete', 'datainitial-cf' ); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ( $total_pages > 1 ) : ?>
                <div class="dcf-pagination">
                    <?php
                    echo wp_kses_post( paginate_links( [
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'current'   => $page,
                        'total'     => $total_pages,
                        'prev_text' => '&laquo; ' . __( 'Prev', 'datainitial-cf' ),
                        'next_text' => __( 'Next', 'datainitial-cf' ) . ' &raquo;',
                    ] ) );
                    ?>
                    <p class="dcf-pagination-info">
                        <?php
                        printf(
                            /* translators: %1$d current page, %2$d total pages, %3$d total rows */
                            esc_html__( 'Page %1$d of %2$d — %3$d total submissions', 'datainitial-cf' ),
                            $page,
                            $total_pages,
                            $total
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ─────────────────────────────────────────────────────────────────
       Render: single submission detail
    ───────────────────────────────────────────────────────────────── */
    private static function render_detail_page( $id ) {
        $row = DCF_DB::get_by_id( $id );

        if ( ! $row ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Submission not found.', 'datainitial-cf' ) . '</p></div>';
            return;
        }

        /* Auto-mark as read when viewed */
        if ( 'new' === $row['status'] ) {
            DCF_DB::update_status( $id, 'read' );
            $row['status'] = 'read';
        }

        $back_url = add_query_arg( 'page', 'dcf-submissions', admin_url( 'admin.php' ) );
        ?>
        <div class="wrap dcf-wrap dcf-detail">
            <h1>
                <a href="<?php echo esc_url( $back_url ); ?>" class="dcf-back-link">
                    &larr; <?php esc_html_e( 'All Submissions', 'datainitial-cf' ); ?>
                </a>
                <?php
                printf(
                    /* translators: %d submission ID */
                    esc_html__( 'Submission #%d', 'datainitial-cf' ),
                    $id
                );
                ?>
            </h1>

            <div class="dcf-detail-grid">
                <!-- Left: message -->
                <div class="dcf-detail-card dcf-detail-message">
                    <h3><?php esc_html_e( 'Message', 'datainitial-cf' ); ?></h3>
                    <p class="dcf-message-text"><?php echo nl2br( esc_html( $row['message'] ) ); ?></p>
                </div>

                <!-- Right: meta -->
                <div class="dcf-detail-card dcf-detail-meta">
                    <h3><?php esc_html_e( 'Details', 'datainitial-cf' ); ?></h3>
                    <table class="dcf-meta-table">
                        <tr>
                            <th><?php esc_html_e( 'Name', 'datainitial-cf' ); ?></th>
                            <td><?php echo esc_html( $row['first_name'] ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Email', 'datainitial-cf' ); ?></th>
                            <td><a href="mailto:<?php echo esc_attr( $row['email'] ); ?>"><?php echo esc_html( $row['email'] ); ?></a></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Phone', 'datainitial-cf' ); ?></th>
                            <td><?php echo esc_html( $row['phone'] ?: '—' ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Subject', 'datainitial-cf' ); ?></th>
                            <td><?php echo esc_html( $row['subject'] ?: '—' ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'IP Address', 'datainitial-cf' ); ?></th>
                            <td><?php echo esc_html( $row['ip_address'] ?: '—' ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Submitted', 'datainitial-cf' ); ?></th>
                            <td><?php echo esc_html( wp_date( 'd M Y \a\t H:i', strtotime( $row['created_at'] ) ) ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Status', 'datainitial-cf' ); ?></th>
                            <td>
                                <span class="dcf-badge dcf-badge--<?php echo esc_attr( $row['status'] ); ?>">
                                    <?php echo esc_html( self::statuses()[ $row['status'] ] ?? $row['status'] ); ?>
                                </span>
                            </td>
                        </tr>
                    </table>

                    <!-- Status update form -->
                    <form method="post" class="dcf-status-form">
                        <?php wp_nonce_field( 'dcf_action', 'dcf_nonce' ); ?>
                        <input type="hidden" name="dcf_action"    value="update_status" />
                        <input type="hidden" name="submission_id" value="<?php echo esc_attr( $id ); ?>" />
                        <label for="dcf-status-select"><?php esc_html_e( 'Change Status:', 'datainitial-cf' ); ?></label>
                        <select name="status" id="dcf-status-select">
                            <?php foreach ( self::statuses() as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row['status'], $key ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e( 'Update', 'datainitial-cf' ); ?>
                        </button>
                    </form>

                    <!-- Delete form -->
                    <form method="post" class="dcf-delete-form"
                          onsubmit="return confirm('<?php esc_attr_e( 'Permanently delete this submission?', 'datainitial-cf' ); ?>');">
                        <?php wp_nonce_field( 'dcf_action', 'dcf_nonce' ); ?>
                        <input type="hidden" name="dcf_action"    value="delete" />
                        <input type="hidden" name="submission_id" value="<?php echo esc_attr( $id ); ?>" />
                        <button type="submit" class="button dcf-btn-delete">
                            <?php esc_html_e( 'Delete Submission', 'datainitial-cf' ); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /* ─────────────────────────────────────────────────────────────────
       Status labels
    ───────────────────────────────────────────────────────────────── */
    private static function statuses() {
        return [
            'new'     => __( 'New',     'datainitial-cf' ),
            'read'    => __( 'Read',    'datainitial-cf' ),
            'replied' => __( 'Replied', 'datainitial-cf' ),
            'spam'    => __( 'Spam',    'datainitial-cf' ),
        ];
    }
}
