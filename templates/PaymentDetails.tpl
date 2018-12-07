{if $action eq 4}
<div class="crm-accordion-wrapper" id='grant-multifund-entries'>
    <div class="crm-accordion-header">
      {ts}Payment Details{/ts}
    </div><!-- /.crm-accordion-header -->
    <div class="crm-accordion-body">
      <table class="crm-info-panel">
        {foreach from=$financialFields key=attr item=label}
          <tr>
            <td class="label">{$label}</td>
            <td>{if $attr eq 'trxn_date'}{$financialValues.$attr|crmDate}{else}{$financialValues.$attr}{/if}</td>
          </tr>
        {/foreach}
      </table>
  </div>
</div>
{/if}
