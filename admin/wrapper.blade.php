@php
  $isServersIndex = request()->is('admin/servers') && !request()->is('admin/servers/*');
@endphp

@if($isServersIndex)
  <style>
    .prm-ad-btn { margin-left: 6px !important; border-radius: 3px !important; background: #00a65a !important; border-color: #008d4c !important; color: #fff !important; }
    .prm-ad-btn:hover { background: #008d4c !important; color: #fff !important; }
    .prm-ad-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.6); z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 20px; }
    .prm-ad-modal { width: min(720px, 100%); max-height: calc(100vh - 40px); display: flex; flex-direction: column; background: #1a2433; border: 1px solid #2c3b4c; border-radius: 6px; box-shadow: 0 18px 50px rgba(0,0,0,.5); color: #ecf0f5; }
    .prm-ad-modal__head { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px; border-bottom: 1px solid #2c3b4c; flex: 0 0 auto; }
    .prm-ad-modal__head h3 { margin: 0; font-size: 17px; font-weight: 600; }
    .prm-ad-modal__close { background: transparent; border: 0; color: #9ca3af; font-size: 22px; cursor: pointer; line-height: 1; }
    .prm-ad-modal__body { padding: 16px 18px; overflow: auto; flex: 1 1 auto; }
    .prm-ad-modal__foot { padding: 12px 18px 16px; display: flex; gap: 8px; justify-content: flex-end; border-top: 1px solid #2c3b4c; flex: 0 0 auto; }
    .prm-ad-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 14px; }
    .prm-ad-full { grid-column: 1 / -1; }
    .prm-ad-field label { display: block; font-size: 11px; font-weight: 700; color: #9ca3af; margin-bottom: 5px; text-transform: uppercase; letter-spacing: .04em; }
    .prm-ad-field input, .prm-ad-field select { width: 100%; height: 36px; border: 1px solid #3c4b5c; border-radius: 4px; background: #111827; color: #ecf0f5; padding: 0 10px; }
    .prm-ad-hint { margin: 14px 0 0; padding: 10px 12px; border-radius: 4px; background: #111827; border: 1px solid #2c3b4c; font-size: 13px; color: #cbd5e1; line-height: 1.45; }
    .prm-ad-hint strong { color: #f8fafc; }
    .prm-ad-log { margin-top: 14px; display: none; border: 1px solid #2c3b4c; border-radius: 4px; overflow: hidden; background: #0b1220; }
    .prm-ad-log.is-open { display: block; }
    .prm-ad-log__title { padding: 8px 12px; font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #94a3b8; border-bottom: 1px solid #1f2a3a; background: #0f172a; }
    .prm-ad-log__body { max-height: 280px; overflow: auto; padding: 10px 12px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; line-height: 1.55; }
    .prm-ad-line { margin: 0 0 6px; white-space: pre-wrap; word-break: break-word; }
    .prm-ad-line.is-ok { color: #4ade80; }
    .prm-ad-line.is-fail { color: #f87171; }
    .prm-ad-line.is-info { color: #93c5fd; }
    .prm-ad-line.is-muted { color: #94a3b8; }
    @media (max-width: 640px) { .prm-ad-grid { grid-template-columns: 1fr; } }
  </style>
  <script>
  (function () {
    var path = location.pathname.replace(/\/+$/, '');
    if (path !== '/admin/servers') return;

    function csrf() {
      var m = document.querySelector('meta[name="csrf-token"], meta[name="_token"]');
      return m ? m.getAttribute('content') : '';
    }
    function el(html) {
      var t = document.createElement('div');
      t.innerHTML = html.trim();
      return t.firstChild;
    }
    function injectButton() {
      var anchor = document.querySelector('a[href*="/admin/servers/new"]');
      if (!anchor || document.getElementById('prm-ad-open')) return false;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.id = 'prm-ad-open';
      btn.className = 'btn btn-sm prm-ad-btn';
      btn.textContent = 'Auto Deploy';
      anchor.insertAdjacentElement('afterend', btn);
      btn.addEventListener('click', openModal);
      return true;
    }

    var meta = null;

    function openModal() {
      if (document.getElementById('prm-ad-backdrop')) return;
      var backdrop = el(
        '<div class="prm-ad-modal-backdrop" id="prm-ad-backdrop">' +
          '<div class="prm-ad-modal" role="dialog" aria-modal="true">' +
            '<div class="prm-ad-modal__head"><h3>PRM Auto Deploy</h3><button type="button" class="prm-ad-modal__close" id="prm-ad-close">&times;</button></div>' +
            '<div class="prm-ad-modal__body">' +
              '<div class="prm-ad-grid">' +
                '<div class="prm-ad-field prm-ad-full"><label for="prm-ad-egg">Egg</label><select id="prm-ad-egg"></select></div>' +
                '<div class="prm-ad-field"><label for="prm-ad-count">Adet</label><input id="prm-ad-count" type="number" min="1" max="50" value="1"></div>' +
                '<div class="prm-ad-field"><label for="prm-ad-prefix">İsim öneki</label><input id="prm-ad-prefix" type="text" value="cs2" maxlength="40"></div>' +
                '<div class="prm-ad-field"><label for="prm-ad-port">Başlangıç portu</label><input id="prm-ad-port" type="number" min="1024" max="65534" placeholder="otomatik"></div>' +
                '<div class="prm-ad-field"><label for="prm-ad-map">Map</label><input id="prm-ad-map" type="text" value="de_dust2"></div>' +
                '<div class="prm-ad-field"><label for="prm-ad-players">Max players</label><input id="prm-ad-players" type="number" min="1" max="128" value="10"></div>' +
                '<div class="prm-ad-field"><label for="prm-ad-node">Node</label><select id="prm-ad-node"></select></div>' +
                '<div class="prm-ad-field"><label for="prm-ad-owner">Owner</label><select id="prm-ad-owner"></select></div>' +
                '<div class="prm-ad-field prm-ad-full"><label><input id="prm-ad-start" type="checkbox"> Oluşturunca start et</label></div>' +
              '</div>' +
              '<p class="prm-ad-hint" id="prm-ad-hint">Egg seçince donor bilgisi burada görünür.</p>' +
              '<div class="prm-ad-log" id="prm-ad-log"><div class="prm-ad-log__title">Deploy log</div><div class="prm-ad-log__body" id="prm-ad-log-body"></div></div>' +
            '</div>' +
            '<div class="prm-ad-modal__foot">' +
              '<button type="button" class="btn btn-default" id="prm-ad-cancel">İptal</button>' +
              '<button type="button" class="btn btn-success" id="prm-ad-run">Deploy</button>' +
            '</div>' +
          '</div>' +
        '</div>'
      );
      document.body.appendChild(backdrop);
      function close() { backdrop.remove(); }
      document.getElementById('prm-ad-close').onclick = close;
      document.getElementById('prm-ad-cancel').onclick = close;
      backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });

      loadMeta().then(function () {
        fillSelects();
        updateHint();
        document.getElementById('prm-ad-egg').addEventListener('change', updateHint);
      }).catch(function (err) {
        document.getElementById('prm-ad-hint').textContent = 'Meta yüklenemedi: ' + err;
      });
      document.getElementById('prm-ad-run').onclick = runDeploy;
    }

    function loadMeta() {
      return fetch('/extensions/prmautodeploy/meta', {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      }).then(function (j) { meta = j; return j; });
    }

    function fillSelects() {
      var eggSel = document.getElementById('prm-ad-egg');
      var nodeSel = document.getElementById('prm-ad-node');
      var ownerSel = document.getElementById('prm-ad-owner');
      eggSel.innerHTML = '';
      (meta.eggs || []).forEach(function (egg) {
        var o = document.createElement('option');
        o.value = egg.id;
        o.textContent = egg.name + (egg.has_donor ? ' ✓ donor' : ' (donor yok)');
        o.disabled = !egg.has_donor;
        eggSel.appendChild(o);
      });
      nodeSel.innerHTML = '';
      (meta.nodes || []).forEach(function (n) {
        var o = document.createElement('option');
        o.value = n.id; o.textContent = n.name; nodeSel.appendChild(o);
      });
      ownerSel.innerHTML = '';
      (meta.owners || []).forEach(function (u) {
        var o = document.createElement('option');
        o.value = u.id; o.textContent = u.username + ' (#' + u.id + ')'; ownerSel.appendChild(o);
      });
    }

    function updateHint() {
      if (!meta) return;
      var id = Number(document.getElementById('prm-ad-egg').value);
      var egg = (meta.eggs || []).find(function (e) { return Number(e.id) === id; });
      var hint = document.getElementById('prm-ad-hint');
      if (!egg) { hint.textContent = 'Egg seç.'; return; }
      if (!egg.has_donor) {
        hint.innerHTML = '<strong>' + egg.name + '</strong> için donor yok. Önce bir sunucuyu normal kur.';
        return;
      }
      hint.innerHTML =
        'Donor: <strong>' + egg.donor.name + '</strong> (' + egg.donor.uuidShort + '). ' +
        'Oyun dosyaları hardlink; <code>cfg</code> + <code>addons</code> ayrı. Özel map → <code>maps/_server</code>.';
    }

    function logOpen() {
      document.getElementById('prm-ad-log').classList.add('is-open');
    }
    function logClear() {
      document.getElementById('prm-ad-log-body').innerHTML = '';
    }
    function logLine(text, kind) {
      var body = document.getElementById('prm-ad-log-body');
      var p = document.createElement('p');
      p.className = 'prm-ad-line' + (kind ? ' is-' + kind : '');
      p.textContent = text;
      body.appendChild(p);
      body.scrollTop = body.scrollHeight;
    }

    function runDeploy() {
      var runBtn = document.getElementById('prm-ad-run');
      logOpen(); logClear();
      logLine('Deploy başlıyor…', 'muted');
      runBtn.disabled = true;

      var body = {
        egg_id: Number(document.getElementById('prm-ad-egg').value),
        node_id: Number(document.getElementById('prm-ad-node').value),
        owner_id: Number(document.getElementById('prm-ad-owner').value),
        count: Number(document.getElementById('prm-ad-count').value) || 1,
        name_prefix: document.getElementById('prm-ad-prefix').value || 'srv',
        map: document.getElementById('prm-ad-map').value || null,
        maxplayers: Number(document.getElementById('prm-ad-players').value) || 10,
        start_after: document.getElementById('prm-ad-start').checked
      };
      var portVal = document.getElementById('prm-ad-port').value;
      if (portVal) body.start_port = Number(portVal);

      fetch('/extensions/prmautodeploy/deploy', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf(),
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(body)
      }).then(function (r) {
        return r.json().then(function (j) { return { ok: r.ok, body: j }; });
      }).then(function (res) {
        runBtn.disabled = false;
        if (!res.ok) {
          logLine('HATA: ' + (res.body.error || JSON.stringify(res.body)), 'fail');
          return;
        }
        logLine('Donor: ' + res.body.donor.name + ' (' + res.body.donor.uuidShort + ')', 'info');
        (res.body.created || []).forEach(function (s) {
          logLine('OK  ' + s.name + '  :' + s.port + '  /server/' + s.uuidShort, 'ok');
        });
        (res.body.errors || []).forEach(function (e) {
          logLine('FAIL  ' + e.name + '  :' + e.port, 'fail');
          logLine('      ' + e.error, 'fail');
        });
        var okCount = (res.body.created || []).length;
        var failCount = (res.body.errors || []).length;
        logLine('Bitti — ' + okCount + ' OK, ' + failCount + ' FAIL', okCount && !failCount ? 'ok' : 'muted');
        if (okCount) {
          logLine('Sayfa 2 sn içinde yenilenecek…', 'muted');
          setTimeout(function () { location.reload(); }, 2000);
        }
      }).catch(function (err) {
        runBtn.disabled = false;
        logLine('İstek hatası: ' + err, 'fail');
      });
    }

    if (!injectButton()) {
      var tries = 0;
      var t = setInterval(function () {
        tries++;
        if (injectButton() || tries > 20) clearInterval(t);
      }, 200);
    }
  })();
  </script>
@endif
