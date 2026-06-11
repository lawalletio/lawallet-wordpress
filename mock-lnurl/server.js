import crypto from 'node:crypto';
import http from 'node:http';
import express from 'express';
import { WebSocketServer } from 'ws';

const app = express();
const port = Number(process.env.MOCK_LNURL_PORT || 4000);
const nostrPubkey = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const invoices = new Map();
const sockets = new Map();

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

  const event = {
    pubkey: nostrPubkey,
    created_at: record.paidAt || Math.floor(Date.now() / 1000),
    kind: 9735,
    tags,
    content: ''
  };
  event.id = eventId(event);
  event.sig = '0'.repeat(128);
  return event;
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
  return true;
}

function broadcastReceipt(record) {
  const event = receiptFor(record);
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
  res.json({
    tag: 'payRequest',
    callback: `http://mock-lnurl:${port}/lnurl/callback/${encodeURIComponent(req.params.name)}`,
    minSendable: 1000,
    maxSendable: 500000000000,
    metadata: JSON.stringify([['text/plain', `Mock Lightning Address ${req.params.name}`]]),
    allowsNostr: true,
    nostrPubkey
  });
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

  res.json({
    status: 'OK',
    routes: [],
    pr,
    verify: record.verify
  });
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

app.get('/test/state', (req, res) => {
  res.json({
    nostrPubkey,
    invoiceCount: invoices.size,
    invoices: Array.from(invoices.values()).map((record) => ({
      id: record.id,
      pr: record.pr,
      settled: record.settled,
      amount: record.amount,
      hasZapRequest: Boolean(record.zapRequest)
    }))
  });
});

app.all('/test/reset', (req, res) => {
  invoices.clear();
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

    if (!Array.isArray(message) || message[0] !== 'REQ') {
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
