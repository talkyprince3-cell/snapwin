# Withdrawal notification component

This package contains a reusable in-website withdrawal notification with an iOS-style alert and drop-down animation. It includes the notification image and sound.

## Files

- `withdrawal-notification.css` — layout, safe-area positioning, and animations.
- `withdrawal-notification.js` — alert/banner behavior, sound, and currency formatting.
- `assets/ab-mobilemoney-light.png` — notification artwork.
- `assets/tone.mp3` — notification sound.
- `integration-example.js` — example for calling the component after a withdrawal.
- `server-response-example.php.txt` — non-executable PHP example showing the JSON response fields the frontend expects.
- `demo.html` — standalone preview using clearly marked demo values.

## Installation

1. Upload the whole folder to the website, for example `/assets/withdrawal-notification/`.
2. Add these tags to the withdrawal page:

```html
<link rel="stylesheet" href="/assets/withdrawal-notification/withdrawal-notification.css">
<script src="/assets/withdrawal-notification/withdrawal-notification.js"></script>
```

3. Configure the asset URL once:

```html
<script>
WithdrawalNotification.configure({
  assetBase: '/assets/withdrawal-notification/assets',
  brandName: 'Your Website'
});
</script>
```

4. Only after the server confirms a successful withdrawal and returns the new database balance, call:

```js
WithdrawalNotification.show({
  amount: data.amount,
  currentBalance: data.new_balance,
  currency: data.currency || 'GHS'
});
```

The backend must return JSON shaped like this:

```json
{
  "success": true,
  "amount": 50,
  "new_balance": 350,
  "currency": "GHS"
}
```

## Important integration rules

- Never calculate or invent the balance in JavaScript. `new_balance` must be read from the database after the withdrawal deduction succeeds.
- Never show the success notification before the server has completed the withdrawal.
- Protect the withdrawal endpoint with authentication, CSRF protection, server-side validation, and a database transaction.
- The browser may block audio unless the notification is triggered by a user action such as submitting the withdrawal form.
- Change `brandName` and the included image only if the website is authorized to use that branding.
