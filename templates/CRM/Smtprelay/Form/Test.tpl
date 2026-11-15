{crmScope extensionKey='nl.onvergetelijk.smtprelay'}
<div class="crm-block crm-form-block">
  <p>{ts}Handshake with the relay: connect, authenticate, MAIL FROM and RCPT TO, then RSET. No message is sent (DATA is never issued).{/ts}</p>
  <p>{ts}Sender{/ts}: <code>{$from|escape}</code> &middot; {ts}Mode{/ts}: <strong>{$relayMode|escape}</strong></p>
  <table class="form-layout-compressed">
    <tr><td class="label">{$form.to.label}</td><td>{$form.to.html}</td></tr>
  </table>
  <div class="crm-submit-buttons">{include file="CRM/common/formButtons.tpl" location="bottom"}</div>
  {if $steps}
    <table class="display">
      <thead><tr><th>{ts}Step{/ts}</th><th>{ts}Result{/ts}</th><th>{ts}Server reply{/ts}</th></tr></thead>
      <tbody>
      {foreach from=$steps item=s}
        <tr>
          <td>{$s.step|escape}</td>
          <td>{if $s.ok}&#10004; {ts}ok{/ts}{else}&#10008; {ts}failed{/ts}{/if}</td>
          <td><code>{$s.reply|escape}</code></td>
        </tr>
      {/foreach}
      </tbody>
    </table>
    <div class="messages {if $ok}status{else}error{/if}">{if $ok}{ts}The relay accepts this sender and recipient.{/ts}{else}{ts}The relay refused; check the settings and the relay configuration.{/ts}{/if}</div>
  {/if}
  <p><a class="button" href="{$settingsUrl|escape}">{ts}Back to SMTP Relay settings{/ts}</a></p>
</div>
{/crmScope}
