jQuery('.rsb_check_status').click(function(e){
var t=jQuery(this);
e.preventDefault();
jQuery.ajax({
    url: "/wp-admin/admin-ajax.php",
    data: {
        action: "rsb_renew_info",
        t_id: t.attr("tid")
    },
    method : "POST",
    success : function (data) {
        console.log(data);

        if (data.ans!=false) {
            t.parents("tr").find(".rsbcol6").html(data.ans.trans_type);
            t.parents("tr").find(".rsbcol8").html(data.ans.status);
            t.parents("tr").find(".rsbcol5").html(data.ans.result);
        }

    },
    error : function(error){ console.log(error) }
});
});
jQuery('.rsb_fullrefund').click(function(e){
var t=jQuery(this),conf=confirm("Вы действительно хотите вернуть платеж?\nОтменить эту операцию будет невозможно.");
e.preventDefault();
if (conf) {
    jQuery.ajax({
        url: "/wp-admin/admin-ajax.php",
        data: {
            action: "rsb_full_refund",
            t_id: t.attr("tid")
        },
        method : "POST",
        success : function (data) {
            if (data.ans!=false) {
                location.reload();
            } else {
                alert("В процессе возврата прозиошла ошибка.");
            }
        },
        error : function(error){ console.log(error) }
    });
}
});