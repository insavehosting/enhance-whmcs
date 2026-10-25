# Product welcome e-mail for Enhance hosting

Since v1.0.2-insave.1 the module sends **no e-mail of its own**. On CreateAccount it writes the panel
login to the WHMCS service:

| Case | `{$service_username}` | `{$service_password}` |
|---|---|---|
| This order created the client's panel login | login e-mail | generated password |
| The client already had a panel login (second plan, re-order) | login e-mail | empty |

Set one **Product/Service** e-mail template as *Welcome Email* on every Enhance product and use
`{if $service_password}` to show the password only when it exists. The template
`Enhance Panel Access` (type General) from older versions is no longer used and can be deleted.

Paste the bodies below in WHMCS → Email Templates → template → language tab → Source code.
Default and English are the same.

## English (default + english)

Subject: `Your hosting for {$service_domain} is ready`

```html
<p>Hello {$client_name},</p>
<p>Your {$service_product_name} plan for <strong>{$service_domain}</strong> is active.</p>
{if $service_password}
<p><strong>Control panel:</strong> <a href="https://control.insave.hosting/">https://control.insave.hosting/</a><br /><strong>Login:</strong> {$service_username}<br /><strong>Password:</strong> {$service_password}</p>
<p>Please change the password after your first sign-in. This login works for all your hosting plans with us.</p>
{else}
<p><strong>Control panel:</strong> <a href="https://control.insave.hosting/">https://control.insave.hosting/</a><br /><strong>Login:</strong> {$service_username} — use your existing password. Forgot it? Reset it on the panel sign-in page.</p>
{/if}
<p>You can also open the panel without a password from your client area: <a href="{$whmcs_url}clientarea.php?action=services">My services</a> → this plan → Open Hosting Panel.</p>
<p><strong>Using your own domain?</strong> If its nameservers are not ours yet, set them at your registrar (not needed for free .insave.me subdomains):<br />{$service_ns1}<br />{$service_ns2}<br />DNS changes usually take a few hours, sometimes up to 48 hours.</p>
<p>Moving from another host? We transfer websites, databases and email for free — just open a ticket.</p>
<p>Need help? Open a ticket at <a href="{$whmcs_url}submitticket.php">{$whmcs_url}submitticket.php</a>. Share passwords and other credentials only in the ticket's protected fields, never in the message text.</p>
```

## Russian

Subject: `Хостинг для {$service_domain} готов`

```html
<p>Здравствуйте, {$client_name}!</p>
<p>Тариф {$service_product_name} для <strong>{$service_domain}</strong> активирован.</p>
{if $service_password}
<p><strong>Панель управления:</strong> <a href="https://control.insave.hosting/">https://control.insave.hosting/</a><br /><strong>Логин:</strong> {$service_username}<br /><strong>Пароль:</strong> {$service_password}</p>
<p>Смените пароль после первого входа. Этот логин действует для всех ваших хостинг-тарифов у нас.</p>
{else}
<p><strong>Панель управления:</strong> <a href="https://control.insave.hosting/">https://control.insave.hosting/</a><br /><strong>Логин:</strong> {$service_username} — используйте ваш текущий пароль. Забыли пароль — восстановите его на странице входа в панель.</p>
{/if}
<p>Открыть панель без пароля можно из личного кабинета: <a href="{$whmcs_url}clientarea.php?action=services">Мои услуги</a> → этот тариф → Open Hosting Panel.</p>
<p><strong>Свой домен?</strong> Если у домена ещё не стоят наши DNS-серверы, укажите их у регистратора (для бесплатных поддоменов .insave.me не нужно):<br />{$service_ns1}<br />{$service_ns2}<br />Изменения DNS обычно вступают в силу за несколько часов, иногда до 48 часов.</p>
<p>Переезжаете от другого хостинга? Бесплатно перенесём сайты, базы данных и почту — просто создайте тикет.</p>
<p>Нужна помощь? Создайте тикет: <a href="{$whmcs_url}submitticket.php">{$whmcs_url}submitticket.php</a>. Пароли и другие доступы передавайте только в защищённых полях формы тикета, а не в тексте сообщения.</p>
```

## Ukrainian

Subject: `Хостинг для {$service_domain} готовий`

```html
<p>Вітаємо, {$client_name}!</p>
<p>Тариф {$service_product_name} для <strong>{$service_domain}</strong> активовано.</p>
{if $service_password}
<p><strong>Панель керування:</strong> <a href="https://control.insave.hosting/">https://control.insave.hosting/</a><br /><strong>Логін:</strong> {$service_username}<br /><strong>Пароль:</strong> {$service_password}</p>
<p>Змініть пароль після першого входу. Цей логін діє для всіх ваших хостинг-тарифів у нас.</p>
{else}
<p><strong>Панель керування:</strong> <a href="https://control.insave.hosting/">https://control.insave.hosting/</a><br /><strong>Логін:</strong> {$service_username} — використовуйте ваш поточний пароль. Забули пароль — відновіть його на сторінці входу в панель.</p>
{/if}
<p>Відкрити панель без пароля можна з особистого кабінету: <a href="{$whmcs_url}clientarea.php?action=services">Мої послуги</a> → цей тариф → Open Hosting Panel.</p>
<p><strong>Власний домен?</strong> Якщо в домену ще не вказані наші DNS-сервери, пропишіть їх у реєстратора (для безкоштовних піддоменів .insave.me не потрібно):<br />{$service_ns1}<br />{$service_ns2}<br />Зміни DNS зазвичай набувають чинності за кілька годин, іноді до 48 годин.</p>
<p>Переїжджаєте від іншого хостингу? Безкоштовно перенесемо сайти, бази даних і пошту — просто створіть тікет.</p>
<p>Потрібна допомога? Створіть тікет: <a href="{$whmcs_url}submitticket.php">{$whmcs_url}submitticket.php</a>. Паролі та інші доступи передавайте лише в захищених полях форми тікета, а не в тексті повідомлення.</p>
```
