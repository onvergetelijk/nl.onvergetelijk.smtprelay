<div class="crm-block crm-content-block">
  <p>{ts}Handshake with the relay: connect, authenticate, MAIL FROM and RCPT TO, then RSET. No message is sent (DATA is never issued).{/ts}</p>
  <p>{ts}Sender{/ts}: <code>{$from|escape}</code> &middot; {ts}Recipient{/ts}: <code>{$to|escape}</code> &middot; {ts}Mode{/ts}: <strong>{$relayMode|escape}</strong></p>
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
  <p><a class="button" href="{$settingsUrl}">{ts}Back to SMTP Relay settings{/ts}</a></p>
</div>
