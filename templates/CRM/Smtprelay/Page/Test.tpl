{crmScope extensionKey='nl.onvergetelijk.smtprelay'}
<div class="crm-block crm-content-block">
  <p>{ts}Handshake with the relay: connect, authenticate, MAIL FROM and RCPT TO, then RSET. No message is sent (DATA is never issued).{/ts}</p>
  <form method="post" action="{$action|escape}">
    <input type="hidden" name="key" value="{$key|escape}" />
    <p>{ts}Sender{/ts}: <code>{$from|escape}</code> &middot; {ts}Mode{/ts}: <strong>{$relayMode|escape}</strong></p>
    <p>
      <label for="smtprelay-to">{ts}Recipient{/ts}</label>
      <input type="email" id="smtprelay-to" name="to" value="{$to|escape}" class="crm-form-text" size="40" required />
      <button type="submit" class="crm-button">{ts}Run test{/ts}</button>
    </p>
  </form>
  {if $error}<p><strong style="color:#c13c61">{$error|escape}</strong></p>{/if}
  {if $ran}
  <table class="display">
    <thead><tr><th>{ts}Step{/ts}</th><th>{ts}Result{/ts}</th><th>{ts}Server reply{/ts}</th></tr></thead>
    <tbody>
    {foreach from=$steps item=s}
      <tr>
        <td>{$s.step|escape}</td>
        <td>{if $s.ok}<span style="color:#28881e">&#10004; ok</span>{else}<span style="color:#c13c61">&#10008; {ts}failed{/ts}</span>{/if}</td>
        <td><code>{$s.reply|escape}</code></td>
      </tr>
    {/foreach}
    </tbody>
  </table>
  <p>{if $ok}<strong style="color:#28881e">{ts}The relay accepts this sender and recipient.{/ts}</strong>{else}<strong style="color:#c13c61">{ts}The relay refused; check the settings and the relay configuration.{/ts}</strong>{/if}</p>
  {/if}
  <p><a class="button" href="{$settingsUrl}">{ts}Back to SMTP Relay settings{/ts}</a></p>
</div>
{/crmScope}
