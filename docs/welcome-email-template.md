# Product welcome e-mail for Enhance hosting

This fork sends **no e-mail of its own**: no "Enhance Panel Access" template is created or sent. A new
client gets two messages:

1. **Your product welcome e-mail**, sent by WHMCS after a successful Create: panel address and login.
2. **Enhance's password-setting e-mail** with a one-time link that expires, sent by the control panel
   when this module has just created the client's panel login.

The two come from different senders, so their order is not guaranteed; write the welcome e-mail so it
reads right either way.

No password is stored or e-mailed. On Create the module writes to the WHMCS service:

| Field | Value |
|---|---|
| `{$service_username}` | the Enhance login: the e-mail of the organisation owner |
| `{$service_password}` | empty — the password WHMCS generated at order time is cleared |

When the password-setting link is sent:

| Case | Link |
|---|---|
| This order created the client's panel login | yes, once |
| Create retried after a failure, login created by the first attempt | yes, once, after the retry succeeds |
| The client already had a panel login (second plan, re-order, login created outside WHMCS) | no |

If the link expires, the client uses "Forgot password" on the panel sign-in page; support can send a new
one with **Send password reset link** in the service's module commands. Clients can also open the panel
without a password from the client area (**Open Control Panel** on the service page).

Example (replace `https://panel.example.com/` with your panel address; add a language tab per client
language):

Subject: `Your hosting for {$service_domain} is ready`

```html
<p>Hello {$client_name},</p>
<p>Your {$service_product_name} plan for <strong>{$service_domain}</strong> is active.</p>
<p><strong>Control panel:</strong> <a href="https://panel.example.com/">https://panel.example.com/</a><br /><strong>Login:</strong> {$service_username}</p>
<p><strong>New to our control panel?</strong> A separate e-mail from the control panel brings a link to set your password. If the link has expired, use "Forgot password" on the panel sign-in page.<br /><strong>Already have an account with us?</strong> Use your current password: this login works for all your hosting plans.</p>
<p>You can also open the panel without a password from your client area: <a href="{$whmcs_url}clientarea.php?action=services">My services</a> → this plan → Open Control Panel.</p>
<p><strong>Using your own domain?</strong> Point it to these nameservers at your registrar:<br />{$service_ns1}<br />{$service_ns2}</p>
<p>Need help? Open a ticket at <a href="{$whmcs_url}submitticket.php">{$whmcs_url}submitticket.php</a>.</p>
```
