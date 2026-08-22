<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Wei_Alipay_Reconcile_Runner {

    public static function run( $date, $type = 'trade' ) {
        $date = is_string( $date ) ? trim( $date ) : '';
        $type = is_string( $type ) ? sanitize_key( $type ) : '';
        if ( ! in_array( $type, array( 'trade', 'signcustomer' ), true ) || ! preg_match( '/^\d{4}-(?:\d{2}|\d{2}-\d{2})$/', $date ) ) {
            return array( 'error' => __( '对账单类型或日期无效。', 'wei-alipay' ) );
        }
        $summary = array(
            'date'       => $date,
            'bill_type'  => $type,
            'downloaded' => false,
            'file'       => '',
            'matched'    => 0,
            'mismatched' => 0,
            'unmatched'  => 0,
            'total_rows' => 0,
            'notes'      => array(),
            'details'    => array(
                'anomalies' => array(),
                'matched'   => array(),
            ),
            'detail_counts' => array(
                'anomalies' => 0,
                'matched'   => 0,
            ),
        );

        // Prepare Alipay SDK client.
        require_once WEI_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
        require_once WEI_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayDataDataserviceBillDownloadurlQueryRequest.php';

        $core = get_option( 'woocommerce_alipay_settings', array() );
        $config = Alipay_SDK_Helper::get_alipay_config( array(
            'appid'       => $core['appid'] ?? '',
            'private_key' => $core['private_key'] ?? '',
            'public_key'  => $core['public_key'] ?? '',
            'sandbox'     => $core['sandbox'] ?? 'no',
        ) );

        $aop = Alipay_SDK_Helper::create_alipay_service( $config );
        if ( ! $aop ) {
            return array( 'error' => __( '创建支付宝服务失败，请检查配置。', 'wei-alipay' ) );
        }

        $req = new AlipayDataDataserviceBillDownloadurlQueryRequest();
        $biz = array(
            'bill_type' => $type,           // 'trade' or 'signcustomer'
            'bill_date' => $date,           // 'yyyy-MM-dd' or 'yyyy-MM'
        );
        $req->setBizContent( wp_json_encode( $biz ) );

        try {
            $resp = $aop->execute( $req );
            $node = 'alipay_data_dataservice_bill_downloadurl_query_response';
            $res  = $resp->$node ?? null;
            if ( ! $res || ! isset( $res->code ) || '10000' !== $res->code || empty( $res->bill_download_url ) ) {
                return array( 'error' => __( '获取对账单下载链接失败。', 'wei-alipay' ) );
            }

            $download_url = (string) $res->bill_download_url;
            $saved = self::download_bill( $download_url, $date, $type );
            if ( is_wp_error( $saved ) ) {
                return array( 'error' => $saved->get_error_message() );
            }

            $summary['downloaded'] = true;
            // Parse the private temporary file, then remove it on every path.
            $parse = self::parse_and_reconcile( $saved['path'] );
            self::delete_temp_file( $saved['path'] );
            if ( is_wp_error( $parse ) ) {
                $summary['notes'][] = $parse->get_error_message();
            } else {
                $summary = array_merge( $summary, $parse );
            }

            return $summary;
        } catch ( Exception $e ) {
            return array( 'error' => __( '支付宝对账请求失败，请稍后重试并检查 WooCommerce 日志。', 'wei-alipay' ) );
        }
    }

    private static function download_bill( $url, $date, $type ) {
        if ( ! self::is_allowed_download_url( $url ) ) {
            return new WP_Error( 'unsafe_download_url', __( '支付宝对账单下载地址未通过安全校验。', 'wei-alipay' ) );
        }

        $max_bytes = 25 * MB_IN_BYTES;
        $download_path = wp_tempnam( 'wei-alipay-bill-download' );
        if ( ! $download_path ) {
            return new WP_Error( 'temp_file_failed', __( '无法创建私有临时账单文件。', 'wei-alipay' ) );
        }
        $response = wp_safe_remote_get( $url, array(
            'timeout'             => 60,
            'redirection'         => 0,
            'reject_unsafe_urls'  => true,
            'limit_response_size' => $max_bytes + 1,
            'stream'              => true,
            'filename'            => $download_path,
        ) );
        if ( is_wp_error( $response ) ) {
            self::delete_temp_file( $download_path );
            return $response;
        }
        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
            self::delete_temp_file( $download_path );
            return new WP_Error( 'download_http_status', __( '支付宝账单下载返回了非 200 状态。', 'wei-alipay' ) );
        }
        $size = is_file( $download_path ) ? filesize( $download_path ) : false;
        if ( false === $size || 0 === $size || $size > $max_bytes ) {
            self::delete_temp_file( $download_path );
            return new WP_Error( 'download_size', __( '支付宝账单为空或超过 25 MiB 安全限制。', 'wei-alipay' ) );
        }

        $content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
        $magic = file_get_contents( $download_path, false, null, 0, 4 );
        $is_zip = false !== strpos( $content_type, 'zip' ) || 0 === strpos( (string) $magic, "PK" );

        if ( $is_zip ) {
            if ( ! class_exists( 'ZipArchive' ) ) {
                self::delete_temp_file( $download_path );
                return new WP_Error( 'zip_archive_missing', __( '服务器缺少 ZipArchive，无法安全解析支付宝账单。', 'wei-alipay' ) );
            }
            $zip = new ZipArchive();
            if ( true !== $zip->open( $download_path ) ) {
                self::delete_temp_file( $download_path );
                return new WP_Error( 'invalid_zip', __( '支付宝账单压缩包无效。', 'wei-alipay' ) );
            }
            if ( $zip->numFiles < 1 || $zip->numFiles > 100 ) {
                $zip->close();
                self::delete_temp_file( $download_path );
                return new WP_Error( 'zip_entries', __( '支付宝账单压缩包文件数量异常。', 'wei-alipay' ) );
            }
            for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                $stat = $zip->statIndex( $i );
                $name = isset( $stat['name'] ) ? (string) $stat['name'] : '';
                $entry_size = isset( $stat['size'] ) ? (int) $stat['size'] : -1;
                $safe_name = '' !== $name && false === strpos( $name, "\0" ) && false === strpos( $name, '../' ) && 0 !== strpos( $name, '/' );
                if ( $safe_name && $entry_size >= 0 && $entry_size <= $max_bytes && preg_match( '/\.csv$/i', $name ) ) {
                    $csv_data = $zip->getFromIndex( $i );
                    if ( false === $csv_data ) {
                        continue;
                    }
                    $csv_data = self::normalize_csv_encoding( $csv_data );
                    if ( ! self::looks_like_transaction_csv( $csv_data ) ) {
                        continue;
                    }
                    $csv_path = wp_tempnam( 'wei-alipay-bill-csv' );
                    if ( ! $csv_path || false === file_put_contents( $csv_path, $csv_data, LOCK_EX ) ) {
                        $zip->close();
                        self::delete_temp_file( $download_path );
                        self::delete_temp_file( $csv_path );
                        return new WP_Error( 'csv_write', __( '无法保存私有临时账单文件。', 'wei-alipay' ) );
                    }
                    $zip->close();
                    self::delete_temp_file( $download_path );
                    return array( 'path' => $csv_path );
                }
            }
            $zip->close();
            self::delete_temp_file( $download_path );
            return new WP_Error( 'no_csv', __( '压缩包中未找到安全的 CSV 文件。', 'wei-alipay' ) );
        }

        if ( false === strpos( $content_type, 'text/' ) && false === strpos( $content_type, 'csv' ) && ! preg_match( '/^[\x09\x0A\x0D\x20-\x7E\x80-\xFF]{1,4}$/s', (string) $magic ) ) {
            self::delete_temp_file( $download_path );
            return new WP_Error( 'download_type', __( '支付宝账单文件类型不受支持。', 'wei-alipay' ) );
        }
        return array( 'path' => $download_path );
    }

    private static function is_allowed_download_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return false;
        }
        $host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
        $scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
        $path = isset( $parts['path'] ) ? $parts['path'] : '';
        return in_array( $scheme, array( 'http', 'https' ), true )
            && 'dwbillcenter.alipay.com' === $host
            && '/downloadBillFile.resource' === $path
            && empty( $parts['port'] )
            && empty( $parts['user'] )
            && empty( $parts['pass'] )
            && empty( $parts['fragment'] )
            && ! empty( $parts['query'] );
    }

    private static function delete_temp_file( $path ) {
        if ( is_string( $path ) && '' !== $path && is_file( $path ) ) {
            wp_delete_file( $path );
        }
    }

    private static function normalize_csv_encoding( $content ) {
        if ( function_exists( 'mb_detect_encoding' ) ) {
            $encoding = mb_detect_encoding( $content, array( 'UTF-8', 'GBK', 'GB2312', 'CP936', 'BIG5' ), true );
            if ( $encoding && 'UTF-8' !== $encoding ) {
                return mb_convert_encoding( $content, 'UTF-8', $encoding );
            }
        }
        return $content;
    }

    private static function looks_like_transaction_csv( $content ) {
        $lines = preg_split( "/\r\n|\n|\r/", (string) $content );
        for ( $i = 0; $i < min( 30, count( $lines ) ); $i++ ) {
            if ( false !== strpos( $lines[ $i ], '商户订单号' ) || false !== strpos( $lines[ $i ], '业务流水号' ) ) {
                return true;
            }
        }
        return false;
    }

    private static function parse_and_reconcile( $csv_path ) {
        if ( ! file_exists( $csv_path ) ) {
            return new WP_Error( 'file_missing', __( 'CSV 文件不存在。', 'wei-alipay' ) );
        }

        $content = file_get_contents( $csv_path );
        if ( ! $content ) {
            return new WP_Error( 'empty_file', __( 'CSV 文件为空。', 'wei-alipay' ) );
        }

        // Convert to UTF-8 if needed (many Alipay bills are GBK encoded).
        $normalized = self::normalize_csv_encoding( $content );
        if ( $normalized !== $content ) {
            $content = $normalized;
            file_put_contents( $csv_path, $content, LOCK_EX );
        }

        $lines = preg_split( "/\r\n|\n|\r/", $content );
        $header_index = -1;
        $headers = array();
        for ( $i = 0; $i < min( 30, count( $lines ) ); $i++ ) {
            $row = str_getcsv( $lines[ $i ], ',', '"', '' );
            if ( is_array( $row ) && count( $row ) >= 3 ) {
                $joined = implode( '', $row );
                if ( false !== mb_strpos( $joined, '商户订单号' ) || false !== mb_strpos( $joined, '业务流水号' ) ) {
                    $header_index = $i;
                    $headers = $row;
                    break;
                }
            }
        }

        if ( $header_index < 0 ) {
            return new WP_Error( 'csv_header', __( '未识别到对账单表头。', 'wei-alipay' ) );
        }

        $map = self::map_header( $headers );
        $rows = array();
        $max_rows = 100000;
        for ( $i = $header_index + 1; $i < count( $lines ); $i++ ) {
            $row = str_getcsv( $lines[ $i ], ',', '"', '' );
            if ( empty( $row ) || count( $row ) < count( $headers ) ) {
                continue;
            }
            $out_trade_no = $map['out_trade_no'] >= 0 ? ( $row[ $map['out_trade_no'] ] ?? '' ) : '';
            $trade_no     = $map['trade_no'] >= 0 ? ( $row[ $map['trade_no'] ] ?? '' ) : '';
            $amount_str   = '';
            foreach ( $map['amount_candidates'] as $idx ) {
                if ( $idx >= 0 && isset( $row[ $idx ] ) && $row[ $idx ] !== '' ) {
                    $amount_str = $row[ $idx ];
                    break;
                }
            }
            $amount = wc_format_decimal( str_replace( array( ',', '￥' ), '', $amount_str ), wc_get_price_decimals() );

            $completed_at = self::first_mapped_value( $row, $map['time_candidates'] );
            $trade_status = self::first_mapped_value( $row, $map['status_candidates'] );

            if ( '' === $out_trade_no && '' === $trade_no ) {
                continue;
            }
            $rows[] = compact( 'out_trade_no', 'trade_no', 'amount', 'completed_at', 'trade_status' );
            if ( count( $rows ) > $max_rows ) {
                return new WP_Error( 'csv_row_limit', __( '支付宝账单超过 100,000 行安全限制。', 'wei-alipay' ) );
            }
        }

        $matched = 0; $mismatched = 0; $unmatched = 0; $notes = array();
        $details = array(
            'anomalies' => array(),
            'matched'   => array(),
        );
        $detail_counts = array(
            'anomalies' => 0,
            'matched'   => 0,
        );
        $detail_limit = 200;
        foreach ( $rows as $tx ) {
            $lookup = self::locate_order_by_out_trade_no( $tx['out_trade_no'] );
            $order = $lookup['order'];
            if ( ! $order ) {
                $unmatched++;
                $detail_counts['anomalies']++;
                if ( count( $details['anomalies'] ) < $detail_limit ) {
                    $details['anomalies'][] = self::build_detail( $tx, null, 'unmatched', $lookup['reason'] );
                }
                continue;
            }
            $order_amount = wc_format_decimal( $order->get_total(), wc_get_price_decimals() );
            if ( Alipay_SDK_Helper::amounts_equal( $order_amount, $tx['amount'] ) ) {
                $matched++;
                $detail_counts['matched']++;
                if ( count( $details['matched'] ) < $detail_limit ) {
                    $details['matched'][] = self::build_detail( $tx, $order, 'matched', 'amounts_match' );
                }
            } else {
                $mismatched++;
                $detail_counts['anomalies']++;
                if ( count( $details['anomalies'] ) < $detail_limit ) {
                    $details['anomalies'][] = self::build_detail( $tx, $order, 'mismatched', 'amount_mismatch' );
                }
                if ( count( $notes ) < 100 ) {
                    $notes[] = sprintf( '订单 #%d 金额不一致：对账 %s / 订单 %s', $order->get_id(), $tx['amount'], $order_amount );
                }
            }
        }

        return array(
            'total_rows' => count( $rows ),
            'matched'    => $matched,
            'mismatched' => $mismatched,
            'unmatched'  => $unmatched,
            'notes'      => $notes,
            'details'    => $details,
            'detail_counts' => $detail_counts,
        );
    }

    private static function first_mapped_value( $row, $indexes ) {
        foreach ( $indexes as $index ) {
            if ( $index >= 0 && isset( $row[ $index ] ) && '' !== trim( (string) $row[ $index ] ) ) {
                return sanitize_text_field( trim( (string) $row[ $index ] ) );
            }
        }
        return '';
    }

    private static function build_detail( $transaction, $order, $result, $reason ) {
        return array(
            'result'          => sanitize_key( $result ),
            'reason'          => sanitize_key( $reason ),
            'out_trade_no'    => sanitize_text_field( (string) $transaction['out_trade_no'] ),
            'trade_no_masked' => self::mask_identifier( (string) $transaction['trade_no'] ),
            'statement_amount'=> wc_format_decimal( $transaction['amount'], wc_get_price_decimals() ),
            'completed_at'    => sanitize_text_field( (string) $transaction['completed_at'] ),
            'trade_status'    => sanitize_text_field( (string) $transaction['trade_status'] ),
            'order_id'        => $order ? (int) $order->get_id() : 0,
            'order_number'    => $order ? sanitize_text_field( (string) $order->get_order_number() ) : '',
            'order_status'    => $order ? sanitize_key( (string) $order->get_status() ) : '',
            'order_amount'    => $order ? wc_format_decimal( $order->get_total(), wc_get_price_decimals() ) : '',
        );
    }

    private static function mask_identifier( $value ) {
        $value = trim( $value );
        $length = strlen( $value );
        if ( $length <= 8 ) {
            return $value;
        }
        return substr( $value, 0, 4 ) . str_repeat( '*', min( 10, $length - 8 ) ) . substr( $value, -4 );
    }

    private static function map_header( $headers ) {
        $map = array(
            'out_trade_no'      => -1,
            'trade_no'          => -1,
            'amount_candidates' => array( -1, -1, -1 ),
            'time_candidates'   => array(),
            'status_candidates' => array(),
        );
        foreach ( $headers as $idx => $name ) {
            $name = trim( (string) $name );
            if ( false !== mb_strpos( $name, '商户订单号' ) ) {
                $map['out_trade_no'] = $idx;
            } elseif ( false !== mb_strpos( $name, '业务流水号' ) || false !== mb_strpos( $name, '支付宝交易号' ) ) {
                $map['trade_no'] = $idx;
            } elseif ( false !== mb_strpos( $name, '订单金额' ) || false !== mb_strpos( $name, '金额' ) ) {
                $map['amount_candidates'][0] = $idx;
            } elseif ( false !== mb_strpos( $name, '收入金额' ) ) {
                $map['amount_candidates'][1] = $idx;
            } elseif ( false !== mb_strpos( $name, '支出金额' ) ) {
                $map['amount_candidates'][2] = $idx;
            }
            if ( false !== mb_strpos( $name, '完成时间' ) || false !== mb_strpos( $name, '付款时间' ) || false !== mb_strpos( $name, '创建时间' ) ) {
                $map['time_candidates'][] = $idx;
            }
            if ( false !== mb_strpos( $name, '交易状态' ) || false !== mb_strpos( $name, '业务状态' ) ) {
                $map['status_candidates'][] = $idx;
            }
        }
        return $map;
    }

    private static function locate_order_by_out_trade_no( $out_trade_no ) {
        if ( empty( $out_trade_no ) ) {
            return array( 'order' => null, 'reason' => 'missing_merchant_order_number' );
        }
        $quoted_blog = preg_quote( (string) get_current_blog_id(), '/' );
        $patterns = array(
            '/^WooA' . $quoted_blog . '-(\d+)-\d+$/',
            '/^WooA(\d+)-\d+$/',
            '/^SubR(\d+)-\d+$/',
            '/^WOOAS' . $quoted_blog . '-(\d+)-\d+$/',
        );
        $order_id = 0;
        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, (string) $out_trade_no, $matches ) ) {
                $order_id = absint( $matches[1] );
                break;
            }
        }
        if ( ! $order_id ) {
            if ( preg_match( '/^WooA(\d+)-(\d+)-\d+$/', (string) $out_trade_no, $site_matches ) && (int) $site_matches[1] !== (int) get_current_blog_id() ) {
                return array( 'order' => null, 'reason' => 'different_site' );
            }
            return array( 'order' => null, 'reason' => 'unrecognized_merchant_order_number' );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return array( 'order' => null, 'reason' => 'order_not_found' );
        }
        $stored_numbers = array_filter( array(
            (string) $order->get_meta( '_alipay_out_trade_no' ),
            (string) $order->get_meta( 'alipay_initalRequest' ),
            (string) $order->get_meta( '_alipay_out_trade_no_withhold' ),
        ) );
        foreach ( $stored_numbers as $stored_number ) {
            if ( hash_equals( $stored_number, (string) $out_trade_no ) ) {
                return array( 'order' => $order, 'reason' => 'matched_order_number' );
            }
        }
        return array( 'order' => null, 'reason' => 'merchant_order_number_not_bound' );
    }
}
