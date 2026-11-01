(function () {
    var DAYS = [
        { id: 'lun', label: 'Lunedì' },
        { id: 'mar', label: 'Martedì' },
        { id: 'mer', label: 'Mercoledì' },
        { id: 'gio', label: 'Giovedì' },
        { id: 'ven', label: 'Venerdì' }
    ];
    var ALERT_ICON = '<svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true"><path d="M12 3.2 22.2 20.5H1.8Z" fill="#f5b301" stroke="#9a3412" stroke-width="1.6" stroke-linejoin="round"/><rect x="11" y="9.3" width="2" height="5.2" rx="1" fill="#5a2d06"/><rect x="11" y="16" width="2" height="2" rx="1" fill="#5a2d06"/></svg>';

    function render(container, data, opts) {
        opts = opts || {};
        container.classList.add('tm-board');
        container.innerHTML = '';

        var board = document.createElement('div'); board.className = 'tm-board__grid';
        var legend = document.createElement('div'); legend.className = 'tm-board__legend';
        container.appendChild(board);
        container.appendChild(legend);

        if (!data.shifts || !data.shifts.length) {
            board.innerHTML = '<div class="tm-board__empty">Nessun orario inserito.</div>';
            return;
        }

        var toMin = function (s) { var p = String(s).split(':').map(Number); return p[0] * 60 + p[1]; };
        var fmtHour = function (min) { return String(min / 60); };
        var bands = (data.meta.bands || []).map(function (b) { return [toMin(b[0]), toMin(b[1])]; });
        var shifts = data.shifts.map(function (s) { return Object.assign({}, s, { doctor: String(s.doctor), s: toMin(s.start), e: toMin(s.end) }); });
        var dayStart = toMin(data.meta.dayStart), dayEnd = toMin(data.meta.dayEnd);
        shifts.forEach(function (s) {
            if (s.e <= s.s) return;
            dayStart = Math.min(dayStart, Math.floor(s.s / 60) * 60);
            dayEnd = Math.max(dayEnd, Math.ceil(s.e / 60) * 60);
        });
        var span = dayEnd - dayStart;
        var offBands = (function () {
            var sorted = bands.slice().sort(function (a, b) { return a[0] - b[0]; });
            var out = [], cursor = dayStart;
            sorted.forEach(function (b) {
                if (b[0] > cursor) out.push([cursor, Math.min(b[0], dayEnd)]);
                cursor = Math.max(cursor, b[1]);
            });
            if (cursor < dayEnd) out.push([cursor, dayEnd]);
            return out.filter(function (r) { return r[1] > r[0]; });
        })();
        var docById = Object.fromEntries(data.doctors.map(function (d) { return [String(d.id), d]; }));
        var locById = Object.fromEntries(data.locations.map(function (l) { return [l.id, l]; }));
        var pct = function (min) { return ((min - dayStart) / span) * 100; };

        function assignLanes(items) {
            var sorted = items.slice().sort(function (a, b) { return a.s - b.s || a.e - b.e; });
            var laneEnds = [];
            sorted.forEach(function (it) {
                var lane = laneEnds.findIndex(function (end) { return end <= it.s; });
                if (lane === -1) { lane = laneEnds.length; laneEnds.push(it.e); } else { laneEnds[lane] = it.e; }
                it.lane = lane;
            });
            return { items: sorted, lanes: laneEnds.length || 1 };
        }
        function coverageSegments(items) {
            var slots = [];
            for (var t = dayStart; t < dayEnd; t += 30) {
                var cls, inBand = bands.some(function (b) { return t >= b[0] && t < b[1]; });
                if (inBand) { var covered = items.some(function (it) { return it.s < t + 30 && it.e > t; }); cls = covered ? 'ok' : 'gap'; }
                else { cls = 'pause'; }
                slots.push({ t: t, cls: cls });
            }
            var segs = [];
            slots.forEach(function (slot) { var last = segs[segs.length - 1]; if (last && last.cls === slot.cls) last.end = slot.t + 30; else segs.push({ cls: slot.cls, start: slot.t, end: slot.t + 30 }); });
            return segs;
        }

        var perDay = DAYS.map(function (day) { var ds = shifts.filter(function (s) { return s.day === day.id; }); var al = assignLanes(ds); var segs = coverageSegments(al.items); return { day: day, items: al.items, lanes: al.lanes, segs: segs, hasGap: segs.some(function (s) { return s.cls === 'gap'; }) }; });
        var totalLanes = perDay.reduce(function (sum, d) { return sum + d.lanes; }, 0) || 1;

        var key = document.createElement('span'); key.className = 'tm-board__key';
        data.locations.forEach(function (l, index) {
            if (index > 0) key.appendChild(document.createTextNode(' · '));
            var short = document.createElement('b'); short.textContent = l.short; key.appendChild(short);
            key.appendChild(document.createTextNode(' ' + l.label));
            if (l.address) {
                var addr = document.createElement('span'); addr.className = 'tm-board__addr'; addr.textContent = ' - ' + l.address;
                key.appendChild(addr);
            }
        });
        legend.appendChild(key);

        function buildRuler() {
            board.appendChild(Object.assign(document.createElement('div'), { className: 'tm-board__rpad' }));
            var ruler = document.createElement('div'); ruler.className = 'tm-board__ruler';
            bands.forEach(function (b) { var bd = document.createElement('div'); bd.className = 'tm-board__band'; bd.style.left = pct(b[0]) + '%'; bd.style.width = (pct(b[1]) - pct(b[0])) + '%'; ruler.appendChild(bd); });
            for (var h = dayStart; h <= dayEnd; h += 60) { var tick = document.createElement('div'); tick.className = 'tm-board__tick'; tick.style.left = pct(h) + '%'; tick.textContent = fmtHour(h); ruler.appendChild(tick); }
            board.appendChild(ruler);
        }
        function buildDays(laneH) {
            var laneGap = 1, stripH = 11, padTop = 3, padBottom = stripH + 4;
            perDay.forEach(function (entry) {
                var label = document.createElement('div');
                label.className = 'tm-board__daylabel' + (entry.hasGap ? ' has-gap' : '');
                label.innerHTML = '<span class="tm-board__alert">' + (entry.hasGap ? ALERT_ICON : '') + '</span><span class="tm-board__dname">' + entry.day.label + '</span>';
                var track = document.createElement('div');
                track.className = 'tm-board__track' + (entry.hasGap ? ' has-gap' : '');
                track.style.height = padTop + entry.lanes * laneH + (entry.lanes - 1) * laneGap + padBottom + 'px';
                offBands.forEach(function (range) {
                    var pause = document.createElement('div'); pause.className = 'tm-board__pause'; pause.style.left = pct(range[0]) + '%'; pause.style.width = (pct(range[1]) - pct(range[0])) + '%'; track.appendChild(pause);
                });
                entry.items.forEach(function (it) {
                    var doc = docById[it.doctor]; if (!doc) return;
                    var loc = locById[it.loc] || { label: it.loc, short: (it.loc || '?').slice(0, 3).toUpperCase() };
                    var pill = document.createElement('div');
                    pill.className = 'tm-board__pill' + (it.booking === 'libero' ? ' is-libero' : '');
                    pill.style.setProperty('--c', doc.color);
                    pill.style.left = pct(it.s) + '%'; pill.style.width = (pct(it.e) - pct(it.s)) + '%'; pill.style.top = padTop + it.lane * (laneH + laneGap) + 'px';
                    pill.style.height = laneH + 'px'; pill.style.lineHeight = (laneH - 2) + 'px';
                    pill.title = doc.name + ' · ' + loc.label + ' · ' + it.start + '–' + it.end + (it.booking === 'libero' ? ' · entrata libera' : ' · su appuntamento');
                    pill.textContent = doc.name + ' ';
                    var locTag = document.createElement('span'); locTag.className = 'tm-board__loc'; locTag.textContent = loc.short;
                    pill.appendChild(locTag);
                    track.appendChild(pill);
                });
                var lines = document.createElement('div'); lines.className = 'tm-board__hlines';
                var hoursN = (dayEnd - dayStart) / 60;
                lines.style.background = 'repeating-linear-gradient(to right, transparent 0, transparent calc(100% / ' + hoursN + ' - 1px), rgba(31,35,40,.16) calc(100% / ' + hoursN + ' - 1px), rgba(31,35,40,.16) calc(100% / ' + hoursN + '))';
                track.appendChild(lines);
                var strip = document.createElement('div'); strip.className = 'tm-board__strip';
                entry.segs.forEach(function (seg) { var div = document.createElement('div'); div.className = 'tm-board__seg is-' + seg.cls; div.style.width = ((seg.end - seg.start) / span) * 100 + '%'; if (seg.cls === 'gap') div.textContent = fmtHour(seg.start) + '-' + fmtHour(seg.end) + ' N.C.'; strip.appendChild(div); });
                track.appendChild(strip);
                board.appendChild(label); board.appendChild(track);
            });
        }
        function renderBoard(laneH) { board.innerHTML = ''; buildRuler(); buildDays(laneH); }

        if (opts.fit === 'window') {
            var PRINT_PAGE_H = 748; // 198mm utili: A4 landscape 210mm - 2x6mm di margine @page
            var overheadHeight = function () { renderBoard(12); return container.scrollHeight - 12 * totalLanes; };
            var fit = function (target) { var overhead = overheadHeight(); var laneH = Math.max(9, Math.min(40, (target - overhead) / totalLanes)); container.style.setProperty('--pill-font', Math.max(9.5, Math.min(13, laneH * 0.66)) + 'px'); renderBoard(laneH); };
            var screenTarget = function () { return window.innerHeight - 90; };
            var headerHeight = function () {
                var header = document.querySelector('.sheet > header');
                if (!header) return 0;
                var cs = getComputedStyle(header);
                return header.getBoundingClientRect().height + (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0);
            };
            var fitPrint = function () {
                document.body.style.zoom = '';
                // in stampa .sheet ha padding 0: l'unico ingombro fuori dal board è l'header
                var outside = headerHeight();
                fit(PRINT_PAGE_H - outside - 6);
                var total = container.scrollHeight + outside;
                if (total > PRINT_PAGE_H) document.body.style.zoom = PRINT_PAGE_H / total;
            };
            fit(screenTarget());
            window.addEventListener('resize', function () { fit(screenTarget()); });
            window.addEventListener('beforeprint', fitPrint);
            window.addEventListener('afterprint', function () { document.body.style.zoom = ''; fit(screenTarget()); });
        } else {
            var laneH = opts.laneH || 16;
            container.style.setProperty('--pill-font', Math.max(9.5, Math.min(13, laneH * 0.66)) + 'px');
            renderBoard(laneH);
        }
    }

    window.TMBoard = { render: render };
})();
