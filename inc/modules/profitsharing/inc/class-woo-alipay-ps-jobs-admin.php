<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Woo_Alipay_PS_Jobs_Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_post_woo_alipay_ps_job_retry', array( $this, 'handle_retry' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Alipay PS Jobs', 'woo-alipay-profitsharing' ),
            __( 'Alipay PS Jobs', 'woo-alipay-profitsharing' ),
            'manage_woocommerce',
            'woo-alipay-ps-jobs',
            array( $this, 'render_page' )
        );
    }

    public function handle_retry() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'woo-alipay-profitsharing' ) );
        }
        check_admin_referer( 'woo_alipay_ps_job_retry' );
        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        $jobs   = get_option( Woo_Alipay_PS_Service::JOBS_OPTION, array() );
        if ( isset( $jobs[ $job_id ] ) ) {
            $jobs[ $job_id ]['status']   = 'pending';
            $jobs[ $job_id ]['next_at']  = time();
            $jobs[ $job_id ]['updated']  = time();
            $jobs[ $job_id ]['last_error']= '';
            update_option( Woo_Alipay_PS_Service::JOBS_OPTION, $jobs, false );
            if ( function_exists( 'as_enqueue_async_action' ) ) {
                as_enqueue_async_action( 'woo_alipay_ps_process_jobs_async' );
            } else if ( function_exists( 'wp_schedule_single_event' ) ) {
                wp_schedule_single_event( time() + 5, 'woo_alipay_ps_process_jobs' );
            }
        }
        wp_safe_redirect( admin_url( 'admin.php?page=woo-alipay-ps-jobs' ) );
        exit;
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_alipay_profitsharing' ) ) { return; }
        $jobs = get_option( Woo_Alipay_PS_Service::JOBS_OPTION, array() );
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Alipay Profit Sharing Jobs', 'woo-alipay-profitsharing' ) . '</h1>';
        echo '<p class="description">' . esc_html__( 'Queued settlement jobs processed in background. Use Retry to requeue failed jobs.', 'woo-alipay-profitsharing' ) . '</p>';

        // Filters
        $filter_status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
        $filter_error  = isset( $_GET['error_only'] ) ? ( '1' === $_GET['error_only'] ) : false;
        echo '<form method="get" style="margin:10px 0">';
        echo '<input type="hidden" name="page" value="woo-alipay-ps-jobs" />';
        echo '<label>' . esc_html__( 'Status', 'woo-alipay-profitsharing' ) . ': <select name="status"><option value="">' . esc_html__( 'All', 'woo-alipay-profitsharing' ) . '</option>';
        foreach ( array( 'pending', 'processing', 'succeeded' ) as $s ) {
            echo '<option value="' . esc_attr( $s ) . '"' . selected( $filter_status, $s, false ) . '>' . esc_html( ucfirst( $s ) ) . '</option>';
        }
        echo '</select></label> ';
        echo '<label><input type="checkbox" name="error_only" value="1"' . checked( $filter_error, true, false ) . ' /> ' . esc_html__( 'Only with error', 'woo-alipay-profitsharing' ) . '</label> ';
        submit_button( esc_html__( 'Filter', 'woo-alipay-profitsharing' ), 'secondary', '', false );
        echo '</form>';

        // Clear succeeded
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:10px 10px 10px 0; display:inline-block">';
        wp_nonce_field( 'woo_alipay_ps_jobs_clear' );
        echo '<input type="hidden" name="action" value="woo_alipay_ps_jobs_clear" />';
        submit_button( esc_html__( 'Clear succeeded jobs', 'woo-alipay-profitsharing' ), 'delete', '', false );
        echo '</form>';

// Clear failed older than N days
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:10px 10px 10px 0; display:inline-block">';
        wp_nonce_field( 'woo_alipay_ps_jobs_clear_old_failed' );
        echo '<input type="hidden" name="action" value="woo_alipay_ps_jobs_clear_old_failed" />';
        echo '<input type="number" min="1" name="days" value="7" style="width:80px" /> ' . esc_html__( 'days', 'woo-alipay-profitsharing' ) . ' ';
        submit_button( esc_html__( 'Clear failed older than N days', 'woo-alipay-profitsharing' ), 'delete', '', false );
        echo '</form>';

        // Export CSV
        $export_url = add_query_arg( array(
            'action' => 'woo_alipay_ps_jobs_export',
            'status' => $filter_status,
            'error_only' => $filter_error ? '1' : '',
        ), admin_url( 'admin-post.php' ) );
        echo '<a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'woo-alipay-profitsharing' ) . '</a>';

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
            echo '<p>' . esc_html__( 'No jobs yet.', 'woo-alipay-profitsharing' ) . '</p>';
        } else {
            echo '<table class="widefat fixed striped" style="max-width:1100px">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Job ID', 'woo-alipay-profitsharing' ) . '</th>';
            echo '<th>' . esc_html__( 'Order', 'woo-alipay-profitsharing' ) . '</th>';
            echo '<th>' . esc_html__( 'Phase', 'woo-alipay-profitsharing' ) . '</th>';
            echo '<th>' . esc_html__( 'Status', 'woo-alipay-profitsharing' ) . '</th>';
            echo '<th>' . esc_html__( 'Attempts', 'woo-alipay-profitsharing' ) . '</th>';
            echo '<th>' . esc_html__( 'Confirm checks', 'woo-alipay-profitsharing' ) . '</th>';
            echo '<th>' . esc_html__( 'Next run', 'woo-alipay-profitsharing' ) . '</th>';
            echo '<th>' . esc_html__( 'Updated', 'woo-alipay-profitsharing' ) . '</th>';
            echo '<th>' . esc_html__( 'Reason', 'woo-alipay-profitsharing' ) . '</th>';
            echo '<th>' . esc_html__( 'Last error', 'woo-alipay-profitsharing' ) . '</th>';
            echo '<th>' . esc_html__( 'Actions', 'woo-alipay-profitsharing' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $jobs as $job ) {
                $edit_link = $job['order_id'] ? get_edit_post_link( (int) $job['order_id'], '' ) : '';
                echo '<tr>';
                echo '<td>' . esc_html( (string) $job['id'] ) . '</td>';
                echo '<td>' . ( $edit_link ? '<a href=\'' . esc_url( $edit_link ) . '\'>#' . (int) $job['order_id'] . '</a>' : (int) $job['order_id'] ) . '</td>';
                echo '<td>' . esc_html( (string) ( $job['phase'] ?? 'settle' ) ) . '</td>';
                echo '<td>' . esc_html( (string) $job['status'] ) . '</td>';
                echo '<td>' . esc_html( (string) ( $job['attempts'] ?? 0 ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $job['confirm_checks'] ?? 0 ) ) . '</td>';
                echo '<td>' . esc_html( date_i18n( 'Y-m-d H:i:s', (int) ( $job['next_at'] ?? 0 ) ) ) . '</td>';
                echo '<td>' . esc_html( date_i18n( 'Y-m-d H:i:s', (int) ( $job['updated'] ?? 0 ) ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $job['reason'] ?? '' ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $job['last_error'] ?? '' ) ) . '</td>';
                echo '<td>';
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
                wp_nonce_field( 'woo_alipay_ps_job_retry' );
                echo '<input type="hidden" name="action" value="woo_alipay_ps_job_retry" />';
                echo '<input type="hidden" name="job_id" value="' . esc_attr( (string) $job['id'] ) . '" />';
                submit_button( esc_html__( 'Retry', 'woo-alipay-profitsharing' ), 'secondary small', '', false );
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }
}
