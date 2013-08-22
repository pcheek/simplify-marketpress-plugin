<?php
/*
MarketPress Simplify Gateway Plugin
Author: MasterCard International Incorporated
*/

/*
 * Copyright (c) 2013, MasterCard International Incorporated
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without modification, are 
 * permitted provided that the following conditions are met:
 * 
 * Redistributions of source code must retain the above copyright notice, this list of 
 * conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of 
 * conditions and the following disclaimer in the documentation and/or other materials 
 * provided with the distribution.
 * Neither the name of the MasterCard International Incorporated nor the names of its 
 * contributors may be used to endorse or promote products derived from this software 
 * without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY 
 * EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES 
 * OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT 
 * SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, 
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED
 * TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; 
 * OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER 
 * IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING 
 * IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF 
 * SUCH DAMAGE.
 */

class MP_Gateway_Simplify extends MP_Gateway_API {

	var $plugin_name = 'simplify';
	var $admin_name = '';
	var $public_name = '';
	var $method_img_url = '';
	var $method_button_img_url = '';
	var $force_ssl;
	var $ipn_url;
	var $skip_form = false;
	var $publishable_key,
		$private_key,
		$currency;

	function on_creation() {
		global $mp;
		$settings = get_option('mp_settings');
		$this->admin_name = __('Simplify', 'mp');
		$this->public_name = __('Credit Card', 'mp');
		$this->method_img_url = $mp->plugin_url . 'images/credit_card.png';
		$this->method_button_img_url = $mp->plugin_url . 'images/cc-button.png';
		if(isset($settings['gateways']['simplify']['publishable_key'])) {
			$this->publishable_key = $settings['gateways']['simplify']['publishable_key'];
			$this->private_key = $settings['gateways']['simplify']['private_key'];
		}
		$this->force_ssl = (bool)( isset($settings['gateways']['simplify']['is_ssl']) && $settings['gateways']['simplify']['is_ssl']);
		$this->currency = isset($settings['gateways']['simplify']['currency']) ? $settings['gateways']['simplify']['currency'] : 'USD';
		add_action( 'wp_enqueue_scripts', array(&$this, 'enqueue_scripts') );
	}

	function enqueue_scripts() {
		global $mp;
		if(!is_admin() && get_query_var('pagename') == 'cart' && get_query_var('checkoutstep') == 'checkout') {
			wp_enqueue_script('js-simplify', 'https://www.simplify.com/commerce/v1/simplify.js', array('jquery'));
			wp_enqueue_script('simplify-token', $mp->plugin_url . 'plugins-gateway/simplify-files/simplify_token.js', array('js-simplify', 'jquery'));
			wp_localize_script('simplify-token', 'simplify', array('publicKey' => $this->publishable_key));
		}
	}

	/**
	* Return fields you need to add to the top of the payment screen, like your credit card info fields
	*
	* @param array $cart. Contains the cart contents for the current blog, global cart if $mp->global_cart is true
	* @param array $shipping_info. Contains shipping info and email in case you need it
	*/
	function payment_form($cart, $shipping_info) {
		global $mp;
		$settings = get_option('mp_settings');
		$name = isset($_SESSION['mp_shipping_info']['name']) ? $_SESSION['mp_shipping_info']['name'] : '';
		$content .= '<div class="row-fluid">';
			$content .= '<div class="span6 offset3">';
				$content .= '<input class="input-block-level" id="cc-number" type="text" maxlength="20" autocomplete="off" value="" placeholder="Card Number" autofocus />';
				$content .= '<div class="row-fluid">';
					$content .= '<div class="span4"><input class="input-block-level" id="cc-cvc" type="text" maxlength="3" autocomplete="off" value="" placeholder="CVC" /></div>';
					$content .= '<div class="span4"><select class="input-block-level" id="cc-exp-month">' . $this->_print_month_dropdown() . '</select></div>';
					$content .= '<div class="span4"><select class="input-block-level" id="cc-exp-year">' . $this->_print_year_dropdown() . '</select></div>';
				$content .= '</div>';
			$content .= '</div>';
		$content .= '</div>';
		return $content;
	}

	/**
	* Return the chosen payment details here for final confirmation. You probably don't need
	* to post anything in the form as it should be in your $_SESSION var already.
	*
	* @param array $cart. Contains the cart contents for the current blog, global cart if $mp->global_cart is true
	* @param array $shipping_info. Contains shipping info and email in case you need it
	*/
	function confirm_payment_form($cart, $shipping_info) {
		global $mp;
		$settings = get_option('mp_settings');

		// Token MUST be set at this point
		if(!isset($_SESSION['simplifyToken'])) {
			$mp->cart_checkout_error(__('The Simplify Token was not generated correctly. Please go back and try again.', 'mp'));
			return false;
		}

		// Setup the Simplify API
		if(!class_exists('Simplify')) {
			require_once($mp->plugin_dir . "plugins-gateway/simplify-files/lib/Simplify.php");
		}
		Simplify::$publicKey = $this->publishable_key;
		Simplify::$privateKey = $this->private_key;

		try {
			$token  = Simplify_CardToken::findCardToken($_SESSION['simplifyToken']);
		} catch (Exception $e) {
			$mp->cart_checkout_error(sprintf(__('%s. Please go back and try again.', 'mp'), $e->getMessage()));
			return false;
		}

		$content = '<table class="mp_cart_billing table table-striped table-bordered table-hover">';
			$content .= '<thead>';
				$content .= '<tr>';
					$content .= '<th>' . __('Billing Information:', 'mp') . '</th>';
					$content .= '<th align="right" class="align-right"><a href="' . mp_checkout_step_url('checkout') . '"> ' . __('Edit', 'mp') . '</a></th>';
				$content .= '</tr>';
			$content .= '</thead>';
			$content .= '<tbody>';
				$content .= '<tr>';
					$content .= '<td align="right" class="span4 align-right">' . __('Card Type:', 'mp') . '</td>';
					$content .= '<td>' . sprintf(__('%1$s', 'mp'), $token->card->type) . '</td>';
				$content .= '</tr>';
				$content .= '<tr>';
					$content .= '<td align="right" class="span4 align-right">' . __('Last 4 Digits:', 'mp') . '</td>';
					$content .= '<td>' . sprintf(__('%1$s', 'mp'), $token->card->last4) . '</td>';
				$content .= '</tr>';
				$content .= '<tr>';
					$content .= '<td align="right" class="span4 align-right">' . __('Expires:', 'mp') . '</td>';
					$content .= '<td>' . sprintf(__('%1$s/%2$s', 'mp'), $token->card->expMonth, $token->card->expYear) . '</td>';
				$content .= '</tr>';
			$content .= '</tbody>';
		$content .= '</table>';
		return $content;
	}

	/**
	* Runs before page load incase you need to run any scripts before loading the success message page
	*/
	function order_confirmation($order) {
	}

	/**
	* Print the years
	*/
	function _print_year_dropdown($sel = '', $pfp = false) {
		$localDate = getdate();
		$minYear = $localDate["year"];
		$maxYear = $minYear + 15;
		$output = "<option value=''>--</option>";
		for($i=$minYear; $i<$maxYear; $i++) {
			if($pfp) {
				$output .= "<option value='" . substr($i, 0, 4) . "'" .($sel==(substr($i, 0, 4))?' selected':'') . ">" . $i . "</option>";
			} else {
				$output .= "<option value='" . substr($i, 2, 2) . "'" .($sel==(substr($i, 2, 2))?' selected':'') . ">" . $i . "</option>";
			}
		}
		return($output);
	}

	/**
	* Print the months
	*/
	function _print_month_dropdown($sel='') {
		$output =  "<option value=''>--</option>";
		$output .=  "<option " . ($sel==1?' selected':'') . " value='01'>01 - Jan</option>";
		$output .=  "<option " . ($sel==2?' selected':'') . "  value='02'>02 - Feb</option>";
		$output .=  "<option " . ($sel==3?' selected':'') . "  value='03'>03 - Mar</option>";
		$output .=  "<option " . ($sel==4?' selected':'') . "  value='04'>04 - Apr</option>";
		$output .=  "<option " . ($sel==5?' selected':'') . "  value='05'>05 - May</option>";
		$output .=  "<option " . ($sel==6?' selected':'') . "  value='06'>06 - Jun</option>";
		$output .=  "<option " . ($sel==7?' selected':'') . "  value='07'>07 - Jul</option>";
		$output .=  "<option " . ($sel==8?' selected':'') . "  value='08'>08 - Aug</option>";
		$output .=  "<option " . ($sel==9?' selected':'') . "  value='09'>09 - Sep</option>";
		$output .=  "<option " . ($sel==10?' selected':'') . "  value='10'>10 - Oct</option>";
		$output .=  "<option " . ($sel==11?' selected':'') . "  value='11'>11 - Nov</option>";
		$output .=  "<option " . ($sel==12?' selected':'') . "  value='12'>12 - Dec</option>";
		return($output);
	}

	/**
	* Use this to process any fields you added. Use the $_POST global,
	* and be sure to save it to both the $_SESSION and usermeta if logged in.
	* DO NOT save credit card details to usermeta as it's not PCI compliant.
	* Call $mp->cart_checkout_error($msg, $context); to handle errors. If no errors
	* it will redirect to the next step.
	*
	* @param array $cart. Contains the cart contents for the current blog, global cart if $mp->global_cart is true
	* @param array $shipping_info. Contains shipping info and email in case you need it
	*/
	function process_payment_form($cart, $shipping_info) {
		global $mp;
		$settings = get_option('mp_settings');

		if(!isset($_POST['simplifyToken'])) {
			$mp->cart_checkout_error(__('The Simplify Token was not generated correctly. Please try again.', 'mp'));
		} elseif(!$mp->checkout_error) {
			$_SESSION['simplifyToken'] = $_POST['simplifyToken'];
		}
	}

	/**
	* Filters the order confirmation email message body. You may want to append something to
	* the message. Optional
	*
	* Don't forget to return!
	*/
	function order_confirmation_email($msg) {
		return $msg;
	}

	/**
	* Return any html you want to show on the confirmation screen after checkout. This
	* should be a payment details box and message.
	*
	* Don't forget to return!
	*/
	function order_confirmation_msg($content, $order) {
		global $mp;
		if($order->post_status == 'order_paid') {
			$content .= '<p>' . sprintf(__('Your payment for this order totaling %s is complete.', 'mp'), $mp->format_currency($order->mp_payment_info['currency'], $order->mp_payment_info['total'])) . '</p>';
		}
		return $content;
	}

	/**
	* Echo a settings meta box with whatever settings you need for you gateway.
	* Form field names should be prefixed with mp[gateways][plugin_name], like "mp[gateways][plugin_name][mysetting]".
	* You can access saved settings via $settings array.
	*/
	function gateway_settings_box($settings) {
		global $mp;
		?>
		<div class="postbox">
			<h3 class='hndle' style="background: #222; box-shadow: inset 0px 15px 15px #333; text-shadow: 0px 1px 0px #000; color: #ccc;">
				<img style="width: 100px; float: left; padding: 5px; padding-right: 25px;" src="data:image/png;base64,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" alt="" />
				<span style="color: #fff;"><?php _e('Simplify Commerce', 'mp') ?> <em><?php _e('by MasterCard', 'mp') ?></em></span> - <span style="color: #ccc;" class="description"><?php _e('Simplify helps merchants to accept online payments from Visa, MasterCard, American Express, Discover, JCB, and Diners Club cards. It\'s that simple. We offer a merchant account and payment gateway in a single, secure package so you can concentrate on what really matters to your business.', 'mp'); ?> <a style="color: #fff;" href="https://www.simplify.com/commerce/login/signup" target="_blank"><?php _e('Signup for Simplify Commerce &raquo;', 'mp') ?></a></span>
				<br style="clear: both;" />
			</h3>
			<div class="inside">
				<table class="form-table">
					<tr>
						<th scope="row"><?php _e('Simplify API Credentials', 'mp') ?></th>
						<td>
							<span class="description"><?php _e('Login to Simplify to <a target="_blank" href="https://manage.simplify.com/#account/apikeys">get your API credentials</a>. Enter your test credentials, then live ones when ready.', 'mp') ?></span>
							<p><label><?php _e('Private Key', 'mp') ?><br /><input value="<?php echo esc_attr($settings['gateways']['simplify']['private_key']); ?>" size="70" name="mp[gateways][simplify][private_key]" type="text" /></label></p>
							<p><label><?php _e('Public Key', 'mp') ?><br /><input value="<?php echo esc_attr($settings['gateways']['simplify']['publishable_key']); ?>" size="70" name="mp[gateways][simplify][publishable_key]" type="text" /></label></p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php _e('Simplify SSL Mode', 'mp') ?></th>
						<td>
							<span class="description"><?php _e('When in live mode, although it is not required, Simplify recommends you have an SSL certificate.', 'mp'); ?></span><br/>
							<select name="mp[gateways][simplify][is_ssl]">
								<option value="1"<?php selected($settings['gateways']['simplify']['is_ssl'], 1); ?>><?php _e('Force SSL', 'mp') ?></option>
								<option value="0"<?php selected($settings['gateways']['simplify']['is_ssl'], 0); ?>><?php _e('No SSL', 'mp') ?></option>
							</select>
						</td>
					</tr>
				</table>
			</div>
		</div>
	<?php
	}

	/**
	* Filters posted data from your settings form. Do anything you need to the $settings['gateways']['plugin_name']
	* array. Don't forget to return!
	*/
	function process_gateway_settings($settings) {
		return $settings;
	}

	/**
	* Use this to do the final payment. Create the order then process the payment. If
	* you know the payment is successful right away go ahead and change the order status
	* as well.
	* Call $mp->cart_checkout_error($msg, $context); to handle errors. If no errors
	* it will redirect to the next step.
	*
	* @param array $cart. Contains the cart contents for the current blog, global cart if $mp->global_cart is true
	* @param array $shipping_info. Contains shipping info and email in case you need it
	*/
	function process_payment($cart, $shipping_info) {
		global $mp;
		$settings = get_option('mp_settings');

		// Token MUST be set at this point
		if(!isset($_SESSION['simplifyToken'])) {
			$mp->cart_checkout_error(__('The Simplify Token was not generated correctly. Please go back and try again.', 'mp'));
			return false;
		}

		// Setup the Simplify API
		if(!class_exists('Simplify')) {
			require_once($mp->plugin_dir . "plugins-gateway/simplify-files/lib/Simplify.php");
		}
		Simplify::$publicKey = $this->publishable_key;
		Simplify::$privateKey = $this->private_key;

		$totals = array();
		foreach ($cart as $product_id => $variations) {
			foreach ($variations as $variation => $data) {
				$totals[] = $mp->before_tax_price($data['price'], $product_id) * $data['quantity'];
			}
		}
		$total = array_sum($totals);

		if($coupon = $mp->coupon_value($mp->get_coupon_code(), $total)) {
			$total = $coupon['new_total'];
		}

		if($shipping_price = $mp->shipping_price()) {
			$total += $shipping_price;
		}

		if($tax_price = $mp->tax_price()) {
			$total += $tax_price;
		}

		$order_id = $mp->generate_order_id();

		try {
			$token = $SESSION['simplifyToken'];
			$charge = Simplify_Payment::createPayment(array(
				'amount' => $total * 100,
				'token' => $_SESSION['simplifyToken'],
				'description' => sprintf(__('%s Store Purchase - Order ID: %s, Email: %s', 'mp'), get_bloginfo('name'), $order_id, $_SESSION['mp_shipping_info']['email']),
				'currency' => 'USD'
				));

			if($charge->paymentStatus == 'APPROVED') {
				$payment_info = array();
				$payment_info['gateway_public_name'] = $this->public_name;
				$payment_info['gateway_private_name'] = $this->admin_name;
				$payment_info['method'] = sprintf(__('%1$s Card ending in %2$s - Expires %3$s', 'mp'), $charge->card->type, $charge->card->last4, $charge->card->expMonth . '/' . $charge->card->expYear);
				$payment_info['transaction_id'] = $charge->id;
				$timestamp = time();
				$payment_info['status'][$timestamp] = __('Paid', 'mp');
				$payment_info['total'] = $total;
				$payment_info['currency'] = $this->currency;
				$order = $mp->create_order(
					$order_id,
					$cart,
					$_SESSION['mp_shipping_info'],
					$payment_info,
					true
					);
				unset($_SESSION['simplifyToken']);
				$mp->set_cart_cookie(Array());
			}
		} catch (Exception $e) {
			unset($_SESSION['simplifyToken']);
			$mp->cart_checkout_error(sprintf(__('There was an error processing your card: "%s". Please <a href="%s">go back and try again</a>.', 'mp'), $e->getMessage(), mp_checkout_step_url('checkout')));
			return false;
		}
	}

	/**
	* INS and payment return
	*/
	function process_ipn_return() {
		global $mp;
		$settings = get_option('mp_settings');
	}

}

mp_register_gateway_plugin('MP_Gateway_Simplify', 'simplify', __('Simplify Commerce by MasterCard', 'mp'));

?>