<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'EDD_Hamrahpay_Gateway' ) ) :

/**
 * Hamrahpay Gateway for Easy Digital Downloads
 *
 * @author 				Hamrahpay
 * @package 			Hamrahpay
 * @subpackage 			Gateways
 */
class EDD_Hamrahpay_Gateway 
{
	
	private $api_version    = "v1";
    private $api_url        = 'https://api.hamrahpay.com/api';
    private $second_api_url = 'https://api.hamrahpay.ir/api';
	
	/**
	 * Gateway keyname
	 *
	 * @var 				string
	 */
	public $keyname;

	/**
	 * Initialize gateway and hook
	 *
	 * @return 				void
	 */
	public function __construct() {
		$this->keyname = 'hamrahpay';
		$this->api_url          .= '/'.$this->api_version;
        $this->second_api_url   .= '/'.$this->api_version;
		add_filter( 'edd_payment_gateways', array( $this, 'add' ) );
		add_action( $this->format( 'edd_{key}_cc_form' ), array( $this, 'cc_form' ) );
		add_action( $this->format( 'edd_gateway_{key}' ), array( $this, 'process' ) );
		add_action( $this->format( 'edd_verify_{key}' ), array( $this, 'verify' ) );
		add_filter( 'edd_settings_gateways', array( $this, 'settings' ) );

		add_action( 'edd_payment_receipt_after', array( $this, 'receipt' ) );

		add_action( 'init', array( $this, 'listen' ) );
	}
	
	// This method sends the data to api
    private function post_data($url,$params)
    {
        try
		{
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				'Content-Type: application/json',
			]);
			$result = curl_exec($ch);
			//echo curl_error($ch);
			curl_close($ch);

			return $result;
		}
		catch(\Exception $e)
		{
			return false;
		}
    }

    // This method returns the api url
    private function getApiUrl($end_point,$use_emergency_url=false)
    {
        if (!$use_emergency_url)
            return $this->api_url.$end_point;
        else
        {
            return $this->second_api_url.$end_point;
        }
    }
	

	/**
	 * Add gateway to list
	 *
	 * @param 				array $gateways Gateways array
	 * @return 				array
	 */
	public function add( $gateways ) {
		global $edd_options;

		$gateways[ $this->keyname ] = array(
			'checkout_label' 		=>	isset( $edd_options['hamrahpay_label'] ) ? $edd_options['hamrahpay_label'] : 'پرداخت آنلاین همراه پی',
			'admin_label' 			=>	'همراه پی'
		);

		return $gateways;
	}

	/**
	 * CC Form
	 * We don't need it anyway.
	 *
	 * @return 				bool
	 */
	public function cc_form() {
		return;
	}

	/**
	 * Process the payment
	 *
	 * @param 				array $purchase_data
	 * @return 				void
	 */
	public function process( $purchase_data ) {
		global $edd_options;
		@ session_start();
		$payment = $this->insert_payment( $purchase_data );

		if ( $payment ) {
			$api_key = ( isset( $edd_options[ $this->keyname . '_api_key' ] ) ? $edd_options[ $this->keyname . '_api_key' ] : '' );
			$desc = 'پرداخت شماره :' . $payment.' | '.$purchase_data['user_info']['first_name'].' '.$purchase_data['user_info']['last_name'];
			$callback = add_query_arg( 'verify_' . $this->keyname, '1', get_permalink( $edd_options['success_page'] ) );

			$amount = intval( $purchase_data['price'] );
			if ( edd_get_currency() == 'IRT' )
				$amount = $amount * 10; // Return back to original one.

			$data =  array(
				'api_key' 			=>	$api_key,
				'amount' 				=>	$amount,
				'description' 			=>	$desc,
				'email' 				=>	$purchase_data['user_info']['email'],
				'callback_url' 			=>	$callback
			);
	
			$result = $this->post_data($this->getApiUrl('/rest/pg/pay-request'),$data);
			if ( $result===false ) {
				edd_insert_payment_note( $payment, 'خطا در CURL' );
				edd_update_payment_status( $payment, 'failed' );
				edd_set_error( 'hamrahpay_connect_error', 'در اتصال به درگاه مشکلی پیش آمد.' );
				edd_send_back_to_checkout();
				return false;
			}
			$result = json_decode( $result, true );

			if (!empty($result['status']) && $result['status']==1) {
				edd_insert_payment_note( $payment, 'توکن پرداخت همراه پی: ' . $result['payment_token'] );
				edd_update_payment_meta( $payment, 'hamrapya_payment_token', $result['payment_token'] );
				$_SESSION['hamrahpay_payment'] = $payment;

				wp_redirect( $result['pay_url']);
			} else {
				edd_insert_payment_note( $payment, 'کد خطا: ' . $result['error_code'] );
				edd_insert_payment_note( $payment, 'علت خطا: ' . $this->error_reason( $result['error_code'] ) );
				edd_update_payment_status( $payment, 'failed' );

				edd_set_error( 'hamrahpay_connect_error', 'در اتصال به درگاه مشکلی پیش آمد. علت: ' . $this->error_reason( $result['error_code'] ) );
				edd_send_back_to_checkout();
			}
		} else {
			edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
		}
	}

	/**
	 * Verify the payment
	 *
	 * @return 				void
	 */
	public function verify() {
		global $edd_options;

		if ( isset( $_GET['payment_token'] ) ) {
			$payment_token = sanitize_text_field( $_GET['payment_token'] );

			@ session_start();
			$payment = edd_get_payment( $_SESSION['hamrahpay_payment'] );
			unset( $_SESSION['hamrapay_payment'] );

			if ( ! $payment ) {
				wp_die( 'رکورد پرداخت موردنظر وجود ندارد!' );
			}

			if ( $payment->status == 'complete' ) {
				return false;
			}

			$amount = intval( edd_get_payment_amount( $payment->ID ) );

			if ( 'IRT' === edd_get_currency() ) {
				$amount = $amount * 10;
			}

			$api_key = ( isset( $edd_options[ $this->keyname . '_api_key' ] ) ? $edd_options[ $this->keyname . '_api_key' ] : '' );
			if (isset( $_GET['status'] ) && $_GET['status']=='OK')
			{
				$data =  array(
				'api_key' 			=>	$api_key,
				'payment_token'				=>	$payment_token
				);

				$result = $this->post_data($this->getApiUrl('/rest/pg/verify'),$data);
				if ($result==false)
				{
					$result = $this->post_data($this->getApiUrl('/rest/pg/verify'),$data);
				}
				
				$result = json_decode($result , true );

				edd_empty_cart();

				if ( version_compare( EDD_VERSION, '2.1', '>=' ) ) {
					edd_set_payment_transaction_id( $payment->ID, $payment_token );
				}

				if ($result['status']==100) 
				{
					edd_insert_payment_note( $payment->ID, 'شماره تراکنش بانکی: ' . $result['reference_number'] );
					edd_insert_payment_note( $payment->ID, 'شماره پیگیری تراکنش: ' . $result['reserve_number'] );
					edd_update_payment_meta( $payment->ID, 'hamrahpay_refid', $result['reference_number'] );
					edd_update_payment_status( $payment->ID, 'publish' );
					edd_send_to_success_page();
				}
				elseif ($result['status']==101)
				{
					edd_update_payment_status( $payment->ID, 'publish' );
					edd_send_to_success_page();
				}
				else {
					edd_update_payment_status( $payment->ID, 'failed' );
					wp_redirect( get_permalink( $edd_options['failure_page'] ) );
					//echo json_encode($result);
					exit;
				}

			} 
			
		}
	}

	/**
	 * Receipt field for payment
	 *
	 * @param 				object $payment
	 * @return 				void
	 */
	public function receipt( $payment ) {
		$refid = edd_get_payment_meta( $payment->ID, 'hamrahpay_refid' );
		if ( $refid ) {
			echo '<tr class="hamrahpay-ref-id-row hamrahpay-field hamrahpay-dev"><td><strong>شماره تراکنش بانکی:</strong></td><td>' . $refid . '</td></tr>';
		}
	}

	/**
	 * Gateway settings
	 *
	 * @param 				array $settings
	 * @return 				array
	 */
	public function settings( $settings ) {
		return array_merge( $settings, array(
			$this->keyname . '_header' 		=>	array(
				'id' 			=>	$this->keyname . '_header',
				'type' 			=>	'header',
				'name' 			=>	'درگاه پرداخت <strong style="color:#2d2b7e">همراه پی</strong>'
			),
			$this->keyname . '_api_key' 		=>	array(
				'id' 			=>	$this->keyname . '_api_key',
				'name' 			=>	'API Key',
				'type' 			=>	'text',
				'size' 			=>	'regular'
			),
			
			$this->keyname . '_label' 	=>	array(
				'id' 			=>	$this->keyname . '_label',
				'name' 			=>	'نام درگاه در صفحه پرداخت',
				'type' 			=>	'text',
				'size' 			=>	'regular',
				'std' 			=>	'پرداخت آنلاین همراه پی'
			)
		) );
	}

	/**
	 * Format a string, replaces {key} with $keyname
	 *
	 * @param 			string $string To format
	 * @return 			string Formatted
	 */
	private function format( $string ) {
		return str_replace( '{key}', $this->keyname, $string );
	}

	/**
	 * Inserts a payment into database
	 *
	 * @param 			array $purchase_data
	 * @return 			int $payment_id
	 */
	private function insert_payment( $purchase_data ) {
		global $edd_options;

		$payment_data = array(
			'price' => $purchase_data['price'],
			'date' => $purchase_data['date'],
			'user_email' => $purchase_data['user_email'],
			'purchase_key' => $purchase_data['purchase_key'],
			'currency' => $edd_options['currency'],
			'downloads' => $purchase_data['downloads'],
			'user_info' => $purchase_data['user_info'],
			'cart_details' => $purchase_data['cart_details'],
			'status' => 'pending'
		);

		// record the pending payment
		$payment = edd_insert_payment( $payment_data );

		return $payment;
	}

	/**
	 * Listen to incoming queries
	 *
	 * @return 			void
	 */
	public function listen() {
		if ( isset( $_GET[ 'verify_' . $this->keyname ] ) && $_GET[ 'verify_' . $this->keyname ] ) {
			do_action( 'edd_verify_' . $this->keyname );
		}
	}

	/**
	 * Error reason for Hamrahpay
	 *
	 * @param 			int $error_id
	 * @return 			string
	 */

	public function error_reason( $error_id ) {
		$message = 'خطای ناشناخته';

		switch ( $error_id ) {
			case '-100':
			$message = 'خطای ناشناخته ای رخ داده است.';
				break;
			case '-1':
				$message = 'اطلاعات ارسال شده ناقص است';
				break;
			case '-2':
				$message = 'IP و يا API Key کسب و کار صحيح نيست.';
				break;
			case '-3':
				$message = 'با توجه به محدوديت هاي شاپرك امكان پرداخت با رقم درخواست شده ميسر نمي باشد';
				break;
			case '-4':
				$message = 'کسب و کار پذیرنده فعال نیست.';
				break;
			case '-5':
				$message = 'درخواست مورد نظر يافت نشد.';
				break;
			case '-6':
				$message = 'پرداخت موفقیت آمیز نبوده است.';
				break;
			case '-7':
				$message = 'فرمت خروجی لینک پرداخت صحیح نیست.';
				break;
			case '-14':
				$message = 'هیچ ترمینالی تعریف نشده است.';
				break;
			case '-15':
				$message = 'توکن پرداخت صحیح نیست.';
				break;
		}

		return $message;

	}
}

endif;

new EDD_Hamrahpay_Gateway;

