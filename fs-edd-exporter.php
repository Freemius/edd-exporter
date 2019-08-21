<?php
    /**
     * Plugin Name: EDD Export to CSV by Freemius
     * Plugin URI:  http://freemius.com/
     * Description: Export EDD data to a CSV for a migration to Freemius.
     * Version:     1.0.0
     * Author:      Freemius
     * Author URI:  http://freemius.com
     * License: GPL2
     *
     * @requires
     *  1. EDD 2.5 or higher, assuming payments currency is USD and have no fees.
     *  2. PHP 5.3 or higher [using spl_autoload_register()]
     */

    if ( ! defined( 'ABSPATH' ) ) {
        exit;
    }

    class FS_EDD_Export {
        /**
         * @var EDD_Software_Licensing
         */
        private $_edd_sl;

        const GUID_TRANSIENT_NAME = 'fs_csv_export_guid';
        const LICENSES_PER_EXECUTION = 500;
        const MAX_LICENSES_PER_EXECUTION = 500;

        const DEBUG = false;

        #region Singleton

        private static $INSTANCE;

        public static function instance() {
            if ( ! isset( self::$INSTANCE ) ) {
                self::$INSTANCE = new self();
            }

            return self::$INSTANCE;
        }

        #endregion

        private function __construct() {
            $this->_edd_sl = edd_software_licensing();
        }

        /**
         * Initiate a non-blocking licenses export.
         *
         * @author Vova Feldman
         * @since  1.0.0
         */
        public function init() {
            $upload = wp_upload_dir();

            $csv_export_path = $upload['basedir'] . '/edd-export.csv';

            require_once( ABSPATH . 'wp-admin/includes/file.php' );

            if ( 'direct' !== get_filesystem_method( array(), $upload['basedir'] ) ) {

                add_action( 'admin_notices', 'insufficient_file_permissions_notice' );

                return;
            }

            if ( self::DEBUG ) {
                $this->do_export( $csv_export_path, 0, 20 );

                exit;
            }

            if ( empty( $_GET[ self::GUID_TRANSIENT_NAME ] ) ) {
                if ( file_exists( $csv_export_path ) ) {
                    // Clean up transient.
                    delete_transient( self::GUID_TRANSIENT_NAME );
                } else {
                    // Generate unique ID.
                    $guid = md5( rand() . microtime() );

                    set_transient( self::GUID_TRANSIENT_NAME, $guid );

                    // Start non-blocking data export.
                    $this->spawn_export( $guid, 0, self::LICENSES_PER_EXECUTION );
                }
            } else {
                $guid = get_transient( self::GUID_TRANSIENT_NAME );

                if ( empty( $guid ) || $guid !== $_GET[ self::GUID_TRANSIENT_NAME ] ) {
                    // Guide does not match.
                    return;
                }

                $offset = ( ! empty( $_GET['offset'] ) && is_numeric( $_GET['offset'] ) ) ?
                    max( $_GET['offset'], 0 ) :
                    0;

                $limit = ( ! empty( $_GET['limit'] ) && is_numeric( $_GET['limit'] ) ) ?
                    min( $_GET['limit'], self::MAX_LICENSES_PER_EXECUTION ) :
                    self::LICENSES_PER_EXECUTION;

                $exported_count = $this->do_export( $csv_export_path, $offset, $limit );

                if ( $exported_count == $limit ) {
                    // Continue with non-blocking data export.
                    $this->spawn_export( $guid, $offset + $limit, $limit );
                }
            }
        }

        /**
         * @param string $guid
         * @param int    $offset
         * @param int    $limit
         */
        private function spawn_export( $guid, $offset = 0, $limit = self::LICENSES_PER_EXECUTION ) {
            $export_url = add_query_arg( array(
                self::GUID_TRANSIENT_NAME => $guid,
                'offset'                  => $offset,
                'limit'                   => $limit,
            ), $this->get_current_url() );

            // Add cookies to trigger request with same user access permissions.
            $cookies = array();
            foreach ( $_COOKIE as $name => $value ) {
                if (0 === strpos( $name, 'tk_') ||
                    0 === strpos( $name, 'mp_')
                ) {
                    continue;
                }

                $cookies[] = new WP_Http_Cookie( array(
                    'name'  => $name,
                    'value' => $value
                ) );
            }

            wp_remote_get(
                $export_url,
                array(
                    'timeout'   => 0.01,
                    'blocking'  => false,
                    'sslverify' => false,
                    'cookies'   => $cookies,
                )
            );
        }

        /**
         * Execute the export.
         *
         * @param string $csv_export_path
         * @param int    $offset
         * @param int    $limit
         *
         * @return int Number of exported licenses.
         */
        private function do_export( $csv_export_path, $offset = 0, $limit = self::LICENSES_PER_EXECUTION ) {
            global $wpdb;

            // Remove execution time limit.
            ini_set( 'max_execution_time', 0 );

            $fp = fopen( $csv_export_path, 'a' );

            if ( 0 == $offset ) {
                fputcsv( $fp, array(
                    'index',
                    // User.
                    'user_email',
                    'user_name',
                    'is_email_verified',

                    // License.
                    'license_created',
                    'license_key',
                    'license_quantity',
                    'license_expires_at',

                    // Billing.
                    'business_name',
                    'website_url',
                    'tax_id',
                    'address_street_1',
                    'address_street_2',
                    'address_city',
                    'address_country_code',
                    'address_state',
                    'address_zip',

                    // Product.
                    'download_id',
                ) );
            }

            $is_new_sl_version = isset( $this->_edd_sl->licenses_db );

            if ( $is_new_sl_version ) {
                $licenses_or_ids = $this->_edd_sl->licenses_db->get_licenses( array(
                    'number' => $limit,
                    'offset' => $offset,
                ) );
            } else {
                $licenses_or_ids = $wpdb->get_col( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_edd_sl_key' LIMIT {$offset},{$limit}" );
            }

            /*
				$license_data = array(
					'id'           => null,
					'parent'       => $license_post->post_parent,
					'user_id'      => $user_id,
				);
             */

            try {
                $i = 0;
                foreach ( $licenses_or_ids as $license_or_id ) {
                    if ( $is_new_sl_version ) {
                        $license     = $license_or_id;
                        $license_id  = $license->id;
                        $download_id = $license->download_id;
                    } else {
                        $license_id = $license_or_id;

                        $license = new EDD_SL_License( $license_id );

                        if ( ! is_object( $license ) ) {
                            continue;
                        }

                        $download_id = get_post_meta( $license_id, '_edd_sl_download_id', true );

                        if ( empty( $download_id ) ) {
                            continue;
                        }
                    }

                    $download = new EDD_Download( $download_id );

                    if ( ! is_object( $download ) ) {
                        continue;
                    }

                    if ( $is_new_sl_version ) {
                        $customer_id        = $license->customer_id;
                        $initial_payment_id = $license->payment_id;
                    } else {
                        $last_license_payments = edd_get_payments( array(
                            'post__in' => $license->payment_ids,
                            'number'   => 1,
                            'status'   => array( 'publish' ),
                            'order'    => 'DESC',
                            'orderby'  => 'ID',
                        ) );

                        if ( is_array( $last_license_payments ) && 0 < count( $last_license_payments ) ) {
                            $payment = new EDD_Payment( $last_license_payments[0]->ID );

                            if ( 0 == $payment->parent_payment ) {
                                $initial_payment_id = $payment->ID;
                            } else {
                                $initial_payment_id = $payment->parent_payment;
                            }
                        } else {
                            $initial_payment_id = get_post_meta( $license_id, '_edd_sl_payment_id', true );
                        }

                        $customer_id = edd_get_payment_customer_id( $initial_payment_id );
                    }


                    $customer = new EDD_Customer( $customer_id );

                    if ( ! is_object( $customer ) ) {
                        continue;
                    }

                    $payment = new EDD_Payment( $initial_payment_id );

                    if ( ! is_object( $payment ) ) {
                        continue;
                    }

//                $subscription = $this->get_edd_subscription(
//                    $download_id,
//                    $initial_payment_id
//                );

                    // User fields.
                    $user_email        = $customer->email;
                    $user_name         = $customer->name;
                    $is_email_verified = false;

                    // License fields.
                    if ( $is_new_sl_version ) {
                        $license_created    = $license->date_created;
                        $license_key        = $license->license_key;
                        $license_quantity   = ( $license->activation_limit > 0 ) ? $license->activation_limit : 0;
                        $license_expires_at = $license->is_lifetime ? '' : $this->get_license_expiration( $license->expiration );
                    } else {
                        $license_created    = $this->get_payment_process_date( $payment );
                        $license_key        = $this->_edd_sl->get_license_key( $license_id );
                        $license_quantity   = $this->get_license_quota( $download_id, $license_id );
                        $license_expires_at = $this->get_license_expiration( $license_expiration = $this->_edd_sl->get_license_expiration( $license_id ) );
                    }

                    // Billing fields.
                    $tax_id  = $this->get_tax_id( $payment, $customer );
                    $address = $this->get_customer_address( $payment, $customer );

                    fputcsv( $fp, array(
                        $offset + $i,

                        // User.
                        $user_email,
                        $user_name,
                        $is_email_verified ? 'true' : 'false',

                        // License.
                        $license_created,
                        $license_key,
                        $license_quantity,
                        $license_expires_at,

                        // Billing.
                        '', // Business name
                        '', // Site URL
                        $tax_id,
                        $address['address_street_1'],
                        $address['address_street_2'],
                        $address['address_city'],
                        $address['address_country_code'],
                        $address['address_state'],
                        $address['address_zip'],

                        // Product.
                        $download_id,
                    ) );

                    if ( self::DEBUG ) {
                        // Debugging.
                        echo json_encode( array(
                            'count'                => $offset + $i,

                            // User.
                            'user_email'           => $user_email,
                            'user_name'            => $user_name,
                            'is_email_verified'    => $is_email_verified ? 'true' : 'false',

                            // License.
                            'license_created'      => $license_created,
                            'license_key'          => $license_key,
                            'license_quantity'     => $license_quantity,
                            'license_expires_at'   => $license_expires_at,

                            // Billing.
                            'business'             => '', // Business name
                            'site_url'             => '', // Site URL
                            'tax_id'               => $tax_id,
                            'address_street_1'     => $address['address_street_1'],
                            'address_street_2'     => $address['address_street_2'],
                            'address_city'         => $address['address_city'],
                            'address_country_code' => $address['address_country_code'],
                            'address_state'        => $address['address_state'],
                            'address_zip'          => $address['address_zip'],

                            // Product.
                            'download_id'          => $download_id,
                        ), JSON_PRETTY_PRINT);

                        echo "<br><br>";
                    }

                    $i ++;
                }
            } catch ( Exception $e ) {
                fputcsv( $fp, var_export( $e, true ) );
            }

            fclose( $fp );

            return count( $licenses_or_ids );
        }

        function insufficient_file_permissions_notice() {
            ?>
            <div class="notice">
                <p><?php _e( 'The EDD data export plugin do not have sufficient permissions to write to the uploads folder.', 'freemius' ); ?></p>
            </div>
            <?php
        }

        #region Helper Methods

        /**
         * Get license quota. If unlimited license, return NULL.
         *
         * @author Vova Feldman
         * @since  1.0.0
         *
         * @param int $edd_download_id
         * @param int $edd_license_id
         *
         * @return int|null
         */
        private function get_license_quota( $edd_download_id, $edd_license_id ) {
            $quota = (int) $this->_edd_sl->get_license_limit(
                $edd_download_id,
                $edd_license_id
            );

            return ( $quota > 0 ) ? $quota : null;
        }

        /**
         * Get EDD subscription entity subscription.
         *
         * @author Vova Feldman
         * @since  1.0.0
         *
         * @param int $edd_download_id
         * @param int $edd_parent_payment_id
         *
         * @return EDD_Subscription
         */
        private function get_edd_subscription( $edd_download_id, $edd_parent_payment_id ) {
            if ( ! class_exists( 'EDD_Recurring' ) ) {
                // EDD recurring payments add-on isn't installed.
                return null;
            }

            /**
             * We need to make sure the singleton is initiated, otherwise,
             * EDD_Subscriptions_DB will not be found because the inclusion
             * of the relevant file is executed in the instance init.
             */
            EDD_Recurring::instance();

            $subscriptions_db = new EDD_Subscriptions_DB();

            $edd_subscriptions = $subscriptions_db->get_subscriptions( array(
                'product_id'        => $edd_download_id,
                'parent_payment_id' => $edd_parent_payment_id
            ) );

            return ( is_array( $edd_subscriptions ) && 0 < count( $edd_subscriptions ) ) ?
                $edd_subscriptions[0] :
                null;
        }

        /**
         * Generate customer address for API.
         *
         * 1. First try to load address from payment details.
         * 2. If empty, try to load address from customer details.
         *
         * @author Vova Feldman
         * @since  1.0.0
         *
         * @param \EDD_Payment  $edd_payment
         * @param \EDD_Customer $edd_customer
         *
         * @return array
         */
        private function get_customer_address( EDD_Payment $edd_payment, EDD_Customer $edd_customer ) {
            $user_info = $edd_payment->user_info;

            $address = array(
                'line1'   => '',
                'line2'   => '',
                'city'    => '',
                'state'   => '',
                'country' => '',
                'zip'     => ''
            );

            if ( ! empty( $user_info['address'] ) ) {
                $address = wp_parse_args( $user_info['address'], $address );
            } else if ( ! empty( $edd_customer->user_id ) ) {
                // Enrich data with customer's address.
                $customer_address = get_user_meta( $edd_customer->user_id, '_edd_user_address', true );

                $address = wp_parse_args( $customer_address, $address );
            }

            $api_address = array();
            if ( ! empty( $address['line1'] ) ) {
                $api_address['address_street_1'] = $address['line1'];
            }
            if ( ! empty( $address['line2'] ) ) {
                $api_address['address_street_2'] = $address['line2'];
            }
            if ( ! empty( $address['city'] ) ) {
                $api_address['address_city'] = $address['city'];
            }
            if ( ! empty( $address['state'] ) ) {
                $api_address['address_state'] = $address['state'];
            }
            if ( ! empty( $address['country'] ) ) {
                $api_address['address_country_code'] = strtolower( $address['country'] );
            }
            if ( ! empty( $address['zip'] ) ) {
                $api_address['address_zip'] = $address['zip'];
            }

            return $api_address;
        }

        /**
         * Get EDD payment's processing date.
         *
         * If payment was never completed, return the payment entity creation datetime.
         *
         * @author Vova Feldman
         * @since  1.0.0
         *
         * @param EDD_Payment $edd_payment
         *
         * @return string
         */
        private function get_payment_process_date( EDD_Payment $edd_payment ) {
            return ! empty( $edd_payment->completed_date ) ?
                $edd_payment->completed_date :
                $edd_payment->date;
        }

        /**
         * Get license expiration in UTC datetime.
         * If it's a lifetime license, return null.
         *
         * @author Vova Feldman
         * @since  1.0.0
         *
         * @param int|string $license_expiration
         *
         * @return null|string
         */
        private function get_license_expiration( $license_expiration ) {
            if ( 'lifetime' === $license_expiration ) {
                return null;
            }

            $timezone = date_default_timezone_get();

            if ( 'UTC' !== $timezone ) {
                // Temporary change time zone.
                date_default_timezone_set( 'UTC' );
            }

            $formatted_license_expiration = date( 'Y-m-d H:i:s', $license_expiration );

            if ( 'UTC' !== $timezone ) {
                // Revert timezone.
                date_default_timezone_set( $timezone );
            }

            return $formatted_license_expiration;
        }

        /**
         * Generate payment gross and tax for API based on given EDD payment.
         *
         * When initial payment associated with a cart that have multiple products,
         * find the gross and tax for the product that is associated with the context
         * license.
         *
         * @author Vova Feldman
         * @since  1.0.0
         *
         * @param EDD_Payment  $edd_payment
         *
         * @return array
         */
        private function get_tax_id( EDD_Payment $edd_payment, EDD_Customer $edd_customer ) {
            if ( is_object( $edd_payment ) ) {
                $user_info = edd_get_payment_meta_user_info( $edd_payment->ID );

                if ( is_array( $user_info ) && ! empty( $user_info['vat_number'] ) ) {
                    // Check if the payment's meta has the VAT ID.
                    return $user_info['vat_number'];
                }
            }

            if ( class_exists( '\lyquidity\edd_vat\Actions' ) ) {
                // Otherwise, try to pull the VAT ID from the user info.
                if ( ! empty( $edd_customer->user_id ) ) {
                    $vat_id = \lyquidity\edd_vat\Actions::instance()->get_vat_number(
                        '',
                        $edd_customer->user_id
                    );

                    if ( ! empty( $vat_id ) ) {
                        return $vat_id;
                    }
                }
            }

            return null;
        }

        /**
         * Get current request full URL.
         *
         * @author   Vova Feldman (@svovaf)
         * @since    1.0.3
         *
         * @return string
         */
        private function get_current_url() {
            $host = $_SERVER['HTTP_HOST'];
            $uri  = $_SERVER['REQUEST_URI'];
            $port = $_SERVER['SERVER_PORT'];

            $is_https = ( 443 == $port || ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) );

            return ( $is_https ? 'https' : 'http' ) . "://{$host}{$uri}";
        }

        #endregion
    }

    function fs_edd_export_init() {
        if ( ! is_admin() ) {
            // Ignore non WP Admin requests.
            return;
        }

        if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ||
             ( defined( 'DOING_CRON' ) && DOING_CRON )
        ) {
            // Ignore AJAX & WP-Cron requests.
            return;
        }

        $csv_exporter = FS_EDD_Export::instance();
        $csv_exporter->init();
    }

    // Get Freemius EDD Migration running.
    add_action( 'plugins_loaded', 'fs_edd_export_init' );