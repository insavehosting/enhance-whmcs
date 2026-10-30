# Product welcome e-mail for Enhance hosting

This fork sends **no e-mail of its own**: no "Enhance Panel Access" template is created or sent, and
Enhance is not asked to mail a password-setting link on Create. The panel login goes into the product
welcome e-mail that WHMCS sends after a successful Create. On CreateAccount the module writes it to the
WHMCS service:

| Case | `{$service_username}` | `{$service_password}` |
|---|---|---|
| This order created the client's panel login | login e-mail | generated password |
| The client already had a panel login (second plan, re-order, login created outside WHMCS) | login e-mail | empty |
| Create retried after a failure, password stored by the first attempt | login e-mail | the stored password |
| Create retried after a failure before the password was stored | login e-mail | empty; Enhance mails the client a password-setting link |

`{$service_username}` is the Enhance login, the e-mail of the organisation owner. When the service has
no new password, the password WHMCS generated at order time is cleared, so the e-mail never shows a
password that does not work.

Set one **Product/Service** template as *Welcome Email* on every Enhance product and show the password
only when it exists. Example (replace `https://panel.example.com/` with your panel address and add a
language tab per client language):

Subject: `Your hosting for {$service_domain} is ready`

```html
<p>Hello {$client_name},</p>
<p>Your {$service_product_name} plan for <strong>{$service_domain}</strong> is active.</p>
{if $service_password}
<p><strong>Control panel:</strong> <a href="https://panel.example.com/">https://panel.example.com/</a><br /><strong>Login:</strong> {$service_username}<br /><strong>Password:</strong> {$service_password}</p>
<p>Please change the password after your first sign-in. This login works for all your hosting plans with us.</p>
{else}
<p><strong>Control panel:</strong> <a href="https://panel.example.com/">https://panel.example.com/</a><br /><strong>Login:</strong> {$service_username} — use your existing password. Forgot it? Reset it on the panel sign-in page.</p>
{/if}
<p>You can also open the panel without a password from your client area: <a href="{$whmcs_url}clientarea.php?action=services">My services</a> → this plan → log in to the control panel.</p>
<p><strong>Using your own domain?</strong> Point it to these nameservers at your registrar:<br />{$service_ns1}<br />{$service_ns2}</p>
<p>Need help? Open a ticket at <a href="{$whmcs_url}submitticket.php">{$whmcs_url}submitticket.php</a>.</p>
```

The "Send password reset link" button in the service's module commands asks Enhance to e-mail the
organisation owner a password-setting link; WHMCS sends nothing.
