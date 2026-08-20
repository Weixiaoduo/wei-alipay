<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Wei_Alipay_PS_Jobs_Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_post_woo_alipay_ps_job_retry', array( $this, 'handle_retry' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'wei-alipay',
            __( 'Wei Alipay Profit Sharing Jobs', 'wei-alipay' ),
            __( 'Profit Sharing Jobs', 'wei-alipay' ),
            'manage_woocommerce',
            'wei-alipay-ps-jobs',
            array( $this, 'render_page' )
        );
    }

    public function handle_retry() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'wei-alipay' ) );
        }
        check_admin_referer( 'woo_alipay_ps_job_retry' );
        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        $jobs   = get_option( Wei_Alipay_PS_Service::JOBS_OPTION, array() );
        if ( isset( $jobs[ $job_id ] ) ) {
            $jobs[ $job_id ]['status']   = 'pending';
            $jobs[ $job_id ]['next_at']  = time();
            $jobs[ $job_id ]['updated']  = time();
            $jobs[ $job_id ]['last_error']= '';
            update_option( Wei_Alipay_PS_Service::JOBS_OPTION, $jobs, false );
            if ( function_exists( 'as_enqueue_async_action' ) ) {
                as_enqueue_async_action( 'woo_alipay_ps_process_jobs_async' );
            } elseif ( function_exists( 'wp_schedule_single_event' ) ) {
                wp_schedule_single_event( time() + 5, 'woo_alipay_ps_process_jobs' );
            }
        }
        wp_safe_redirect( admin_url( 'admin.php?page=wei-alipay-ps-jobs' ) );
        exit;
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) { return; }
        $jobs = get_option( Wei_Alipay_PS_Service::JOBS_OPTION, array() );
        echo '<div class="wrap wei-alipay-admin">';
        echo '<h1>' . esc_html__( 'Alipay Profit Sharing Jobs', 'wei-alipay' ) . '</h1>';
        echo '<p class="wei-alipay-admin__description">' . esc_html__( 'Queued settlement jobs processed in background. Use Retry to requeue failed jobs.', 'wei-alipay' ) . '</p>';

        // Filters
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
        $filter_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
        $filter_status = in_array( $filter_status, array( 'pending', 'processing', 'succeeded', 'failed' ), true ) ? $filter_status : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
        $filter_error  = isset( $_GET['error_only'] ) ? ( '1' === $_GET['error_only'] ) : false;
        echo '<div class="tablenav top"><div class="alignleft actions">';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="wei-alipay-ps-jobs" />';
        echo '<label>' . esc_html__( 'Status', 'wei-alipay' ) . ': <select name="status"><option value="">' . esc_html__( 'All', 'wei-alipay' ) . '</option>';
        foreach ( array( 'pending', 'processing', 'succeeded', 'failed' ) as $s ) {
            echo '<option value="' . esc_attr( $s ) . '"' . selected( $filter_status, $s, false ) . '>' . esc_html( ucfirst( $s ) ) . '</option>';
        }
        echo '</select></label> ';
        echo '<label><input type="checkbox" name="error_only" value="1"' . checked( $filter_error, true, false ) . ' /> ' . esc_html__( 'Only with error', 'wei-alipay' ) . '</label> ';
        submit_button( esc_html__( 'Filter', 'wei-alipay' ), 'secondary', '', false );
        echo '</form>';
        echo '</div><br class="clear"></div>';

        echo '<div class="wei-alipay-actions">';
        // Export CSV
        $export_url = add_query_arg( array(
            'action' => 'woo_alipay_ps_jobs_export',
            'status' => $filter_status,
            'error_only' => $filter_error ? '1' : '',
        ), admin_url( 'admin-post.php' ) );
        $export_url = wp_nonce_url( $export_url, 'woo_alipay_ps_jobs_export' );
        echo '<a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'wei-alipay' ) . '</a>';
        echo '</div>';

        echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Cleanup permanently removes queue records. Order data and settlement history stored on orders are retained.', 'wei-alipay' ) . '</p>';
        echo '<div class="wei-alipay-actions">';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'woo_alipay_ps_jobs_clear' ) ) . '" />';
        echo '<input type="hidden" name="action" value="woo_alipay_ps_jobs_clear" />';
        echo '<button type="submit" class="button button-link-delete" data-wei-confirm="' . esc_attr__( 'Permanently clear all succeeded job records?', 'wei-alipay' ) . '">' . esc_html__( 'Clear succeeded jobs', 'wei-alipay' ) . '</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'woo_alipay_ps_jobs_clear_old_failed' ) ) . '" />';
        echo '<input type="hidden" name="action" value="woo_alipay_ps_jobs_clear_old_failed" />';
        echo '<label><span class="screen-reader-text">' . esc_html__( 'Age in days', 'wei-alipay' ) . '</span><input type="number" min="1" name="days" value="7" class="small-text" /> ' . esc_html__( 'days', 'wei-alipay' ) . '</label> ';
        echo '<button type="submit" class="button button-link-delete" data-wei-confirm="' . esc_attr__( 'Permanently clear failed job records older than this age?', 'wei-alipay' ) . '">' . esc_html__( 'Clear old failed jobs', 'wei-alipay' ) . '</button>';
        echo '</form>';
        echo '</div></div>';

        // Apply filters
        if ( ! empty( $jobs ) && $filter_status ) {
            $jobs = array_filter( $jobs, function( $j ) use ( $filter_status ) {
                return ( $j['status'] ?? '' ) === $filter_status;
            } );
        }
        if ( ! empty( $jobs ) && $filter_error ) {
            $jobs = array_filter( $jobs, function( $j ) {
                return ! empty( $j['last_error'] );
            } );
        }

        if ( empty( $jobs ) ) {
            echo '<div class="wei-alipay-empty"><p>' . esc_html__( 'No jobs match the current filter.', 'wei-alipay' ) . '</p></div>';
        } else {
            echo '<div class="wei-alipay-table-wrap"><table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Job ID', 'wei-alipay' ) . '</th>';
            echo '<th>' . esc_html__( 'Order', 'wei-alipay' ) . '</th>';
            echo '<th>' . esc_html__( 'Phase', 'wei-alipay' ) . '</th>';
            echo '<th>' . esc_html__( 'Status', 'wei-alipay' ) . '</th>';
            echo '<th>' . esc_html__( 'Attempts', 'wei-alipay' ) . '</th>';
            echo '<th>' . esc_html__( 'Confirm checks', 'wei-alipay' ) . '</th>';
            echo '<th>' . esc_html__( 'Next run', 'wei-alipay' ) . '</th>';
            echo '<th>' . esc_html__( 'Updated', 'wei-alipay' ) . '</th>';
            echo '<th>' . esc_html__( 'Reason', 'wei-alipay' ) . '</th>';
            echo '<th>' . esc_html__( 'Last error', 'wei-alipay' ) . '</th>';
            echo '<th>' . esc_html__( 'Actions', 'wei-alipay' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $jobs as $job ) {
                $edit_link = $job['order_id'] ? get_edit_post_link( (int) $job['order_id'], '' ) : '';
                echo '<tr>';
                echo '<td>' . esc_html( (string) $job['id'] ) . '</td>';
                echo '<td>' . ( $edit_link ? '<a href=\'' . esc_url( $edit_link ) . '\'>#' . (int) $job['order_id'] . '</a>' : (int) $job['order_id'] ) . '</td>';
                echo '<td>' . esc_html( (string) ( $job['phase'] ?? 'settle' ) ) . '</td>';
                $status = (string) $job['status'];
                $status_class = 'succeeded' === $status ? ' is-success' : ( 'failed' === $status ? ' is-error' : ( 'processing' === $status ? ' is-warning' : '' ) );
                echo '<td><span class="wei-alipay-status' . esc_attr( $status_class ) . '">' . esc_html( ucfirst( $status ) ) . '</span></td>';
                echo '<td>' . esc_html( (string) ( $job['attempts'] ?? 0 ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $job['confirm_checks'] ?? 0 ) ) . '</td>';
                echo '<td>' . esc_html( date_i18n( 'Y-m-d H:i:s', (int) ( $job['next_at'] ?? 0 ) ) ) . '</td>';
                echo '<td>' . esc_html( date_i18n( 'Y-m-d H:i:s', (int) ( $job['updated'] ?? 0 ) ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $job['reason'] ?? '' ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $job['last_error'] ?? '' ) ) . '</td>';
                echo '<td>';
                echo '<form class="wei-alipay-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'woo_alipay_ps_job_retry' ) ) . '" />';
                echo '<input type="hidden" name="action" value="woo_alipay_ps_job_retry" />';
                echo '<input type="hidden" name="job_id" value="' . esc_attr( (string) $job['id'] ) . '" />';
                submit_button( esc_html__( 'Retry', 'wei-alipay' ), 'secondary small', '', false );
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '</div>';
    }
}
