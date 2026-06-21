import crypto from 'node:crypto';
import http from 'node:http';
import express from 'express';
import { WebSocketServer } from 'ws';
import { finalizeEvent, getPublicKey } from 'nostr-tools/pure';
import { nip04 } from 'nostr-tools';

const app = express();
const port = Number(process.env.MOCK_LNURL_PORT || 4000);
// Fixed test key so the advertised pubkey and the signed zap receipts are consistent.
const nostrSecretKey = Uint8Array.from(Buffer.from('01'.repeat(32), 'hex'));
const nostrPubkey = getPublicKey(nostrSecretKey);
const invoices = new Map();
const sockets = new Map();

// NIP-47 (NWC) proxy wallets — a registry, so the mock can act as MANY wallets
// (the lncurl endpoint mints a fresh one per call). Each request is decrypted /
// signed with the wallet identified by the request's "p" tag.
const nwcWallets = new Map(); // pubkey -> { secretHex, secretKey, alive, lud16 }

function registerNwcWallet(secretHex, lud16) {
  const secretKey = Uint8Array.from(Buffer.from(secretHex, 'hex'));
  const pubkey = getPublicKey(secretKey);
  nwcWallets.set(pubkey, {
    secretHex,
    secretKey,
    alive: true,
    balanceMsat: 0,
    lud16: lud16 || `lncurl-${pubkey.slice(0, 8)}@mock-lnurl`
  });
  return pubkey;
}

// A fixed wallet (0x02) + client (0x03) kept for the static-connection tests.
const nwcSecretHex = '02'.repeat(32);
const nwcWalletPubkey = registerNwcWallet(nwcSecretHex);
const nwcClientSecretHex = '03'.repeat(32);
const nwcClientPubkey = getPublicKey(Uint8Array.from(Buffer.from(nwcClientSecretHex, 'hex')));
const nwcInvoices = new Map(); // payment_hash -> record
const forwards = []; // pay_invoice calls (the proxy forwarding to the merchant LA)

function nwcConnectionUri() {
  const relay = encodeURIComponent(`ws://mock-lnurl:${port}/nostr`);
  return `nostr+walletconnect://${nwcWalletPubkey}?relay=${relay}&secret=${nwcClientSecretHex}`;
}

app.use(express.json());

function invoiceFor(amountMsat) {
  const sats = Math.max(1, Math.ceil(Number(amountMsat) / 1000));
  const suffix = crypto.randomBytes(12).toString('hex');
  return `lnbc${sats}n1p${Date.now().toString(36)}${suffix}`;
}

function eventId(event) {
  const serialized = JSON.stringify([0, event.pubkey, event.created_at, event.kind, event.tags, event.content]);
  return crypto.createHash('sha256').update(serialized).digest('hex');
}

function receiptFor(record) {
  const zap = record.zapRequest || {};
  const tags = [
    ['p', nostrPubkey],
    ['bolt11', record.pr],
    ['description', JSON.stringify(zap)]
  ];

  if (zap.pubkey) {
    tags.push(['P', zap.pubkey]);
  }

  tags.push(['preimage', record.preimage]);

  return finalizeEvent(
    {
      kind: 9735,
      created_at: record.paidAt || Math.floor(Date.now() / 1000),
      tags,
      content: ''
    },
    nostrSecretKey
  );
}

function matchesFilter(event, filter) {
  if (Array.isArray(filter.kinds) && !filter.kinds.includes(event.kind)) {
    return false;
  }
  if (Array.isArray(filter.authors) && !filter.authors.includes(event.pubkey)) {
    return false;
  }
  if (filter.since && event.created_at < Number(filter.since)) {
    return false;
  }
  // Single-letter tag filters such as #e / #p.
  for (const [key, values] of Object.entries(filter)) {
    if (!key.startsWith('#') || !Array.isArray(values)) {
      continue;
    }
    const tagName = key.slice(1);
    const eventValues = (event.tags || []).filter((t) => t[0] === tagName).map((t) => t[1]);
    if (!values.some((v) => eventValues.includes(v))) {
      return false;
    }
  }
  return true;
}

function broadcast(event) {
  for (const [socket, subscriptions] of sockets.entries()) {
    if (socket.readyState !== socket.OPEN) {
      continue;
    }
    for (const sub of subscriptions) {
      if (matchesFilter(event, sub.filter)) {
        socket.send(JSON.stringify(['EVENT', sub.id, event]));
      }
    }
  }
}

function broadcastReceipt(record) {
  broadcast(receiptFor(record));
}

// ----- NIP-47 (NWC) proxy wallet -----

function makeNwcBolt11(amountMsat) {
  const sats = Math.max(1, Math.ceil(Number(amountMsat) / 1000));
  const suffix = crypto.randomBytes(12).toString('hex');
  return `lnbc${sats}n1pnwc${Date.now().toString(36)}${suffix}`;
}

function serializeNwcInvoice(record) {
  return {
    type: 'incoming',
    invoice: record.invoice,
    bolt11: record.invoice,
    description: record.description,
    payment_hash: record.payment_hash,
    amount: record.amount,
    fees_paid: 0,
    created_at: record.created_at,
    expires_at: record.expires_at,
    settled_at: record.settled_at,
    preimage: record.preimage,
    state: record.settled_at ? 'settled' : 'pending'
  };
}

function nwcResult(method, result) {
  return { result_type: method, error: null, result };
}

function nwcError(method, code, message) {
  return { result_type: method, error: { code, message }, result: null };
}

async function handleNwcRequest(socket, event) {
  if (!event || event.kind !== 23194 || typeof event.content !== 'string') {
    return;
  }

  // The target wallet is named in the request's "p" tag; the mock serves many.
  const pTag = (event.tags || []).find((t) => Array.isArray(t) && t[0] === 'p');
  const walletPubkey = pTag ? pTag[1] : nwcWalletPubkey;
  const wallet = nwcWallets.get(walletPubkey);
  if (!wallet || !wallet.alive) {
    return; // Unknown or killed wallet: stay silent so the client treats it as dead.
  }

  const clientPubkey = event.pubkey;
  let req;
  try {
    req = JSON.parse(await nip04.decrypt(wallet.secretHex, clientPubkey, event.content));
  } catch (error) {
    return; // Cannot decrypt (wrong key / NIP-44) — ignore.
  }

  const method = req.method;
  const params = req.params || {};
  let payload;

  switch (method) {
    case 'get_info':
      payload = nwcResult('get_info', {
        alias: 'Mock NWC Wallet',
        color: '#22c55e',
        pubkey: walletPubkey,
        network: 'regtest',
        block_height: 1,
        block_hash: '00'.repeat(32),
        methods: ['get_info', 'get_balance', 'make_invoice', 'lookup_invoice', 'pay_invoice'],
        notifications: ['payment_received']
      });
      break;

    case 'get_balance':
      payload = nwcResult('get_balance', { balance: wallet.balanceMsat });
      break;

    case 'make_invoice': {
      const amount = Number(params.amount || 0);
      const now = Math.floor(Date.now() / 1000);
      const record = {
        invoice: makeNwcBolt11(amount),
        payment_hash: crypto.randomBytes(32).toString('hex'),
        description: params.description || '',
        amount,
        created_at: now,
        expires_at: now + Number(params.expiry || 3600),
        settled_at: null,
        preimage: null,
        clientPubkey,
        walletPubkey
      };
      nwcInvoices.set(record.payment_hash, record);
      payload = nwcResult('make_invoice', serializeNwcInvoice(record));
      break;
    }

    case 'lookup_invoice': {
      let record = params.payment_hash ? nwcInvoices.get(params.payment_hash) : null;
      if (!record && params.invoice) {
        record = Array.from(nwcInvoices.values()).find((r) => r.invoice === params.invoice) || null;
      }
      payload = record
        ? nwcResult('lookup_invoice', serializeNwcInvoice(record))
        : nwcError('lookup_invoice', 'NOT_FOUND', 'Invoice not found');
      break;
    }

    case 'pay_invoice': {
      const bolt11 = String(params.invoice || '');
      // In tests the forward destination is this same mock (the merchant LA), so
      // settle the matching lnurl invoice and record the forward + its amount.
      const dest = Array.from(invoices.values()).find((r) => r.pr === bolt11);
      const preimage = crypto.randomBytes(32).toString('hex');
      const amount = dest ? dest.amount : (params.amount ? Number(params.amount) : null);
      forwards.push({ invoice: bolt11, amount, preimage, from: walletPubkey, at: Math.floor(Date.now() / 1000) });
      if (amount) {
        wallet.balanceMsat = Math.max(0, wallet.balanceMsat - Number(amount));
      }
      if (dest) {
        dest.settled = true;
        dest.preimage = preimage;
        dest.paidAt = Math.floor(Date.now() / 1000);
      }
      payload = nwcResult('pay_invoice', { preimage, fees_paid: 0 });
      break;
    }

    default:
      payload = nwcError(method || 'unknown', 'NOT_IMPLEMENTED', `Method ${method} not implemented`);
  }

  const content = await nip04.encrypt(wallet.secretHex, clientPubkey, JSON.stringify(payload));
  const response = finalizeEvent(
    {
      kind: 23195,
      created_at: Math.floor(Date.now() / 1000),
      tags: [['p', clientPubkey], ['e', event.id]],
      content
    },
    wallet.secretKey
  );
  broadcast(response);
}

app.get('/healthz', (req, res) => {
  res.json({ ok: true });
});

app.get('/api/settings', (req, res) => {
  res.json({
    domain: 'mock-lnurl.local',
    endpoint: `http://mock-lnurl:${port}`,
    subdomain: `http://mock-lnurl:${port}`,
    brand_theme: '#22c55e',
    brand_rounding: 'Medium',
    community_name: 'Mock LaWallet',
    logotype_url: 'https://dummyimage.com/420x120/ffffff/111827.png&text=Mock+LaWallet',
    isotypo_url: 'https://dummyimage.com/160x160/22c55e/ffffff.png&text=LW',
    maintenance_enabled: 'false',
    social_website: 'mock-lnurl.local',
    social_twitter: 'MockLaWallet',
    social_telegram: 'mocklawallet',
    social_discord: 'mocklawallet',
    social_nostr: 'mock@mock-lnurl.local',
    social_email: 'mock@example.com'
  });
});

app.get('/.well-known/lnurlp/:name', (req, res) => {
  const body = {
    tag: 'payRequest',
    callback: `http://mock-lnurl:${port}/lnurl/callback/${encodeURIComponent(req.params.name)}`,
    minSendable: 1000,
    maxSendable: 500000000000,
    metadata: JSON.stringify([['text/plain', `Mock Lightning Address ${req.params.name}`]]),
    allowsNostr: true,
    nostrPubkey
  };
  // "noverify" simulates an address with NEITHER LUD-21 NOR NIP-57, which forces
  // the NWC proxy settlement path. (The "nolud21" address still advertises
  // NIP-57, so it is insufficient to exercise NWC.)
  if (req.params.name === 'noverify') {
    delete body.allowsNostr;
    delete body.nostrPubkey;
  }
  res.json(body);
});

app.get('/.well-known/nostr.json', (req, res) => {
  const name = String(req.query.name || '_');
  res.json({
    names: {
      [name]: nostrPubkey
    },
    relays: {
      [nostrPubkey]: [`ws://localhost:${port}/nostr`]
    }
  });
});

app.get('/lnurl/callback/:name', (req, res) => {
  const amount = Number(req.query.amount || 0);
  if (!Number.isFinite(amount) || amount < 1000) {
    res.status(400).json({ status: 'ERROR', reason: 'Invalid amount' });
    return;
  }

  let zapRequest = null;
  if (typeof req.query.nostr === 'string') {
    try {
      zapRequest = JSON.parse(req.query.nostr);
    } catch (error) {
      res.status(400).json({ status: 'ERROR', reason: 'Invalid nostr event' });
      return;
    }
  }

  const id = crypto.randomUUID();
  const pr = invoiceFor(amount);
  const record = {
    id,
    amount,
    name: req.params.name,
    pr,
    verify: `http://mock-lnurl:${port}/lnurl/verify/${id}`,
    settled: false,
    preimage: null,
    zapRequest,
    createdAt: Math.floor(Date.now() / 1000)
  };
  invoices.set(id, record);

  const responseBody = {
    status: 'OK',
    routes: [],
    pr,
    verify: record.verify
  };
  // Test affordance: "nolud21" (NIP-57 only) and "noverify" (neither LUD-21 nor
  // NIP-57) both omit the LUD-21 verify URL.
  if (req.params.name === 'nolud21' || req.params.name === 'noverify') {
    delete responseBody.verify;
  }
  res.json(responseBody);
});

app.get('/lnurl/verify/:id', (req, res) => {
  const record = invoices.get(req.params.id);
  if (!record) {
    res.status(404).json({ status: 'ERROR', reason: 'Not found' });
    return;
  }

  res.json({
    status: 'OK',
    settled: record.settled,
    preimage: record.preimage,
    pr: record.pr
  });
});

app.all('/test/pay-latest', (req, res) => {
  const record = Array.from(invoices.values()).at(-1);
  if (!record) {
    res.status(404).json({ ok: false, error: 'No invoices' });
    return;
  }

  record.settled = true;
  record.preimage = crypto.randomBytes(32).toString('hex');
  record.paidAt = Math.floor(Date.now() / 1000);
  invoices.set(record.id, record);
  broadcastReceipt(record);
  res.json({ ok: true, invoice: record.pr, verify: record.verify, id: record.id });
});

// Mint a fresh disposable wallet and return its NWC connection string as plain
// text — mirrors lncurl.lol's `POST https://lncurl.lol`.
app.post('/lncurl', (req, res) => {
  const secretHex = crypto.randomBytes(32).toString('hex');
  const pubkey = registerNwcWallet(secretHex);
  const relay = `ws://mock-lnurl:${port}/nostr`;
  const uri = `nostr+walletconnect://${pubkey}?relay=${relay}&secret=${secretHex}&lud16=${nwcWallets.get(pubkey).lud16}`;
  res.type('text/plain').send(uri);
});

// Simulate an lncurl wallet dying: it stops answering NWC requests. Targets the
// given pubkey, or the most recently minted wallet.
app.all('/test/kill-nwc', (req, res) => {
  const target = String(req.query.pubkey || '');
  let pubkey = target;
  if (!pubkey) {
    const keys = Array.from(nwcWallets.keys());
    pubkey = keys[keys.length - 1];
  }
  const wallet = nwcWallets.get(pubkey);
  if (!wallet) {
    res.status(404).json({ ok: false, error: 'Unknown wallet' });
    return;
  }
  wallet.alive = false;
  res.json({ ok: true, killed: pubkey });
});

app.all('/test/pay-nwc', async (req, res) => {
  const target = String(req.query.payment_hash || '');
  const record = target ? nwcInvoices.get(target) : Array.from(nwcInvoices.values()).at(-1);
  if (!record) {
    res.status(404).json({ ok: false, error: 'No NWC invoices' });
    return;
  }

  record.settled_at = Math.floor(Date.now() / 1000);
  record.preimage = crypto.randomBytes(32).toString('hex');
  nwcInvoices.set(record.payment_hash, record);

  // Credit the wallet that issued the invoice (its balance now backs the forward).
  const wallet = nwcWallets.get(record.walletPubkey) || nwcWallets.get(nwcWalletPubkey);
  if (wallet) {
    wallet.balanceMsat += Number(record.amount || 0);
  }

  // Emit a NIP-04 (kind 23196) payment_received notification so the browser
  // watcher fires; the backend still confirms via lookup_invoice.
  try {
    const note = JSON.stringify({
      notification_type: 'payment_received',
      notification: serializeNwcInvoice(record)
    });
    const content = await nip04.encrypt(wallet.secretHex, record.clientPubkey, note);
    const event = finalizeEvent(
      {
        kind: 23196,
        created_at: Math.floor(Date.now() / 1000),
        tags: [['p', record.clientPubkey]],
        content
      },
      wallet.secretKey
    );
    broadcast(event);
  } catch (error) {
    // Notification is best-effort; lookup_invoice remains the source of truth.
  }

  res.json({ ok: true, payment_hash: record.payment_hash, invoice: record.invoice });
});

app.get('/test/state', (req, res) => {
  res.json({
    nostrPubkey,
    nwcWalletPubkey,
    nwcClientPubkey,
    nwcUri: nwcConnectionUri(),
    invoiceCount: invoices.size,
    invoices: Array.from(invoices.values()).map((record) => ({
      id: record.id,
      pr: record.pr,
      settled: record.settled,
      amount: record.amount,
      hasZapRequest: Boolean(record.zapRequest)
    })),
    nwcInvoices: Array.from(nwcInvoices.values()).map((record) => ({
      payment_hash: record.payment_hash,
      invoice: record.invoice,
      amount: record.amount,
      settled: Boolean(record.settled_at),
      walletPubkey: record.walletPubkey
    })),
    nwcWallets: Array.from(nwcWallets.entries()).map(([pubkey, w]) => ({
      pubkey,
      alive: w.alive,
      balanceMsat: w.balanceMsat
    })),
    forwards
  });
});

app.all('/test/reset', (req, res) => {
  invoices.clear();
  nwcInvoices.clear();
  forwards.length = 0;
  // Drop minted disposable wallets; re-register only the fixed test wallet.
  nwcWallets.clear();
  registerNwcWallet(nwcSecretHex);
  res.json({ ok: true });
});

const server = http.createServer(app);
const wss = new WebSocketServer({ server, path: '/nostr' });

wss.on('connection', (socket) => {
  sockets.set(socket, []);

  socket.on('message', (raw) => {
    let message;
    try {
      message = JSON.parse(raw.toString());
    } catch (error) {
      return;
    }

    if (!Array.isArray(message)) {
      return;
    }

    // NWC client requests arrive as a published EVENT (kind 23194).
    if (message[0] === 'EVENT' && message[1] && typeof message[1] === 'object') {
      handleNwcRequest(socket, message[1]).catch(() => {});
      return;
    }

    if (message[0] === 'CLOSE') {
      const remaining = (sockets.get(socket) || []).filter((sub) => sub.id !== String(message[1]));
      sockets.set(socket, remaining);
      return;
    }

    if (message[0] !== 'REQ') {
      return;
    }

    const id = String(message[1] || 'sub');
    const filter = message[2] && typeof message[2] === 'object' ? message[2] : {};
    const subscriptions = sockets.get(socket) || [];
    subscriptions.push({ id, filter });
    sockets.set(socket, subscriptions);

    for (const record of invoices.values()) {
      if (record.settled) {
        const event = receiptFor(record);
        if (matchesFilter(event, filter)) {
          socket.send(JSON.stringify(['EVENT', id, event]));
        }
      }
    }
  });

  socket.on('close', () => {
    sockets.delete(socket);
  });
});

server.listen(port, '0.0.0.0', () => {
  console.log(`Mock LNURL/LUD-21 provider listening on ${port}`);
});
