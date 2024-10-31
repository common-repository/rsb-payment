<?php
/*
 * Plugin Name:  Payment gateway of Russian Standard Bank for WooCommerce
 * Plugin URI:  https://wordpress.org/plugins/rsb-payment/
 * Description: Payment gateway of Russian Standard Bank for WooCommerce
 * Version: 1.0.2
 * Author: RSB
 * Author URI: https://www.rsb.ru/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network: true
 */

spl_autoload_register(function($className) {
    if (strpos($className, 'Ipol\RSBRequest') === 0) {
        $classPath = implode(DIRECTORY_SEPARATOR, explode('\\', substr($className,5)));
        $filePath = __DIR__.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.$classPath.'.php';
        if (is_readable($filePath) && file_exists($filePath)) require_once $filePath;
    }
});

load_textdomain('rsb-payment',dirname(__FILE__).'/languages/rsb-payment-ru_RU.mo');

register_activation_hook( __FILE__, 'rsbpayment_moduleInstall' );
function rsbpayment_moduleInstall() {
    global $wpdb;
    $wpdb->query("CREATE TABLE IF NOT EXISTS `".$wpdb->get_blog_prefix()."rsb_transactions` (
	`id` int(8) unsigned NOT NULL auto_increment,
	`created` datetime,
	`order_info` text NOT NULL DEFAULT '',
	`trans_id` varchar(50) NOT NULL DEFAULT '',
	`order_id` int(8) unsigned NOT NULL DEFAULT '0',
	`result` text NOT NULL DEFAULT '',
	`trans_type` mediumint(8) unsigned NOT NULL default '0',
	`control` mediumint(8) unsigned NOT NULL default '0',
	`dtm` datetime,
	`status` varchar(250) NOT NULL DEFAULT '',
	`check_raw` text NOT NULL DEFAULT '',
	`trans_sum` varchar(20) NOT NULL DEFAULT '',
	
	`client_ip` varchar(20) NOT NULL DEFAULT '',	
	`ans_raw` text NOT NULL DEFAULT '',
	PRIMARY KEY (`id`)
	) DEFAULT CHARSET=".$wpdb->charset.";");
}
register_uninstall_hook(__FILE__,'rsbpayment_moduleUninstall');
function rsbpayment_moduleUninstall() {
    global $wpdb;
    $wpdb->query('DROP TABLE '.$wpdb->get_blog_prefix().'rsb_transactions');
}

add_filter('woocommerce_payment_gateways', function($gateways){
    $gateways[]='RSBPayment';
    return $gateways;
});

add_action('admin_menu', function(){
    add_submenu_page(
        'woocommerce',
        __('Payments Russian Standard Bank','rsb-payment'),
        __('Payments Russian Standard Bank','rsb-payment'),
        'manage_options',
        'rsb_payment_menu',
        ['RSBPayment','renderAdminPage']
    );
});

add_action('add_meta_boxes', function(){
    $order = wc_get_order();
    $paym = '';
    if ($order) $paym = $order->get_payment_method();
    if ($paym=='rsbpayment') {
        wp_enqueue_script( 'rsb-admin-order', plugin_dir_url( __FILE__ ) . 'assets/js/rsb-admin-order.js', [], '1.0' );
    }
}, 10);

add_action( 'wp_ajax_rsb_renew_info', ['RSBPayment','ajaxRenew'] );
add_action( 'wp_ajax_rsb_full_refund', ['RSBPayment','ajaxFullRefund'] );

add_action('plugins_loaded','russtandartbankpayment_gateway_class');
function russtandartbankpayment_gateway_class() {

    class RSBPayment extends WC_Payment_Gateway {
        private $tablename = 'rsb_transactions';
        private $currency=643;
        private $lang = 'ru';
        public $supports = ['products','refunds'];

        public function __construct() {
            $this->id = 'rsbpayment';
            $this->icon='';
            $this->method_title=__('Russian Standard Bank acquiring','rsb-payment');
            $this->method_description = __('Allows you to accept payments - Russian Standard Bank','rsb-payment');
            $this->title = __('Russian Standard Bank','rsb-payment');
            $this->description = __('Payment by bank card','rsb-payment');

            $this->init_form_fields();
            $this->init_settings();
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action('woocommerce_api_wc_'.$this->id, [$this, 'rest_functions']);
            add_action( 'woocommerce_order_refunded', [ $this, 'process_refund'], 10, 2 );
        }

        //Correct Order Items Data - delete TAX info && correct item Price
        //This function is run only one time in SUCCESS_PAY handler
        private function correctOrderItemsData(int $order_id) {
            if (!$this->isCheckMode()) return;
            $pay_trans = $this->getTransaction($order_id,true);
            $check = unserialize($pay_trans[0]->check_raw);
            if (!$check) return;
            $fnl_correct = false;
            foreach ($check as $ch) {
                if ($ch['itm_id']=='Delivery') { //NoFree Shipping
                    $order = wc_get_order($order_id);
                    $shipings = $order->get_shipping_methods();
                    foreach ($shipings as $shipping) {
                        if ($shipping->get_id() == $ch['dlv_id']) {
                            $shipping->set_taxes([]);
                            $shipping->set_total($ch['Price']/100);
                            $shipping->save();
                            break;
                        }
                    }
                } elseif($ch['itm_id']=='NewItem') {
                    $prod = new WC_Order_Item_Product($ch['parent_id']);
                    $prod->set_quantity( $prod->get_quantity() - 1 );
                    $prod->save();
                    $fnl_correct=$ch['parent_id'];

                    $new = new WC_Order_Item_Product();
                    $new->set_product($prod->get_product());
                    $new->set_variation_id($prod->get_variation_id());
                    $new->set_order_id($prod->get_order_id());
                    $new->set_quantity(1);
                    $new->set_name($prod->get_name().' '.__('1 piece','rsb-payment'));
                    $new->set_taxes([]);
                    $new->set_total($ch['Price']/100);
                    $new->save();
                } else {
                    $prod = new WC_Order_Item_Product($ch['itm_id']);
                    $prod->set_taxes([]);
                    $prod->set_total(($ch['Price']/100) * ($ch['Qty'] / 1000));
                    $prod->set_subtotal(($ch['Price']/100) * ($ch['Qty'] / 1000));
                    $prod->save();
                }
            }
            if ($fnl_correct) foreach ($check as $ch) {
                if ($ch['itm_id']==$fnl_correct) {
                    $prod = new WC_Order_Item_Product($ch['itm_id']);
                    $prod->set_taxes([]);
                    $prod->set_total(($ch['Price']/100) * ($ch['Qty'] / 1000));
                    $prod->set_subtotal(($ch['Price']/100) * ($ch['Qty'] / 1000));
                    $prod->save();
                }
            }
        }

        function rest_functions() {
            global $woocommerce;
            if (isset($_REQUEST['rsbpayment']) AND ($_REQUEST['rsbpayment'] == 'success')) {
                $trans_id = sanitize_text_field($_REQUEST['trans_id']);
                $iserror=false;
                $res = $this->renewinfo($trans_id);
                if (!$res) $iserror=true;
                $trans = $this->getTransaction($trans_id);
                $trans = $trans[0];
                $order_id = intval($trans->order_id);
                $order = new WC_Order($order_id);
                if ($res['isOk']) {
                    if ($res['RESULT_PS']=='FINISHED' || $res['RESULT_PS']=='ACTIVE') {
                        $this->correctOrderItemsData($order->get_id());
                        $woocommerce->cart->empty_cart();
                        $order->update_status('processing', __('Order has been paid','rsb-payment').'.');
                        $order->payment_complete($trans_id);
                        wp_redirect($this->get_return_url($order));
                    } else $iserror=true;
                } else $iserror=true;
                if ($iserror) {
                    $errcode=$res['RESULT_CODE'];
                    $ermsg2='';
                    if (isset($this->resultcodes()[$errcode])) $ermsg2=$this->resultcodes()[$errcode];

                    $ermsg1='';
                    if (isset($_REQUEST['error'])) $ermsg1=__('Payment system message','rsb-payment').': '.sanitize_text_field($_REQUEST['error']);
                    $order->update_status('failed', __('Payment attempt failed','rsb-payment').'.'.$ermsg1.'  '.$ermsg2);

                    get_header();

                    echo wp_kses('<div class="woocommerce"><ul class="woocommerce-error" role="alert"><li>'.__('Refuse. Payment system message: Response code','rsb-payment').' - '.$res['RESULT_CODE'].'; '.(($ermsg1!='')?'<br/>'.$ermsg1:'').(($ermsg2!='')?'<br/>'.$ermsg2:'').'</li></ul></div>
                        <div style="width:100%;height:100px;"></div>
                        <p><a href="/">'.__('Return to the homepage','rsb-payment').'</a></p>
                        <div style="width:100%;height:100px;"></div>
                    ',[
                        'div'=>[ 'class'=>[], 'style'=>[] ],
                        'br'=>[],
                        'ul'=>[ 'class'=>[], 'role'=>[] ],
                        'li'=>[],
                        'p'=>[],
                        'a'=>['href'=>[]]
                    ]);
                    get_footer();
                }
                exit();
            } elseif (isset($_REQUEST['rsbpayment']) AND ($_REQUEST['rsbpayment'] == 'fail')) { // http://wordpress.qq/?wc-api=wc_rsbpayment&rsbpayment=fail
                $errmsg='';
                if (isset($_REQUEST['error'])) $errmsg=sanitize_text_field($_REQUEST['error']);
                get_header();
                echo wp_kses('<div class="woocommerce"><ul class="woocommerce-error" role="alert"><li>'.__('Sorry, there was a payment error','rsb-payment').'. '.(($errmsg!='')?'<br/>'.$errmsg:'').'</li></ul></div>
                        <div style="width:100%;height:100px;"></div>
                        <p><a href="/">'.__('Return to the homepage','rsb-payment').'</a></p>
                        <div style="width:100%;height:100px;"></div>',[
                            'div'=>[ 'class'=>[], 'style'=>[] ],
                            'br'=>[],
                            'ul'=>[ 'class'=>[], 'role'=>[] ],
                            'li'=>[],
                            'p'=>[],
                            'a'=>['href'=>[]]
                ]);
                get_footer();
                exit();
            }
        }

        //Settings
        public function init_form_fields() {
            $form_fields = [
                'urls_mode' => [
                    'title' => __('Request address set','rsb-payment').':',
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'default' => '0',
                    'options' => [
                        '0' => __('For testing','rsb-payment'),
                        '1' => __('Work mode','rsb-payment'),
                        '2' => __('Custom','rsb-payment')
                    ],
                ],
                'url1' => [
                    'title' => __('Sending buyers for payment','rsb-payment').':',
                    'type' => 'text',
                    'default' => '',
                ],
                'url2' => [
                    'title' => __('For payment module requests','rsb-payment').':',
                    'type' => 'text',
                    'default' => '',
                ],
                'sbp'=>[
                    'title'=> __('SBP payment scenario','rsb-payment').':',
                    'type'=>'text',
                    'default'=>''
                ],
                //CHECK Settings
                'en_check' => [
                    'title' => __('Enable sending checks','rsb-payment'),
                    'type' => 'checkbox',
                    'label' => __('Send checks','rsb-payment'),
                    'description' => __('If enabled, checks will be generated upon payment','rsb-payment'),
                    'default' => 'no',
                    'desc_tip' => true
                ],
                'tcp_id' => [
                    'title' => __('TSP identifier','rsb-payment').':',
                    'type' => 'text',
                    'default' => '',
                    'description' => __('TSP identifier must be obtained from the representatives of the Bank or in your personal account','rsb-payment'),
                    'desc_tip' => true
                ],
                'pay_attribute' => [
                    'title' => __('PayAttribute','rsb-payment').':',
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'default' => '0',
                    'description'=>__('You must select PayAttribute from the table below','rsb-payment'),
                    'desc_tip' => true,
                    'options' => [
                        '0' => __('PayAttribute #0','rsb-payment'),
                        '1' => __('PayAttribute #1','rsb-payment'),
                        '2' => __('PayAttribute #2','rsb-payment'),
                        '3' => __('PayAttribute #3','rsb-payment'),
                        '4' => __('PayAttribute #4','rsb-payment'),
                        '5' => __('PayAttribute #5','rsb-payment'),
                        '6' => __('PayAttribute #6','rsb-payment'),
                        '7' => __('PayAttribute #7','rsb-payment')
                    ],
                ],
                'force_tax' => [
                    'title' => __('Force use TaxID for receipts','rsb-payment').':',
                    'type' => 'checkbox',
                    'label' => __('Use TaxID','rsb-payment'),
                    'description' => __('If required, check the box to force the use of the TaxID selected below','rsb-payment'),
                    'default' => 'no',
                    'desc_tip' => true
                ],
                'tax_id' => [
                    'title' => __('TaxID','rsb-payment').':',
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'default' => '1',
                    'description'=>__("This TaxID will be used if the checkbox \"Force use TaxID for receipts\" is checked",'rsb-payment'),
                    'desc_tip' => true,
                    'options' => [
                        '1' => __('Tax','rsb-payment').' 20%',
                        '2' => __('Tax','rsb-payment').' 10%',
                        '3' => __('Tax','rsb-payment').' 0%',
                        '4' => __('No Tax','rsb-payment')
                    ],
                ],

                //Loading CRT Files
                'f_pem' => [
                    'title' => __('PEM File','rsb-payment').':',
                    'type' => 'hidden',
                    'default' => '',
                    'description'=>__('Certificate file that you can receive after registering your merchant with the Bank','rsb-payment')
                ],
                'f_key' => [
                    'title' => __('KEY File','rsb-payment').':',
                    'type' => 'hidden',
                    'default' => '',
                    'description'=>__('The key file that you will receive when creating a certificate request','rsb-payment')
                ],
                'f_crt' => [
                    'title' => __('CRT File','rsb-payment').':',
                    'type' => 'hidden',
                    'default' => '',
                    'description'=>__('Certificate chain file that you can download yourself','rsb-payment').' <a href="https://testsecurepay.rsb.ru/email/new-ecomm-ca-root-ca.zip" target="_blank">'.__('here','rsb-payment').'</a>'
                ],
                'enlogs' => [
                    'title' => __('Enable query log','rsb-payment').':',
                    'type' => 'checkbox',
                    'label' => __('Log enabled','rsb-payment'),
                    'description' => __('If enabled, requests to the bank and responses will be recorded in a log file (only for technical specialists!)','rsb-payment'),
                    'default' => 'no',
                    'desc_tip' => true
                ]
            ];
            $this->form_fields = $form_fields;
        }
        public function process_admin_options() { //Saving settings && CRT Files
            $this->init_settings();
            $post_data = $this->get_post_data();
            foreach ( $this->get_form_fields() as $key => $field ) {
                if ( 'title' !== $this->get_field_type( $field ) ) {
                    try {
                        $this->settings[ $key ] = $this->get_field_value( $key, $field, $post_data );
                    } catch ( Exception $e ) {
                        $this->add_error( $e->getMessage() );
                    }
                }
            }
            if (!isset($post_data['woocommerce_rsbpayment_enlogs'])) {if (file_exists(plugin_dir_path(__FILE__).'log/mainlog.txt')) unlink(plugin_dir_path(__FILE__).'log/mainlog.txt');}
            else if ($post_data['woocommerce_rsbpayment_enlogs']!='1') if (file_exists(plugin_dir_path(__FILE__).'log/mainlog.txt')) unlink(plugin_dir_path(__FILE__).'log/mainlog.txt');

            if (isset($post_data['woocommerce_rsbpayment_sbp'])) {
                $sbp = sanitize_text_field($post_data['woocommerce_rsbpayment_sbp']);
                $sbp = intval(mb_substr($sbp,0,10));
                if ($sbp==0) $sbp='';
                $this->settings['sbp']=$sbp;
            }

            //Modify CRT Files!!
            $fname = $this->plugin_id.$this->id.'_f_pem_file';
            $upload_dir = plugin_dir_path(__FILE__).'crt/';
            if ( ($_FILES[$fname]['error']==UPLOAD_ERR_OK)&&($_FILES[$fname]['size']>0)&&($_FILES[$fname]['tmp_name']!='') ) {
                if (move_uploaded_file($_FILES[$fname]['tmp_name'],$upload_dir.$_FILES[$fname]['name'])) {
                    $this->settings['f_pem']=sanitize_file_name($_FILES[$fname]['name']);
                }
            }
            $fname = $this->plugin_id.$this->id.'_f_key_file';
            if ( ($_FILES[$fname]['error']==UPLOAD_ERR_OK)&&($_FILES[$fname]['size']>0)&&($_FILES[$fname]['tmp_name']!='') ) {
                if (move_uploaded_file($_FILES[$fname]['tmp_name'],$upload_dir.$_FILES[$fname]['name'])) {
                    $this->settings['f_key']=sanitize_file_name($_FILES[$fname]['name']);
                }
            }
            $fname = $this->plugin_id.$this->id.'_f_crt_file';
            if ( ($_FILES[$fname]['error']==UPLOAD_ERR_OK)&&($_FILES[$fname]['size']>0)&&($_FILES[$fname]['tmp_name']!='') ) {
                if (move_uploaded_file($_FILES[$fname]['tmp_name'],$upload_dir.$_FILES[$fname]['name'])) {
                    $this->settings['f_crt']=sanitize_file_name($_FILES[$fname]['name']);
                }
            }
            return update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ), 'yes' );
        }
        //Custom part settings page
        public function admin_options() {
            parent::admin_options();
            $id = $this->plugin_id.$this->id.'_';
            ?>
                <script>
                    if (jQuery=='undefined') {
                        console.log('NotJQuery');
                    } else {
                        jQuery(function(){
                            function changeurls() {
                                switch(jQuery('#woocommerce_rsbpayment_urls_mode')[0].value) {
                                    case '0':
                                        jQuery('#woocommerce_rsbpayment_url1').val('https://testsecurepay.rsb.ru/ecomm2/ClientHandler?trans_id=').attr('readonly','readonly');
                                        jQuery('#woocommerce_rsbpayment_url2').val('https://testsecurepay.rsb.ru:9443/ecomm2/MerchantHandler').attr('readonly','readonly');
                                        break;
                                    case '1':
                                        jQuery('#woocommerce_rsbpayment_url1').val('https://securepay.rsb.ru/ecomm2/ClientHandler?trans_id=').attr('readonly','readonly');
                                        jQuery('#woocommerce_rsbpayment_url2').val('https://securepay.rsb.ru:9443/ecomm2/MerchantHandler').attr('readonly','readonly');
                                        break;
                                    case '2':
                                        jQuery('#woocommerce_rsbpayment_url1').removeAttr('readonly');
                                        jQuery('#woocommerce_rsbpayment_url2').removeAttr('readonly');
                                        break;
                                }
                            }
                            changeurls();
                            jQuery('#woocommerce_rsbpayment_urls_mode').change(function(){
                                changeurls();
                            });

                            var obj=jQuery('#<?php echo esc_attr($id)?>f_pem').parent();
                            jQuery('<input type="file" name="<?php echo esc_attr($id)?>f_pem_file" accept=".pem">').prependTo(obj);
                            <?php if ($this->settings['f_pem']) { ?>
                            jQuery('<p class="file_loaded"><?php echo __('File already uploaded','rsb-payment') ?>: <b><?php echo esc_attr($this->settings['f_pem'])?></b><br/><?php echo __('Want to upload another file','rsb-payment') ?>?</p>').prependTo(obj);
                            <?php } ?>

                            var obj=jQuery('#<?php echo esc_attr($id)?>f_key').parent();
                            jQuery('<input type="file" name="<?php echo esc_attr($id)?>f_key_file" accept=".key">').prependTo(obj);
                            <?php if ($this->settings['f_key']) { ?>
                            jQuery('<p class="file_loaded"><?php echo __('File already uploaded','rsb-payment') ?>: <b><?php echo esc_attr($this->settings['f_key'])?></b><br/><?php echo __('Want to upload another file','rsb-payment') ?>?</p>').prependTo(obj);
                            <?php } ?>

                            var obj=jQuery('#<?php echo esc_attr($id)?>f_crt').parent();
                            jQuery('<input type="file" name="<?php echo esc_attr($id)?>f_crt_file" accept=".crt">').prependTo(obj);
                            <?php if ($this->settings['f_crt']) { ?>
                            jQuery('<p class="file_loaded"><?php echo __('File already uploaded','rsb-payment') ?>: <b><?php echo esc_attr($this->settings['f_crt'])?></b><br/><?php echo __('Want to upload another file','rsb-payment') ?>?</p>').prependTo(obj);
                            <?php } ?>

                            var obj = document.getElementById('<?php echo esc_attr($id)?>sbp').addEventListener("keyup",function(){
                                var t=this,rep = /[-;":'a-zA-Zа-яА-Я\\=`ёЁ/\*++!@#$%\^&_№?><\s|~(),\[\]{}\.]/g;
                                if (rep.test(t.value)) t.value = t.value.replace(rep, '');
                                if (t.value.length>10) t.value = t.value.substring(0,10);
                            });
                        });
                    }
                </script>
                <style>
                    p.file_loaded {
                        color:#1d2327;
                        margin-bottom:10px !important;
                    }
                    p.file_loaded b {
                        color:#c71212;
                    }
                    table.pay_attribute {
                        width:100%;
                        border-spacing:0;
                    }
                    table.pay_attribute th, table.pay_attribute td {
                        padding:5px;
                        border-top:1px solid #ccc;
                        border-left:1px solid #ccc;
                    }
                    table.pay_attribute th:last-child, table.pay_attribute td:last-child {
                        border-right:1px solid #ccc;
                    }
                    table.pay_attribute tr:last-child td {
                        border-bottom: 1px solid #ccc;
                    }
                    table.pay_attribute .center {
                        text-align:center;
                    }
                    table.pay_attribute .top {
                        vertical-align: top;
                    }
                </style>
            <h3><?php echo __('Table of «PayAttribute»','rsb-payment') ?>:</h3>
            <table class="pay_attribute">
                <tr>
                    <th class="center" width="50">№№</th>
                    <th><?php echo __('The list of grounds for assigning the variable «sign of the method of calculation» of the corresponding value of the variable','rsb-payment') ?></th>
                </tr>
                <tr>
                    <td class="center top">0</td>
                    <td><?php echo __('For individual entrepreneurs who are taxpayers applying the patent taxation system and the simplified taxation system, as well as individual entrepreneurs applying the taxation system for agricultural producers, the taxation system in the form of a single tax on imputed income for certain types of activities when carrying out the types of entrepreneurial activities established by paragraph 2 Article 346.26 of the Tax Code of the Russian Federation, with the exception of individual entrepreneurs trading in excisable goods, the requirement for the mandatory inclusion of details in the cash receipt and SRF is applied from February 1, 2021. The PayAttribute field can be omitted','rsb-payment') ?>.</td>
                </tr>
                <tr>
                    <td class="center top">1</td>
                    <td><?php echo __('Full advance payment before the transfer of the subject of calculation','rsb-payment') ?></td>
                </tr>
                <tr>
                    <td class="center top">2</td>
                    <td><?php echo __('Partial advance payment until the transfer of the subject of calculation','rsb-payment') ?></td>
                </tr>
                <tr>
                    <td class="center top">3</td>
                    <td><?php echo __('Prepaid expense','rsb-payment') ?></td>
                </tr>
                <tr>
                    <td class="center top">4</td>
                    <td><?php echo __('Full payment, including taking into account the advance payment (prepayment) at the time of transfer of the subject of calculation','rsb-payment') ?></td>
                </tr>
                <tr>
                    <td class="center top">5</td>
                    <td><?php echo __('Partial payment of the subject of settlement at the time of its transfer, followed by payment on credit','rsb-payment') ?></td>
                </tr>
                <tr>
                    <td class="center top">6</td>
                    <td><?php echo __('Transfer of the subject of settlement without its payment at the time of its transfer, followed by payment on credit','rsb-payment') ?></td>
                </tr>
                <tr>
                    <td class="center top">7</td>
                    <td><?php echo __('Payment of the subject of settlement after its transfer with payment on credit (loan payment). This attribute must be the only one in the document, and a document with this attribute can contain only one line','rsb-payment') ?>.</td>
                </tr>
            </table>
            <?php
        }

        //Payment
        public function process_payment($order_id) {
            global $wpdb;

            $order = new WC_Order($order_id);
            $order->update_status('on-hold', __('Payment expected','rsb-payment'));

            $order_items = $order->get_items();
            $order_total = intval($order->get_total()*100);

            $ipaddr = $this->client_ip_addr($order->get_customer_ip_address());
            $q = [
                "command" => "v",
                "amount" => $order_total,
                "currency" => $this->currency,
                "client_ip_addr" => $ipaddr,
                "description" => "ORDER N".$order_id,
                "mrch_transaction_id" => "SMS transaction",
                "language" => $this->lang,
                "msg_type" => "SMS",
                'server_version'=>'2.0'
            ];

            if ( ($sbp = intval($this->settings['sbp'])) && ($sbp>0) ) {
                $q['ecomm_payment_scenario'] = $sbp;
            }

            $bskitmraw=[];
            if ($this->isCheckMode()) {
                $allAmount = 0;
                $bskitm=[];
                $bskitmraw=[];
                foreach ($order_items as $item) {
                    $ord_item = new WC_Order_Item_Product($item->get_id());

                    $total = floatval($ord_item->get_total_tax())+floatval($ord_item->get_total());
                    $qty = $ord_item->get_quantity();
                    $price_for_one = $total / $qty;
                    $price_for_one_norm = round($price_for_one*100);

                    $tax_id = 4;//NoTax
                    if ($this->settings['force_tax']=='yes') $tax_id=intval($this->settings['tax_id']);
                    else {
                        $tax = new WC_Tax();
                        $var_id = $item['variation_id'];
                        if ($var_id) $prod = new WC_Product_Variation($item['variation_id']);
                        else $prod = new WC_Product($item['product_id']);
                        //TaxRate
                        if (get_option("woocommerce_calc_taxes") == "no") {} else {
                            $tx = $tax->get_base_tax_rates($prod->get_tax_class(true));
                            if (!empty($tx)) {
                                $rates = $tax->get_rates($prod->get_tax_class());
                                $rates = (int)round(array_shift($rates)['rate']);
                                if ($rates==20) $tax_id=1;
                                if ($rates==10) $tax_id=2;
                            }
                        }
                    }
                    $bskitm[]=[
                        'Qty'=>$qty*1000,
                        'Price'=>$price_for_one_norm,
                        'PayAttribute'=>$this->settings['pay_attribute'],
                        'TaxId'=>$tax_id,
                        'Description'=>$item['name']
                    ];
                    $bskitmraw[]=[
                        'itm_id'=>$item->get_id(),
                        'Qty'=>$qty*1000,
                        'Price'=>$price_for_one_norm,
                        'PayAttribute'=>$this->settings['pay_attribute'],
                        'TaxId'=>$tax_id,
                        'Description'=>$item['name']
                    ];
                    $allAmount += ($price_for_one_norm*$qty);
                }

                $shippings = $order->get_shipping_methods();
                $shipping_id = 0;
                $shp_tax_id=4;//NoTax
                $shipping_name = '';
                $shp_price=0;
                $shipping = null;
                if (count($shippings)>0) {
                    //AddShipping - get FIRST service!
                    $shipping = array_shift($shippings);
                    $shipping_id = $shipping->get_id();
                    $shipping_name = $shipping->get_name();

                    $shp_price = intval(( floatval($shipping->get_total()) + floatval($shipping->get_total_tax()) ) * 100);

                    if ($this->settings['force_tax']=='yes') $shp_tax_id=intval($this->settings['tax_id']);
                    else {
                        $taxes = $shipping->get_taxes();
                        if (is_array($taxes)) {
                            if (count($taxes)>0) {
                                if (count($taxes['total'])>0) foreach ($taxes['total'] as $tax_rate_id=>$val) {
                                    $val = intval($val);
                                    if ($val>0) {
                                        $tax = $wpdb->get_var($wpdb->prepare("SELECT `tax_rate` FROM `{$wpdb->base_prefix}woocommerce_tax_rates` WHERE `tax_rate_id` = %d;",$tax_rate_id));
                                        $tax = (int)round($tax);
                                        if ($tax==20) $shp_tax_id=1;
                                        if ($tax==10) $shp_tax_id=2;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
                $allAmount+=$shp_price;

                if ($allAmount!=$order_total) {
                    $delta = $order_total-$allAmount;
                    if ($shp_price>0) { //if isset shipping service with pay - correct shipment price
                        $shp_price+=$delta;
                        if ($shipping) $shipping->set_total($shp_price/100);
                    } else {
                        $corrected=false;
                        for ($i=count($bskitm)-1;$i>=0;$i--) { //Is single item?
                            if ($bskitm[$i]['Qty']==1000) {
                                $bskitm[$i]['Price']+=$delta;
                                $corrected=true;
                                break;
                            }
                        }
                        if (!$corrected) { //Get last prod_item and split it - create new single item
                            $newbskel = $bskitm[count($bskitm)-1];
                            $bskitm[count($bskitm)-1]['Qty']-=1000;
                            $newbskel['Qty']=1000;
                            $newbskel['Description'].=' '.__('1 piece','rsb-payment');
                            $newbskel['Price']+=$delta;
                            $bskitm[]=$newbskel;

                            $newbskel_raw = $bskitmraw[count($bskitmraw)-1];
                            $bskitmraw[count($bskitmraw)-1]['Qty']-=1000;
                            $newbskel_raw['parent_id']=$newbskel_raw['itm_id'];
                            $newbskel_raw['itm_id']='NewItem';
                            $newbskel_raw['Qty']=1000;
                            $newbskel_raw['Description'].=' '.__('1 piece','rsb-payment');
                            $newbskel_raw['Price']+=$delta;
                            $bskitmraw[]=$newbskel_raw;
                        }
                    }
                }
                //adding shipment in check if payment not free
                if ($shp_price>0) {
                    $bskitm[]=[
                        'Qty'=>1000,
                        'Price'=>$shp_price,
                        'PayAttribute'=>$this->settings['pay_attribute'],
                        'TaxId'=>$shp_tax_id,
                        'Description'=>__('Delivery','rsb-payment').': '.$shipping_name
                    ];
                    $bskitmraw[]=[
                        'itm_id'=>'Delivery',
                        'dlv_id'=>$shipping_id,
                        'Qty'=>1000,
                        'Price'=>$shp_price,
                        'PayAttribute'=>$this->settings['pay_attribute'],
                        'TaxId'=>$shp_tax_id,
                        'Description'=>__('Delivery','rsb-payment').': '.$shipping_name
                    ];
                }
                $q = array_merge($q,[
                    'basket'=>json_encode(['Lines'=>$bskitm],JSON_UNESCAPED_UNICODE),
                    'email'=>$order->get_billing_email(),
                    'Group'=>$this->settings['tcp_id']
                ]);
            }

            //Order History in admin page
            $order_info_str='';
            foreach ($order_items as $item) {
                $order_info_str.='<p><b>'.$item['name'].'</b> - '.$item['quantity'].' '.__('piece','rsb-payment').'.</p>';
            }

            try {
                //$controller = new \Ipol\RSBRequest\MainController($this->getReqParams()[0],$this->getReqParams()[1],$this->getReqParams()[2],$this->getReqParams()[3]);
                $controller = $this->getReqController();
                $response = $controller->newTransaction($q);
            } catch (\Exception $e) {
                wc_add_notice( __('An error occurred during the payment process. Contact the store administration','rsb-payment').'.', 'error' );
                $order->add_order_note( __('An error occurred during the payment process. No certificate files specified or address for submitting requests not specified','rsb-payment').'.' );
                return [
                    'result' => 'fail',
                    'redirect' => $this->get_return_url($order)
                ];
            }

            if (!$response->getTransID()) {
                wc_add_notice( __('An error occurred during the payment process. Contact the store administration','rsb-payment').'.', 'error' );
                $order->add_order_note( __('An error occurred during the payment process. Error in request, please check your settings (there is no TransID in the response)','rsb-payment').'.' );
                return [
                    'result' => 'fail',
                    'redirect' => $this->get_return_url($order)
                ];
            }
            $redirect_url = preg_replace("#\?.+#", "", $this->settings['url1']);
            $redirect_url.='?'.http_build_query([
                'trans_id'=>$response->getTransID(),
                'client_ip_addr'=>$this->client_ip_addr()
            ]);

            $now = new \DateTime();

            //if enchecks - create control transaction
            if ($this->isCheckMode()) {
                $this->deleteControlTransaction($order_id);
                $this->newTransaction([
                    'trans_id'=>$response->getTransID(),
                    'order_id'=>$order_id, //i
                    'trans_type'=>0,
                    'control'=>1,//Control
                    'dtm'=>$now->format('Y-m-d H:i:s'),
                    'check_raw'=>serialize($bskitmraw),
                    'trans_sum'=>$order_total
                ]);
            }
            //Add New Pay Transaction
            $this->newTransaction([
                'order_info'=>$order_info_str,
                'trans_id'=>$response->getTransID(),
                'order_id'=>$order_id, //i
                'result'=>$response->getRawString(),
                'trans_type'=>20, //SMS_NotFinished
                'control'=>0,
                'dtm'=>$now->format('Y-m-d H:i:s'),
                'status'=>$response->getTransStatus(),
                'check_raw'=>serialize($bskitmraw),
                'trans_sum'=>$order_total,
                'client_ip'=>$ipaddr,
                'ans_raw'=>$response->getResponseRawData()
            ]);
            return array(
                'result' => 'success',
                'redirect' => $redirect_url
            );
        }

        public function process_refund($order_id, $amount = null, $reason = '') {
            $order_id = (int)$order_id;
            $order = new WC_Order($order_id);
            if ($amount==null) {
                $order->add_order_note( __('Refund attempt: refund amount not transferred','rsb-payment').'.' );
                return false;
            }
            $amount_str = $amount;
            $amount=abs((int)($amount*100));
            $order_amount = (int)($order->get_total()*100);

            $trans = $this->getTransaction($order_id);
            if (!$trans) return false; //Trans not found

            $pay_trans=false;
            $refuse_trans=[];
            $last_date=\DateTime::createFromFormat('Y-m-d H:i:s','1950-01-01 00:00:00');
            foreach ($trans as $tr) {
                if ( ($tr->trans_type==0) || ($tr->trans_type==3) ) $pay_trans=$tr;
                if ( ($tr->trans_type==4) ) $refuse_trans[]=$tr;
                $created = \DateTime::createFromFormat('Y-m-d H:i:s',$tr->created);
                if ($created > $last_date) $last_date = $created;
            }
            if (!$pay_trans) {
                $order->add_order_note( __('Refund attempt: no paid (completed) bank transaction found','rsb-payment').'.' );
                return false;
            }

            $nowdate = new \DateTime();
            $time1 = $last_date->getTimestamp();
            $time2 = $nowdate->getTimestamp();
            if (($time2-$time1)<=30) return false;


            $d1 = \DateTime::createFromFormat('Y-m-d H:i:s',$pay_trans->dtm);
            $d2 = new \DateTime();
            $thisday=($d1->format('Y-m-d') == $d2->format('Y-m-d'));
            $new_type=4;

            $check_raw = unserialize($pay_trans->check_raw);
            $ref_wcheck = false;
            if (count($check_raw)>0) $ref_wcheck=true;

            $q=[
                'command' => "k",
                'trans_id' => $pay_trans->trans_id,
                'client_ip_addr' => $pay_trans->client_ip,
                'server_version'=>'2.0'
            ];
            $new_check=[];
            $new_checkraw=[];

            $full_refund=false;
            if ($amount==$order_amount) { //if order_amount = refund_amount - total simple refund
                $full_refund=true;
                if ($thisday) {
                    $q['command']='r';
                    $new_type=5;
                }
            } else { //part refund
                $q['amount']=$amount;
                if ($ref_wcheck) { //W check

                    //EnCheck?
                    if (($this->settings['en_check']=='no') && (strlen($this->settings['tcp_id'])<=3)) {
                        $order->add_order_note( __('Attempt to return: the order was placed with a check, but now checks are disabled or TCP_ID is not specified','rsb-payment').'.' );
                        return false;
                    }

                    //GetControl
                    $control = $this->getTransaction($pay_trans->trans_id,false,true);
                    $control=$control[0];
                    $controlcheck = unserialize($control->check_raw);

                    $order_refunds = $order->get_refunds();
                    $ref_amount=0; //
                    $shp_refund=0; //ShipmentRefundAmount
                    $refund_id=0;
                    foreach ($order_refunds as $or) {
                        if ($or->id>$refund_id) $refund_id=$or->id;
                    }
                    $check_txt=__('Amounts for returning goods','rsb-payment').':';
                    foreach ($order_refunds as $or) {
                        if ($or->id!=$refund_id) continue;
                        foreach ($or->get_items() as $ord_item) {
                            $itm_qty = abs($ord_item->get_quantity());
                            $tax_id = 0;
                            $pay_attribute=0;
                            $description='';
                            $itm_price_one=0;

                            $real_itm_id = intval($ord_item->get_meta('_refunded_item_id'));
                            foreach ($controlcheck as $itm) {
                                if ($itm['Qty']==0) { //Error!
                                    $order->add_order_note( __('Return attempt: product','rsb-payment').' '.$ord_item->get_name().' '.__('can no longer be returned (remainder = 0)','rsb-payment').'.' );
                                    return false;
                                }
                                if ( ($itm['Qty'] - ($itm_qty*1000))<0 ) { //Error!
                                    $order->add_order_note( __('Return attempt: product','rsb-payment').' '.$ord_item->get_name().' '.__('cannot be returned anymore (attempt to return more than the remainder)','rsb-payment').'.' );
                                    return false;
                                }
                                if ($real_itm_id==intval($itm['itm_id'])) {
                                    $tax_id=$itm['TaxId'];
                                    $pay_attribute=$itm['PayAttribute'];
                                    $description=$itm['Description'];
                                    $itm_price_one=$itm['Price'];
                                    break;
                                }
                            }
                            if ($itm_price_one==0) {
                                $order->add_order_note( __('Attempt to return: product return error','rsb-payment').' '.$ord_item->get_name().' '.__('the goods have not determined the value of the check','rsb-payment').'.' );
                                return false;
                            }
                            $new_check[]=[
                                'Qty'=>intval($itm_qty*1000),
                                'Price'=>$itm_price_one,
                                'PayAttribute'=>$pay_attribute,
                                'TaxId'=>$tax_id,
                                'Description'=>$description
                            ];
                            $new_checkraw[]=[
                                'itm_id'=>$real_itm_id,
                                'Qty'=>intval($itm_qty*1000),
                                'Price'=>$itm_price_one,
                                'PayAttribute'=>$pay_attribute,
                                'TaxId'=>$tax_id,
                                'Description'=>$description
                            ];
                            $check_txt.='<br/>'.$description.': '.(abs($itm_price_one)/100).' '.__('Rub/piece','rsb-payment');
                            $ref_amount+=abs(intval( $itm_price_one * $itm_qty ));
                        }
                        //Shipping...
                        $shp_refund = abs($or->get_shipping_total())+abs($or->get_shipping_tax());
                        if ($shp_refund>0) {
                            $shp_refund = intval($shp_refund*100);
                            foreach ($check_raw as $itm) {
                                //if ($itm['prod_id']=='Delivery') {
                                if ($itm['itm_id']=='Delivery') {
                                    if ($shp_refund==$itm['Price']) {
                                        //Add Shipping to Refund
                                        $new_check[]=[
                                            'Qty'=>$itm['Qty'],
                                            'Price'=>$itm['Price'],
                                            'PayAttribute'=>$itm['PayAttribute'],
                                            'TaxId'=>$itm['TaxId'],
                                            'Description'=>$itm['Description']
                                        ];
                                        $new_checkraw[]=[
                                            'itm_id'=>$itm['itm_id'],
                                            'dlv_id'=>$itm['dlv_id'],
                                            'Qty'=>$itm['Qty'],
                                            'Price'=>$itm['Price'],
                                            'PayAttribute'=>$itm['PayAttribute'],
                                            'TaxId'=>$itm['TaxId'],
                                            'Description'=>$itm['Description']
                                        ];
                                        $check_txt.='<br/>'.$itm['Description'].': '.($shp_refund/100).' '.__('Rub','rsb-payment');
                                        $ref_amount+=$shp_refund;
                                    } else {
                                        $shp_refund = $itm['Price'];
                                        $check_txt.='<br/>'.$itm['Description'].': '.($itm['Price']/100).' '.__('Rub','rsb-payment');
                                    }
                                }
                            }
                        }
                    }
                    if ($ref_amount!=$amount) {
                        $delta = abs($amount - $ref_amount - $shp_refund);
                        $order->add_order_note( __('Refund attempt: Refund and settlement amounts for the check differ by','rsb-payment').' '.$delta.' '.__('pennies. Amount correction needed','rsb-payment').'.' );
                        $order->add_order_note($check_txt);
                        return false;
                    }

                    //update_control_check
                    foreach ($new_checkraw as $newch) {
                        foreach ($controlcheck as &$cntl) {
                            if ($newch['itm_id']==$cntl['itm_id']) $cntl['Qty']-=$newch['Qty'];
                        }
                        unset($cntl);
                    }

                    $q['description']='return for order N '.$order_id;
                    $q['email']=$order->get_billing_email();
                    $q['basket']=json_encode(['Lines'=>$new_check],JSON_UNESCAPED_UNICODE);
                    $q['Group']=$this->settings['tcp_id'];
                }
            }

            //$controller = new \Ipol\RSBRequest\MainController($this->getReqParams()[0],$this->getReqParams()[1],$this->getReqParams()[2],$this->getReqParams()[3]);
            $controller = $this->getReqController();
            $response = $controller->refundTransaction($q);
            if ($response->isOk()) {
                $result=false;
                if ($response->getRefundTransID()) { //New Transaction
                    $new_trans=[
                        'order_id'=>$order_id,
                        'trans_id'=>$response->getRefundTransID(),
                        'dtm'=>Date('Y-m-d H:i:s'),
                        'status'=>$response->getCurrentStatus(),
                        'control'=>0,
                        'check_raw'=>serialize($new_checkraw),
                        'trans_sum'=>$amount,
                        'client_ip'=>$pay_trans->client_ip,
                        'trans_type'=>$new_type,
                        'result'=>$response->getRawString(),
                        'ans_raw'=>$response->getResponseRawData()
                    ];
                    $result = $this->newTransaction($new_trans);
                } else { //Update Transaction
                    $upd_trans = [
                        'trans_type'=>$new_type,
                        'status'=>'Cancel',
                        'ans_raw'=>$response->getResponseRawData()
                    ];
                    $result = $this->updateTransaction($upd_trans,$pay_trans->trans_id);
                }
                if ($result) {
                    //if check - update control
                    if (($this->settings['en_check']=='yes') && (strlen($this->settings['tcp_id'])>3)) {
                        $this->updateTransaction(['check_raw'=>serialize($controlcheck)],$pay_trans->trans_id,true);
                    }
                    $order->add_order_note( __('Refund attempt: Refund','rsb-payment').' '.$amount_str.' '.__('RUB made','rsb-payment').'.' );
                    if ($full_refund) $order->update_status('refunded', __('Payment returned','rsb-payment'));
                    return true;
                } else {
                    $order->add_order_note( __('Refund attempt: Refund made for the amount','rsb-payment').' '.$amount_str.' '.__('RUB, however, saving or update the bank transaction record failed. Please check your bank account','rsb-payment').'.' );
                    if ($full_refund) $order->update_status('refunded', __('Payment returned','rsb-payment'));
                    return true;
                }
            }
            $order->add_order_note( __('Return attempt: Return attempt failed','rsb-payment').'.' );
            return false;
        }

        public function renewinfo($trans_id){
            $trans = $this->getTransaction($trans_id);
            if (!$trans) return false;
            if (count($trans)!=1) return false;
            $trans=$trans[0];
            $q=[
                'command'=>'c',
                'trans_id'=>$trans->trans_id,
                'client_ip_addr'=>$trans->client_ip,
                'server_version'=>'2.0'
            ];
            $trans_type = $trans->trans_type;
            $new_type = $trans_type;
            $nstatus='';
            $now = new \DateTime();

            //$controller = new \Ipol\RSBRequest\MainController($this->getReqParams()[0],$this->getReqParams()[1],$this->getReqParams()[2],$this->getReqParams()[3]);
            $controller = $this->getReqController();
            $response = $controller->updateTransaction($q);
            if ($response->isOk()) {
                $new_type = $response->getNewType($new_type);
                $q = [
                    'trans_type'=>$new_type,
                    'result'=>$response->getRawString(),
                    'dtm'=>$now->format('Y-m-d H:i:s'),
                    'ans_raw'=>$response->getResponseRawData()
                ];
                if ($response->getCurrentStatus()) {
                    $q['status']=$response->getCurrentStatus();
                    $nstatus=$response->getCurrentStatus();
                }
                $this->updateTransaction($q,$trans->trans_id);
            } else {
                if ($response->getCurrentStatus()) $nstatus=$response->getCurrentStatus();
                $this->updateTransaction([
                    'status'=>$nstatus,
                    'result'=>$response->getRawString(),
                    'dtm'=>$now->format('Y-m-d H:i:s'),
                    'ans_raw'=>$response->getResponseRawData()
                ],$trans->trans_id);
            }
            $type_str='';
            switch ($new_type) {
                case 0:
                    $type_str='SMS Finished';
                    break;
                case 1:
                    $type_str='DMS Not Finished';
                    break;
                case 2:
                    $type_str='Recurrent';
                    break;
                case 3:
                    $type_str='DMS Not Finished';
                    break;
                case 4:
                    $type_str='Refund';
                    break;
                case 5:
                    $type_str='Cancel';
                    break;
            }
            $resultstr = preg_replace('/\n/','<br>',$response->getRawString());
            return [
                'trans_type'=>$type_str,
                'status'=>$nstatus,
                'result'=>$resultstr,
                'isOk'=>$response->isOk(),
                'RESULT_PS'=>$response->getCurrentStatus(),
                'RESULT_CODE'=>$response->getCurrentCode()
            ];
        }
        public function fullrefund($trans_id) {
            $trans = $this->getTransaction($trans_id);
            if (!$trans) return false;
            if (count($trans)!=1) return false;
            $trans=$trans[0];

            $d1 = \DateTime::createFromFormat('Y-m-d H:i:s',$trans->dtm);
            $d2 = new \DateTime();
            $thisday=($d1->format('Y-m-d') == $d2->format('Y-m-d'));

            $q=[
                'command' => 'k',
                'trans_id' => $trans->trans_id,
                'client_ip_addr' => $trans->client_ip,
                'server_version'=>'2.0'
            ];
            $new_type=4;
            if ($thisday && ($trans->trans_type==0)) {
                $q['command']='r';
                $new_type=5;
            }

            $order_id=$trans->order_id;
            $order = new WC_Order($order_id);

            $checks = unserialize($trans->check_raw);
            $refcheck=[];
            $chamount = $trans->trans_sum;
            if ( (count($checks)>0) && ($this->settings['en_check']=='yes') && (strlen($this->settings['tcp_id'])>3) ) {
                $refunds = $this->isWasRefunds($trans->order_id);
                if ($refunds>0) { //FullRefund W check
                    $control = $this->getTransaction($trans_id,false,true);
                    $control=$control[0];
                    $control_check = unserialize($control->check_raw);
                    $chamount = 0;
                    foreach ($control_check as $ch) {
                        if ($ch['Qty']==0) continue;
                        $price = intval($ch['Price']*$ch['Qty']/1000);
                        $chamount+=$price;
                        $refcheck[]=[
                            'Qty'=>$ch['Qty'],
                            'Price'=>$ch['Price'],
                            'PayAttribute'=>$ch['PayAttribute'],
                            'TaxId'=>$ch['TaxId'],
                            'Description'=>$ch['Description']
                        ];
                    }
                    if (count($refcheck)>0) {
                        $q['command']='k';
                        $new_type=4;
                        $q['amount']=$chamount;
                        $q['basket']=json_encode(['Lines'=>$refcheck],JSON_UNESCAPED_UNICODE);
                        $q['email']=$order->get_billing_email();
                        $q['Group']=$this->settings['tcp_id'];
                    }
                }
            }

            //$controller = new \Ipol\RSBRequest\MainController($this->getReqParams()[0],$this->getReqParams()[1],$this->getReqParams()[2],$this->getReqParams()[3]);
            $controller = $this->getReqController();
            $response = $controller->refundTransaction($q);
            if ($response->isOk()) {
                $result=false;
                $amount_str=$trans->trans_sum/100;
                if ($response->getRefundTransID()) { //New Trans
                    $new_trans=[
                        'order_id'=>$order_id,
                        'trans_id'=>$response->getRefundTransID(),
                        'dtm'=>Date('Y-m-d H:i:s'),
                        'status'=>$response->getCurrentStatus(),
                        'control'=>0,
                        'check_raw'=>serialize($refcheck),
                        'trans_sum'=>$chamount,
                        'client_ip'=>$trans->client_ip,
                        'trans_type'=>$new_type,
                        'result'=>$response->getRawString(),
                        'ans_raw'=>$response->getResponseRawData()
                    ];
                    $result = $this->newTransaction($new_trans);
                } else { //upd transaction
                    $upd_trans = [
                        'trans_type'=>$new_type,
                        'status'=>'Refund',
                        'ans_raw'=>$response->getResponseRawData()
                    ];
                    $result = $this->updateTransaction($upd_trans,$trans->trans_id);
                }
                if ($result) {
                    $order->add_order_note( __('Refund attempt: Refund','rsb-payment').' '.$amount_str.' '.__('RUB made','rsb-payment').'.' );
                    $order->update_status('refunded', __('Payment returned','rsb-payment'));
                    return true;
                } else { //Error on BD saving?
                    $order->add_order_note( __('Refund attempt: Refund made for the amount','rsb-payment').' '.$amount_str.' '.__('RUB, however, saving or update the bank transaction record failed. Please check your bank account','rsb-payment').'.' );
                    $order->update_status('refunded', __('Payment returned','rsb-payment'));
                    return true;
                }
            }
            $order->add_order_note( __('Return attempt: Return attempt failed','rsb-payment').'.' );
            return false;
        }

        private function toLog($logtype='',$logmes='') {
            if ($this->settings['enlogs']!='yes') return;
            if ($logmes=='') return;
            file_put_contents(
                plugin_dir_url(__FILE__).'log/mainlog.txt',
                Date('Y-m-d H:i:s').(($logtype!='')?' -- ('.$logtype.')':'')."\r\n\r\n".$logmes."\r\n\r\n______________\r\n\r\n",
                FILE_APPEND
            );
        }

        public static function renderAdminPage() {
            wp_enqueue_style('rsb-admin',plugin_dir_url( __FILE__ ).'assets/css/rsb-admin.css',[],'1.0');
            $htmlclasses=[
                'h1'=>[],
                'link'=>[ 'href'=>[],'type'=>[],'rel'=>[] ],
                'script'=>[ 'type'=>[],'src'=>[] ],
                'div'=>[ 'class'=>[], ],
                'table'=>[ 'class'=>[] ],
                'th'=>[],
                'tr'=>[ 'class'=>[] ],
                'td'=>[ 'class'=>[] ],
                'a'=>[ 'href'=>[], 'class'=>[],'tid'=>[] ],
                'strong'=>[],
                'br'=>[],
                'span'=>[ 'class'=>[] ]
            ];
            $page = '
                <div class="wrap">
                <h1>'.__('Payment module - Russian Standard Bank','rsb-payment').'</h1>
                <table class="wp-list-table widefat fixed striped table-view-list pages">
                <tr>
                <td class="manage-column rsbcol1">'.__('Created on','rsb-payment').'</td>
                <td class="manage-column rsbcol2">'.__('Order ID','rsb-payment').'</td>
                <td class="manage-column rsbcol3">'.__('Order Info','rsb-payment').'</td>
                <td class="manage-column rsbcol4">'.__('Transaction ID','rsb-payment').'</td>
                <td class="manage-column rsbcol5">'.__('Result','rsb-payment').'</td>
                <td class="manage-column rsbcol6">'.__('Type','rsb-payment').'</td>
                <td class="manage-column rsbcol7">'.__('Date of last request','rsb-payment').'</td>
                <td class="manage-column rsbcol8">'.__('Status','rsb-payment').'</td>
                <td class="manage-column rsbcol9">'.__('Generated check','rsb-payment').'</td>
                <td class="manage-column rsbcol10">'.__('Amount','rsb-payment').'</td>
                </tr>';

            $pay_module = new RSBPayment();
            $page .= $pay_module->get_admin_page();

            $page.='</table></div>';
            echo wp_kses($page,$htmlclasses);
            wp_enqueue_script( 'rsb-admin', plugin_dir_url( __FILE__ ) . 'assets/js/rsb-admin.js', [], '1.0' );
        }

        public function get_admin_page() {
            global $wpdb;
            $page='';
            $rows = $wpdb->get_results("SELECT * FROM `{$wpdb->base_prefix}rsb_transactions` WHERE `control`<>1 ORDER BY `order_id` DESC;");
            foreach ($rows as $row) {
                $trans_type = $row->trans_type;
                $typestr='';
                switch ($trans_type) {
                    case 0:
                        $typestr='SMS';
                        break;
                    case 1:
                        $typestr='DMS NOT_FINISHED';
                        break;
                    case 2:
                        $typestr='Recurrent';
                        break;
                    case 3:
                        $typestr='DMS Finished';
                        break;
                    case 4:
                        $typestr='Refund';
                        break;
                    case 5:
                        $typestr='Cancelled';
                        break;
                    case 20:
                        $typestr='SMS - Not Finished';
                        break;
                }

                $created = \DateTime::createFromFormat('Y-m-d H:i:s',$row->created);
                $dtm = \DateTime::createFromFormat('Y-m-d H:i:s',$row->dtm);
                $amount = number_format(floatval($row->trans_sum)/100,2);

                $resultstr = preg_replace('/\n/','<br>',(string)$row->result);
                $check = unserialize($row->check_raw);
                $check_str = '<table><tr><th>Description</th><th>Price</th><th>Qty</th><th>P_A</th><th>TaxId</th></tr>';
                foreach ($check as $ch) {
                    $check_str.='<tr><td>'.$ch['Description'].'</td><td>'.$ch['Price'].'</td><td>'.$ch['Qty'].'</td><td>'.$ch['PayAttribute'].'</td><td>'.$ch['TaxId'].'</td></tr>';
                }
                $check_str.='</table>';

                $page.='<tr class="iedit author-self level-0 type-page status-publish hentry">
                <td class="rsbcol1">'.$created->format('d.m.Y H:i:s').'</td>
                <td class="rsbcol2"><a href="/wp-admin/post.php?post='.$row->order_id.'&action=edit">№ '.$row->order_id.'</a></td>
                <td class="rsbcol3">'.$row->order_info.'</td>
                <td class="has-row-actions rsbcol4">
                    <strong>'.$row->trans_id.'</strong>
                    <div class="row-actions">
                    <span class="inline hide-if-no-js"><a class="rsb_check_status" tid="'.$row->trans_id.'" href="#">'.__('Check status','rsb-payment').'</a> | </span>
                    '.( ( ($trans_type==0)||($trans_type==3) )?'<span class="inline trash hide-if-no-js"><a class="rsb_fullrefund" tid="'.$row->trans_id.'" href="#">'.__('Full refund','rsb-payment').'</a> | </span>':'' ).'
                    </div>
                </td>
                <td class="rsbcol5">'.$resultstr.'</td>
                <td class="rsbcol6">'.$typestr.'</td>
                <td class="rsbcol7">'.$dtm->format('d.m.Y H:i:s').'</td>
                <td class="rsbcol8">'.$row->status.'</td>
                <td class="rsbcol9">'.$check_str.'</td>
                <td class="rsbcol10">'.$amount.' '.__('Rub','rsb-payment').'.</td>
                
                </tr>';
            }
            return $page;
        }

        public static function ajaxRenew() {
            header('Content-Type: application/json');
            if (!is_ajax()) {
                echo json_encode(['ans'=>false]);
                wp_die();
            }
            if (!isset($_POST['t_id'])) {
                echo json_encode(['ans'=>false]);
                wp_die();
            }
            $pay_module = new RSBPayment();
            echo json_encode( ['ans'=>$pay_module->renewinfo(sanitize_text_field($_POST['t_id']))] );
            wp_die();
        }
        public static function ajaxFullRefund() {
            header('Content-Type: application/json');
            if (!is_ajax()) {
                echo json_encode(['ans'=>false]);
                wp_die();
            }
            if (!isset($_POST['t_id'])) {
                echo json_encode(['ans'=>false]);
                wp_die();
            }
            $pay_module = new RSBPayment();
            echo json_encode( ['ans'=>$pay_module->fullrefund(sanitize_text_field($_POST['t_id']))] );
            wp_die();
        }

        public function isCheckMode() {
            return ( ($this->settings['en_check']=='yes')&&(strlen($this->settings['tcp_id'])>3) );
        }
        //WorkWBD

        //Delete CONTROL transaction before add new CONTROL
        private function deleteControlTransaction(int $order_id) {
            global $wpdb;
            $sql = $wpdb->prepare("DELETE FROM `".$wpdb->get_blog_prefix().$this->tablename."` WHERE `control`=1 AND `order_id` = %d;",$order_id);
            $wpdb->query($sql);
        }
        private function newTransaction(array $trans_data) {
            global $wpdb;
            $now = new \DateTime();
            $upd = ['created'=>$now->format('Y-m-d H:i:s')];
            $trans_data=array_merge($trans_data,$upd);
            $coltypes=[];
            foreach ($trans_data as $key=>$vl) if (($key=='order_id')||($key=='trans_type')||($key=='control')) $coltypes[]='%d'; else $coltypes[]='%s';
            return $wpdb->insert($wpdb->get_blog_prefix().$this->tablename,$trans_data,$coltypes);
        }

        public function getTransaction($trans_or_order_id,$pay_trans_only=false,$control_trans=false) {
            global $wpdb;
            $res=[];
            $sql='';
            if (gettype($trans_or_order_id)=='integer') {
                if ($control_trans) $sql = $wpdb->prepare("SELECT * from `".$wpdb->get_blog_prefix().$this->tablename."` WHERE `order_id` = %d AND `control` = 1 ORDER BY `id`;",$trans_or_order_id);
                else $sql = $wpdb->prepare("SELECT * from `".$wpdb->get_blog_prefix().$this->tablename."` WHERE `order_id` = %d AND `control`<> 1 ORDER BY `id`;",$trans_or_order_id);
                $res = $wpdb->get_results($sql);
            }
            if (gettype($trans_or_order_id)=='string') {
                if ($control_trans) $sql = $wpdb->prepare("SELECT * from `".$wpdb->get_blog_prefix().$this->tablename."` WHERE `trans_id` = %s AND `control` = 1 ORDER BY `id`;",$trans_or_order_id);
                else $sql = $wpdb->prepare("SELECT * from `".$wpdb->get_blog_prefix().$this->tablename."` WHERE `trans_id` = %s AND `control`<> 1 ORDER BY `id`;",$trans_or_order_id);
                $res = $wpdb->get_results($sql);
            }
            if (count($res)==0) return false;
            if ($pay_trans_only) {
                foreach ($res as $r) {
                    if ( ($r->trans_type==0) || ($r->trans_type==3) ) return [$r];
                }
            }
            return $res;
        }

        private function updateTransaction(array $updatedata,$trans_id,$control=false) {
            global $wpdb;
            $coltypes=[];
            foreach ($updatedata as $key=>$vl) if (($key=='order_id')||($key=='trans_type')||($key=='control')) $coltypes[]='%d'; else $coltypes[]='%s';
            $where=['control'=>0];
            if ($control) $where=['control'=>1];
            $where['trans_id']=$trans_id;
            return $wpdb->update($wpdb->get_blog_prefix().$this->tablename,$updatedata,$where,$coltypes,['%d','%s']);
        }

        private function isWasRefunds($order_id=0) {
            $order_id=intval($order_id);
            if ($order_id==0) return false;
            global $wpdb;
            $transes = $wpdb->get_results($wpdb->prepare("SELECT * FROM `".$wpdb->get_blog_prefix().$this->tablename."` WHERE `order_id` = %d AND `trans_type` = 4;",$order_id));
            return count($transes);
        }

        private function client_ip_addr($ip='') {
            if($this->check_ip($ip)) {
                $client_ip = $ip;
            } elseif(isset($_SERVER['REMOTE_ADDR'])) {
                $client_ip = sanitize_text_field($_SERVER['REMOTE_ADDR']);
            } else {
                $client_ip = "0.0.0.0";
            }
            return $client_ip;
        }

        private function check_ip($ip) {
            if(preg_match("#^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$#", $ip)) { return true; }
            return false;
        }

        private function resultcodes() {
            return [
                '100'=>__('Failure','rsb-payment'),
                '101'=>__('Failure: card expired','rsb-payment'),
                '116'=>__('Failure: insufficient funds','rsb-payment')
            ];
        }

        private function getReqController() {
            //$this->settings['url2']='http://soc.krdev.ru/?id=43';
            $logfile = plugin_dir_path(__FILE__).'log/mainlog.txt';
            if ($this->settings['enlogs']!='yes') $logfile='';
            add_action('http_api_curl', function($handle){
                curl_setopt($handle, CURLOPT_SSLKEY, plugin_dir_path(__FILE__).'crt/'.$this->settings['f_key']);
                curl_setopt($handle, CURLOPT_SSLCERT, plugin_dir_path(__FILE__).'crt/'.$this->settings['f_pem']);
                curl_setopt($handle, CURLOPT_CAINFO, plugin_dir_path(__FILE__).'crt/'.$this->settings['f_crt']);
                curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 0);
            }, 10, 1);
            return new \Ipol\RSBRequest\MainController($this->settings['url2'],$logfile);
        }


    }

}

