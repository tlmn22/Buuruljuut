/* KPI2 статусын жагсаалтын компонентууд — "kpi2 template/components/bundle.js"-ээс.
   Өөрчлөлт: href-гүй (KPI үүсгээгүй) мөрийг холбоос биш энгийн мөрөөр зурна. */
(function () {
  "use strict";

  /* The five KPI2 states plus the two edge states. step = which of the 4 steps is current (0 = none, 5 = all closed). */
  var STATES = {
    "none":      { step: 0, label: "KPI үүсгээгүй",        waitOn: "KPI үүсгэх",             bucket: "none" },
    "plan":      { step: 1, label: "Төлөвлөгөө гаргах",    waitOn: "Ажилтан",                bucket: "plan" },
    "returned":  { step: 1, label: "Буцаагдсан",           waitOn: "Ажилтан засварлана",     bucket: "plan" },
    "approve":   { step: 2, label: "Төлөвлөгөө батлах",    waitOn: "Шууд удирдлага",         bucket: "approve" },
    "self-eval": { step: 3, label: "Үнэлгээ хийх",         waitOn: "Ажилтан",                bucket: "eval" },
    "mgr-eval":  { step: 4, label: "Удирдлагын үнэлгээ",   waitOn: "Удирдлага",              bucket: "eval" },
    "done":      { step: 5, label: "Дууссан",              waitOn: "",                       bucket: "done" }
  };
  var STEP_NAMES = ["Төлөвлөгөө гаргах", "Төлөвлөгөө батлах", "Үнэлгээ хийх", "Удирдлагын үнэлгээ"];

  var CHECK = '<svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 6.2l2.3 2.3 4.7-5"/></svg>';
  var CHEVRON = '<svg class="kpi-row__go" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3.5L10.5 8 6 12.5"/></svg>';

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  /* 4-dot stepper. dates: optional array of up to 4 date strings shown in tooltips. */
  function stepper(state, dates) {
    var s = STATES[state] || STATES.none, out = [];
    for (var i = 1; i <= 4; i++) {
      var cls = "kpi-stepper__dot", inner = String(i), tip = STEP_NAMES[i - 1];
      if (s.step > i) { cls += " is-done"; inner = CHECK; tip += " — дууссан" + (dates && dates[i - 1] ? " " + dates[i - 1] : ""); }
      else if (s.step === i) { cls += " is-current"; tip += " — " + (state === "returned" ? "буцаагдсан" : "явагдаж байна"); }
      if (i > 1) out.push('<span class="kpi-stepper__line' + (s.step >= i ? " is-done" : "") + '"></span>');
      out.push('<span class="' + cls + '" title="' + esc(tip) + '">' + inner + "</span>");
    }
    var aria = s.step === 0 ? "KPI үүсгээгүй" : s.step === 5 ? "4 алхам бүгд дууссан" : "4 алхмаас " + s.step + "-р алхам: " + s.label;
    return '<span class="kpi-stepper" data-state="' + esc(state) + '" role="img" aria-label="' + esc(aria) + '">' + out.join("") + "</span>";
  }

  function chip(state) {
    var s = STATES[state] || STATES.none;
    var step = s.step >= 1 && s.step <= 4 ? '<span class="kpi-chip__step">' + s.step + "/4</span> " : "";
    return '<span class="kpi-chip" data-state="' + esc(state) + '">' + step + esc(s.label) + "</span>";
  }

  /* emp: {idx, name, id, state, days, lateAfter, mine, score, href, dates, closed} */
  function row(emp) {
    var s = STATES[emp.state] || STATES.none;
    var wait;
    if (emp.state === "done") {
      wait = '<span class="kpi-row__waiton">Хаагдсан</span>' + (emp.closed ? '<span class="kpi-row__age">' + esc(emp.closed) + "</span>" : "");
    } else if (emp.state === "none") {
      wait = '<span class="kpi-row__waiton">—</span>';
    } else {
      var late = emp.days != null && emp.days > (emp.lateAfter || 7);
      wait = '<span class="kpi-row__waiton">' + esc(s.waitOn) + (emp.mine ? ' <span class="kpi-badge-you">Таны ээлж</span>' : "") + "</span>" +
        (emp.days != null ? '<span class="kpi-row__age' + (late ? " is-late" : "") + '">' + emp.days + " хоног хүлээгдэж байна</span>" : "");
    }
    var score = emp.score != null
      ? '<span class="kpi-row__score">' + esc(emp.score) + "</span>"
      : '<span class="kpi-row__score is-empty">—</span>';
    var inner =
      '<span class="kpi-row__idx">' + esc(emp.idx) + "</span>" +
      '<span class="kpi-row__who' + (emp.lead ? " is-lead" : "") + '"><span class="kpi-row__line"><span class="kpi-row__name">' + esc(emp.name) + '</span><span class="kpi-row__id">' + esc(emp.id) + "</span></span>" +
        (emp.position ? '<span class="kpi-row__pos" title="' + esc(emp.position) + '">' + esc(emp.position) + "</span>" : "") + "</span>" +
      stepper(emp.state, emp.dates) + chip(emp.state) +
      '<span class="kpi-row__wait">' + wait + "</span>" + score + (emp.href ? CHEVRON : "<span></span>");
    return emp.href
      ? '<a class="kpi-row" data-state="' + esc(emp.state) + '" href="' + esc(emp.href) + '">' + inner + "</a>"
      : '<div class="kpi-row is-static" data-state="' + esc(emp.state) + '">' + inner + "</div>";
  }

  function listHead() {
    return '<div class="kpi-list-head"><span>#</span><span>Ажилтан</span><span>Явц</span><span>Төлөв</span><span>Хэнээс хүлээж байна</span><span>Оноо</span><span></span></div>';
  }

  /* counts: {none, plan, approve, eval, done} */
  function dist(counts) {
    var order = [["plan", "plan"], ["approve", "approve"], ["eval", "self-eval"], ["done", "done"], ["none", "none"]];
    return '<div class="kpi-dist" role="img" aria-label="Төлөвийн тархалт">' + order.map(function (o) {
      return counts[o[0]] ? '<span data-state="' + o[1] + '" style="flex-grow:' + counts[o[0]] + '"></span>' : "";
    }).join("") + "</div>";
  }

  function legend(counts) {
    var items = [["plan", "plan", "Төлөвлөж байгаа"], ["approve", "approve", "Батлахаар хүлээгдэж"], ["eval", "self-eval", "Үнэлгээ"], ["done", "done", "Дууссан"], ["none", "none", "KPI үүсгээгүй"]];
    return '<div class="kpi-legend">' + items.map(function (it) {
      return '<span data-state="' + it[1] + '">' + it[2] + " <b>" + (counts[it[0]] || 0) + "</b></span>";
    }).join("") + "</div>";
  }

  function bucketOf(state) { return (STATES[state] || STATES.none).bucket; }

  window.KPI2 = { STATES: STATES, STEP_NAMES: STEP_NAMES, stepper: stepper, chip: chip, row: row, listHead: listHead, dist: dist, legend: legend, bucketOf: bucketOf, esc: esc };
})();
