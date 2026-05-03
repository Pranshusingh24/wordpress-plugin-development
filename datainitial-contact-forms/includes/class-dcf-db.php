<?php
/**
 * DCF_DB — Database layer
 * Handles table creation, inserts, queries, and deletes.
 */

defined( 'ABSPATH' ) || exit;

class DCF_DB {

    /* ── Table name (with WP prefix) ── */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . DCF_TABLE;
    }

    /* ─────────────────────────────────────────────────────────────────
       Create table on plugin activation
    ───────────────────────────────────────────────────────────────── */
    public static function create_table() {
        global $wpdb;

        $table      = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name  VARCHAR(100)        NOT NULL DEFAULT '',
            email       VARCHAR(200)        NOT NULL DEFAULT '',
            phone       VARCHAR(50)         NOT NULL DEFAULT '',
            subject     VARCHAR(255)        NOT NULL DEFAULT '',
            message     TEXT                NOT NULL,
            ip_address  VARCHAR(45)         NOT NULL DEFAULT '',
            status      VARCHAR(20)         NOT NULL DEFAULT 'new',
            created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY email (email),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'dcf_db_version', DCF_VERSION );
    }

    /* ─────────────────────────────────────────────────────────────────
       Insert a new submission
       Returns inserted ID (int) or WP_Error
    ───────────────────────────────────────────────────────────────── */
    public static function insert( array $data ) {
        global $wpdb;

        $inserted = $wpdb->insert(
            self::table(),
            [
                'first_name' => sanitize_text_field( $data['firstName'] ?? '' ),
                'email'      => sanitize_email( $data['email'] ?? '' ),
                'phone'      => sanitize_text_field( $data['phone'] ?? '' ),
                'subject'    => sanitize_text_field( $data['subject'] ?? '' ),
                'message'    => sanitize_textarea_field( $data['message'] ?? '' ),
                'ip_address' => self::get_client_ip(),
                'status'     => 'new',
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            return new WP_Error( 'db_insert_error', $wpdb->last_error );
        }

        return (int) $wpdb->insert_id;
    }

    /* ─────────────────────────────────────────────────────────────────
       Get paginated submissions with optional search
    ───────────────────────────────────────────────────────────────── */
    public static function get_submissions( array $args = [] ) {
        global $wpdb;

        $defaults = [
            'search'   => '',
            'status'   => '',
            'per_page' => 20,
            'page'     => 1,
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        ];
        $args = wp_parse_args( $args, $defaults );

        $table     = self::table();
        $where     = [];
        $values    = [];

        /* Search across name / email / subject */
        if ( ! empty( $args['search'] ) ) {
            $like      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]   = '( first_name LIKE %s OR email LIKE %s OR subject LIKE %s OR phone LIKE %s )';
            $values[]  = $like;
            $values[]  = $like;
            $values[]  = $like;
            $values[]  = $like;
        }

        /* Status filter */
        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = $args['status'];
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        /* Allowed columns for ORDER BY */
        $allowed_orderby = [ 'id', 'first_name', 'email', 'status', 'created_at' ];
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $per_page = max( 1, (int) $args['per_page'] );
        $offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

        /* Total count */
        $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        $total     = $values
            ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) )
            : (int) $wpdb->get_var( $count_sql );

        /* Rows */
        $rows_sql = "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $row_values = array_merge( $values, [ $per_page, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $row_values ), ARRAY_A );

        return [
            'rows'       => $rows ?: [],
            'total'      => $total,
            'per_page'   => $per_page,
            'page'       => (int) $args['page'],
            'total_pages'=> (int) ceil( $total / $per_page ),
        ];
    }

    /* ─────────────────────────────────────────────────────────────────
       Get all submissions for CSV export (no pagination)
    ───────────────────────────────────────────────────────────────── */
    public static function get_all_for_export( $search = '', $status = '' ) {
        global $wpdb;

        $table  = self::table();
        $where  = [];
        $values = [];

        if ( ! empty( $search ) ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]  = '( first_name LIKE %s OR email LIKE %s OR subject LIKE %s OR phone LIKE %s )';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        if ( ! empty( $status ) ) {
            $where[]  = 'status = %s';
            $values[] = $status;
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $sql       = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC";

        return $values
            ? ( $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ) ?: [] )
            : ( $wpdb->get_results( $sql, ARRAY_A ) ?: [] );
    }

    /* ─────────────────────────────────────────────────────────────────
       Update status of a single submission
    ───────────────────────────────────────────────────────────────── */
    public static function update_status( $id, $status ) {
        global $wpdb;

        $allowed = [ 'new', 'read', 'replied', 'spam' ];
        if ( ! in_array( $status, $allowed, true ) ) {
            return false;
        }

        return (bool) $wpdb->update(
            self::table(),
            [ 'status' => $status ],
            [ 'id'     => $id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    /* ─────────────────────────────────────────────────────────────────
       Delete a submission
    ───────────────────────────────────────────────────────────────── */
    public static function delete( $id ) {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    /* ─────────────────────────────────────────────────────────────────
       Get a single submission by ID
    ───────────────────────────────────────────────────────────────── */
    public static function get_by_id( $id ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /* ─────────────────────────────────────────────────────────────────
       Helper: resolve client IP
    ───────────────────────────────────────────────────────────────── */
    private static function get_client_ip() {
        $keys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];
        foreach ( $keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '';
    }
}
