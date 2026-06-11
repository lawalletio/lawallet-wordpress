import { test, expect } from '@playwright/test';

async function loginAdmin(page) {
  await page.goto('http://localhost:8080/wp-login.php', { waitUntil: 'domcontentloaded' });
  const loginForm = page.locator('#loginform');
  if ((await loginForm.count()) === 0) {
    await expect(page).toHaveURL(/\/wp-admin\//);
    return;
  }

  await expect(loginForm).toBeVisible();
  await page.evaluate(() => {
    document.querySelector('#user_login').value = 'admin';
    document.querySelector('#user_pass').value = 'password';
  });
  await expect(page.locator('#user_login')).toHaveValue('admin');
  await expect(page.locator('#user_pass')).toHaveValue('password');
  await Promise.all([
    page.waitForURL(/\/wp-admin\//, { timeout: 15000 }),
    page.locator('#wp-submit').click(),
  ]);
}

test('customer pays a WooCommerce order through LUD-21 verified Lightning checkout', async ({ page, request }) => {
  await request.post('http://localhost:4000/test/reset');
  await page.addInitScript(() => {
    window.__weblnPayments = [];
    window.__weblnEnabled = false;
    window.webln = {
      enable: async () => {
        window.__weblnEnabled = true;
      },
      sendPayment: async (invoice) => {
        window.__weblnPayments.push(invoice);
        return { preimage: 'mock-preimage' };
      },
    };
  });

  const products = await (await request.get('http://localhost:8080/wp-json/wc/store/v1/products?slug=lightning-test-product')).json();
  expect(products.length).toBeGreaterThan(0);

  const discovery = await request.get('http://localhost:8080/.well-known/nostr.json?name=_', { maxRedirects: 0 });
  expect(discovery.status()).toBe(307);
  expect(discovery.headers().location).toBe('http://mock-lnurl:4000/.well-known/nostr.json?name=_');

  await page.goto(`http://localhost:8080/checkout/?add-to-cart=${products[0].id}`);

  await page.locator('#billing_first_name').fill('Ada');
  await page.locator('#billing_last_name').fill('Lovelace');
  await page.locator('#billing_address_1').fill('1 Lightning Way');
  await page.locator('#billing_city').fill('Buenos Aires');
  await page.locator('#billing_postcode').fill('1000');
  await page.locator('#billing_phone').fill('+541100000000');
  await page.locator('#billing_email').fill(`ada-${Date.now()}@example.com`);

  const gateway = page.locator('#payment_method_wcll_gateway');
  if (await gateway.count()) {
    await gateway.check();
  }

  await page.locator('#place_order').click();
  await expect(page.locator('.wcll-payment')).toBeVisible({ timeout: 30000 });
  await expect(page).toHaveURL(/\/checkout\/order-pay\/\d+\/\?key=/);
  const paymentUrl = page.url();
  await expect(page.locator('[data-wcll-qr] img')).toBeVisible();
  await expect(page.locator('[data-wcll-status-text]')).toContainText(/Waiting for payment/i);

  const invoice = await page.locator('[data-wcll-invoice]').inputValue();
  expect(invoice).toMatch(/^lnbc/i);

  const webLnButton = page.getByRole('button', { name: 'Pay with WebLN' });
  await expect(webLnButton).toBeVisible();
  await webLnButton.click();
  await expect.poll(() => page.evaluate(() => window.__weblnPayments.length)).toBe(1);
  await expect.poll(() => page.evaluate(() => window.__weblnEnabled)).toBe(true);
  await expect.poll(() => page.evaluate(() => window.__weblnPayments[0])).toBe(invoice);

  const stateBefore = await (await request.get('http://localhost:4000/test/state')).json();
  expect(stateBefore.invoiceCount).toBeGreaterThan(0);
  expect(stateBefore.invoices.at(-1).hasZapRequest).toBe(true);

  await request.post('http://localhost:4000/test/pay-latest');

  await expect(page.locator('[data-wcll-status-text]')).toContainText(/Payment received/i, { timeout: 15000 });
  await expect(page.locator('[data-wcll-paid-overlay]')).toBeVisible();
  await page.waitForURL(/order-received/, { timeout: 15000 });
  await expect(page.locator('.wcll-payment')).toHaveCount(0);

  await page.goto(paymentUrl);
  await expect(page).toHaveURL(/order-received/, { timeout: 15000 });
  await expect(page.locator('.wcll-payment')).toHaveCount(0);
});

test('admin LaWallet connection flow waits until typing settles', async ({ page }) => {
  await loginAdmin(page);
  await page.goto('http://localhost:8080/wp-admin/options-general.php?page=lawallet-wordpress', { waitUntil: 'domcontentloaded' });

  const input = page.locator('#lawallet_gateway_endpoint');
  const status = page.locator('[data-lawallet-endpoint-status]');
  const paymentsHeading = page.getByRole('heading', { name: 'WooCommerce Lightning payments' });
  const discoveryCard = page.locator('.lawallet-card').filter({ has: page.getByRole('heading', { name: 'Lightning Address for your users' }) });
  const connectedStatus = discoveryCard.locator('.lawallet-status.ready');
  const instanceCard = discoveryCard.locator('[data-lawallet-instance-card]');
  const instanceName = discoveryCard.locator('[data-lawallet-instance-name]');
  const instanceMeta = discoveryCard.locator('[data-lawallet-instance-meta]');
  const instanceDetails = discoveryCard.locator('[data-lawallet-instance-details]');
  const instanceSocials = discoveryCard.locator('[data-lawallet-instance-socials]');
  await expect(paymentsHeading).toBeVisible();
  await expect(page.getByText('Option 1:')).toHaveCount(0);
  await expect(connectedStatus).toContainText('Connected');
  await expect(input).toHaveCount(0);
  await expect(instanceCard).toBeVisible();
  await expect(instanceName).toContainText('Mock LaWallet');
  await expect(instanceMeta).toContainText('mock-lnurl.local');

  await page.evaluate(() => {
    const button = document.querySelector('button[name="lawallet_disconnect_submit"]');
    button.form.addEventListener('submit', (event) => {
      event.preventDefault();
    }, { capture: true, once: true });
  });
  await page.getByRole('button', { name: 'Disconnect', exact: true }).click();
  const disconnectingButton = page.getByRole('button', { name: 'Disconnecting', exact: true });
  await expect(disconnectingButton).toBeVisible();
  await expect(disconnectingButton).toHaveClass(/is-loading/);

  await page.reload({ waitUntil: 'domcontentloaded' });
  let checkRequests = 0;
  page.on('request', (request) => {
    const postData = request.postData() || '';
    if (request.url().includes('/wp-admin/admin-ajax.php') && postData.includes('lawallet_check_gateway_endpoint')) {
      checkRequests += 1;
    }
  });

  let releaseEndpointCheck;
  let pausedEndpointChecks = 0;
  const endpointCheckPattern = '**/wp-admin/admin-ajax.php';
  const endpointCheckHandler = async (route) => {
    const postData = route.request().postData() || '';
    if (postData.includes('lawallet_check_gateway_endpoint')) {
      pausedEndpointChecks += 1;
      await new Promise((resolve) => {
        releaseEndpointCheck = resolve;
      });
    }
    await route.continue();
  };
  await page.route(endpointCheckPattern, endpointCheckHandler);

  await page.getByRole('button', { name: 'Disconnect', exact: true }).click();

  await expect(input).toBeVisible();
  await expect(status).toBeVisible();
  await expect(instanceCard).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Disconnect', exact: true })).toHaveCount(0);
  const connectButton = page.getByRole('button', { name: 'Connect', exact: true });
  await expect(status).toHaveClass(/is-loading/, { timeout: 10000 });
  await expect(connectButton).toBeDisabled();
  await expect.poll(() => pausedEndpointChecks).toBe(1);
  releaseEndpointCheck();

  await expect(status).toHaveClass(/is-ready/, { timeout: 10000 });
  await page.unroute(endpointCheckPattern, endpointCheckHandler);
  await expect.poll(() => checkRequests).toBe(1);
  await expect(connectButton).toBeEnabled();

  await page.evaluate(() => {
    const button = document.querySelector('button[name="lawallet_settings_submit"]');
    button.form.addEventListener('submit', (event) => {
      event.preventDefault();
    }, { capture: true, once: true });
  });
  await connectButton.click();
  const connectingButton = page.getByRole('button', { name: 'Connecting', exact: true });
  await expect(connectingButton).toBeVisible();
  await expect(input).toBeDisabled();

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(input).toBeVisible();
  await expect(status).toHaveClass(/is-ready/, { timeout: 10000 });
  await expect(connectButton).toBeEnabled();

  checkRequests = 0;
  await input.fill('');
  await expect(connectButton).toBeDisabled();
  await input.type('http://mock-lnurl:4000', { delay: 20 });

  await expect(status).toHaveClass(/is-ready/, { timeout: 10000 });
  await expect.poll(() => checkRequests).toBe(1);
  await expect(connectButton).toBeEnabled();
  await page.getByRole('button', { name: 'Connect', exact: true }).click();

  await expect(input).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Disconnect', exact: true })).toBeVisible();
  await expect(instanceCard).not.toHaveClass(/is-empty/);
  await expect(instanceName).toContainText('Mock LaWallet');
  await expect(instanceMeta).toContainText('mock-lnurl.local');
  await expect(instanceDetails).toContainText('Maintenance');
  await expect(instanceDetails).not.toContainText('Theme');
  await expect(instanceDetails).not.toContainText('Rounding');
  await expect(instanceSocials).toContainText('Website: mock-lnurl.local');
});
