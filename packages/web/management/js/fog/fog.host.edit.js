var LoginHistory = $('#login-history');
var LoginHistoryDate = $('#loghist-date');
var LoginHistoryData = new Array();
var Labels = new Array();
var LabelData = new Array();
var LoginData = new Array();
var LoginDateMin = new Array();
var LoginDateMax = new Array();
function UpdateLoginGraph() {
    url = location.href.replace('edit','hostlogins');
    $.post(url,{dte: LoginHistoryDate.val()},function(data) {UpdateLoginGraphPlot();});
}
function UpdateLoginGraphPlot(data) {
    if (data == null) return;
    j =0;
    for (i in data) {
        LoginDateMin = new Date(new Date(data[i].min * 1000).getTime() - new Date(data[i].min * 1000).getTimezoneOffset() * 60000);
        LoginDateMax = new Date(new Date(data[i].max * 1000).getTime() - new Date(data[i].max * 1000).getTimezoneOffset() * 60000);
        LoginTime = new Date(new Date(data[i].login * 1000).getTime() - new Date(data[i].login * 1000).getTimezoneOffset() * 60000);
        LogoutTime = new Date(new Date(data[i].logout * 1000).getTime() - new Date(data[i].logout * 1000).getTimezoneOffset() * 60000);
        if (typeof(Labels) == 'undefined') {
            Labels = new Array();
            LabelData[i] = new Array();
            LoginData[i] = new Array();
        }
        if ($.inArray(data[i].user,Labels) > -1) {
            LoginData[i] = [LoginTime,$.inArray(data[i].user,Labels)+1,LogoutTime,data[i].user];
        } else {
            Labels.push(data[i].user);
            LabelData[i] = [j+1,data[i].user];
            LoginData[i] = [LoginTime,++j,LogoutTime,data[i].user];
        }
    }
    LoginHistoryData = [{label: 'Logged In Time',data:LoginData}];
    var LoginHistoryOpts = {
        colors: ['rgb(0,120,0)'],
        series: {
            gantt: {
                active:true,
                show:true,
                barHeight:.2
            }
        },
        xaxis: {
            min: LoginDateMin,
            max: LoginDateMax,
            tickSize: [2,'hour'],
            mode: 'time'
        },
        yaxis: {
            min: 0,
            max: LabelData.length + 1,
            ticks: LabelData
        },
        grid: {
            hoverable: true,
            clickable: true
        },
        legend: {position: "nw"}
    };
    $.plot(LoginHistory, LoginHistoryData, LoginHistoryOpts);
}
$(function() {
    $('#resetSecData').val('Reset Encryption Data');
    $('#resetSecData').click(function() {
        $('#resetSecDataBox').html('Are you sure you wish to reset this hosts encryption data?');
        $('#resetSecDataBox').dialog({
            resizable: false,
            modal: true,
            title: 'Clear Encryption',
            buttons: {
                'Yes': function() {
                    $.post('../management/index.php',{sub: 'clearAES',id:$_GET.id});
                    $(this).dialog('close');
                },
                'No': function() {
                    $(this).dialog('close');
                }
            }
        });
    });
    UpdateLoginGraph();
    $('input:not(:hidden):checkbox[name="default"]').change(function() {
        $(this).each(function(e) {
            if (this.checked) this.checked = false;
            e.preventDefault();
        });
        this.checked = false;
    });
    $('.mac-manufactor').each(function() {
        input = $(this).parent().find('input');
        var mac = (input.size() ? input.val() : $(this).parent().find('.mac').html());
        $(this).load('../management/index.php?sub=getmacman&prefix='+mac);
    });
    removeMACField();
    MACUpdate();
    $('.add-mac').click(function(e) {
        $('#additionalMACsRow').show();
        $('#additionalMACsCell').append('<div><input class="additionalMAC" type="text" name="additionalMACs[]" />&nbsp;&nbsp;<i class="icon fa fa-minus-circle remove-mac hand" title="Remove MAC"></i><br/><span class="mac-manufactor"></span></div>');
        removeMACField();
        MACUpdate();
        HookTooltips();
        e.preventDefault();
    });
    if ($('.additionalMAC').size()) $('#additionalMACsRow').show();
    checkboxAssociations('.toggle-checkbox1:checkbox','.toggle-group1:checkbox');
    checkboxAssociations('.toggle-checkbox2:checkbox','.toggle-group2:checkbox');
    checkboxAssociations('#groupMeShow:checkbox','#groupNotInMe:checkbox');
    checkboxAssociations('#printerNotInHost:checkbox','#printerNotInHost:checkbox');
    checkboxAssociations('#snapinNotInHost:checkbox','#snapinNotInHost:checkbox');
    checkboxAssociations('.toggle-checkboxprint:checkbox','.toggle-print:checkbox');
    checkboxAssociations('.toggle-checkboxsnapin:checkbox','.toggle-snapin:checkbox');
    checkboxAssociations('#rempowerselectors:checkbox','.rempoweritems:checkbox');
    $('#groupMeShow:checkbox').change(function(e) {
        if ($(this).is(':checked')) $('#groupNotInMe').show();
        else $('#groupNotInMe').hide();
        e.preventDefault();
    });
    $('#groupMeShow:checkbox').trigger('change');
    $('#hostPrinterShow:checkbox').change(function(e) {
        if ($(this).is(':checked')) $('#printerNotInHost').show();
        else $('#printerNotInHost').hide();
        e.preventDefault();
    });
    $('#hostPrinterShow:checkbox').trigger('change');
    $('#hostSnapinShow:checkbox').change(function(e) {
        if ($(this).is(':checked')) $('#snapinNotInHost').show();
        else $('#snapinNotInHost').hide();
        e.preventDefault();
    });
    $('#hostSnapinShow:checkbox').trigger('change');
    result = true;
    $('#scheduleOnDemand').change(function() {
        if ($(this).is(':checked') === true) {
            $(this).parents('form').each(function() {
                $("input[name^='scheduleCron']",this).each(function() {
                    $(this).val('').prop('readonly',true).hide().parents('tr').hide();
                });
            });
        } else {
            $(this).parents('form').each(function() {
                $("input[name^='scheduleCron']",this).each(function() {
                    $(this).val('').prop('readonly',false).show().parents('tr').show();
                });
            });
        }
    });
    $("form.deploy-container").submit(function() {
        if ($('#scheduleOnDemand').is(':checked')) {
            $("p#cronOptions > input[name^='scheduleCron']",$(this)).each(function() {
                $(this).val('').prop('disabled',true);
            });
            return true;
        } else {
            $("p#cronOptions > input[name^='scheduleCron']",$(this)).each(function() {
                result = validateCronInputs($(this));
                if (result === false) return false;
            });
        }
        return result;
    }).each(function() {
        $("input[name^='scheduleCron']",this).each(function(id,value) {
            if (!validateCronInputs($(this))) $(this).addClass('error');
        }).blur(function() {
            if (!validateCronInputs($(this))) $(this).addClass('error');
        });
    });
});
