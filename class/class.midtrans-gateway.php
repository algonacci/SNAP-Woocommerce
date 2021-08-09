<?php
  /**
   * ### Midtrans Payment Plugin for Wordrpress-WooCommerce ###
   *
   * This plugin allow your Wordrpress-WooCommerce to accept payment from customer using Midtrans Payment Gateway solution.
   *
   * @category   Wordrpress-WooCommerce Payment Plugin
   * @author     Rizda Dwi Prasetya <rizda.prasetya@midtrans.com>
   * @link       http://docs.midtrans.com
   * (This plugin is made based on Payment Plugin Template by WooCommerce)
   *
   * LICENSE: This program is free software; you can redistribute it and/or
   * modify it under the terms of the GNU General Public License
   * as published by the Free Software Foundation; either version 2
   * of the License, or (at your option) any later version.
   * 
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   * GNU General Public License for more details.
   * 
   * You should have received a copy of the GNU General Public License
   * along with this program; if not, write to the Free Software
   * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
   */

    /**
     * Midtrans Payment Gateway Class
     * extend implementation from main blueprint of abstract class
     */
    class WC_Gateway_Midtrans extends WC_Gateway_Midtrans_Abstract {

      /**
       * Constructor
       */
      function __construct() {
        /**
         * Fetch config option field values and set it as private variables
         */
        $this->id           = 'midtrans';
        $this->method_title = __( $this->pluginTitle(), 'midtrans-woocommerce' );
        $this->method_description = $this->getSettingsDescription();
        $this->has_fields   = true;

        parent::__construct();
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
        // Hook for displaying payment page HTML on receipt page
        add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
      }

      /**
       * Admin Panel Options
       * See definition on the extended abstract class
       * @access public
       * @return void
       */
      public function admin_options() { ?>
        <h3><?php _e( $this->pluginTitle(), 'midtrans-woocommerce' ); ?></h3>
        <p><?php _e($this->getSettingsDescription(), 'midtrans-woocommerce' ); ?></p>
        <table class="form-table">
          <?php
            // Generate the HTML For the settings form. generated from `init_form_fields`
            $this->generate_settings_html();
          ?>
        </table><!--/.form-table-->
        <?php
      }

      /**
       * Initialise Gateway Settings Form Fields
       * See definition on the extended abstract class
       */
      function init_form_fields() {
        // Build array of configuration fieldss that will be displayed on Admin Panel
        // Use config fields template from abstract class
        parent::init_form_fields();
        // Specific config fields for this main gateway goes below
        WC_Midtrans_Utils::array_insert( $this->form_fields, 'enable_3d_secure', array(
          'acquring_bank' => array(
            'title' => __( 'Acquiring Bank', 'midtrans-woocommerce'),
            'type' => 'text',
            'label' => __( 'Acquiring Bank', 'midtrans-woocommerce' ),
            'description' => __( 'You should leave it empty, it will be auto configured. </br> Alternatively may specify your card-payment acquiring bank for this payment option. </br> Options: BCA, BRI, DANAMON, MAYBANK, BNI, MANDIRI, CIMB, etc (Only choose 1 bank).' , 'midtrans-woocommerce' ),
            'default' => ''
          )
        ));
        // Make this payment method enabled-checkbox 'yes' by default
        $this->form_fields['enabled']['default'] = 'yes';
        // Set icons config field specific placeholder
        $this->form_fields['sub_payment_method_image_file_names_str']['placeholder'] = 'midtrans.png,credit_card.png';
        $this->form_fields['sub_payment_method_image_file_names_str']['default'] = 'midtrans.png';
      }

      /**
       * Hook function that will be auto-called by WC on customer initiate payment
       * act as entry point when payment process is initated
       * @param  string $order_id generated from WC
       * @return array contains redirect_url of payment for customer
       */
      function process_payment( $order_id ) {
        // pass through the real function handling the logic
        return $this->process_payment_helper($order_id);
      }

      /**
       * Helper function to handle additional params from sub separated gateway buttons 
       * @param  string $order_id auto generated by WC
       * @param  array mixed array opts, used by sub separated gateway buttons classes
       * @return array contains redirect_url of payment for customer
       */
      public function process_payment_helper( $order_id, $options = false ) {
        global $woocommerce;
        
        // Create the order object
        $order = new WC_Order( $order_id );
        // Get response object template
        $successResponse = $this->getResponseTemplate( $order );
        // Get data for charge to midtrans API
        $params = $this->getPaymentRequestData( $order_id );
        // Add acquiring bank params
        if (strlen($this->get_option('acquring_bank')) > 0)
          $params['credit_card']['bank'] = strtoupper ($this->get_option('acquring_bank'));

        // if coming from sub separated gateway buttons
        if($options && $options['sub_payment_method_params']){
          $params['enabled_payments'] = $options['sub_payment_method_params'];
        }
        // @TODO: add order thank you page as snap finish url

        // Empty the cart because payment is initiated.
        $woocommerce->cart->empty_cart();
        // allow merchant-defined custom filter function to modify snap $params
        $params = apply_filters( 'midtrans_snap_params_main_before_charge', $params );
        try {
          $snapResponse = WC_Midtrans_API::createSnapTransaction( $params, $this->id );
        } catch (Exception $e) {
            $this->setLogError( $e->getMessage() );
            WC_Midtrans_Utils::json_print_exception( $e, $this );
          exit();
        }

        // If `enable_redirect` admin config used, snap redirect
        if(property_exists($this,'enable_redirect') && $this->enable_redirect == 'yes'){
          $redirectUrl = $snapResponse->redirect_url;
        }else{
          $redirectUrl = $order->get_checkout_payment_url( true )."&snap_token=".$snapResponse->token;
        }

        // Store snap token & snap redirect url to $order metadata
        $order->update_meta_data('_mt_payment_snap_token',$snapResponse->token);
        $order->update_meta_data('_mt_payment_url',$snapResponse->redirect_url);
        $order->save();

        // @TODO: default to yes or remove this options: enable_immediate_reduce_stock
        if(property_exists($this,'enable_immediate_reduce_stock') && $this->enable_immediate_reduce_stock == 'yes'){
          // Reduce item stock on WC, item also auto reduced on order `pending` status changes
          // @NOTE: unable to replace with this code: `$order->update_status('on-hold',__('Customer proceed to Midtrans Payment page','midtrans-woocommerce'));`
          // because of `This order’s status is “On hold”—it cannot be paid for.` 
          wc_reduce_stock_levels($order);
        }

        $successResponse['redirect'] = $redirectUrl;
        return $successResponse;
      }

      /**
       * Hook function that will be called on receipt page
       * Output HTML for Snap payment page. Including `snap.pay()` part
       * See definition on the extended abstract class
       * @param  string $order_id generated by WC
       * @return string HTML
       */
      function receipt_page( $order_id ) {
        global $woocommerce;
        $pluginName = 'fullpayment';
        // Separated as Shared PHP included by multiple class
        require_once(dirname(__FILE__) . '/payment-page.php'); 

      }
      
      /**
       * @return string
       */
      public function pluginTitle() {
        return "Midtrans";
      }

      /**
       * @return string
       */
      protected function getDefaultTitle () {
        return __('All Supported Payment', 'midtrans-woocommerce');
      }

      /**
       * @return string
       */
      protected function getSettingsDescription() {
        return __('Secure payment via Midtrans that accept various payment methods, with mobile friendly built-in interface, or (optionally) redirection. This is the main payment button, 1 single button for multiple available payments methods. <a href="https://docs.midtrans.com/en/snap/with-plugins?id=woocommerce-plugin-configuration" target="_blank">Please follow "how-to configure guide" here.</a>', 'midtrans-woocommerce');
      }

      /**
       * @return string
       */
      protected function getDefaultDescription () {
        return __('Accept all various supported payment methods. Choose your preferred payment on the next page. Secure payment via Midtrans.', 'midtrans-woocommerce');
      }

    }
