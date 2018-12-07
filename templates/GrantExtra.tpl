<table class='financial-type-block'><tbody>
  <tr class="crm-grant-form-block-financial_type">
    <td class="label">{$form.financial_type_id.label}</td>
    <td>
      {if !$financialType}
        {capture assign=ftUrl}{crmURL p='civicrm/admin/financial/financialType' q="reset=1"}{/capture}
        {ts 1=$ftUrl}There is no Financial Type configured.<a href='%1'> Click here</a> if you want to configure financial type for your site.{/ts}
      {else}
        {$form.financial_type_id.html}
      {/if}
    </td>
  </tr>
</tbody></table>
<div class="crm-accordion-wrapper" id="payment-details">
  <div class="crm-accordion-header">{ts}Payment Details{/ts}</div><!-- /.crm-accordion-header -->
  <div class="crm-accordion-body" style="display: block;">
    <table class="form-layout-compressed">
    {foreach from=$paymentFields key=fieldName item=paymentField}
      {assign var='name' value=$fieldName}
     <tr class="crm-container {$name}-section">
        <td class="label">{$form.$name.label}</td>
        <td class="content">{$form.$name.html}</td>
      </tr>
    {/foreach}
     </table>
  </div><!-- /.crm-accordion-body -->
</div>

{literal}
<script type="text/javascript">
  CRM.$(function($) {
    $('.crm-grant-form-block-financial_type').insertAfter('.crm-grant-form-block-money_transfer_date');
    var statusChange = ($.inArray($("#status_id option:selected").text(), ['Paid', 'Approved for Payment', 'Withdrawn', 'Eligible']) > -1);

    $('.crm-grant-form-block-financial_type').toggle(statusChange);
    if (!statusChange) {
      $('#financial_type_id').val('');
    }

    $('#payment-details').toggle(($("#status_id option:selected").text() === 'Paid'));

    $('#status_id').on('change', function() {
      var statusChange = ($.inArray($("#status_id option:selected").text(), ['Paid', 'Approved for Payment', 'Withdrawn', 'Eligible']) > -1);
      $('.grant_rejected_reason_id').toggle(($("#status_id option:selected").text() == 'Ineligible'));
      $('.grant_incomplete_reason_id').toggle(($("#status_id option:selected").text() == 'Awaiting Information'));
      $('.crm-grant-form-block-financial_type').toggle(statusChange);
      if (!statusChange) {
        $('#financial_type_id').val('');
      }
      $('#payment-details').toggle(($("#status_id option:selected").text() === 'Paid'));
      if ($("#status_id option:selected").text() != 'Paid') {
        $('#contribution_batch_id').val('');
      }
    });

    $('#payment-details').insertBefore('#customData');

  });
</script>
{/literal}
