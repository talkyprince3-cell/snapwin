async function submitWithdrawal(form) {
  const response = await fetch('/api/withdraw.php', {
    method: 'POST',
    headers: { 'Accept': 'application/json' },
    body: new FormData(form),
    credentials: 'same-origin'
  });

  const data = await response.json();
  if (!response.ok || !data.success) {
    throw new Error(data.message || 'Withdrawal failed.');
  }

  // These values must come from the server after it has deducted the withdrawal.
  WithdrawalNotification.show({
    amount: data.amount,
    currentBalance: data.new_balance,
    currency: data.currency || 'GHS',
    brandName: 'Your Website'
  });

  // Optional: update balances already visible on the page immediately.
  document.querySelectorAll('[data-current-balance], [data-available-balance]').forEach(function (element) {
    element.textContent = Number(data.new_balance).toFixed(2);
  });
}
