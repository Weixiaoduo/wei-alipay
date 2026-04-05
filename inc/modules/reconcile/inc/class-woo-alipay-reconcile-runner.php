<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Woo_Alipay_Reconcile_Runner {

    public static function run( $date, $type = 'trade' ) {
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
        );

        // Prepare Alipay SDK client.
        require_once WOO_ALIPAY_PLUGIN_PATH . 'inc/class-alipay-sdk-helper.php';
        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/AopClient.php';
        require_once WOO_ALIPAY_PLUGIN_PATH . 'lib/alipay/aop/request/AlipayDataDataserviceBillDownloadurlQueryRequest.php';

        $core = get_option( 'woocommerce_alipay_settings', array() );
        $config = Alipay_SDK_Helper::get_alipay_config( array(
            'appid'       => $core['appid'] ?? '',
            'private_key' => $core['private_key'] ?? '',
            'public_key'  => $core['public_key'] ?? '',
            'sandbox'     => $core['sandbox'] ?? 'no',
        ) );

        $aop = Alipay_SDK_Helper::create_alipay_service( $config );
        if ( ! $aop ) {
            return array( 'error' => __( '创建支付宝服务失败，请检查配置。', 'woo-alipay-reconcile-pro' ) );
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
                return array( 'error' => __( '获取对账单下载链接失败。', 'woo-alipay-reconcile-pro' ), 'raw' => $resp );
            }

            $download_url = (string) $res->bill_download_url;
            $saved = self::download_bill( $download_url, $date, $type );
            if ( is_wp_error( $saved ) ) {
                return array( 'error' => $saved->get_error_message() );
            }

            $summary['downloaded'] = true;
            $summary['file']       = $saved['path'];
            $summary['file_url']   = $saved['url'];

            // Parse and reconcile
            $parse = self::parse_and_reconcile( $saved['path'] );
            if ( is_wp_error( $parse ) ) {
                $summary['notes'][] = $parse->get_error_message();
            } else {
                $summary = array_merge( $summary, $parse );
            }

            return $summary;
        } catch ( Exception $e ) {
            return array( 'error' => $e->getMessage() );
        }
    }

    private static function download_bill( $url, $date, $type ) {
        $uploads = wp_upload_dir();
        $dir     = trailingslashit( $uploads['basedir'] ) . 'woo-alipay-reconcile';
        wp_mkdir_p( $dir );

        $response = wp_remote_get( $url, array( 'timeout' => 60 ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body        = wp_remote_retrieve_body( $response );
        $content_type= wp_remote_retrieve_header( $response, 'content-type' );
        $filename    = sprintf( 'alipay_%s_%s', sanitize_key( $type ), preg_replace( '/[^0-9\-]/', '', $date ) );

        // Handle zip or csv.
        $is_zip = false;
        if ( $content_type && false !== stripos( $content_type, 'zip' ) ) {
            $is_zip = true;
        } elseif ( substr( $body, 0, 2 ) === "PK" ) { // ZIP file magic
            $is_zip = true;
        }

        if ( $is_zip ) {
            $zip_path = trailingslashit( $dir ) . $filename . '.zip';
            file_put_contents( $zip_path, $body );
            if ( class_exists( 'ZipArchive' ) ) {
                $zip = new ZipArchive();
                if ( true === $zip->open( $zip_path ) ) {
                    // Extract first CSV found
                    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                        $stat = $zip->statIndex( $i );
                        $name = $stat['name'];
                        if ( preg_match( '/\.csv$/i', $name ) ) {
                            $csv_data = $zip->getFromIndex( $i );
                            $csv_path = trailingslashit( $dir ) . $filename . '.csv';
                            file_put_contents( $csv_path, $csv_data );
                            $zip->close();
                            return array(
                                'path' => $csv_path,
                                'url'  => trailingslashit( $uploads['baseurl'] ) . 'woo-alipay-reconcile/' . basename( $csv_path ),
                            );
                        }
                    }
                    $zip->close();
                }
                // If we reach here, we couldn’t extract CSV.
                return new WP_Error( 'no_csv', __( '压缩包中未找到 CSV 文件。', 'woo-alipay-reconcile-pro' ) );
            }
            // ZipArchive not available; store the zip and ask user to process manually.
            return new WP_Error( 'zip_archive_missing', sprintf( __( '已下载 ZIP 文件：%s，请手动解压后上传解析。', 'woo-alipay-reconcile-pro' ), $zip_path ) );
        }

        // Assume CSV text
        $csv_path = trailingslashit( $dir ) . $filename . '.csv';
        file_put_contents( $csv_path, $body );
        return array(
            'path' => $csv_path,
            'url'  => trailingslashit( $uploads['baseurl'] ) . 'woo-alipay-reconcile/' . basename( $csv_path ),
        );
    }

    private static function parse_and_reconcile( $csv_path ) {
        if ( ! file_exists( $csv_path ) ) {
            return new WP_Error( 'file_missing', __( 'CSV 文件不存在。', 'woo-alipay-reconcile-pro' ) );
        }

        $content = file_get_contents( $csv_path );
        if ( ! $content ) {
            return new WP_Error( 'empty_file', __( 'CSV 文件为空。', 'woo-alipay-reconcile-pro' ) );
        }

        // Convert to UTF-8 if needed (many Alipay bills are GBK encoded)
        if ( function_exists( 'mb_detect_encoding' ) ) {
            $enc = mb_detect_encoding( $content, array( 'UTF-8', 'GBK', 'GB2312', 'CP936', 'BIG5' ), true );
            if ( $enc && 'UTF-8' !== $enc ) {
                $content = mb_convert_encoding( $content, 'UTF-8', $enc );
                file_put_contents( $csv_path, $content );
            }
        }

        $lines = preg_split( "/\r\n|\n|\r/", $content );
        $header_index = -1;
        $headers = array();
        for ( $i = 0; $i < min( 30, count( $lines ) ); $i++ ) {
            $row = str_getcsv( $lines[ $i ] );
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
            return new WP_Error( 'csv_header', __( '未识别到对账单表头。', 'woo-alipay-reconcile-pro' ) );
        }

        $map = self::map_header( $headers );
        $rows = array();
        for ( $i = $header_index + 1; $i < count( $lines ); $i++ ) {
            $row = str_getcsv( $lines[ $i ] );
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
            $amount = floatval( str_replace( array( ',', '￥' ), '', $amount_str ) );

            if ( '' === $out_trade_no && '' === $trade_no ) {
                continue;
            }
            $rows[] = compact( 'out_trade_no', 'trade_no', 'amount' );
        }

        $matched = 0; $mismatched = 0; $unmatched = 0; $notes = array();
        foreach ( $rows as $tx ) {
            $order = self::locate_order_by_out_trade_no( $tx['out_trade_no'] );
            if ( ! $order ) {
                $unmatched++;
                continue;
            }
            $order_amount = floatval( $order->get_total() );
            if ( abs( $order_amount - $tx['amount'] ) <= 0.01 ) {
                $matched++;
            } else {
                $mismatched++;
                $notes[] = sprintf( '订单 #%d 金额不一致：对账 %.2f / 订单 %.2f', $order->get_id(), $tx['amount'], $order_amount );
            }
        }

        return array(
            'total_rows' => count( $rows ),
            'matched'    => $matched,
            'mismatched' => $mismatched,
            'unmatched'  => $unmatched,
            'notes'      => $notes,
        );
    }

    private static function map_header( $headers ) {
        $map = array(
            'out_trade_no'      => -1,
            'trade_no'          => -1,
            'amount_candidates' => array( -1, -1, -1 ),
        );
        foreach ( $headers as $idx => $name ) {
            if ( false !== mb_strpos( $name, '商户订单号' ) ) {
                $map['out_trade_no'] = $idx;
            } elseif ( false !== mb_strpos( $name, '业务流水号' ) ) {
                $map['trade_no'] = $idx;
            } elseif ( false !== mb_strpos( $name, '订单金额' ) || false !== mb_strpos( $name, '金额' ) ) {
                $map['amount_candidates'][0] = $idx;
            } elseif ( false !== mb_strpos( $name, '收入金额' ) ) {
                $map['amount_candidates'][1] = $idx;
            } elseif ( false !== mb_strpos( $name, '支出金额' ) ) {
                $map['amount_candidates'][2] = $idx;
            }
        }
        return $map;
    }

    private static function locate_order_by_out_trade_no( $out_trade_no ) {
        if ( empty( $out_trade_no ) ) {
            return null;
        }
        // Try meta match first.
        $orders = wc_get_orders( array(
            'limit'     => 1,
            'meta_key'  => '_alipay_out_trade_no',
            'meta_value'=> $out_trade_no,
            'return'    => 'objects',
        ) );
        if ( ! empty( $orders ) && $orders[0] instanceof WC_Order ) {
            return $orders[0];
        }
        // Try to parse WooA/WooB prefixes to get order ID.
        if ( preg_match( '/(Woo[A-Z]?)(\d+)/', $out_trade_no, $m ) ) {
            $order_id = absint( $m[2] );
            $order    = wc_get_order( $order_id );
            if ( $order ) { return $order; }
        }
        // As a last resort, extract leading digits.
        if ( preg_match( '/(\d{1,10})/', $out_trade_no, $m ) ) {
            $order_id = absint( $m[1] );
            $order    = wc_get_order( $order_id );
            if ( $order ) { return $order; }
        }
        return null;
    }
}
