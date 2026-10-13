// Gateway USB qua trình duyệt: Web Serial ⇄ MQTT (WebSocket). Làm đúng việc của bridge/bridge.py.
//   gateway in ra dòng JSON  → ars/<room>/resp  (thêm "via":"ble")
//   ars/<room>/cmd/gw        → ghi nguyên dòng xuống gateway
//   bản giữ lại ars/<room>/state → gửi lại các lệnh trong "cmds" khi vừa nối USB
//   ars/<room>/gw/status     = {"online":…, "port":"webserial", "pkt_per_s":…} mỗi 5 s (Last Will = offline)
(function () {
  const app = document.getElementById('gwapp');
  const ROOM = +app.dataset.room;
  const $ = (id) => document.getElementById(id);
  const logEl = $('log');
  const log = (m) => {
    const t = new Date().toLocaleTimeString('vi-VN');
    logEl.textContent += `[${t}] ${m}\n`;
    if (logEl.textContent.length > 60000) logEl.textContent = logEl.textContent.slice(-40000);
    logEl.scrollTop = logEl.scrollHeight;
  };
  const badge = (id, text, ok) => { const e = $(id); e.textContent = text; e.className = 'gw ' + (ok ? 'on' : 'off'); };

  if (!window.isSecureContext) { $('need-secure').hidden = false; $('btn-connect').disabled = true; }
  if (!('serial' in navigator)) { $('need-chrome').hidden = false; $('btn-connect').disabled = true; }

  // ---------------------------------------------------------------- MQTT
  const wsUrl = (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + app.dataset.path;
  const statusTopic = `ars/${ROOM}/gw/status`;
  const cli = mqtt.connect(wsUrl, {
    username: app.dataset.user, password: app.dataset.pass,
    clientId: `ars-webgw-${ROOM}-${Math.random().toString(16).slice(2, 8)}`,
    reconnectPeriod: 2000, connectTimeout: 8000, clean: true,
    will: { topic: statusTopic, payload: JSON.stringify({ online: false, port: 'webserial' }), qos: 1, retain: true },
  });
  let wantReplay = false;
  cli.on('connect', () => {
    badge('st-mqtt', 'Máy chủ: đã nối', true); log('Đã nối MQTT ' + wsUrl);
    cli.subscribe([`ars/${ROOM}/cmd/gw`, `ars/${ROOM}/state`], { qos: 1 });
  });
  cli.on('reconnect', () => badge('st-mqtt', 'Máy chủ: đang nối lại…', false));
  cli.on('offline', () => badge('st-mqtt', 'Máy chủ: mất kết nối', false));
  cli.on('error', (e) => log('Lỗi MQTT: ' + e.message));
  cli.on('message', (topic, payload) => {
    const s = payload.toString();
    if (topic.endsWith('/cmd/gw')) writeLine(s);
    else if (topic.endsWith('/state') && wantReplay) {
      wantReplay = false;
      try { (JSON.parse(s).cmds || []).forEach(writeLine); log('Đã gửi lại trạng thái lớp cho gateway'); } catch (e) { /* bỏ qua */ }
    }
  });

  // ---------------------------------------------------------------- Web Serial
  let port = null, writer = null, reader = null, n = 0, bad = 0, stop = false;
  const enc = new TextEncoder();
  async function writeLine(line) {
    if (!writer) return;
    try { await writer.write(enc.encode(line.trim() + '\n')); } catch (e) { log('Ghi USB lỗi: ' + e.message); }
  }
  function onLine(line) {
    line = line.trim();
    if (!line) return;
    if (!line.startsWith('{')) { log('gateway: ' + line); return; }
    let pkt;
    try { pkt = JSON.parse(line); } catch (e) { bad++; return; }
    pkt.via = 'ble'; pkt.ts = Date.now() / 1000;
    const r = Number.isInteger(+pkt.room) ? +pkt.room : ROOM;
    cli.publish(`ars/${r}/resp`, JSON.stringify(pkt), { qos: 1 });
    n++;
  }
  async function readLoop() {
    const dec = new TextDecoderStream();
    const closed = port.readable.pipeTo(dec.writable).catch(() => {});
    reader = dec.readable.getReader();
    let buf = '';
    try {
      while (!stop) {
        const { value, done } = await reader.read();
        if (done) break;
        buf += value;
        let i;
        while ((i = buf.indexOf('\n')) >= 0) { onLine(buf.slice(0, i)); buf = buf.slice(i + 1); }
      }
    } catch (e) { log('Đọc USB dừng: ' + e.message); }
    try { reader.releaseLock(); } catch (e) { /* */ }
    await closed;
  }
  async function connect() {
    try {
      port = await navigator.serial.requestPort();
      await port.open({ baudRate: 115200 });
      // firmware gateway chỉ gửi gói khi cổng báo có người nghe (DTR), như pyserial của bridge.py
      try { await port.setSignals({ dataTerminalReady: true }); } catch (e) { /* trình duyệt cũ: bỏ qua */ }
    } catch (e) { log('Không mở được cổng: ' + e.message); port = null; return; }
    stop = false;
    writer = port.writable.getWriter();
    const info = port.getInfo ? port.getInfo() : {};
    log(`Đã mở cổng USB${info.usbVendorId ? ` (VID ${info.usbVendorId.toString(16)}, PID ${info.usbProductId.toString(16)})` : ''}`);
    badge('st-serial', 'USB: đã kết nối', true);
    $('btn-connect').disabled = true; $('btn-disconnect').disabled = false;
    wantReplay = true;                                          // lấy lại bản STATE giữ lại
    cli.unsubscribe(`ars/${ROOM}/state`, () => cli.subscribe(`ars/${ROOM}/state`, { qos: 1 }));
    await readLoop();
    await disconnect(true);
  }
  async function disconnect(fromLoop) {
    stop = true;
    if (!fromLoop && reader) { try { await reader.cancel(); } catch (e) { /* */ } }
    if (writer) { try { writer.releaseLock(); } catch (e) { /* */ } writer = null; }
    if (port) { try { await port.close(); } catch (e) { /* */ } port = null; log('Đã ngắt cổng USB'); }
    badge('st-serial', 'USB: chưa kết nối', false);
    $('btn-connect').disabled = !window.isSecureContext || !('serial' in navigator);
    $('btn-disconnect').disabled = true;
    report();
  }
  $('btn-connect').addEventListener('click', connect);
  $('btn-disconnect').addEventListener('click', () => disconnect(false));
  if ('serial' in navigator) navigator.serial.addEventListener('disconnect', () => { log('Gateway bị rút khỏi USB'); disconnect(false); });

  let wasOpen = false;
  function report() {
    const rate = Math.round(n / 5 * 10) / 10; n = 0;
    badge('st-rate', `${rate} gói/s`, rate > 0);
    const open = !!port, send = open || wasOpen; wasOpen = open;    // chỉ báo khi đang mở + một lần lúc vừa ngắt
    if (send && cli.connected) cli.publish(statusTopic, JSON.stringify({ online: !!port, port: 'webserial', pkt_per_s: rate, bad_lines: bad, ts: Date.now() / 1000 }), { qos: 1, retain: true });
  }
  setInterval(report, 5000);
  window.addEventListener('beforeunload', () => {
    if (cli.connected) cli.publish(statusTopic, JSON.stringify({ online: false, port: 'webserial' }), { qos: 1, retain: true });
  });
  badge('st-serial', 'USB: chưa kết nối', false);
})();
