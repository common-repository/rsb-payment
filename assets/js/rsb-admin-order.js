var intrvl = setInterval(function(){
    if (typeof(jQuery)!='undefined') {
        clearInterval(intrvl);
        jQuery(function(){
            if (jQuery('#woocommerce-order-items').length>0) {
                jQuery('#order_line_items .item').each(function(){
                    var t=jQuery(this),ref=Number( t.find('td.quantity .view .refunded').html() ),max=Number( t.find('td.quantity .refund input').attr('max') );
                    if (isNaN(ref)) ref=0;
                    if (isNaN(max)) return;
                    t.find('td.quantity .refund input').attr('max',(max+ref));
                });
            }
        });
    }
},100);